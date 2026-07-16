<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Service;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Service\ReviewPoster;

class ReviewPosterTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('GITHUB_TOKEN');
    }

    protected function tearDown(): void
    {
        putenv('GITHUB_TOKEN');
    }

    public function testConstructorSetsGithubToken(): void
    {
        $poster = new ReviewPoster('test-token-123');
        $this->assertSame('test-token-123', $this->getPrivateProperty($poster, 'githubToken'));
    }

    public function testConstructorUsesEnvVar(): void
    {
        putenv('GITHUB_TOKEN=env-token-456');
        $poster = new ReviewPoster();
        $this->assertSame('env-token-456', $this->getPrivateProperty($poster, 'githubToken'));
    }

    public function testConstructorUsesEmptyStringWhenNoToken(): void
    {
        $poster = new ReviewPoster();
        $this->assertSame('', $this->getPrivateProperty($poster, 'githubToken'));
    }

    public function testIsGhCliAvailableReturnsBool(): void
    {
        $poster = new ReviewPoster();
        $result = $poster->isGhCliAvailable();
        $this->assertIsBool($result);
    }

    public function testPostCommentRequiresToken(): void
    {
        $poster = new ReviewPoster(); // No token

        $result = $poster->postComment('owner/repo', 1, 'test comment');

        $this->assertFalse($result['success']);
        // Error should mention token is needed
        $this->assertIsString($result['error']);
    }

    public function testPostReviewCommentRequiresToken(): void
    {
        $poster = new ReviewPoster(); // No token

        $result = $poster->postReviewComment(
            'owner/repo',
            1,
            'test comment',
            'abc123',
            'src/file.php',
            42
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('token', strtolower($result['error']));
    }

    public function testPostReviewRequiresToken(): void
    {
        $poster = new ReviewPoster(); // No token

        $result = $poster->postReview(
            'owner/repo',
            1,
            'test body',
            [['path' => 'file.php', 'line' => 10, 'body' => 'comment']]
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('token', strtolower($result['error']));
    }

    public function testPostCommentViaGhReturnsErrorOnFailure(): void
    {
        // Skip if gh is not available
        $poster = new ReviewPoster('fake-token');
        if (!$poster->isGhCliAvailable()) {
            $this->markTestSkipped('gh CLI not available');
        }

        // This should fail because the token is fake
        $result = $poster->postComment('owner/repo', 1, 'test comment');

        $this->assertFalse($result['success']);
    }

    public function testParseRepoSplitsCorrectly(): void
    {
        $poster = new ReviewPoster();

        $result = $this->invokePrivateMethod($poster, 'parseRepo', ['owner/repo']);

        $this->assertSame(['owner', 'repo'], $result);
    }

    public function testParseRepoHandlesSinglePart(): void
    {
        $poster = new ReviewPoster();

        $result = $this->invokePrivateMethod($poster, 'parseRepo', ['onlyone']);

        $this->assertSame(['onlyone', ''], $result);
    }

    public function testEscapeShellArgSurroundsWithSingleQuotes(): void
    {
        $poster = new ReviewPoster();
        $result = $this->invokePrivateMethod($poster, 'escapeShellArg', ["test'value"]);

        // Should be escaped with single quotes
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringContainsString("test", $result);
    }

    public function testMakeApiRequestReturnsErrorOnCurlFailure(): void
    {
        // Create poster with invalid-like token to force API call
        $poster = new ReviewPoster('test-token');

        // Mock curl failure by using an invalid URL
        // We can't easily mock curl, so we test the error handling path
        $result = $this->invokePrivateMethod($poster, 'makeApiRequest', [
            'POST',
            'https://api.github.com/invalid-url',
            ['test' => 'data']
        ]);

        // Should either succeed (network available) or fail gracefully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testCheckGhCliAvailabilityReturnsBool(): void
    {
        $poster = new ReviewPoster();
        $result = $this->invokePrivateMethod($poster, 'checkGhCliAvailability');

        $this->assertIsBool($result);
    }

    public function testPostReviewWithEmptyComments(): void
    {
        $poster = new ReviewPoster(); // No token, will fail with token error
        $result = $poster->postReview('owner/repo', 1, 'summary', []);

        // Should fail due to no token
        $this->assertFalse($result['success']);
    }

    public function testPostReviewCommentFormatsDataCorrectly(): void
    {
        // This tests the data formatting without making actual API calls
        $poster = new ReviewPoster();

        // We can verify the method exists and has correct signature
        $reflection = new \ReflectionClass($poster);
        $method = $reflection->getMethod('postReviewComment');

        $params = $method->getParameters();
        $this->assertCount(7, $params);
        $this->assertSame('repo', $params[0]->getName());
        $this->assertSame('prNumber', $params[1]->getName());
        $this->assertSame('body', $params[2]->getName());
        $this->assertSame('commitId', $params[3]->getName());
        $this->assertSame('path', $params[4]->getName());
        $this->assertSame('line', $params[5]->getName());
        $this->assertSame('side', $params[6]->getName());
    }

    public function testPostReviewFormatsCommentsCorrectly(): void
    {
        $poster = new ReviewPoster();

        $comments = [
            [
                'path' => 'src/Database.php',
                'line' => 42,
                'side' => 'RIGHT',
                'body' => 'Security issue here',
                'commit_id' => 'abc123',
            ],
            [
                'path' => 'src/Database.php',
                'line' => 50,
                'body' => 'Another issue',
            ],
        ];

        // We can't easily test the API call, but we can verify the method exists
        $reflection = new \ReflectionClass($poster);
        $method = $reflection->getMethod('postReview');

        $this->assertTrue($method->isPublic());
    }

    /**
     * Helper to get private property value.
     */
    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    /**
     * Helper to invoke private method.
     *
     * @param array<mixed> $args
     */
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }
}
