<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the submit-option handling in scripts/github-code-review.php:
 * composeReviewPrompt() (audit-type scope prefix), buildCombinedComment()
 * (--combine), groupIssuesForSplit()/buildGroupComment() (--split-changes), and
 * the post_branch/issue_for_commits warn-and-fall-back stub. All logic under
 * test is pure — no network calls.
 */
class ReviewOptionsTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // === composeReviewPrompt (audit_types scope) ===

    public function testComposePromptFullReturnsBaseUnchanged(): void
    {
        $base = 'BASE PROMPT';
        $this->assertSame($base, composeReviewPrompt(['full'], $base));
    }

    public function testComposePromptAllReturnsBaseUnchanged(): void
    {
        $base = 'BASE PROMPT';
        $this->assertSame($base, composeReviewPrompt(['all'], $base));
    }

    public function testComposePromptEmptyReturnsBaseUnchanged(): void
    {
        $base = 'BASE PROMPT';
        $this->assertSame($base, composeReviewPrompt([], $base));
    }

    public function testComposePromptSubsetPrependsScopeLine(): void
    {
        $base = 'BASE PROMPT';
        $out = composeReviewPrompt(['security', 'logic'], $base);

        $this->assertNotSame($base, $out);
        $this->assertStringStartsWith('REVIEW SCOPE:', $out);
        $this->assertStringContainsString('security, logic', $out);
        $this->assertStringContainsString('BASE PROMPT', $out);
        // Base prompt must survive intact after the scope prefix.
        $this->assertStringEndsWith($base, $out);
    }

    public function testComposePromptIgnoresUnknownCategories(): void
    {
        $base = 'BASE PROMPT';
        // Only "style" is valid here; "bogus" is dropped.
        $out = composeReviewPrompt(['bogus', 'style'], $base);
        $this->assertStringContainsString('style', $out);
        $this->assertStringNotContainsString('bogus', $out);
    }

    public function testComposePromptAllUnknownReturnsBase(): void
    {
        $base = 'BASE PROMPT';
        $this->assertSame($base, composeReviewPrompt(['bogus', 'nope'], $base));
    }

    // === buildCombinedComment (--combine) ===

    public function testCombinedCommentListsAllIssuesAndDiff(): void
    {
        $issues = [
            ['file' => 'a.php', 'line' => 10, 'severity' => 'critical', 'message' => 'SQL injection'],
            ['file' => 'b.php', 'line' => 20, 'severity' => 'minor', 'message' => 'Naming nit'],
        ];
        $diff = "--- a/a.php\n+++ b/a.php\n@@ -10 +10 @@\n-bad\n+good\n";

        $body = buildCombinedComment($issues, $diff);

        $this->assertStringContainsString('Code Review Summary', $body);
        $this->assertStringContainsString('2 issues', $body);
        $this->assertStringContainsString('`a.php`:10', $body);
        $this->assertStringContainsString('SQL injection', $body);
        $this->assertStringContainsString('`b.php`:20', $body);
        $this->assertStringContainsString('Naming nit', $body);
        // Combined fix diff is embedded once.
        $this->assertStringContainsString('### Proposed Fixes', $body);
        $this->assertStringContainsString('```diff', $body);
        $this->assertStringContainsString('+good', $body);
    }

    public function testCombinedCommentWithoutDiffOmitsFixesSection(): void
    {
        $issues = [['file' => 'a.php', 'line' => 1, 'severity' => 'info', 'message' => 'x']];
        $body = buildCombinedComment($issues, '');
        $this->assertStringContainsString('1 issue', $body);
        $this->assertStringNotContainsString('Proposed Fixes', $body);
    }

    // === groupIssuesForSplit (--split-changes) ===

    /** @return array<int, array<string, mixed>> */
    private function makeIssues(int $n, string $file = 'a.php', string $severity = 'minor'): array
    {
        $issues = [];
        for ($i = 0; $i < $n; $i++) {
            $issues[] = ['file' => $file, 'line' => $i + 1, 'severity' => $severity, 'message' => "issue {$i}"];
        }
        return $issues;
    }

    public function testSplitByFileGroupsPerFile(): void
    {
        $issues = array_merge(
            $this->makeIssues(2, 'a.php'),
            $this->makeIssues(3, 'b.php')
        );
        $groups = groupIssuesForSplit($issues, 'file', 10);
        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups[0]);
        $this->assertCount(3, $groups[1]);
    }

    public function testSplitBySeverityGroupsPerSeverity(): void
    {
        $issues = array_merge(
            $this->makeIssues(1, 'a.php', 'critical'),
            $this->makeIssues(2, 'b.php', 'minor')
        );
        $groups = groupIssuesForSplit($issues, 'severity', 10);
        $this->assertCount(2, $groups);
    }

    public function testSplitByAuditUsesAuditKey(): void
    {
        $issues = [
            ['file' => 'a.php', 'line' => 1, 'severity' => 'minor', 'audit' => 'security', 'message' => 'x'],
            ['file' => 'b.php', 'line' => 2, 'severity' => 'minor', 'audit' => 'security', 'message' => 'y'],
            ['file' => 'c.php', 'line' => 3, 'severity' => 'minor', 'audit' => 'style', 'message' => 'z'],
        ];
        $groups = groupIssuesForSplit($issues, 'audit', 10);
        $this->assertCount(2, $groups, 'two audit buckets: security (2) + style (1)');
    }

    public function testSplitBySizeChunksIntoFixedBatches(): void
    {
        $issues = $this->makeIssues(25, 'a.php');
        $groups = groupIssuesForSplit($issues, 'size', 10);
        $this->assertCount(3, $groups);
        $this->assertCount(10, $groups[0]);
        $this->assertCount(10, $groups[1]);
        $this->assertCount(5, $groups[2]);
    }

    public function testSplitBatchSizeBoundaryWithinOneBucket(): void
    {
        // 12 issues in ONE file, batch 10 -> two sub-batches (10, 2).
        $issues = $this->makeIssues(12, 'a.php');
        $groups = groupIssuesForSplit($issues, 'file', 10);
        $this->assertCount(2, $groups);
        $this->assertCount(10, $groups[0]);
        $this->assertCount(2, $groups[1]);
    }

    public function testSplitEmptyIssuesGivesNoGroups(): void
    {
        $this->assertSame([], groupIssuesForSplit([], 'file', 10));
    }

    public function testSplitUnknownStrategyDefaultsToFile(): void
    {
        $issues = array_merge($this->makeIssues(1, 'a.php'), $this->makeIssues(1, 'b.php'));
        $groups = groupIssuesForSplit($issues, 'bogus', 10);
        $this->assertCount(2, $groups);
    }

    public function testSplitZeroBatchSizeDefaultsToTen(): void
    {
        $issues = $this->makeIssues(15, 'a.php');
        $groups = groupIssuesForSplit($issues, 'file', 0);
        $this->assertCount(2, $groups);
        $this->assertCount(10, $groups[0]);
        $this->assertCount(5, $groups[1]);
    }

    public function testBuildGroupCommentMentionsSplitLabel(): void
    {
        $group = $this->makeIssues(2, 'a.php');
        $body = buildGroupComment($group, 'file', 1, 3, 'batch-A');
        $this->assertStringContainsString('group 1/3', $body);
        $this->assertStringContainsString('by file', $body);
        $this->assertStringContainsString('batch-A', $body);
        $this->assertStringContainsString('`a.php`:1', $body);
    }

    public function testBuildGroupCommentWithoutLabel(): void
    {
        $group = $this->makeIssues(1, 'a.php');
        $body = buildGroupComment($group, 'severity', 2, 2);
        $this->assertStringContainsString('group 2/2', $body);
        $this->assertStringNotContainsString('[', $body);
    }

    // === post_branch / issue_for_commits stubs ===

    public function testPostBranchStubWarnsAndCreatesNothing(): void
    {
        $logFile = sys_get_temp_dir() . '/crw_optlog_' . bin2hex(random_bytes(6)) . '.log';
        $this->tmpFiles[] = $logFile;
        $GLOBALS['verbose'] = 1;
        $GLOBALS['logFile'] = $logFile;

        $postCtx = ['kind' => 'pr', 'repo' => 'owner/repo', 'pr_number' => 1, 'commit_sha' => 'abc'];
        // Empty issue list -> the default posting loop makes zero network calls,
        // but the not-implemented warning still fires.
        $posted = postReviewFindings([], '/tmp/nonexistent', 'token', $postCtx, ['post_branch' => true], '', []);

        $this->assertSame(0, $posted, 'no branch/PR/issue is created');
        $log = (string)file_get_contents($logFile);
        $this->assertStringContainsString('post_branch', $log);
        $this->assertStringContainsString('not yet implemented', $log);
    }

    public function testIssueForCommitsStubWarnsAndCreatesNothing(): void
    {
        $logFile = sys_get_temp_dir() . '/crw_optlog_' . bin2hex(random_bytes(6)) . '.log';
        $this->tmpFiles[] = $logFile;
        $GLOBALS['verbose'] = 1;
        $GLOBALS['logFile'] = $logFile;

        $postCtx = ['kind' => 'push', 'repo' => 'owner/repo', 'pr_number' => 0, 'commit_sha' => 'abc'];
        $posted = postReviewFindings([], '/tmp/nonexistent', 'token', $postCtx, [
            'post_branch' => true,
            'issue_for_commits' => true,
        ], '', []);

        $this->assertSame(0, $posted);
        $log = (string)file_get_contents($logFile);
        $this->assertStringContainsString('post_branch/issue_for_commits', $log);
        $this->assertStringContainsString('not yet implemented', $log);
    }
}
