<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class GithubMessageBuilderTest extends TestCase
{
    /**
     * @dataProvider fixtureProvider
     */
    public function testBuildText(string $EventType, array $Payload, string $ExpectedText): void
    {
        $Builder = new GithubMessageBuilder($EventType, $Payload);
        $Msg = $Builder->build();

        $this->assertArrayHasKey('text', $Msg);
        $this->assertSame($ExpectedText, $Msg['text']);
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testBuildAlwaysHasAvatar(string $EventType, array $Payload): void
    {
        $Builder = new GithubMessageBuilder($EventType, $Payload);
        $Msg = $Builder->build();

        $this->assertArrayHasKey('avatar', $Msg);
        $this->assertNotEmpty($Msg['avatar']);
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testGetRepositoryName(string $EventType, array $Payload, string $_ExpectedText, string $ExpectedRepo): void
    {
        $Builder = new GithubMessageBuilder($EventType, $Payload);
        $this->assertSame($ExpectedRepo, $Builder->getRepositoryName());
    }

    /**
     * @return array<string, array{string, array<mixed>, string, string}>
     */
    public function fixtureProvider(): array
    {
        $data = [];
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'events';

        foreach (new DirectoryIterator($dir) as $File) {
            if ($File->isDot() || !$File->isDir()) {
                continue;
            }

            $path = $File->getPathname();
            $expectedTextFile = $path . DIRECTORY_SEPARATOR . 'expected_text.txt';

            if (!file_exists($expectedTextFile)) {
                continue;
            }

            $eventType = trim((string)file_get_contents($path . DIRECTORY_SEPARATOR . 'type.txt'));
            $payload = json_decode((string)file_get_contents($path . DIRECTORY_SEPARATOR . 'payload.json'), true);
            $expectedText = trim((string)file_get_contents($expectedTextFile));

            $repo = $payload['repository']['full_name']
                ?? sprintf('%s/%s', $payload['repository']['owner']['name'], $payload['repository']['name']);

            $data[$File->getFilename()] = [$eventType, $payload, $expectedText, $repo];
        }

        return $data;
    }

    // ── Push-specific tests ──────────────────────────────────────────────────

    public function testPushSameAuthorOmitsByLine(): void
    {
        $payload = $this->makePushPayload('Alice', [
            ['id' => 'aaa0001', 'author' => 'Alice', 'message' => 'First commit', 'modified' => ['a.php']],
            ['id' => 'bbb0002', 'author' => 'Alice', 'message' => 'Second commit', 'added' => ['b.php']],
        ]);

        $Builder = new GithubMessageBuilder('push', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringNotContainsString(' by Alice', $text);
    }

    public function testPushMultipleAuthorShowsByLine(): void
    {
        $payload = $this->makePushPayload('Alice', [
            ['id' => 'aaa0001', 'author' => 'Alice', 'message' => 'First commit'],
            ['id' => 'bbb0002', 'author' => 'Bob', 'message' => 'Second commit'],
        ]);

        $Builder = new GithubMessageBuilder('push', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString(' by Alice', $text);
        $this->assertStringContainsString(' by Bob', $text);
    }

    public function testPushSingleCommitLabel(): void
    {
        $payload = $this->makePushPayload('Alice', [
            ['id' => 'aaa0001', 'author' => 'Alice', 'message' => 'Fix bug'],
        ]);

        $Builder = new GithubMessageBuilder('push', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('pushed 1 commit', $text);
        $this->assertStringNotContainsString('commits', $text);
    }

    public function testPushMultipleCommitsLabel(): void
    {
        $payload = $this->makePushPayload('Alice', [
            ['id' => 'aaa0001', 'author' => 'Alice', 'message' => 'First'],
            ['id' => 'bbb0002', 'author' => 'Alice', 'message' => 'Second'],
        ]);

        $Builder = new GithubMessageBuilder('push', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('pushed 2 commits', $text);
    }

    public function testPushFileCountsInCommitLine(): void
    {
        $payload = $this->makePushPayload('Alice', [
            ['id' => 'aaa0001', 'author' => 'Alice', 'message' => 'Update', 'added' => ['x.php'], 'modified' => ['a.php', 'b.php'], 'removed' => ['old.php']],
        ]);

        $Builder = new GithubMessageBuilder('push', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('+1 ~2 -1 files', $text);
    }

    public function testPushMultilineCommitMessageUsesFirstLine(): void
    {
        $payload = $this->makePushPayload('Alice', [
            ['id' => 'aaa0001', 'author' => 'Alice', 'message' => "Short summary\n\nLonger body that should not appear"],
        ]);

        $Builder = new GithubMessageBuilder('push', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('Short summary', $text);
        $this->assertStringNotContainsString('Longer body', $text);
    }

    // ── Issues-specific tests ────────────────────────────────────────────────

    public function testIssueOpenedFormat(): void
    {
        $payload = $this->makeIssuePayload('opened', 42, 'Bug report', 'alice', 'org/repo');

        $Builder = new GithubMessageBuilder('issues', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('🐛', $text);
        $this->assertStringContainsString('**opened**', $text);
        $this->assertStringContainsString('#42', $text);
        $this->assertStringContainsString('Bug report', $text);
    }

    public function testIssueClosedIncludesStateReason(): void
    {
        $payload = $this->makeIssuePayload('closed', 10, 'Old issue', 'alice', 'org/repo', 'not_planned');

        $Builder = new GithubMessageBuilder('issues', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('_(reason: not_planned)_', $text);
    }

    public function testIssueWithLabelShowsLabel(): void
    {
        $payload = $this->makeIssuePayload('opened', 5, 'Title', 'alice', 'org/repo', null, ['bug']);

        $Builder = new GithubMessageBuilder('issues', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('`bug`', $text);
    }

    public function testIssueBodyTruncatedAt500Chars(): void
    {
        $longBody = str_repeat('x', 600);
        $payload = $this->makeIssuePayload('opened', 1, 'Title', 'alice', 'org/repo', null, [], $longBody);

        $Builder = new GithubMessageBuilder('issues', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('…', $text);
        $this->assertStringNotContainsString(str_repeat('x', 600), $text);
    }

    // ── Pull request tests ───────────────────────────────────────────────────

    public function testPullRequestOpenedFormat(): void
    {
        $payload = $this->makePRPayload('opened', 7, 'Add feature', 'alice', 'org/repo', 'feature', 'main');

        $Builder = new GithubMessageBuilder('pull_request', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('🔀', $text);
        $this->assertStringContainsString('**opened**', $text);
        $this->assertStringContainsString('#7', $text);
        $this->assertStringContainsString('`feature` → `main`', $text);
    }

    public function testPullRequestMergedShowsCheckmark(): void
    {
        $payload = $this->makePRPayload('closed', 3, 'Merge this', 'alice', 'org/repo', 'feat', 'main', true);

        $Builder = new GithubMessageBuilder('pull_request', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('✅ Merged.', $text);
    }

    public function testPullRequestClosedNotMergedNoCheckmark(): void
    {
        $payload = $this->makePRPayload('closed', 3, 'Close this', 'alice', 'org/repo', 'feat', 'main', false);

        $Builder = new GithubMessageBuilder('pull_request', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringNotContainsString('✅ Merged', $text);
    }

    // ── Gollum tests ─────────────────────────────────────────────────────────

    public function testGollumSinglePageFormat(): void
    {
        $payload = [
            'pages' => [
                ['action' => 'created', 'title' => 'Home', 'html_url' => 'https://github.com/org/repo/wiki/Home', 'summary' => null],
            ],
            'repository' => ['full_name' => 'org/repo'],
            'sender' => ['login' => 'alice', 'avatar_url' => 'https://example.com/a.png'],
        ];

        $Builder = new GithubMessageBuilder('gollum', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('📝', $text);
        $this->assertStringContainsString('updated 1 wiki page', $text);
        $this->assertStringContainsString('**created**', $text);
        $this->assertStringContainsString('[Home]', $text);
    }

    public function testGollumMultiplePagesFormat(): void
    {
        $payload = [
            'pages' => [
                ['action' => 'edited', 'title' => 'Home', 'html_url' => 'https://example.com/Home', 'summary' => null],
                ['action' => 'created', 'title' => 'FAQ', 'html_url' => 'https://example.com/FAQ', 'summary' => 'New page'],
            ],
            'repository' => ['full_name' => 'org/repo'],
            'sender' => ['login' => 'alice', 'avatar_url' => 'https://example.com/a.png'],
        ];

        $Builder = new GithubMessageBuilder('gollum', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('updated 2 wiki pages', $text);
        $this->assertStringContainsString('— New page', $text);
    }

    // ── Default handler tests ────────────────────────────────────────────────

    public function testDefaultHandlerIncludesEventType(): void
    {
        $payload = [
            'action' => 'created',
            'repository' => ['full_name' => 'org/repo'],
            'sender' => ['login' => 'alice', 'avatar_url' => 'https://example.com/a.png'],
        ];

        $Builder = new GithubMessageBuilder('some_event', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringContainsString('**some_event**', $text);
        $this->assertStringContainsString('(created)', $text);
        $this->assertStringContainsString('org/repo', $text);
    }

    public function testDefaultHandlerNoActionOmitsParens(): void
    {
        $payload = [
            'repository' => ['full_name' => 'org/repo'],
            'sender' => ['login' => 'alice', 'avatar_url' => 'https://example.com/a.png'],
        ];

        $Builder = new GithubMessageBuilder('some_event', $payload);
        $text = $Builder->build()['text'];

        $this->assertStringNotContainsString('()', $text);
    }

    // ── GithubWebhook unit tests ─────────────────────────────────────────────

    public function testWebhookProcessRequestFormEncoded(): void
    {
        $_SERVER['HTTP_X_GITHUB_EVENT'] = 'push';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST['payload'] = json_encode(['repository' => ['full_name' => 'org/repo', 'name' => 'repo', 'owner' => ['name' => 'org']], 'sender' => ['login' => 'alice']]);

        $Hook = new GithubWebhook();
        $Hook->ProcessRequest();

        $this->assertSame('push', $Hook->GetEventType());
        $this->assertSame('org/repo', $Hook->GetFullRepositoryName());
    }

    public function testWebhookMissingEventHeaderThrows(): void
    {
        unset($_SERVER['HTTP_X_GITHUB_EVENT']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing event header');

        $Hook = new GithubWebhook();
        $Hook->ProcessRequest();
    }

    public function testWebhookOrgOnlyEventDerivesFullName(): void
    {
        $_SERVER['HTTP_X_GITHUB_EVENT'] = 'org_event';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST['payload'] = json_encode([
            'organization' => ['login' => 'myorg'],
            'sender' => ['login' => 'alice'],
        ]);

        $Hook = new GithubWebhook();
        $Hook->ProcessRequest();

        $this->assertSame('myorg/repositories', $Hook->GetFullRepositoryName());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<array{id: string, author: string, message: string, added?: string[], modified?: string[], removed?: string[]}> $commits
     * @return array<mixed>
     */
    private function makePushPayload(string $authorName, array $commits): array
    {
        $commitData = [];
        foreach ($commits as $c) {
            $commitData[] = [
                'id' => $c['id'],
                'url' => 'https://github.com/org/repo/commit/' . $c['id'],
                'message' => $c['message'],
                'author' => ['name' => $c['author']],
                'added' => $c['added'] ?? [],
                'modified' => $c['modified'] ?? [],
                'removed' => $c['removed'] ?? [],
            ];
        }

        $lastCommit = end($commitData);

        return [
            'ref' => 'refs/heads/main',
            'compare' => 'https://github.com/org/repo/compare/abc...def',
            'commits' => $commitData,
            'head_commit' => array_merge($lastCommit, ['author' => ['name' => $authorName]]),
            'repository' => ['full_name' => 'org/repo'],
            'sender' => ['login' => 'org', 'avatar_url' => 'https://example.com/a.png'],
        ];
    }

    /**
     * @param string[] $labels
     * @return array<mixed>
     */
    private function makeIssuePayload(string $action, int $number, string $title, string $user, string $repo, ?string $stateReason = null, array $labels = [], string $body = ''): array
    {
        return [
            'action' => $action,
            'issue' => [
                'number' => $number,
                'title' => $title,
                'html_url' => "https://github.com/{$repo}/issues/{$number}",
                'state_reason' => $stateReason,
                'labels' => array_map(fn($l) => ['name' => $l], $labels),
                'body' => $body,
            ],
            'repository' => ['full_name' => $repo],
            'sender' => ['login' => $user, 'avatar_url' => 'https://example.com/a.png'],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function makePRPayload(string $action, int $number, string $title, string $user, string $repo, string $head, string $base, bool $merged = false): array
    {
        return [
            'action' => $action,
            'pull_request' => [
                'number' => $number,
                'title' => $title,
                'html_url' => "https://github.com/{$repo}/pull/{$number}",
                'head' => ['ref' => $head],
                'base' => ['ref' => $base],
                'draft' => false,
                'merged' => $merged,
                'commits' => 1,
                'additions' => 10,
                'deletions' => 2,
                'changed_files' => 1,
                'body' => '',
            ],
            'repository' => ['full_name' => $repo],
            'sender' => ['login' => $user, 'avatar_url' => 'https://example.com/a.png'],
        ];
    }
}
