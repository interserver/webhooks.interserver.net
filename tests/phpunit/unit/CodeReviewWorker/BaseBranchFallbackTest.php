<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

class BaseBranchFallbackTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        $this->scriptPath = __DIR__ . '/../../../../scripts/github-code-review.php';
        $this->assertFileExists($this->scriptPath);
    }

    public function testBaseBranchFallbackFlow(): void
    {
        $job = [
            'id' => 'test_job_123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'base_branch' => 'main',
            'head_branch' => 'feature-branch',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'testuser',
            'sha' => 'abc123',
            'action' => 'opened',
        ];

        $log = [];
        $checkoutCalls = [];
        $running = true;

        $mockCheckoutBranch = function(string $repo, string $branch, string $path) use (&$checkoutCalls): string {
            $checkoutCalls[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path];
            if ($branch === 'main') {
                return 'gh repo clone failed: main branch not found';
            }
            if ($branch === 'master') {
                return 'true';
            }
            return 'unknown branch';
        };

        $mockGetPRBaseBranch = function(string $repo, int $prNumber): string {
            return 'master';
        };

        $result = $this->simulateCheckoutFlow(
            $job,
            $mockCheckoutBranch,
            $mockGetPRBaseBranch,
            $running
        );

        $this->assertCount(2, $checkoutCalls, 'Should call checkoutBranch twice');
        $this->assertSame('main', $checkoutCalls[0]['branch'], 'First call should use base_branch=main');
        $this->assertSame('master', $checkoutCalls[1]['branch'], 'Second call should use actualBaseBranch=master');
        $this->assertSame('true', $result, 'Should succeed after fallback to master');
    }

    public function testBaseBranchFallbackFailsWhenActualBranchCheckoutAlsoFails(): void
    {
        $job = [
            'id' => 'test_job_123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'base_branch' => 'main',
            'head_branch' => 'feature-branch',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'testuser',
            'sha' => 'abc123',
            'action' => 'opened',
        ];

        $checkoutCalls = [];
        $running = true;

        $mockCheckoutBranch = function(string $repo, string $branch, string $path) use (&$checkoutCalls): string {
            $checkoutCalls[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path];
            return 'gh repo clone failed: branch not found';
        };

        $mockGetPRBaseBranch = function(string $repo, int $prNumber): string {
            return 'master';
        };

        $result = $this->simulateCheckoutFlow(
            $job,
            $mockCheckoutBranch,
            $mockGetPRBaseBranch,
            $running
        );

        $this->assertCount(2, $checkoutCalls, 'Should call checkoutBranch twice');
        $this->assertStringContainsString('checkout failed', $result, 'Should return checkout failed error');
    }

    public function testGetPRBaseBranchIsCalledOnlyWhenFirstCheckoutFails(): void
    {
        $job = [
            'id' => 'test_job_123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'base_branch' => 'main',
            'head_branch' => 'feature-branch',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'testuser',
            'sha' => 'abc123',
            'action' => 'opened',
        ];

        $checkoutCalls = [];
        $getPRBaseBranchCalls = [];
        $running = true;

        $mockCheckoutBranch = function(string $repo, string $branch, string $path) use (&$checkoutCalls): string {
            $checkoutCalls[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path];
            return 'true';
        };

        $mockGetPRBaseBranch = function(string $repo, int $prNumber) use (&$getPRBaseBranchCalls): string {
            $getPRBaseBranchCalls[] = ['repo' => $repo, 'prNumber' => $prNumber];
            return 'master';
        };

        $result = $this->simulateCheckoutFlow(
            $job,
            $mockCheckoutBranch,
            $mockGetPRBaseBranch,
            $running
        );

        $this->assertCount(1, $checkoutCalls, 'Should call checkoutBranch only once');
        $this->assertCount(0, $getPRBaseBranchCalls, 'Should NOT call getPRBaseBranch when first checkout succeeds');
        $this->assertSame('true', $result, 'Should succeed on first checkout');
    }

    public function testGetPRBaseBranchNotCalledWhenShutdownBeforeFallback(): void
    {
        $job = [
            'id' => 'test_job_123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'base_branch' => 'main',
            'head_branch' => 'feature-branch',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'testuser',
            'sha' => 'abc123',
            'action' => 'opened',
        ];

        $checkoutCalls = [];
        $getPRBaseBranchCalls = [];
        $running = false;

        $mockCheckoutBranch = function(string $repo, string $branch, string $path) use (&$checkoutCalls): string {
            $checkoutCalls[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path];
            return 'shutdown';
        };

        $mockGetPRBaseBranch = function(string $repo, int $prNumber) use (&$getPRBaseBranchCalls): string {
            $getPRBaseBranchCalls[] = ['repo' => $repo, 'prNumber' => $prNumber];
            return 'master';
        };

        $result = $this->simulateCheckoutFlow(
            $job,
            $mockCheckoutBranch,
            $mockGetPRBaseBranch,
            $running
        );

        $this->assertCount(1, $checkoutCalls, 'Should call checkoutBranch once');
        $this->assertCount(0, $getPRBaseBranchCalls, 'Should NOT call getPRBaseBranch when shutdown before fallback');
        $this->assertSame('shutdown', $result, 'Should return shutdown');
    }

    public function testGetPRBaseBranchReturnsNullDoesNotRetry(): void
    {
        $job = [
            'id' => 'test_job_123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'base_branch' => 'main',
            'head_branch' => 'feature-branch',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'testuser',
            'sha' => 'abc123',
            'action' => 'opened',
        ];

        $checkoutCalls = [];
        $running = true;

        $mockCheckoutBranch = function(string $repo, string $branch, string $path) use (&$checkoutCalls): string {
            $checkoutCalls[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path];
            if ($branch === 'main') {
                return 'gh repo clone failed: main branch not found';
            }
            return 'true';
        };

        $mockGetPRBaseBranch = function(string $repo, int $prNumber): ?string {
            return null;
        };

        $result = $this->simulateCheckoutFlow(
            $job,
            $mockCheckoutBranch,
            $mockGetPRBaseBranch,
            $running
        );

        $this->assertCount(1, $checkoutCalls, 'Should call checkoutBranch only once');
        $this->assertSame('main', $checkoutCalls[0]['branch'], 'First call should use main');
        $this->assertStringContainsString('checkout failed', $result, 'Should fail when getPRBaseBranch returns null');
    }

    public function testActualBaseBranchSameAsBaseBranchDoesNotRetry(): void
    {
        $job = [
            'id' => 'test_job_123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'base_branch' => 'main',
            'head_branch' => 'feature-branch',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'author' => 'testuser',
            'sha' => 'abc123',
            'action' => 'opened',
        ];

        $checkoutCalls = [];
        $running = true;

        $mockCheckoutBranch = function(string $repo, string $branch, string $path) use (&$checkoutCalls): string {
            $checkoutCalls[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path];
            return 'gh repo clone failed: main branch not found';
        };

        $mockGetPRBaseBranch = function(string $repo, int $prNumber): string {
            return 'main';
        };

        $result = $this->simulateCheckoutFlow(
            $job,
            $mockCheckoutBranch,
            $mockGetPRBaseBranch,
            $running
        );

        $this->assertCount(1, $checkoutCalls, 'Should call checkoutBranch only once');
        $this->assertSame('main', $checkoutCalls[0]['branch'], 'Should use main (same as actual)');
        $this->assertStringContainsString('checkout failed', $result, 'Should fail when base branches match and checkout fails');
    }

    /**
     * Simulate the checkout flow from lines 410-451 of github-code-review.php
     */
    private function simulateCheckoutFlow(
        array $job,
        callable $checkoutBranchFn,
        callable $getPRBaseBranchFn,
        bool $running
    ): string {
        $baseBranch = $job['base_branch'] ?? '';
        $repo = $job['repo'] ?? '';
        $prNumber = (int)($job['pr_number'] ?? 0);

        $checkoutPath = '/tmp/test_checkout/' . $repo . '/' . $prNumber;

        $checkoutOk = $checkoutBranchFn($repo, $baseBranch, $checkoutPath);

        if ($checkoutOk === 'shutdown') {
            return 'shutdown';
        }

        if ($checkoutOk !== 'true') {
            if (!$running) {
                return 'shutdown';
            }

            $actualBaseBranch = $getPRBaseBranchFn($repo, $prNumber);

            if ($actualBaseBranch !== null && $actualBaseBranch !== $baseBranch) {
                $checkoutOk = $checkoutBranchFn($repo, $actualBaseBranch, $checkoutPath);

                if ($checkoutOk === 'shutdown') {
                    return 'shutdown';
                }
            }

            if ($checkoutOk !== 'true') {
                return 'checkout failed: ' . $checkoutOk;
            }

            if (isset($actualBaseBranch)) {
                $baseBranch = $actualBaseBranch;
            }
        }

        return 'true';
    }
}
