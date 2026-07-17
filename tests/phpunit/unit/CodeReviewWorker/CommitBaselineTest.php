<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the commit-baseline redesign in scripts/github-code-review.php:
 * commitPrSnapshot(), captureAgentFixes(), getAgentFileDiff(), and
 * resetCheckoutToCleanState(). Each test drives a real throwaway git repo so
 * the git plumbing is exercised end to end.
 */
class CommitBaselineTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;

        $this->dir = sys_get_temp_dir() . '/crw_baseline_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** Run a git command in the test repo and return trimmed stdout+stderr. */
    private function git(string $args): string
    {
        return trim((string)shell_exec('cd ' . escapeshellarg($this->dir) . ' && git ' . $args . ' 2>&1'));
    }

    /**
     * Create an initial commit on a `main` branch with the given files and
     * return the base commit SHA.
     *
     * @param array<string, string> $files path => contents
     */
    private function initBaseRepo(array $files): string
    {
        $this->git('init -q');
        foreach ($files as $path => $contents) {
            file_put_contents($this->dir . '/' . $path, $contents);
        }
        $this->git('add -A');
        $this->git('-c user.email=setup@test -c user.name=Setup commit -q -m base');
        $this->git('branch -M main');
        return $this->git('rev-parse HEAD');
    }

    public function testCommitPrSnapshotRecordsBaseAndCommitsPr(): void
    {
        $baseSha = $this->initBaseRepo(['a.php' => "<?php\necho 1;\n"]);

        // Apply a "PR": modify a tracked file and add a new one.
        file_put_contents($this->dir . '/a.php', "<?php\necho 2;\n");
        file_put_contents($this->dir . '/b.php', "<?php\necho 'new';\n");

        $this->assertTrue(commitPrSnapshot($this->dir, 42));

        // _base pins the base commit; HEAD has advanced to the snapshot.
        $this->assertSame($baseSha, $this->git('rev-parse _base'), '_base must pin the base commit');
        $this->assertNotSame($baseSha, $this->git('rev-parse HEAD'), 'HEAD must advance to the snapshot');
        $this->assertSame('PR #42 snapshot', $this->git('log -1 --pretty=%s'));

        // Working tree is clean and the PR shows up as _base..HEAD.
        $this->assertSame('', $this->git('status --porcelain'), 'tree must be clean after snapshot');
        $changed = $this->git('diff --name-only _base HEAD');
        $this->assertStringContainsString('a.php', $changed);
        $this->assertStringContainsString('b.php', $changed);
    }

    public function testCaptureAgentFixesReturnsOnlyAgentEdits(): void
    {
        $this->initBaseRepo(['a.php' => "<?php\necho 1;\n"]);
        file_put_contents($this->dir . '/a.php', "<?php\necho 2;\n");
        file_put_contents($this->dir . '/b.php', "<?php\necho 'new';\n");
        $this->assertTrue(commitPrSnapshot($this->dir, 7));

        // Agent edits one file in place, leaving it uncommitted.
        file_put_contents($this->dir . '/a.php', "<?php\necho 2; // fixed by agent\n");

        $fixes = captureAgentFixes($this->dir);
        $this->assertSame(['a.php'], $fixes['files'], 'only the agent-edited file is listed');
        $this->assertStringContainsString('fixed by agent', $fixes['diff']);

        // Per-file diff has hunks only for the edited file.
        $this->assertStringContainsString('fixed by agent', getAgentFileDiff($this->dir, 'a.php'));
        $this->assertSame('', trim(getAgentFileDiff($this->dir, 'b.php')));
    }

    public function testCaptureAgentFixesEmptyWhenTreeClean(): void
    {
        $this->initBaseRepo(['a.php' => "<?php\necho 1;\n"]);
        file_put_contents($this->dir . '/a.php', "<?php\necho 2;\n");
        $this->assertTrue(commitPrSnapshot($this->dir, 1));

        $fixes = captureAgentFixes($this->dir);
        $this->assertSame([], $fixes['files']);
        $this->assertSame('', $fixes['diff']);
    }

    public function testResetCheckoutToCleanStateWipesEditsSnapshotAndBaseRef(): void
    {
        $baseSha = $this->initBaseRepo(['a.php' => "v1\n"]);

        // Simulate a previous job's leftovers: a committed PR snapshot (which a
        // fresh checkoutBranch() would already have re-pointed the branch away
        // from — emulated here by resetting main back to the base commit), a
        // stale _base ref, an in-place tracked edit, and an untracked file.
        file_put_contents($this->dir . '/a.php', "v2\n");
        file_put_contents($this->dir . '/b.php', "added by PR\n");
        $this->assertTrue(commitPrSnapshot($this->dir, 99));
        $this->git('reset --hard ' . $baseSha); // emulate checkoutBranch sync to fresh base
        file_put_contents($this->dir . '/a.php', "agent-edit\n"); // leftover tracked edit
        file_put_contents($this->dir . '/c.php', "agent-untracked\n"); // leftover untracked file

        $this->assertTrue(resetCheckoutToCleanState($this->dir, 'main'));

        // Tree is pristine at the base commit.
        $this->assertSame($baseSha, $this->git('rev-parse HEAD'));
        $this->assertSame('', $this->git('status --porcelain'), 'working tree must be clean');
        $this->assertSame("v1\n", file_get_contents($this->dir . '/a.php'), 'tracked edit reverted');
        $this->assertFileDoesNotExist($this->dir . '/c.php', 'untracked file removed');
        $this->assertFileDoesNotExist($this->dir . '/b.php', 'snapshot-only file removed');

        // The scratch _base ref is gone so a cached checkout cannot drift on it.
        $this->assertSame('', $this->git('branch --list _base'), '_base ref must be deleted');
    }
}
