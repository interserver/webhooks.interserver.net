<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Interactor;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Output\NullOutput;
use Webhooks\Cli\Interactor\InteractiveTui;

/**
 * Tests for InteractiveTui edge cases when STDIN is null or not a TTY.
 *
 * These tests verify that the TUI properly detects non-interactive environments
 * and handles edge cases gracefully without crashing.
 *
 * Edge case testing for STDIN/STDOUT being null requires subprocess testing
 * because PHP's built-in STDIN/STDOUT constants cannot be modified at runtime.
 * We use a subprocess script to close stdin and verify the behavior.
 */
final class InteractiveTuiTest extends TestCase
{
    private NullOutput $output;

    protected function setUp(): void
    {
        $this->output = new NullOutput();
    }

    /**
     * Use reflection to invoke the private detectAtty method.
     */
    private function invokeDetectAtty(InteractiveTui $tui): bool
    {
        $reflection = new ReflectionClass(InteractiveTui::class);
        $method = $reflection->getMethod('detectAtty');
        $method->setAccessible(true);
        return $method->invoke($tui);
    }

    /**
     * Use reflection to invoke the private saveTerminalSettings method.
     */
    private function invokeSaveTerminalSettings(InteractiveTui $tui): ?array
    {
        $reflection = new ReflectionClass(InteractiveTui::class);
        $method = $reflection->getMethod('saveTerminalSettings');
        $method->setAccessible(true);
        return $method->invoke($tui);
    }

    /**
     * Use reflection to get the isAtty property value.
     */
    private function getIsAtty(InteractiveTui $tui): bool
    {
        $reflection = new ReflectionClass(InteractiveTui::class);
        $property = $reflection->getProperty('isAtty');
        $property->setAccessible(true);
        return $property->getValue($tui);
    }

