<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pinned normal-PR review path added to
 * scripts/github-code-review.php: resolvePrHeadSha(), setupPrHeadBaseline() and
 * the underlying setupCommitRangeBaseline() plumbing it relies on.
 *
 * Every test is network-free: the git-plumbing case drives
 * setupCommitRangeBaseline() directly with local SHAs (the merge-base and head),
 * and the resolver/guard cases never reach the GitHub API.
 */
class PrHeadBaselineTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;

        $this->dir = sys_get_temp_dir() . '/crw_prhead_' . bin2hex(random_bytes(6));
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
     * Build a merge-base commit + a feature (head) commit on main and return
     * [mergeBaseSha, headSha]. In this linear history the merge-base is simply
     * the commit the feature was built on — exactly what GitHub's 3-dot compare
     * would report for a PR.
     *
     * @return array{0: string, 1: string}
     */
    private function initMergeBaseAndHead(): array
    {
        $this->git('init -q');
        file_put_contents($this->dir . '/a.php', "<?php\necho 1;\n");
        $this->git('add -A');
        $this->git('-c user.email=setup@test -c user.name=Setup commit -q -m base');
        $this->git('branch -M main');
        $mergeBase = $this->git('rev-parse HEAD');

        file_put_contents($this->dir . '/a.php', "<?php\necho 2;\n");
        file_put_contents($this->dir . '/b.php', "<?php\necho 'feature';\n");
        $this->git('add -A');
        $this->git('-c user.email=setup@test -c user.name=Setup commit -q -m feature');
        $head = $this->git('rev-parse HEAD');

        return [$mergeBase, $head];
    }

    /**
     * The plumbing setupPrHeadBaseline() delegates to: driven directly with the
     * local merge-base and head SHAs so no network fetch happens. `git diff
     * _base HEAD` must be exactly the PR change, and a later agent edit must be
     * the only thing captureAgentFixes() reports.
     */
    public function testCommitRangeBaselinePinsMergeBaseAndHead(): void
    {
        [$mergeBase, $head] = $this->initMergeBaseAndHead();

        $this->assertSame('true', setupCommitRangeBaseline($this->dir, 'owner/repo', $mergeBase, $head));

        $this->assertSame($mergeBase, $this->git('rev-parse _base'), '_base pins the merge-base commit');
        $this->assertSame($head, $this->git('rev-parse HEAD'), 'HEAD lands on the head commit');

        // The PR change is exactly _base..HEAD.
        $changed = $this->git('diff --name-only _base HEAD');
        $this->assertStringContainsString('a.php', $changed);
        $this->assertStringContainsString('b.php', $changed);

        // Agent edits one file in place -> captureAgentFixes returns ONLY that.
        file_put_contents($this->dir . '/a.php', "<?php\necho 2; // fixed by agent\n");
        $fixes = captureAgentFixes($this->dir);
        $this->assertSame(['a.php'], $fixes['files'], 'only the agent-edited file is listed');
        $this->assertStringContainsString('fixed by agent', $fixes['diff']);
    }

    /**
     * resolvePrHeadSha() prefers a non-empty $job['sha'] and returns it verbatim
     * (trimmed) without any GitHub API lookup.
     */
    public function testResolvePrHeadShaPrefersJobSha(): void
    {
        $sha = '0123456789abcdef0123456789abcdef01234567';
        $job = ['sha' => $sha, 'pr_number' => 42, 'base_branch' => 'main'];

        $this->assertSame($sha, resolvePrHeadSha($job, 'owner/repo', 42));
    }

    public function testResolvePrHeadShaTrimsJobSha(): void
    {
        $job = ['sha' => "  abc1234  \n"];
        $this->assertSame('abc1234', resolvePrHeadSha($job, 'owner/repo', 42));
    }

    /**
     * With no job SHA and nothing to query against (pr_number 0), the resolver
     * returns '' without touching the network so the caller takes the fallback.
     */
    public function testResolvePrHeadShaEmptyWhenNoShaAndNoPrNumber(): void
    {
        $this->assertSame('', resolvePrHeadSha(['sha' => ''], 'owner/repo', 0));
        $this->assertSame('', resolvePrHeadSha([], 'owner/repo', 0));
        $this->assertSame('', resolvePrHeadSha(['sha' => ''], '', 42));
    }

    /**
     * setupPrHeadBaseline() short-circuits on an empty head SHA before any API
     * call, so the guard is exercised network-free.
     */
    public function testSetupPrHeadBaselineMissingHeadShaReturnsError(): void
    {
        $this->git('init -q');
        $this->assertSame('missing head sha', setupPrHeadBaseline($this->dir, 'owner/repo', 'main', ''));
    }
}
