<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the parallel-worker isolation + feedback-footer + prompt-override
 * additions to scripts/github-code-review.php:
 *   - outputPrefix() / buildWorkerLogPrefix()  (per-worker/per-job log tagging)
 *   - checkoutCacheKey()                        (per-worker cache namespacing)
 *   - reviewFeedbackFooter()                    (the 👍/👎 blurb on every comment)
 *   - composeReviewPrompt() with an override    (--prompt / --prompt-file base)
 *
 * All logic under test is pure — no network, no fork, no git.
 */
class ParallelAndPromptTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;
        $GLOBALS['workerSlot'] = -1;
        $GLOBALS['logPrefix'] = '';
        $GLOBALS['checkoutCacheNs'] = '';
    }

    protected function tearDown(): void
    {
        // Restore single-worker defaults so we never leak a prefix/namespace
        // into another test's expectations.
        $GLOBALS['workerSlot'] = -1;
        $GLOBALS['logPrefix'] = '';
        $GLOBALS['checkoutCacheNs'] = '';
    }

    // === outputPrefix / buildWorkerLogPrefix ===

    public function testSingleWorkerHasNoPrefix(): void
    {
        $GLOBALS['workerSlot'] = -1;
        $this->assertSame('', buildWorkerLogPrefix());
        $this->assertSame('', buildWorkerLogPrefix(['repo' => 'o/r', 'pr_number' => 1, 'id' => 'abcdef12']));
    }

    public function testOutputPrefixReflectsLogPrefixGlobal(): void
    {
        $GLOBALS['logPrefix'] = '[w1] ';
        $this->assertSame('[w1] ', outputPrefix());
        $GLOBALS['logPrefix'] = '';
        $this->assertSame('', outputPrefix());
    }

    public function testParallelWorkerIdleTagNamesOnlyTheWorker(): void
    {
        $GLOBALS['workerSlot'] = 0;
        $this->assertSame('[w0] ', buildWorkerLogPrefix());
    }

    public function testParallelWorkerPrTagNamesTheQueueEntry(): void
    {
        $GLOBALS['workerSlot'] = 2;
        $tag = buildWorkerLogPrefix([
            'repo' => 'detain/my',
            'kind' => 'pr',
            'pr_number' => 158,
            'id' => 'abcd1234 effff',
        ]);
        $this->assertSame('[w2 detain/my#158 abcd1234] ', $tag);
    }

    public function testParallelWorkerPushTagUsesRef(): void
    {
        $GLOBALS['workerSlot'] = 3;
        $tag = buildWorkerLogPrefix([
            'repo' => 'detain/my',
            'kind' => 'push',
            'ref' => 'master',
            'id' => 'zzzz9999yyyy',
        ]);
        $this->assertSame('[w3 detain/my@master zzzz9999] ', $tag);
    }

    // === checkoutCacheKey (per-worker isolation) ===

    public function testCheckoutCacheKeyDefaultHasNoNamespace(): void
    {
        $GLOBALS['checkoutCacheNs'] = '';
        $this->assertSame('github:checkout:v1:owner/repo:main', checkoutCacheKey('owner/repo', 'main'));
    }

    public function testCheckoutCacheKeyIsNamespacedPerWorker(): void
    {
        $GLOBALS['checkoutCacheNs'] = 'w0:';
        $k0 = checkoutCacheKey('owner/repo', 'main');
        $GLOBALS['checkoutCacheNs'] = 'w1:';
        $k1 = checkoutCacheKey('owner/repo', 'main');

        $this->assertSame('github:checkout:v1:w0:owner/repo:main', $k0);
        $this->assertSame('github:checkout:v1:w1:owner/repo:main', $k1);
        $this->assertNotSame($k0, $k1, 'two workers must not share a cache key for the same repo:branch');
    }

    // === reviewFeedbackFooter ===

    public function testFeedbackFooterHasThumbsAndNoBracket(): void
    {
        $footer = reviewFeedbackFooter();
        $this->assertStringContainsString("\u{1F44D}", $footer); // 👍
        $this->assertStringContainsString("\u{1F44E}", $footer); // 👎
        // buildGroupComment()'s no-label test asserts the body has no '[', and
        // this footer rides along on that body.
        $this->assertStringNotContainsString('[', $footer);
    }

    public function testEveryCommentBuilderCarriesTheFooter(): void
    {
        $issue = ['file' => 'a.php', 'line' => 10, 'severity' => 'critical', 'message' => 'SQLi'];

        $this->assertStringContainsString("\u{1F44D}", buildIssueComment($issue, null, 'o/r', 1, false));
        $this->assertStringContainsString("\u{1F44D}", buildCombinedComment([$issue], ''));
        $this->assertStringContainsString("\u{1F44D}", buildGroupComment([$issue], 'file', 1, 1, ''));
    }

    // === composeReviewPrompt with a --prompt / --prompt-file override ===

    public function testPromptOverrideBecomesTheBaseWithAuditScope(): void
    {
        $out = composeReviewPrompt(['security'], 'MY-CUSTOM-PROMPT');
        $this->assertStringContainsString('MY-CUSTOM-PROMPT', $out);
        $this->assertStringContainsString('REVIEW SCOPE', $out);
        $this->assertStringContainsString('security', $out);
    }

    public function testPromptOverrideUsedVerbatimForFullAudit(): void
    {
        $this->assertSame('MY-CUSTOM-PROMPT', composeReviewPrompt(['full'], 'MY-CUSTOM-PROMPT'));
        $this->assertSame('MY-CUSTOM-PROMPT', composeReviewPrompt([], 'MY-CUSTOM-PROMPT'));
    }
}
