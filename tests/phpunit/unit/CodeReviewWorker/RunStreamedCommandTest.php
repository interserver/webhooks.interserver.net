<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for runStreamedCommand() in scripts/github-code-review.php:
 * output accumulation, stderr capture, exit codes, timeout kill behavior,
 * and interrupt handling via the $running global.
 */
class RunStreamedCommandTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../../scripts/github-code-review.php';
        // Reset worker globals the function depends on
        $GLOBALS['running'] = true;
        $GLOBALS['verbose'] = 0;
        $GLOBALS['logFile'] = null;
        $GLOBALS['activeChildPid'] = 0;
    }

    protected function tearDown(): void
    {
        $GLOBALS['running'] = true;
    }

    public function testCapturesStdout(): void
    {
        $result = runStreamedCommand('echo hello', null, 10, false);

        $this->assertSame(0, $result['exitCode']);
        $this->assertFalse($result['timedOut']);
        $this->assertFalse($result['interrupted']);
        $this->assertStringContainsString('hello', $result['output']);
    }

    public function testCapturesStderrSeparately(): void
    {
        $result = runStreamedCommand('sh -c "echo out; echo err >&2"', null, 10, false);

        $this->assertStringContainsString('out', $result['output']);
        $this->assertStringNotContainsString('err', $result['output']);
        $this->assertStringContainsString('err', $result['stderr']);
    }

    public function testPropagatesExitCode(): void
    {
        $result = runStreamedCommand('sh -c "exit 3"', null, 10, false);

        $this->assertSame(3, $result['exitCode']);
    }

    public function testRunsInGivenWorkingDirectory(): void
    {
        $result = runStreamedCommand('pwd', sys_get_temp_dir(), 10, false);

        $this->assertSame(realpath(sys_get_temp_dir()), trim($result['output']));
    }

    public function testTimeoutKillsLongRunningProcess(): void
    {
        $start = microtime(true);
        $result = runStreamedCommand('sh -c "sleep 30; echo done"', null, 1, false);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result['timedOut']);
        $this->assertStringNotContainsString('done', $result['output']);
        // 1s deadline + up to 1s select granularity + kill overhead; must be
        // nowhere near the 30s sleep
        $this->assertLessThan(8.0, $elapsed, 'timed-out child was not killed promptly');
    }

    public function testTimeoutKillsWholeProcessGroup(): void
    {
        // Parent spawns a background grandchild and prints its pid; killing
        // only the direct child would leave the grandchild running
        $cmd = "sh -c 'sleep 300 & echo BGPID:\$!; sleep 300'";

        $result = runStreamedCommand($cmd, null, 1, false);
        $this->assertTrue($result['timedOut']);

        $this->assertMatchesRegularExpression('/BGPID:(\d+)/', $result['output']);
        preg_match('/BGPID:(\d+)/', $result['output'], $m);
        $bgPid = (int)$m[1];

        usleep(300000);
        // Signal 0 probes existence without sending anything
        $alive = function_exists('posix_kill') ? @posix_kill($bgPid, 0) : false;
        $this->assertFalse($alive, "background grandchild pid {$bgPid} survived the timeout kill");
    }

    public function testInterruptedFlagWhenRunningIsFalse(): void
    {
        // Simulate a SIGINT that already happened before/during the run
        $GLOBALS['running'] = false;

        $start = microtime(true);
        $result = runStreamedCommand('sh -c "sleep 30; echo done"', null, 30, false);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result['interrupted']);
        $this->assertStringNotContainsString('done', $result['output']);
        $this->assertLessThan(8.0, $elapsed, 'interrupted child was not killed promptly');
    }

    public function testHandlesFailedCommand(): void
    {
        $result = runStreamedCommand('this-command-does-not-exist-xyz 2>/dev/null', null, 5, false);

        $this->assertNotSame(0, $result['exitCode']);
        $this->assertFalse($result['timedOut']);
    }

    public function testClearsActiveChildPidAfterRun(): void
    {
        runStreamedCommand('echo x', null, 5, false);

        $this->assertSame(0, $GLOBALS['activeChildPid']);
    }
}
