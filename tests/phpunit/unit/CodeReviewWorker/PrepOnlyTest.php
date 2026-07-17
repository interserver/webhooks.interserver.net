<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the --only-prep support functions in scripts/github-code-review.php:
 * getReviewPrompt(), prepareOpencodeCommand(), and buildPrepInstructions().
 */
class PrepOnlyTest extends TestCase
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
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testGetReviewPromptLoadsProjectPromptFile(): void
    {
        $prompt = getReviewPrompt();
        $this->assertNotSame('', $prompt);
        // scripts/prompts/review.txt identifies itself as a Code Review agent
        $this->assertStringContainsString('Code Review', $prompt);
    }

    public function testPrepareOpencodeCommandWritesPromptAndBuildsCommand(): void
    {
        $jobId = 'unit-test-' . getmypid();
        $res = prepareOpencodeCommand($jobId);
        $this->tmpFiles[] = $res['promptFile'];

        $this->assertArrayHasKey('cmd', $res);
        $this->assertArrayHasKey('promptFile', $res);
        $this->assertSame("/tmp/opencode-prompt-{$jobId}.txt", $res['promptFile']);
        $this->assertFileExists($res['promptFile']);

        // Command must cat the exact prompt file it just wrote, in JSON format
        $this->assertStringContainsString('opencode run "$(cat ', $res['cmd']);
        $this->assertStringContainsString($res['promptFile'], $res['cmd']);
        $this->assertStringContainsString('--format json', $res['cmd']);

        // File content matches the loaded review prompt
        $this->assertSame(getReviewPrompt(), file_get_contents($res['promptFile']));
    }

    public function testBuildPrepInstructionsIncludesRunAndCleanupCommands(): void
    {
        $checkout = '/tmp/pr-checkouts/owner/repo/base-main';
        $promptFile = '/tmp/opencode-prompt-abc.txt';
        $opencodeCmd = 'opencode run "$(cat ' . escapeshellarg($promptFile) . ')" --format json';

        $text = buildPrepInstructions($checkout, 'owner/repo', 42, 'main', $promptFile, $opencodeCmd, "src/a.php\nsrc/b.php");

        // Header + metadata
        $this->assertStringContainsString('--only-prep', $text);
        $this->assertStringContainsString('owner/repo#42', $text);
        $this->assertStringContainsString('Base ref  : main', $text);
        $this->assertStringContainsString($checkout, $text);
        $this->assertStringContainsString('src/a.php, src/b.php', $text);

        // The runnable command: cd into the checkout then the opencode command
        $this->assertStringContainsString('cd ' . escapeshellarg($checkout), $text);
        $this->assertStringContainsString($opencodeCmd, $text);

        // The cleanup command removes both the checkout and the prompt file
        $this->assertStringContainsString('rm -rf ' . escapeshellarg($checkout) . ' ' . escapeshellarg($promptFile), $text);
    }

    public function testBuildPrepInstructionsWarnsWhenNoChangedFiles(): void
    {
        $text = buildPrepInstructions('/tmp/x', 'o/r', 1, 'main', '/tmp/p.txt', 'opencode run x', '');
        $this->assertStringContainsString('none', $text);
        $this->assertStringContainsString('failed to apply', $text);
    }
}
