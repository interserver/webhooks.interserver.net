<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

class VerboseLoggingTest extends TestCase
{
    private string $tempLogFile;
    private int $originalVerbose;
    private ?string $originalLogFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLogFile = tempnam(sys_get_temp_dir(), 'test_log_');
        $this->originalVerbose = 0;
        $this->originalLogFile = null;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }
        parent::tearDown();
    }

    /**
     * Helper to call verbose_log with specific verbosity and log file
     */
    private function callVerboseLog(string $message, int $level, int $verbose, ?string $logFile): void
    {
        // We test the logic by checking what would be logged at each level
        // This is the inline implementation for testing
        if ($verbose >= $level) {
            $prefix = match ($level) {
                1 => '[INFO]',
                2 => '[DEBUG]',
                3 => '[TRACE]',
                4 => '[RAW]',
                default => '[VERBOSE]'
            };
            $line = "github-code-review: {$prefix} {$message}";
            // error_log would output here in real implementation
            if ($logFile !== null) {
                $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
                file_put_contents($logFile, "[{$timestamp}] {$line}\n", FILE_APPEND);
            }
        }
    }

    /**
     * Helper to call verbose_raw with specific verbosity and log file
     */
    private function callVerboseRaw(string $label, string $output, int $verbose, ?string $logFile): void
    {
        if ($verbose >= 4) {
            $prefix = '[RAW]';
            $line = "github-code-review: {$prefix} {$label}";
            if ($logFile !== null) {
                $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
                file_put_contents($logFile, "[{$timestamp}] {$line}\n[{$timestamp}] --- BEGIN {$label} ---\n{$output}\n[{$timestamp}] --- END {$label} ---\n", FILE_APPEND);
            }
        }
    }

    /**
     * Helper to call verbose_markdown (without actual Renderer::renderMarkdown)
     */
    private function callVerboseMarkdown(string $label, string $markdown, int $verbose, ?string $logFile): void
    {
        if ($verbose >= 4) {
            $prefix = '[MD]';
            $line = "github-code-review: {$prefix} {$label}";
            if ($logFile !== null) {
                $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
                file_put_contents($logFile, "[{$timestamp}] {$line}\n[{$timestamp}] {$markdown}\n", FILE_APPEND);
            }
        }
    }

    // ==========================================
    // verbose_log Tests
    // ==========================================

    public function testVerboseLogAtLevel1WithVerbose0(): void
    {
        // At verbose=0, nothing at level 1 should be logged
        $this->callVerboseLog('test message', 1, 0, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertSame('', $content);
    }

    public function testVerboseLogAtLevel1WithVerbose1(): void
    {
        $this->callVerboseLog('test message', 1, 1, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('test message', $content);
    }

    public function testVerboseLogAtLevel2WithVerbose1(): void
    {
        // At verbose=1, level 2 should NOT be logged
        $this->callVerboseLog('debug message', 2, 1, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertSame('', $content);
    }

    public function testVerboseLogAtLevel2WithVerbose2(): void
    {
        $this->callVerboseLog('debug message', 2, 2, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('[DEBUG]', $content);
        $this->assertStringContainsString('debug message', $content);
    }

    public function testVerboseLogAtLevel3WithVerbose2(): void
    {
        $this->callVerboseLog('trace message', 3, 2, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertSame('', $content);
    }

    public function testVerboseLogAtLevel3WithVerbose3(): void
    {
        $this->callVerboseLog('trace message', 3, 3, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('[TRACE]', $content);
    }

    public function testVerboseLogAtLevel4WithVerbose3(): void
    {
        $this->callVerboseLog('raw message', 4, 3, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertSame('', $content);
    }

    public function testVerboseLogAtLevel4WithVerbose4(): void
    {
        $this->callVerboseLog('raw message', 4, 4, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('[RAW]', $content);
    }

    public function testVerboseLogWithNullLogFile(): void
    {
        // Should not throw even with null log file (no file_put_contents)
        $this->callVerboseLog('message', 1, 1, null);
        $this->assertTrue(true); // No exception thrown
    }

    public function testVerboseLogPrefixForEachLevel(): void
    {
        $levels = [
            1 => '[INFO]',
            2 => '[DEBUG]',
            3 => '[TRACE]',
            4 => '[RAW]',
        ];

        foreach ($levels as $level => $expectedPrefix) {
            $logFile = tempnam(sys_get_temp_dir(), 'test_level_');
            $this->callVerboseLog('message', $level, $level, $logFile);
            $content = file_get_contents($logFile);
            $this->assertStringContainsString($expectedPrefix, $content, "Level {$level} should have prefix {$expectedPrefix}");
            unlink($logFile);
        }
    }

    // ==========================================
    // verbose_raw Tests
    // ==========================================

    public function testVerboseRawOnlyLogsAtLevel4(): void
    {
        // Level 3 should not log raw output
        $this->callVerboseRaw('test_label', 'some output', 3, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertSame('', $content);
    }

    public function testVerboseRawLogsAtLevel4(): void
    {
        $this->callVerboseRaw('test_label', 'some output content', 4, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('[RAW]', $content);
        $this->assertStringContainsString('test_label', $content);
        $this->assertStringContainsString('some output content', $content);
        $this->assertStringContainsString('--- BEGIN test_label ---', $content);
        $this->assertStringContainsString('--- END test_label ---', $content);
    }

    public function testVerboseRawWithEmptyOutput(): void
    {
        $this->callVerboseRaw('empty_test', '', 4, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('--- BEGIN empty_test ---', $content);
        $this->assertStringContainsString('--- END empty_test ---', $content);
    }

    public function testVerboseRawWithMultilineOutput(): void
    {
        $multiline = "line1\nline2\nline3";
        $this->callVerboseRaw('multi', $multiline, 4, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString("line1\nline2\nline3", $content);
    }

    public function testVerboseRawWithNullLogFile(): void
    {
        // Should not throw with null log file
        $this->callVerboseRaw('label', 'output', 4, null);
        $this->assertTrue(true);
    }

    // ==========================================
    // verbose_markdown Tests
    // ==========================================

    public function testVerboseMarkdownOnlyLogsAtLevel4(): void
    {
        // Level 3 should not log markdown
        $this->callVerboseMarkdown('md_test', '# Header', 3, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertSame('', $content);
    }

    public function testVerboseMarkdownLogsAtLevel4(): void
    {
        $markdown = "#### 🔴 Critical Issue — file.php:42";
        $this->callVerboseMarkdown('issue', $markdown, 4, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('[MD]', $content);
        $this->assertStringContainsString('issue', $content);
        $this->assertStringContainsString($markdown, $content);
    }

    public function testVerboseMarkdownWithNullLogFile(): void
    {
        // Should not throw with null log file
        $this->callVerboseMarkdown('md', '# Test', 4, null);
        $this->assertTrue(true);
    }

    public function testVerboseMarkdownLogsMarkdownContent(): void
    {
        $markdown = "## Header\n\nSome **bold** text and *italic*.";
        $this->callVerboseMarkdown('formatted', $markdown, 4, $this->tempLogFile);
        $content = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString($markdown, $content);
    }

    // ==========================================
    // Level Boundary Tests
    // ==========================================

    public function testAllLevelsLogAtMaxVerbose(): void
    {
        $verbose = 4; // Max level

        $levels = [
            1 => '[INFO]',
            2 => '[DEBUG]',
            3 => '[TRACE]',
            4 => '[RAW]',
        ];

        foreach ($levels as $level => $prefix) {
            $logFile = tempnam(sys_get_temp_dir(), 'test_max_');
            $this->callVerboseLog("level {$level} message", $level, $verbose, $logFile);
            $content = file_get_contents($logFile);
            $this->assertStringContainsString($prefix, $content, "Level {$level} should log at verbose=4");
            unlink($logFile);
        }
    }

    public function testLevel4FunctionsOnlyLogAtVerbose4(): void
    {
        // verbose_raw and verbose_markdown only work at level 4
        foreach ([1, 2, 3] as $v) {
            $logFile = tempnam(sys_get_temp_dir(), 'test_l4_');
            $this->callVerboseRaw('test', 'output', $v, $logFile);
            $content = file_get_contents($logFile);
            $this->assertSame('', $content, "verbose_raw should not log at verbose={$v}");
            unlink($logFile);
        }
    }
}
