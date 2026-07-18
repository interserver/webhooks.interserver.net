<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the push / commit-scoped baseline helpers in
 * scripts/github-code-review.php: setupPushBaseline() and
 * setupCommitScopedBaseline() (both via setupCommitRangeBaseline()). Each test
 * drives a real throwaway git repo whose commits already exist locally, so no
 * network fetch is triggered.
 */
class PushBaselineTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;

        $this->dir = sys_get_temp_dir() . '/crw_push_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function git(string $args): string
    {
        return trim((string)shell_exec('cd ' . escapeshellarg($this->dir) . ' && git ' . $args . ' 2>&1'));
    }

    /**
     * Build a two-commit history on main and return [beforeSha, afterSha].
     *
     * @return array{0: string, 1: string}
     */
    private function initTwoCommitRepo(): array
    {
        $this->git('init -q');
        file_put_contents($this->dir . '/a.php', "<?php\necho 1;\n");
        $this->git('add -A');
        $this->git('-c user.email=setup@test -c user.name=Setup commit -q -m first');
        $this->git('branch -M main');
        $before = $this->git('rev-parse HEAD');

        file_put_contents($this->dir . '/a.php', "<?php\necho 2;\n");
        file_put_contents($this->dir . '/b.php', "<?php\necho 'pushed';\n");
        $this->git('add -A');
        $this->git('-c user.email=setup@test -c user.name=Setup commit -q -m second');
        $after = $this->git('rev-parse HEAD');

        return [$before, $after];
    }

    public function testPushBaselineWithBeforeAndAfter(): void
    {
        [$before, $after] = $this->initTwoCommitRepo();

        $this->assertSame('true', setupPushBaseline($this->dir, 'owner/repo', $before, $after));

        $this->assertSame($before, $this->git('rev-parse _base'), '_base pins the before commit');
        $this->assertSame($after, $this->git('rev-parse HEAD'), 'HEAD lands on the after commit');

        // The pushed change is exactly _base..HEAD.
        $changed = $this->git('diff --name-only _base HEAD');
        $this->assertStringContainsString('a.php', $changed);
        $this->assertStringContainsString('b.php', $changed);

        // Agent edits in place -> captureAgentFixes returns ONLY the agent edit.
        file_put_contents($this->dir . '/a.php', "<?php\necho 2; // fixed by agent\n");
        $fixes = captureAgentFixes($this->dir);
        $this->assertSame(['a.php'], $fixes['files']);
        $this->assertStringContainsString('fixed by agent', $fixes['diff']);
    }

    public function testPushBaselineNewBranchFallsBackToAfterParent(): void
    {
        [$before, $after] = $this->initTwoCommitRepo();

        // Empty before_sha signals a brand-new branch: baseline must be after^,
        // which in this linear history equals the first commit.
        $this->assertSame('true', setupPushBaseline($this->dir, 'owner/repo', '', $after));

        $this->assertSame($before, $this->git('rev-parse _base'), '_base must be after^');
        $this->assertSame($after, $this->git('rev-parse HEAD'));
    }

    public function testCommitScopedBaseline(): void
    {
        [$before, $after] = $this->initTwoCommitRepo();

        // Commit-scoped review of the second commit: _base = SHA^, HEAD = SHA.
        $this->assertSame('true', setupCommitScopedBaseline($this->dir, 'owner/repo', $after));

        $this->assertSame($before, $this->git('rev-parse _base'), '_base must be the reviewed commit parent');
        $this->assertSame($after, $this->git('rev-parse HEAD'));

        $changed = $this->git('diff --name-only _base HEAD');
        $this->assertStringContainsString('b.php', $changed);
    }

    public function testMissingHeadShaReturnsError(): void
    {
        $this->git('init -q');
        $result = setupPushBaseline($this->dir, 'owner/repo', '', '');
        $this->assertSame('missing head sha', $result);
    }
}
