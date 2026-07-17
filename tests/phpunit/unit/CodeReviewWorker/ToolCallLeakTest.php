<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the MiniMax/SGLang tool-call-leak detection and cleanup in
 * scripts/github-code-review.php: looksLikeLeakedToolCall() and
 * stripLeakedToolCalls().
 */
class ToolCallLeakTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
    }

    public function testDetectsMinimaxToolCallLeakAtEnd(): void
    {
        $out = "I'll start the review.\n\n<invoke name=\"bash\">\n<parameter name=\"command\">git status</parameter>\n</invoke>\n</minimax:tool_call>\n";
        $this->assertTrue(looksLikeLeakedToolCall($out));
    }

    public function testDetectsInvokeBlockLeak(): void
    {
        $out = "Let me check.\n<invoke name=\"grep\">\n<parameter name=\"pattern\">foo</parameter>\n</invoke>";
        $this->assertTrue(looksLikeLeakedToolCall($out));
    }

    public function testDetectsPipeStyleToolCallLeak(): void
    {
        $this->assertTrue(looksLikeLeakedToolCall("working...\n<|tool_calls_begin|><tool_call>"));
    }

    public function testCleanReportIsNotFlagged(): void
    {
        $report = <<<'MD'
Here is my review.

#### 🔴 SQL Injection — include/file.php:207

User input concatenated into the query.

That's all — no other issues found.
MD;
        $this->assertFalse(looksLikeLeakedToolCall($report));
    }

    public function testEmptyOutputIsNotFlagged(): void
    {
        $this->assertFalse(looksLikeLeakedToolCall(''));
    }

    public function testMarkupQuotedEarlyButCleanEndingIsNotFlagged(): void
    {
        // A report that mentions <invoke ...> markup near the top but then ends
        // with a normal 2 KB summary should NOT be treated as truncated,
        // because only the tail is inspected.
        $body = "The agent printed <invoke name=\"bash\"> once by mistake early on.\n\n";
        $body .= str_repeat("This is a normal concluding sentence of the review. ", 60);
        $this->assertFalse(looksLikeLeakedToolCall($body));
    }

    public function testDetectionIgnoresTrailingAnsiAndWhitespace(): void
    {
        $out = "review...\n</minimax:tool_call>\e[0m\n   ";
        $this->assertTrue(looksLikeLeakedToolCall($out));
    }

    public function testStripRemovesPairedMinimaxAndInvokeBlocks(): void
    {
        $in = "Report body.\n<minimax:tool_call><invoke name=\"bash\"><parameter name=\"command\">ls</parameter></invoke></minimax:tool_call>\nMore report.";
        $out = stripLeakedToolCalls($in);
        $this->assertStringNotContainsString('minimax:tool_call', $out);
        $this->assertStringNotContainsString('<invoke', $out);
        $this->assertStringNotContainsString('<parameter', $out);
        $this->assertStringContainsString('Report body.', $out);
        $this->assertStringContainsString('More report.', $out);
    }

    public function testStripLeavesNormalMarkdownUntouched(): void
    {
        $md = "#### 🟡 Style — a.php:5\n\nUse strict comparison.";
        $this->assertSame($md, stripLeakedToolCalls($md));
    }

    public function testParseAnalysisOutputStripsLeakedMarkupFromAcceptedPartial(): void
    {
        // A partial report that still contains a leaked block should parse the
        // real issue and not choke on the markup.
        $raw = "#### 🔴 Bug — src/a.php:12\n\nOff-by-one in the loop.\n\n<invoke name=\"bash\"><parameter name=\"command\">git log</parameter></invoke></minimax:tool_call>";
        $issues = parseAnalysisOutput($raw);
        $this->assertCount(1, $issues);
        $this->assertSame('src/a.php', $issues[0]['file']);
        $this->assertSame(12, $issues[0]['line']);
        $this->assertStringNotContainsString('invoke', $issues[0]['message']);
    }
}
