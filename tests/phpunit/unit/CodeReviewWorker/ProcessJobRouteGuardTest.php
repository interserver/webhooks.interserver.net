<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for processJob()'s kind routing and validation guards in
 * scripts/github-code-review.php. Every case here returns BEFORE any checkout,
 * gh, or GitHub API call, so no network is touched.
 */
class ProcessJobRouteGuardTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;
        $GLOBALS['running'] = true;
        $GLOBALS['reviewBots'] = false;
        $GLOBALS['onlyPrep'] = false;
        $GLOBALS['checkoutRoot'] = sys_get_temp_dir() . '/crw-guard-checkouts';
    }

    protected function tearDown(): void
    {
        $GLOBALS['running'] = true;
    }

    public function testShutdownWhenNotRunning(): void
    {
        $GLOBALS['running'] = false;
        $this->assertSame('shutdown', processJob(['kind' => 'pr', 'repo' => 'owner/repo']));
    }

    public function testMissingRepoRejected(): void
    {
        $this->assertSame('invalid job: missing repo', processJob(['kind' => 'pr', 'repo' => '']));
    }

    public function testBadRepoNameRejected(): void
    {
        $this->assertSame('invalid job: bad repo name', processJob(['kind' => 'pr', 'repo' => 'bad repo name']));
    }

    public function testPathTraversalRepoRejected(): void
    {
        $this->assertSame('invalid job: bad repo name', processJob(['kind' => 'pr', 'repo' => '../../etc/passwd']));
    }

    public function testBotJobSkippedWhenReviewBotsOff(): void
    {
        $result = processJob([
            'kind' => 'pr',
            'repo' => 'owner/repo',
            'author' => 'dependabot[bot]',
            'pr_number' => 5,
            'base_branch' => 'main',
        ]);
        $this->assertSame('skipped bot job', $result);
    }

    public function testBotPushSkippedWhenReviewBotsOff(): void
    {
        $result = processJob([
            'kind' => 'push',
            'repo' => 'owner/repo',
            'is_bot' => true,
            'ref' => 'main',
            'after_sha' => 'abc123',
        ]);
        $this->assertSame('skipped bot job', $result);
    }

    public function testPrMissingRequiredFieldsRejected(): void
    {
        $result = processJob([
            'kind' => 'pr',
            'repo' => 'owner/repo',
            'pr_number' => 0,
            'base_branch' => 'main',
        ]);
        $this->assertSame('invalid job: missing required fields', $result);
    }

    public function testPushMissingAfterShaRejected(): void
    {
        $result = processJob([
            'kind' => 'push',
            'repo' => 'owner/repo',
            'ref' => 'main',
            'after_sha' => '',
        ]);
        $this->assertSame('invalid job: push missing ref or after_sha', $result);
    }

    public function testPushMissingRefRejected(): void
    {
        $result = processJob([
            'kind' => 'push',
            'repo' => 'owner/repo',
            'ref' => '',
            'after_sha' => 'abc123',
        ]);
        $this->assertSame('invalid job: push missing ref or after_sha', $result);
    }

    public function testLegacyEnvelopeWithoutKindRoutesAsPr(): void
    {
        // No "kind" -> treated as pr; missing pr fields -> pr validation error
        // (proves it did NOT route to the push path).
        $result = processJob([
            'repo' => 'owner/repo',
            'pr_number' => 0,
            'base_branch' => '',
        ]);
        $this->assertSame('invalid job: missing required fields', $result);
    }
}
