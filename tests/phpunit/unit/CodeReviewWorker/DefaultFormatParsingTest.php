<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for --format default (plain text) output handling in
 * scripts/github-code-review.php: parseAnalysisOutput fallbacks,
 * formatStreamLine display routing, ANSI stripping, and checkout path
 * construction.
 */
class DefaultFormatParsingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
    }

    // ==========================================
    // parseAnalysisOutput — plain text (--format default)
    // ==========================================

    public function testParseAnalysisOutputPlainTextMarkdown(): void
    {
        $raw = <<<'TXT'
Here is my review of the changes.

#### 🔴 SQL Injection Vulnerability — include/file.php:207

User input is concatenated directly into the query string.

#### 🟡 Loose Comparison — src/Foo.php:10

Use strict comparison (===) instead of ==.

TXT;

        $issues = parseAnalysisOutput($raw);

        $this->assertCount(2, $issues);
        $this->assertSame('include/file.php', $issues[0]['file']);
        $this->assertSame(207, $issues[0]['line']);
        $this->assertSame('critical', $issues[0]['severity']);
        $this->assertStringContainsString('concatenated', $issues[0]['message']);
        $this->assertSame('src/Foo.php', $issues[1]['file']);
        $this->assertSame(10, $issues[1]['line']);
        $this->assertSame('minor', $issues[1]['severity']);
    }

    public function testParseAnalysisOutputPlainTextWithAnsiCodes(): void
    {
        $raw = "\e[1m#### 🔴 Hardcoded Secret — config/app.php:3\e[0m\n\nAPI key committed to the repository.\n";

        $issues = parseAnalysisOutput($raw);

        $this->assertCount(1, $issues);
        $this->assertSame('config/app.php', $issues[0]['file']);
        $this->assertSame(3, $issues[0]['line']);
    }

    public function testParseAnalysisOutputPlainTextJsonBlock(): void
    {
        $raw = "Review complete.\n```json\n[{\"file\":\"a.php\",\"line\":5,\"severity\":\"error\",\"message\":\"bad call\"}]\n```\n";

        $issues = parseAnalysisOutput($raw);

        $this->assertCount(1, $issues);
        $this->assertSame('a.php', $issues[0]['file']);
        $this->assertSame(5, $issues[0]['line']);
        $this->assertSame('critical', $issues[0]['severity']);
    }

    public function testParseAnalysisOutputNdjsonStillSupported(): void
    {
        $event = json_encode([
            'type' => 'text',
            'part' => ['text' => "```json\n[{\"file\":\"b.php\",\"line\":9,\"severity\":\"warning\",\"message\":\"smell\"}]\n```"],
        ]);
        $issues = parseAnalysisOutput($event . "\n");

        $this->assertCount(1, $issues);
        $this->assertSame('b.php', $issues[0]['file']);
        $this->assertSame(9, $issues[0]['line']);
        $this->assertSame('minor', $issues[0]['severity']);
    }

    public function testParseAnalysisOutputEmptyInput(): void
    {
        $this->assertSame([], parseAnalysisOutput(''));
    }

    public function testParseAnalysisOutputFiltersIssuesWithoutFileLineByDefault(): void
    {
        $raw = "#### 🟢 General Note\n\nOverall the code looks fine.\n";

        $this->assertSame([], parseAnalysisOutput($raw));
        $all = parseAnalysisOutput($raw, true);
        $this->assertCount(1, $all);
        $this->assertSame('', $all[0]['file']);
    }

    // ==========================================
    // formatStreamLine — display routing
    // ==========================================

    public function testFormatStreamLinePassesThroughPlainText(): void
    {
        $this->assertSame('Explore 1: -> glob **/*.php', formatStreamLine("Explore 1: -> glob **/*.php\n"));
    }

    public function testFormatStreamLinePreservesBlankLines(): void
    {
        $this->assertSame('', formatStreamLine("\n"));
    }

    public function testFormatStreamLineFormatsNdjsonTextEvent(): void
    {
        $line = json_encode(['type' => 'text', 'part' => ['text' => 'hello world']]) . "\n";
        $this->assertSame('hello world', formatStreamLine($line, 0));
    }

    public function testFormatStreamLineFormatsStepFinishEvent(): void
    {
        $line = json_encode(['type' => 'step_finish', 'part' => ['tokens' => ['input' => 10, 'output' => 5]]]);
        $this->assertSame('✓ step 10+5 tokens', formatStreamLine($line, 0));
    }

    public function testFormatStreamLineSkipsUnknownNdjsonEvents(): void
    {
        $this->assertNull(formatStreamLine('{"type":"snapshot","part":{}}'));
    }

    public function testFormatStreamLineTreatsInvalidJsonAsPlainText(): void
    {
        $this->assertSame('{"broken json', formatStreamLine('{"broken json' . "\n"));
    }

    // ==========================================
    // subagent NDJSON events (from subagent-reporter.ts in --format json mode)
    // ==========================================

    public function testFormatStreamLineRendersSubagentToolEvent(): void
    {
        $line = json_encode(['type' => 'subagent', 'event' => 'tool', 'agent' => 'Explore', 'n' => 1, 'tool' => 'bash', 'detail' => 'git status']);
        $this->assertSame('Explore 1: -> bash git status', formatStreamLine($line, 0));
    }

    public function testFormatStreamLineRendersSubagentToolEventWithoutDetail(): void
    {
        $line = json_encode(['type' => 'subagent', 'event' => 'tool', 'agent' => 'Explore', 'n' => 2, 'tool' => 'glob', 'detail' => '']);
        $this->assertSame('Explore 2: -> glob', formatStreamLine($line, 0));
    }

    public function testFormatStreamLineRendersSubagentTextEvent(): void
    {
        $line = json_encode(['type' => 'subagent', 'event' => 'text', 'agent' => 'Explore', 'n' => 1, 'text' => 'Found the changes.']);
        $this->assertSame('Explore 1: Found the changes.', formatStreamLine($line, 0));
    }

    public function testFormatStreamLineRendersSubagentReasoningEvent(): void
    {
        $line = json_encode(['type' => 'subagent', 'event' => 'reasoning', 'agent' => 'Explore', 'n' => 1, 'text' => 'Let me check git.']);
        $this->assertSame('Explore 1: Thinking: Let me check git.', formatStreamLine($line, 0));
    }

    public function testFormatStreamLineRendersSubagentFinishedEvent(): void
    {
        $line = json_encode(['type' => 'subagent', 'event' => 'finished', 'agent' => 'Explore', 'n' => 1]);
        $this->assertSame('**** [EXPLORE FINISHED] ****', formatStreamLine($line, 0));
    }

    public function testParseAnalysisOutputIgnoresSubagentEventsAndKeepsMainReview(): void
    {
        // --format json stream: opencode's own text event carries the review;
        // interspersed subagent events must NOT be parsed as issues.
        $lines = [
            json_encode(['type' => 'subagent', 'event' => 'tool', 'agent' => 'Explore', 'n' => 1, 'tool' => 'bash', 'detail' => 'git diff']),
            json_encode(['type' => 'subagent', 'event' => 'text', 'agent' => 'Explore', 'n' => 1, 'text' => '#### 🔴 Fake — decoy.php:99']),
            json_encode(['type' => 'text', 'part' => ['text' => "```json\n[{\"file\":\"real.php\",\"line\":7,\"severity\":\"error\",\"message\":\"real bug\"}]\n```"]]),
            json_encode(['type' => 'subagent', 'event' => 'finished', 'agent' => 'Explore', 'n' => 1]),
        ];
        $issues = parseAnalysisOutput(implode("\n", $lines) . "\n");

        $this->assertCount(1, $issues);
        $this->assertSame('real.php', $issues[0]['file']);
        $this->assertSame(7, $issues[0]['line']);
    }

    // ==========================================
    // stripAnsi
    // ==========================================

    public function testStripAnsiRemovesCsiAndOscSequences(): void
    {
        $this->assertSame('red text', stripAnsi("\e[31mred\e[0m text"));
        $this->assertSame('title', stripAnsi("\e]0;window name\x07title"));
        $this->assertSame('plain', stripAnsi('plain'));
    }

    // ==========================================
    // buildCheckoutPath
    // ==========================================

    public function testBuildCheckoutPathSanitizesBranchNames(): void
    {
        $this->assertSame(
            '/tmp/pr-checkouts/owner/repo/base-feature_foo',
            buildCheckoutPath('/tmp/pr-checkouts', 'owner/repo', 'feature/foo')
        );
        $this->assertSame(
            '/tmp/pr-checkouts/owner/repo/base-main',
            buildCheckoutPath('/tmp/pr-checkouts', 'owner/repo', 'main')
        );
        $this->assertSame(
            '/tmp/pr-checkouts/owner/repo/base-branch',
            buildCheckoutPath('/tmp/pr-checkouts', 'owner/repo', '')
        );
    }

    // ==========================================
    // normalizeIssue (real production function)
    // ==========================================

    public function testNormalizeIssueSeverityMapping(): void
    {
        $this->assertSame('critical', normalizeIssue(['severity' => 'error'])['severity']);
        $this->assertSame('major', normalizeIssue(['severity' => 'Major'])['severity']);
        $this->assertSame('minor', normalizeIssue(['severity' => 'warn'])['severity']);
        $this->assertSame('info', normalizeIssue(['severity' => 'suggestion'])['severity']);
    }
}