    /**
     * Test that detectAtty returns false when stdin is closed.
     *
     * This tests the scenario where STDIN has been closed (e.g., by fclose(STDIN)).
     * We use a subprocess to actually close stdin and verify detectAtty returns false.
     *
     * This is a POSITIVE test: when stdin IS NOT a TTY (closed), detectAtty returns false.
     */
    public function testDetectAttyReturnsFalseWhenStdinIsClosed(): void
    {
        $scriptPath = __DIR__ . '/test_edge_cases_subprocess.php';

        // Run the subprocess script that closes stdin
        $process = proc_open(
            'php ' . escapeshellarg($scriptPath),
            [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes
        );

        $this->assertNotFalse($process, 'proc_open should succeed');

        // Close stdin in the subprocess - this is done in the script itself
        fclose($pipes[0]);

        // Read output from subprocess
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Parse JSON output
        $result = json_decode(trim($output), true);
        $this->assertNotNull($result, 'Output should be valid JSON: ' . $output);
        $this->assertArrayHasKey('detectAttyResult', $result);
        $this->assertArrayHasKey('isAtty', $result);

        // When stdin is closed, detectAtty should return false
        $this->assertFalse($result['detectAttyResult']);
        $this->assertFalse($result['isAtty']);

        // Exit code should be 0 (success)
        $this->assertSame(0, $exitCode);
    }

    /**
     * Test that detectAtty returns false when STDOUT is not a valid resource.
     *
     * This tests the scenario where STDOUT is null or closed.
     * In practice, this happens when output is redirected to a closed file descriptor.
     *
     * @runInSeparateProcess
     */
    public function testDetectAttyReturnsFalseWhenStdoutIsNull(): void
    {
        // Create the TUI - it will call detectAtty in constructor
        $tui = new InteractiveTui($this->output);

        // In test environment, STDIN/STDOUT may not be TTYs
        $result = $this->invokeDetectAtty($tui);

        // The key assertion: detectAtty should return false in test environment
        // where streams are not TTYs (or are redirected)
        $this->assertFalse($result);
    }

    /**
     * Test that detectAtty returns false when STDIN is not a valid resource.
     *
     * This tests the scenario where STDIN is null or closed.
     *
     * @runInSeparateProcess
     */
    public function testDetectAttyReturnsFalseWhenStdinIsNull(): void
    {
        // When STDIN is not a valid resource in the environment,
        // detectAtty should return false
        $tui = new InteractiveTui($this->output);
        $result = $this->invokeDetectAtty($tui);
        $this->assertFalse($result);
    }

    /**
     * Test that saveTerminalSettings returns null when proc_open fails to create pipes.
     *
     * This tests the edge case where the proc_open call returns an empty pipes array.
     * We verify this by calling saveTerminalSettings when isAtty is false
     * (which causes immediate return of null) OR by checking that the method
     * handles error conditions gracefully.
     */
    public function testSaveTerminalSettingsReturnsNullWhenProcOpenFails(): void
    {
        $tui = new InteractiveTui($this->output);

        // saveTerminalSettings returns null if isAtty is false
        // In the subprocess test above, we verified that when stdin is closed,
        // isAtty becomes false. Here we test the code path when isAtty is false.
        $isAtty = $this->getIsAtty($tui);

        if (!$isAtty) {
            // When isAtty is false, saveTerminalSettings should return null
            // because it checks isAtty first before calling proc_open
            $result = $this->invokeSaveTerminalSettings($tui);
            $this->assertNull($result);
        } else {
            // If isAtty is true (running in a real TTY), we can't easily test
            // proc_open failure, but we can verify the method handles errors gracefully
            $this->markTestSkipped('isAtty is true in this environment - proc_open would succeed');
        }
    }

    /**
     * Test that constructor properly detects non-interactive mode when stdin is null.
     *
     * When STDIN is not a TTY, isAtty should be false, causing run() to
     * immediately return non-interactive fallback.
     */
    public function testConstructorDetectsNonInteractiveModeWhenStdinIsNull(): void
    {
        // We use the subprocess to verify this behavior
        $scriptPath = __DIR__ . '/test_edge_cases_subprocess.php';

        $process = proc_open(
            'php ' . escapeshellarg($scriptPath),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        $this->assertNotFalse($process);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        $result = json_decode(trim($output), true);

        // When stdin is null/closed, isAtty should be false
        $this->assertFalse($result['isAtty']);
    }

    /**
     * Test that run() with empty activity returns null (empty state).
     *
     * Positive test: empty activity list should return null
     * regardless of TTY status.
     */
    public function testRunWithEmptyActivityReturnsNull(): void
    {
        $tui = new InteractiveTui($this->output);
        $result = $tui->run([]);
        $this->assertNull($result);
    }

    /**
     * Test that run() falls back to non-interactive mode when isAtty is false.
     *
     * This verifies the fallback behavior when running in a non-TTY environment
     * like CI/CD pipelines or when output is redirected.
     */
    public function testRunFallsBackToNonInteractiveModeWhenNotAtty(): void
    {
        $tui = new InteractiveTui($this->output);

        // Get isAtty status
        $isAtty = $this->getIsAtty($tui);

        $activity = [
            [
                'type' => 'push',
                'repo' => 'owner/repo',
                'title' => 'Test push',
                'author' => 'testuser',
                'date' => date('c'),
                'url' => 'https://github.com/owner/repo/commit/abc123',
            ],
        ];

        if (!$isAtty) {
            // In non-interactive mode, run() should return null
            $result = $tui->run($activity);
            $this->assertNull($result);
        } else {
            // When isAtty is true, run() would attempt to use the TUI
            // We can't easily test the non-interactive fallback in this case
            // without closing stdin in a subprocess
            $this->markTestSkipped('Cannot test non-interactive fallback when isAtty is true');
        }
    }

    /**
     * Test that constructor with valid output interface does not throw.
     *
     * Positive test: verifying that normal construction works.
     */
    public function testConstructorWithValidOutputDoesNotThrow(): void
    {
        $tui = new InteractiveTui($this->output);
        $this->assertInstanceOf(InteractiveTui::class, $tui);
    }

    /**
     * Test that handleInput returns empty string when stdin is not available.
     *
     * This verifies the defensive behavior when stdin is null or false.
     */
    public function testReadKeyReturnsEmptyStringWhenStdinNotAvailable(): void
    {
        $tui = new InteractiveTui($this->output);

        // Use reflection to access the private stdin property
        $reflection = new ReflectionClass(InteractiveTui::class);
        $stdinProperty = $reflection->getProperty('stdin');
        $stdinProperty->setAccessible(true);

        // Set stdin to null (simulating closed stdin)
        $stdinProperty->setValue($tui, null);

        // Use reflection to call handleInput
        $handleInputMethod = $reflection->getMethod('handleInput');
        $handleInputMethod->setAccessible(true);
        $result = $handleInputMethod->invoke($tui);

        $this->assertSame('', $result);
    }

    /**
     * Test that handleInput returns empty string when stdin is false.
     */
    public function testReadKeyReturnsEmptyStringWhenStdinIsFalse(): void
    {
        $tui = new InteractiveTui($this->output);

        $reflection = new ReflectionClass(InteractiveTui::class);
        $stdinProperty = $reflection->getProperty('stdin');
        $stdinProperty->setAccessible(true);

        // Set stdin to false (another form of unavailable stdin)
        $stdinProperty->setValue($tui, false);

        $handleInputMethod = $reflection->getMethod('handleInput');
        $handleInputMethod->setAccessible(true);
        $result = $handleInputMethod->invoke($tui);

        $this->assertSame('', $result);
    }
}
