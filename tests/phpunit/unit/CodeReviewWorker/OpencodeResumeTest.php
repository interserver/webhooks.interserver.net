<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\CodeReviewWorker;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the leaked-tool-call RESUME path in scripts/github-code-review.php:
 * extractOpencodeSessionId(), getResumeNudge(), buildOpencodeResumeCommand(),
 * and the per-attempt command selection in selectOpencodeAttemptCommand().
 *
 * All network-free — no opencode process is ever invoked; only the pure command
 * builders and scrapers are exercised.
 */
class OpencodeResumeTest extends TestCase
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
        putenv('OPENCODE_RESUME_NUDGE');
    }

    // ==========================================
    // extractOpencodeSessionId
    // ==========================================

    public function testExtractsSessionIdFromNdjsonJsonKey(): void
    {
        $ndjson = implode("\n", [
            '{"type":"start","sessionID":"ses_abc123DEF","model":"minimax-m2"}',
            '{"type":"text","part":{"text":"reviewing..."}}',
        ]);
        $this->assertSame('ses_abc123DEF', extractOpencodeSessionId($ndjson));
    }

    public function testExtractsSessionIdFromSnakeCaseKey(): void
    {
        $this->assertSame('ses_snake99', extractOpencodeSessionId('{"session_id":"ses_snake99"}'));
    }

    public function testFallsBackToBareSessionToken(): void
    {
        // No JSON key, but a bare ses_ token appears somewhere in the stream
        $this->assertSame('ses_bareToken12', extractOpencodeSessionId("session started: ses_bareToken12 ok\n"));
    }

    public function testReturnsEmptyWhenNoSessionIdPresent(): void
    {
        $this->assertSame('', extractOpencodeSessionId('{"type":"text","part":{"text":"no id here"}}'));
        $this->assertSame('', extractOpencodeSessionId(''));
    }

    // ==========================================
    // getResumeNudge
    // ==========================================

    public function testResumeNudgeMentionsNoToolsAndRequiredFormat(): void
    {
        $nudge = getResumeNudge();
        $this->assertNotSame('', trim($nudge));
        // Instructs the model not to call any more tools
        $this->assertMatchesRegularExpression('/do not call any tools/i', $nudge);
        $this->assertStringContainsString('todowrite', $nudge);
        // Names the exact required report format + the trailing tally line
        $this->assertStringContainsString('#### <emoji> <title>', $nudge);
        $this->assertStringContainsString('Fixed N of M issues.', $nudge);
    }

    public function testResumeNudgeHonorsEnvOverride(): void
    {
        putenv('OPENCODE_RESUME_NUDGE=just finish the report please');
        $this->assertSame('just finish the report please', getResumeNudge());
    }

    // ==========================================
    // buildOpencodeResumeCommand
    // ==========================================

    public function testResumeCommandUsesSpecificSessionWhenIdKnown(): void
    {
        $jobId = 'unit-resume-' . getmypid();
        $res = buildOpencodeResumeCommand($jobId, 'ses_xyz789');
        $this->tmpFiles[] = $res['promptFile'];

        $this->assertSame("/tmp/opencode-resume-{$jobId}.txt", $res['promptFile']);
        $this->assertFileExists($res['promptFile']);
        // -s <escaped id>, cats the nudge file, keeps --format json
        $this->assertStringContainsString('opencode run -s ' . escapeshellarg('ses_xyz789'), $res['cmd']);
        $this->assertStringContainsString('"$(cat ' . escapeshellarg($res['promptFile']) . ')"', $res['cmd']);
        $this->assertStringContainsString('--format json', $res['cmd']);
        // The nudge file holds exactly the resume nudge
        $this->assertSame(getResumeNudge(), file_get_contents($res['promptFile']));
    }

    public function testResumeCommandUsesContinueFlagWhenNoId(): void
    {
        $jobId = 'unit-resume-c-' . getmypid();
        $res = buildOpencodeResumeCommand($jobId, '');
        $this->tmpFiles[] = $res['promptFile'];

        $this->assertStringContainsString('opencode run -c "$(cat ', $res['cmd']);
        $this->assertStringNotContainsString('-s ', $res['cmd']);
        $this->assertStringContainsString('--format json', $res['cmd']);
    }

    // ==========================================
    // selectOpencodeAttemptCommand — per-attempt routing
    // ==========================================

    public function testAttemptOneUsesFullPromptCommand(): void
    {
        $jobId = 'unit-sel1-' . getmypid();
        $sel = selectOpencodeAttemptCommand($jobId, 1, '');
        $this->tmpFiles[] = $sel['promptFile'];

        $this->assertSame('fresh', $sel['mode']);
        $this->assertSame("/tmp/opencode-prompt-{$jobId}.txt", $sel['promptFile']);
        $this->assertStringContainsString('opencode run "$(cat ', $sel['cmd']);
        $this->assertStringNotContainsString('-s ', $sel['cmd']);
        $this->assertStringNotContainsString('opencode run -c', $sel['cmd']);
    }

    public function testLaterAttemptUsesResumeCommand(): void
    {
        $jobId = 'unit-sel2-' . getmypid();
        $sel = selectOpencodeAttemptCommand($jobId, 2, 'ses_keep42');
        $this->tmpFiles[] = $sel['promptFile'];

        $this->assertSame('resume', $sel['mode']);
        $this->assertSame("/tmp/opencode-resume-{$jobId}.txt", $sel['promptFile']);
        $this->assertStringContainsString('opencode run -s ' . escapeshellarg('ses_keep42'), $sel['cmd']);
        $this->assertStringContainsString('--format json', $sel['cmd']);
    }

    public function testForceFreshOverridesResumeOnLaterAttempt(): void
    {
        // When a prior resume returned nothing, the loop forces a fresh run even
        // on attempt > 1 to avoid an empty-resume loop.
        $jobId = 'unit-sel3-' . getmypid();
        $sel = selectOpencodeAttemptCommand($jobId, 3, 'ses_ignored', true);
        $this->tmpFiles[] = $sel['promptFile'];

        $this->assertSame('fresh', $sel['mode']);
        $this->assertStringContainsString('opencode run "$(cat ', $sel['cmd']);
        $this->assertStringNotContainsString('-s ', $sel['cmd']);
    }
}
