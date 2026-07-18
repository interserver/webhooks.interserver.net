<?php
declare(strict_types=1);

/**
 * GitHub Code Review Worker
 *
 * Processes jobs from the codereview:queue Redis list. Each envelope has a
 * "kind" (default "pr"); three review shapes are supported, and all of them end
 * with `_base` and HEAD set so that `git diff _base HEAD` is exactly the change
 * under review and a later plain `git diff` is exactly the agent's in-place fixes:
 *
 *   - kind=pr (no options.commit): clone the PR base branch and pin the review to
 *     the PR head commit — _base = GitHub's 3-dot merge-base, HEAD = head SHA — so
 *     a later synchronize push or a moving base branch cannot shift the tree under
 *     review. Falls back to applying the live PR diff as a snapshot (HEAD =
 *     base+PR, _base = base commit) only when the head SHA cannot be pinned.
 *   - kind=pr WITH options.commit=SHA: review just that one commit
 *     (_base = SHA^, HEAD = SHA); results still post to the PR.
 *   - kind=push: review a before..after commit range with no PR; the commits
 *     are already real, so _base = before_sha (or after_sha^ for a new branch)
 *     and HEAD = after_sha — no diff apply, no snapshot commit.
 *
 * For each job:
 *   1. Set up the baseline for its kind (above).
 *   2. Run opencode once to review AND fix the changes in place; the agent's
 *      edits stay uncommitted in the working tree.
 *   3. Capture the agent's fixes with `git diff` (working tree vs HEAD).
 *   4. For each issue found, post a comment (PR comment for pr jobs, commit
 *      comment for push jobs) — with the per-file fix diff when the agent edited
 *      that file, otherwise flag-only.
 *
 * Usage:
 *   php scripts/github-code-review.php [-v|--verbose]... [-a|--show-agent]
 *
 * Verbose levels:
 *   -v    [INFO]   Essential progress (job start/complete/errors)
 *   -vv   [DEBUG]  Detailed progress (checkpoints, function entry)
 *   -vvv  [TRACE]  Full trace (loop iterations, issue details)
 *   -vvvv [RAW]    Everything, plus live opencode streaming (implies -a)
 *
 * -a / --show-agent streams opencode output (--format default plain text and
 * --format json NDJSON events) to the console in real-time at any verbosity.
 *
 * Ctrl-C once: graceful shutdown — the in-flight opencode process group is
 * terminated, the job is requeued, and the worker exits.
 * Ctrl-C twice: force quit immediately.
 *
 * Run as a systemd service or cron loop:
 *   while true; do php scripts/github-code-review.php; sleep 1; done
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/CodeReviewQueue.php';

// Load .env if present
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// === Runtime bootstrap ===
// When this file is include()d (e.g. from PHPUnit), only define functions and
// globals — do not parse argv, contact gh/Redis, or start the worker.
// Assign defaults via $GLOBALS so an include from inside a function (PHPUnit
// setUp) still initializes the real globals used by verbose_log() and friends.
$__directRun = (get_included_files()[0] ?? '') === __FILE__;

$GLOBALS['verbose'] = 0;
$GLOBALS['running'] = true;
$GLOBALS['logFile'] = null;
$GLOBALS['waitIfEmpty'] = false;
$GLOBALS['reviewBots'] = false;
$GLOBALS['showAgent'] = false;
$GLOBALS['showAllIssues'] = false;
$GLOBALS['onlyPrep'] = false;          // --only-prep: prep checkout, print opencode cmd, exit without running
$GLOBALS['opencodeTimeout'] = 30 * 60; // default: 30 minutes in seconds
$GLOBALS['activeChildPid'] = 0;        // pid (process-group leader) of in-flight opencode child
$GLOBALS['signalCount'] = 0;           // SIGINT/SIGTERM presses so far
$GLOBALS['interruptReadFd'] = null;    // socket pair used to wake stream_select() from the signal handler
$GLOBALS['interruptWriteFd'] = null;

if ($__directRun) {
    // At file scope these are the same variables as the $GLOBALS defaults above
    $verbose = $GLOBALS['verbose'];
    $optind = 0;
    // NOTE: no ':' after 'b'/'a' — they are flags, not options with values.
    $args = getopt('vhwba', ['verbose', 'help', 'wait-if-empty', 'review-bots', 'show-agent', 'show-all-issues', 'only-prep', 'timeout:', 'log::'], $optind);
    if (isset($args['h']) || isset($args['help'])) {
        fwrite(STDOUT, "Usage: php scripts/github-code-review.php [-v|--verbose]... [-w|--wait-if-empty] [-b|--review-bots] [-a|--show-agent] [--show-all-issues] [--only-prep] [--timeout=30m] [--log=FILE]\n");
        fwrite(STDOUT, "  -v, --verbose      Increase verbosity (can stack: -vv, -vvv, -vvvv)\n");
        fwrite(STDOUT, "    -v   [INFO]     Essential progress (job start/complete/errors)\n");
        fwrite(STDOUT, "    -vv  [DEBUG]    Detailed progress (checkpoints, function entry)\n");
        fwrite(STDOUT, "    -vvv [TRACE]    Full trace (loop iterations, gh commands)\n");
        fwrite(STDOUT, "    -vvvv [RAW]     Everything: raw gh output, API responses, live opencode stream (implies -a)\n");
        fwrite(STDOUT, "  -w, --wait-if-empty  Wait for jobs when queue is empty (default: exit)\n");
        fwrite(STDOUT, "  -b, --review-bots    Also review PRs from bots like dependabot (default: skip)\n");
        fwrite(STDOUT, "  -a, --show-agent      Stream opencode output in real-time at any verbosity\n");
        fwrite(STDOUT, "  --show-all-issues     Include issues without file/line in report (default: skip summary sections)\n");
        fwrite(STDOUT, "  --only-prep           Dequeue ONE job, check out the base branch, apply the PR diff,\n");
        fwrite(STDOUT, "                        print the opencode command to run by hand, then exit WITHOUT\n");
        fwrite(STDOUT, "                        running opencode. The checkout is left in place for manual\n");
        fwrite(STDOUT, "                        testing; a cleanup command is printed. (Implies -w.)\n");
        fwrite(STDOUT, "  --timeout=N           Timeout for opencode analysis (default: 30m). Examples: 30, 30s, 30m\n");
        fwrite(STDOUT, "  --log=FILE            Append all log output to FILE (in addition to stderr)\n");
        fwrite(STDOUT, "  -h, --help        Show this help message\n");
        fwrite(STDOUT, "\n  Press Ctrl-C once for graceful shutdown (stops opencode, finishes cleanup);\n");
        fwrite(STDOUT, "  press Ctrl-C a second time to force-quit immediately.\n");
        exit(0);
    }
    if (isset($args['v'])) {
        $verbose = min(4, $verbose + count((array)$args['v']));
    }
    if (isset($args['verbose'])) {
        $verbose = min(4, $verbose + count((array)$args['verbose']));
    }
    $waitIfEmpty = isset($args['w']) || isset($args['wait-if-empty']);
    $reviewBots = isset($args['b']) || isset($args['review-bots']);
    $showAgent = isset($args['a']) || isset($args['show-agent']);
    $showAllIssues = isset($args['show-all-issues']);
    $onlyPrep = isset($args['only-prep']);
    if ($onlyPrep) {
        // Prep mode reviews exactly one queued job; wait for one if the queue
        // is momentarily empty rather than exiting immediately.
        $waitIfEmpty = true;
    }

    // Parse timeout: bare number = seconds, number + s = seconds, number + m = minutes
    if (isset($args['timeout'])) {
        $raw = is_array($args['timeout']) ? $args['timeout'][0] : $args['timeout'];
        if ($raw !== false && $raw !== '') {
            $raw = trim($raw);
            if (preg_match('/^(\d+)([smh])?$/i', $raw, $m)) {
                $val = (int)$m[1];
                $unit = strtolower($m[2] ?? '');
                if ($unit === 'm') {
                    $opencodeTimeout = $val * 60;
                } elseif ($unit === 'h') {
                    $opencodeTimeout = $val * 3600;
                } else {
                    $opencodeTimeout = $val; // bare number = seconds
                }
            } else {
                fwrite(STDERR, "github-code-review: invalid --timeout value '{$raw}', using default 30m\n");
            }
        }
    }
    if (isset($args['log'])) {
        $logFile = $args['log'] !== false ? $args['log'] : 'github-code-review.log';
    }
}

/**
 * Emit verbose log message at the specified verbosity level.
 *
 * @param string $message Log message
 * @param int $level Verbosity level (1=INFO, 2=DEBUG, 3=TRACE, 4=RAW)
 */
function verbose_log(string $message, int $level = 1): void
{
    global $verbose, $logFile;
    if ($verbose >= $level) {
        $prefix = match ($level) {
            1 => '[INFO]',
            2 => '[DEBUG]',
            3 => '[TRACE]',
            4 => '[RAW]',
            default => '[VERBOSE]'
        };
        $line = "github-code-review: {$prefix} {$message}";
        error_log($line);
        if ($logFile !== null) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
            file_put_contents($logFile, "[{$timestamp}] {$line}\n", FILE_APPEND);
        }
    }
}

/**
 * Log raw command output (only at -vvvv level)
 */
function verbose_raw(string $label, string $output): void
{
    global $verbose, $logFile;
    if ($verbose >= 4) {
        $prefix = '[RAW]';
        $line = "github-code-review: {$prefix} {$label}";
        error_log($line);
        error_log("--- BEGIN {$label} ---");
        error_log($output);
        error_log("--- END {$label} ---");
        if ($logFile !== null) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
            file_put_contents($logFile, "[{$timestamp}] {$line}\n[{$timestamp}] --- BEGIN {$label} ---\n{$output}\n[{$timestamp}] --- END {$label} ---\n", FILE_APPEND);
        }
    }
}

/**
 * Render and log markdown with ANSI colors using candy-shine (only at -vvvv level)
 */
function verbose_markdown(string $label, string $markdown): void
{
    global $verbose, $logFile;
    if ($verbose >= 4) {
        $prefix = '[MD]';
        $line = "github-code-review: {$prefix} {$label}";
        error_log($line);
        try {
            $colored = \SugarCraft\Shine\Renderer::renderMarkdown($markdown);
            error_log($colored);
        } catch (\Throwable $e) {
            // Fallback to plain text if rendering fails
            error_log($markdown);
        }
        if ($logFile !== null) {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
            file_put_contents($logFile, "[{$timestamp}] {$line}\n[{$timestamp}] {$markdown}\n", FILE_APPEND);
        }
    }
}

/**
 * Parse an opencode NDJSON event line and return a display string or null
 *
 * Event types:
 *   - text      → display part.text (truncate at 5000 chars)
 *   - tool_use  → display 🔧 [tool] filePath or prompt snippet
 *   - step_start → display step-start: {id}
 *   - step_finish → display ✓ step {tokens.input+output}
 *   - stop      → display ■ analysis complete
 *   - subagent  → display sub-agent activity emitted by the
 *                 subagent-reporter.ts opencode plugin in --format json mode
 *                 (event: tool|text|reasoning|finished; agent, n, ...)
 *
 * @param string $line Raw NDJSON line
 * @param int $verbose Current verbosity level
 * @return string|null Formatted display string, or null to skip
 */
function parseOpencodeEventLine(string $line, int $verbose = 4): ?string
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $event = json_decode($line, true);
    if (!is_array($event)) {
        return null;
    }

    $type = $event['type'] ?? '';

    switch ($type) {
        case 'text':
            $part = $event['part'] ?? [];
            $text = is_array($part) ? ($part['text'] ?? '') : (is_string($part) ? $part : '');
            if ($text === '') {
                return null;
            }
            // Truncate at 5000 chars
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '... [truncated]';
            }
            // Colorize markdown at verbosity 4
            if ($verbose >= 4) {
                try {
                    return \SugarCraft\Shine\Renderer::renderMarkdown($text);
                } catch (\Throwable $e) {
                    return $text;
                }
            }
            return $text;

        case 'tool_use':
            $part = $event['part'] ?? [];
            $tool = is_array($part) ? ($part['tool'] ?? 'unknown') : 'unknown';
            $state = $event['part']['state'] ?? [];
            $input = is_array($state) ? ($state['input'] ?? []) : [];

            if (isset($input['filePath'])) {
                $path = $input['filePath'];
                // Shorten long paths
                if (strlen($path) > 60) {
                    $path = '...' . substr($path, -57);
                }
                return "🔧 [{$tool}] {$path}";
            }
            if (isset($input['prompt'])) {
                $prompt = $input['prompt'];
                // Truncate prompt display
                if (strlen($prompt) > 60) {
                    $prompt = substr($prompt, 0, 60) . '...';
                }
                return "🔧 [{$tool}] {$prompt}";
            }
            // Just show tool name if no input context
            if (isset($input['query'])) {
                $query = $input['query'];
                if (strlen($query) > 60) {
                    $query = substr($query, 0, 60) . '...';
                }
                return "🔧 [{$tool}] {$query}";
            }
            return "🔧 [{$tool}]";

        case 'step_start':
            $stepId = $event['part']['stepId'] ?? $event['part']['id'] ?? 'unknown';
            return "▶ step-start: {$stepId}";

        case 'step_finish':
            $part = $event['part'] ?? [];
            $tokens = $part['tokens'] ?? [];
            $inputTokens = is_array($tokens) ? ($tokens['input'] ?? 0) : 0;
            $outputTokens = is_array($tokens) ? ($tokens['output'] ?? 0) : 0;
            return "✓ step {$inputTokens}+{$outputTokens} tokens";

        case 'stop':
            return '■ analysis complete';

        case 'subagent':
            // Emitted by the subagent-reporter.ts plugin in --format json mode.
            // These carry sub-agent progress that opencode itself does not put
            // in its NDJSON stream; render them to mirror the plain-text output.
            $agent = is_string($event['agent'] ?? null) && $event['agent'] !== '' ? $event['agent'] : 'Subagent';
            $n = $event['n'] ?? 1;
            $sub = $event['event'] ?? '';
            $prefix = "{$agent} {$n}: ";
            if ($sub === 'finished') {
                return '**** [' . strtoupper($agent) . ' FINISHED] ****';
            }
            if ($sub === 'tool') {
                $tool = is_string($event['tool'] ?? null) && $event['tool'] !== '' ? $event['tool'] : 'tool';
                $detail = is_string($event['detail'] ?? null) ? trim($event['detail']) : '';
                return $prefix . '-> ' . $tool . ($detail !== '' ? ' ' . $detail : '');
            }
            $text = is_string($event['text'] ?? null) ? trim($event['text']) : '';
            if ($text === '') {
                return null;
            }
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '... [truncated]';
            }
            return $prefix . ($sub === 'reasoning' ? 'Thinking: ' : '') . $text;

        default:
            // For unknown types, show nothing (no spam)
            return null;
    }
}

/**
 * Format one raw output line for real-time display.
 *
 * With --format json, opencode emits NDJSON events — those are pretty-printed
 * via parseOpencodeEventLine(). With --format default (used with the
 * subagent-reporter plugin), lines are plain text — passed through verbatim.
 *
 * @param string $line Raw line (may include trailing newline)
 * @param int $verbose Current verbosity level
 * @return string|null Text to display (without trailing newline), or null to skip
 */
function formatStreamLine(string $line, int $verbose = 0): ?string
{
    $trimmed = trim($line);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && isset($decoded['type'])) {
            // NDJSON event line (--format json): pretty-print or skip
            return parseOpencodeEventLine($trimmed, $verbose);
        }
    }
    // Plain text line (--format default): passthrough as-is, preserving blank
    // lines so markdown spacing survives
    return rtrim($line, "\r\n");
}

/**
 * Create the global interrupt socket pair used to wake stream_select() from
 * the signal handler. Safe to call multiple times.
 */
function createInterruptPipe(): void
{
    if (is_resource($GLOBALS['interruptReadFd'] ?? null)) {
        return;
    }
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        verbose_log('createInterruptPipe: stream_socket_pair failed — Ctrl-C wakeups will rely on select timeouts', 1);
        return;
    }
    stream_set_blocking($pair[0], false);
    stream_set_blocking($pair[1], false);
    $GLOBALS['interruptReadFd'] = $pair[0];
    $GLOBALS['interruptWriteFd'] = $pair[1];
}

/**
 * Drain any pending bytes from the interrupt pipe (stale signal wakeups).
 */
function drainInterruptPipe(): void
{
    $fd = $GLOBALS['interruptReadFd'] ?? null;
    if (is_resource($fd)) {
        while (true) {
            $bytes = fread($fd, 64);
            if ($bytes === '' || $bytes === false) {
                break;
            }
        }
    }
}

/**
 * Send a signal to a child and its entire process group/tree.
 *
 * Children are launched via `setsid --wait`, which either execs the command
 * directly (child pid == new group id) or forks first (the new group leader
 * is a *child* of $pid). Handle both by collecting the process-group ids of
 * $pid and each of its children, then signalling every group.
 */
function terminateChildGroup(int $pid, int $signal = SIGTERM): void
{
    if ($pid <= 0) {
        return;
    }

    $pgids = [$pid => true];
    $kids = trim((string)shell_exec('pgrep -P ' . $pid . ' 2>/dev/null'));
    if ($kids !== '') {
        foreach (preg_split('/\s+/', $kids) ?: [] as $kid) {
            $kid = (int)$kid;
            if ($kid > 0) {
                $kpgid = (int)trim((string)shell_exec('ps -o pgid= -p ' . $kid . ' 2>/dev/null'));
                $pgids[$kpgid > 0 ? $kpgid : $kid] = true;
            }
        }
    }

    foreach (array_keys($pgids) as $pgid) {
        if (function_exists('posix_kill')) {
            @posix_kill(-$pgid, $signal); // whole process group
            @posix_kill($pgid, $signal);  // and the pid directly, in case it changed group
        } else {
            exec('kill -' . $signal . ' -- -' . $pgid . ' ' . $pgid . ' 2>/dev/null');
        }
    }
}

/**
 * Run a shell command via proc_open with real-time streaming, timeout
 * enforcement, and reliable Ctrl-C termination.
 *
 * - stdout and stderr are both drained continuously (a full pipe buffer can
 *   otherwise deadlock the child).
 * - Each complete stdout line is displayed via formatStreamLine() when
 *   $stream is true.
 * - The child is launched through setsid so the whole process tree can be
 *   killed as one process group; the pid is registered in
 *   $GLOBALS['activeChildPid'] so the SIGINT handler can terminate it.
 * - proc_close() is only called after the child is confirmed dead, so it can
 *   never block a shutdown.
 *
 * @param string $cmd Shell command
 * @param string|null $cwd Working directory for the child
 * @param int $timeout Seconds before the child is killed (0 = 24h safety cap)
 * @param bool $stream Display output lines on STDERR in real-time
 * @return array{output: string, stderr: string, exitCode: int, timedOut: bool, interrupted: bool}
 */
function runStreamedCommand(string $cmd, ?string $cwd, int $timeout, bool $stream): array
{
    global $running, $verbose, $logFile;

    $result = ['output' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false, 'interrupted' => false];

    // setsid puts the child in its own session/process group so
    // terminateChildGroup() can kill opencode and all of its subagents at
    // once; --wait keeps the direct child alive until the command exits so
    // proc_get_status() tracks it and the real exit code propagates
    static $setsid = null;
    if ($setsid === null) {
        $setsid = trim((string)shell_exec('command -v setsid 2>/dev/null'));
    }
    $fullCmd = ($setsid !== '' ? $setsid . ' --wait ' : '') . $cmd;

    $desc = [
        0 => ['file', '/dev/null', 'r'], // no stdin — opencode must not wait for input
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($fullCmd, $desc, $pipes, $cwd);
    if ($proc === false) {
        verbose_log('runStreamedCommand: proc_open failed', 1);
        return $result;
    }

    $status = proc_get_status($proc);
    $pid = (int)$status['pid'];
    $GLOBALS['activeChildPid'] = $pid;

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    createInterruptPipe();
    drainInterruptPipe();
    $interruptFd = $GLOBALS['interruptReadFd'];

    $timeoutSecs = $timeout > 0 ? $timeout : 86400; // safety cap when no timeout given
    $deadline = microtime(true) + $timeoutSecs;
    $stdoutBuf = '';

    $displayLine = static function (string $rawLine) use ($stream, $verbose, $logFile): void {
        if (!$stream) {
            return;
        }
        $display = formatStreamLine($rawLine, $verbose);
        if ($display === null) {
            return;
        }
        fwrite(STDERR, $display . "\n");
        fflush(STDERR);
        if ($logFile !== null && trim($display) !== '') {
            $timestamp = date('Y-m-d H:i:s.') . sprintf('%06d', (microtime(true) - floor(microtime(true))) * 1000000);
            file_put_contents($logFile, "[{$timestamp}] [AGENT] {$display}\n", FILE_APPEND);
        }
    };

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        if (!$running) {
            $result['interrupted'] = true;
            verbose_log('runStreamedCommand: shutdown requested, stopping child', 2);
            break;
        }
        if (microtime(true) >= $deadline) {
            $result['timedOut'] = true;
            verbose_log("runStreamedCommand: timeout reached ({$timeoutSecs}s), terminating child", 1);
            break;
        }

        $read = [$pipes[1], $pipes[2]];
        if (is_resource($interruptFd)) {
            $read[] = $interruptFd;
        }
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 1, 0);
        if ($changed === false) {
            // Interrupted by a signal (EINTR) — loop re-checks $running/deadline
            continue;
        }
        if ($changed === 0) {
            continue;
        }

        if (is_resource($interruptFd) && in_array($interruptFd, $read, true)) {
            drainInterruptPipe();
            continue; // loop top re-checks $running
        }

        if (in_array($pipes[1], $read, true)) {
            $chunk = fread($pipes[1], 65536);
            if (is_string($chunk) && $chunk !== '') {
                $result['output'] .= $chunk;
                $stdoutBuf .= $chunk;
                while (($pos = strpos($stdoutBuf, "\n")) !== false) {
                    $displayLine(substr($stdoutBuf, 0, $pos + 1));
                    $stdoutBuf = substr($stdoutBuf, $pos + 1);
                }
            }
        }
        if (in_array($pipes[2], $read, true)) {
            $chunk = fread($pipes[2], 65536);
            if (is_string($chunk) && $chunk !== '') {
                $result['stderr'] .= $chunk;
                if ($stream) {
                    fwrite(STDERR, $chunk);
                    fflush(STDERR);
                }
            }
        }
    }

    // Flush any partial final line
    if ($stdoutBuf !== '') {
        $displayLine($stdoutBuf);
    }

    // Make sure the child (and its whole process group) is dead before
    // proc_close() — otherwise proc_close blocks until opencode finishes,
    // which is exactly the "Ctrl-C doesn't stop it" bug.
    $status = proc_get_status($proc);
    if ($status['running']) {
        terminateChildGroup($pid, SIGTERM);
        $waitUntil = microtime(true) + 5.0;
        while ($status['running'] && microtime(true) < $waitUntil) {
            usleep(100000);
            $status = proc_get_status($proc);
        }
        if ($status['running']) {
            verbose_log('runStreamedCommand: child ignored SIGTERM, sending SIGKILL', 1);
            terminateChildGroup($pid, SIGKILL);
            usleep(200000);
            $status = proc_get_status($proc);
        }
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }

    $closeCode = proc_close($proc);
    // proc_get_status() reports the real exit code once the process has
    // exited; proc_close() returns -1 in that case, so prefer the former.
    $result['exitCode'] = $status['exitcode'] !== -1 ? $status['exitcode'] : $closeCode;

    $GLOBALS['activeChildPid'] = 0;

    return $result;
}

/**
 * Look up a PR ref (head or base branch) via GitHub API with retry logic
 *
 * @param string $repo Owner/repo format
 * @param int $prNumber PR number
 * @param string $refType 'head' or 'base'
 * @return string|null The ref name, or null on failure
 */
function getPRRef(string $repo, int $prNumber, string $refType): ?string
{
    $output = [];
    $ret = 0;
    $attempts = 0;
    $maxAttempts = 3;

    while ($attempts < $maxAttempts) {
        $attempts++;
        $cmd = sprintf(
            'gh api repos/%s/pulls/%d --jq .%s.ref 2>&1',
            escapeshellarg($repo),
            $prNumber,
            $refType
        );
        exec($cmd, $output, $ret);

        // At level 4, log the raw gh api output
        $rawOutput = implode("\n", $output);
        verbose_raw("gh_api_{$refType}", "cmd: {$cmd}\noutput: {$rawOutput}\nret: {$ret}");

        if ($ret === 0 && !empty($output)) {
            $ref = trim(implode("\n", $output));
            if ($ref !== '') {
                return $ref;
            }
        }

        // Check for rate limiting (GitHub API returns 403)
        $outputStr = implode("\n", $output);
        if (strpos($outputStr, 'API rate limit') !== false || strpos($outputStr, '403') !== false) {
            verbose_log("GitHub API rate limited, waiting before retry...", 2);
            sleep(2);
            $output = [];
            continue;
        }

        // On failure, wait a bit before retry (except on last attempt)
        if ($attempts < $maxAttempts) {
            usleep(250000); // 250ms
            $output = [];
        }
    }

    return null;
}

/**
 * Look up the actual head branch name for a PR via GitHub API
 *
 * @param string $repo Owner/repo format
 * @param int $prNumber PR number
 * @return string|null The head branch name, or null on failure
 */
function getPRHeadBranch(string $repo, int $prNumber): ?string
{
    return getPRRef($repo, $prNumber, 'head');
}

/**
 * Look up the actual base branch name for a PR via GitHub API
 *
 * @param string $repo Owner/repo format
 * @param int $prNumber PR number
 * @return string|null The base branch name (where PR is merging into), or null on failure
 */
function getPRBaseBranch(string $repo, int $prNumber): ?string
{
    return getPRRef($repo, $prNumber, 'base');
}

/**
 * Resolve the head commit SHA to review for a PR job. Prefers a non-empty
 * $job['sha'] (the pr.head.sha captured at enqueue time) so no network call is
 * needed in the common case; otherwise it looks the SHA up from the GitHub API.
 * Returns '' when the SHA cannot be resolved (the caller then falls back to the
 * live diff-apply path).
 *
 * @param array<string, mixed> $job
 */
function resolvePrHeadSha(array $job, string $repo, int $prNumber): string
{
    $sha = trim((string)($job['sha'] ?? ''));
    if ($sha !== '') {
        return $sha;
    }

    // No SHA on the job envelope — look it up. Skip the API entirely when we
    // have nothing to query with (keeps callers/tests network-free).
    if ($repo === '' || $prNumber <= 0) {
        return '';
    }

    $cmd = sprintf(
        'gh api %s --jq .head.sha 2>&1',
        escapeshellarg("repos/{$repo}/pulls/{$prNumber}")
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    verbose_raw('gh_api_pr_head_sha', "cmd: {$cmd}\noutput: " . implode("\n", $output) . "\nret: {$ret}");
    if ($ret === 0 && !empty($output)) {
        $resolved = trim(implode("\n", $output));
        // Guard against error text / non-SHA output leaking through 2>&1.
        if (preg_match('/^[0-9a-f]{7,40}$/i', $resolved) === 1) {
            return $resolved;
        }
    }
    return '';
}

/**
 * Get the diff for a PR using gh pr diff
 *
 * @param string $repo Owner/repo format
 * @param int $prNumber PR number
 * @return string|null The diff content, or null on failure
 */
function getPRDiff(string $repo, int $prNumber): ?string
{
    $output = [];
    $ret = 0;
    $cmd = sprintf('gh pr diff %d --repo %s 2>&1', $prNumber, escapeshellarg($repo));
    exec($cmd, $output, $ret);

    if ($ret !== 0 || empty($output)) {
        verbose_log("getPRDiff: failed to fetch diff for {$repo}#{$prNumber}", 2);
        return null;
    }

    $diff = implode("\n", $output);
    // exec() strips the trailing newline from each line, so the last line
    // (line 1188) has no \n. Append one so git apply sees a complete diff.
    if ($diff !== '' && substr($diff, -1) !== "\n") {
        $diff .= "\n";
    }
    return $diff ?: null;
}

/**
 * Apply a PR diff to a git repository using git apply
 *
 * @param string $checkoutPath Path to the git repository
 * @param string $diff The diff content to apply
 * @return bool True on success, false on failure
 */
function applyPRDiff(string $checkoutPath, ?string $diff): bool
{
    if ($diff === null || $diff === '') {
        return false;
    }

    // Write diff to a temporary file (use binary mode to preserve all bytes)
    $diffFile = tempnam(sys_get_temp_dir(), 'pr_diff_');
    if ($diffFile === false) {
        verbose_log("applyPRDiff: failed to create temp file", 1);
        return false;
    }

    try {
        // Write with explicit LF line endings (not CRLF) for cross-platform compat
        $diffNormalized = str_replace(["\r\n", "\r"], "\n", $diff);
        file_put_contents($diffFile, $diffNormalized);

        // Try multiple strategies in order of preference
        // Strategy order: most reliable first, least desperate last
        $strategies = [
            // Strategy 1: plain git apply — most reliable when base files exist (they do, we cloned the base branch)
            [
                'cmd' => 'git apply --whitespace=nowarn %s 2>&1',
                'label' => 'git-apply',
            ],
            // Strategy 2: git apply with --whitespace=fix (auto-fix whitespace issues)
            [
                'cmd' => 'git apply --whitespace=fix %s 2>&1',
                'label' => 'git-apply-whitespace-fix',
            ],
            // Strategy 3: git apply ignoring whitespace (handles context drift)
            [
                'cmd' => 'git apply --whitespace=nowarn --ignore-whitespace %s 2>&1',
                'label' => 'git-apply-ignorews',
            ],
            // Strategy 4: git apply binary (handles binary file mode changes in the diff)
            [
                'cmd' => 'git apply --binary --whitespace=nowarn %s 2>&1',
                'label' => 'git-apply-binary',
            ],
            // Strategy 5: patch as last resort — most lenient, handles binary, long lines, etc.
            [
                'cmd' => 'patch -p1 --no-backup-if-mismatch < %s 2>&1',
                'label' => 'patch',
            ],
        ];

        $lastOutput = '';

        foreach ($strategies as $strategy) {
            $cmd = sprintf(
                'cd %s && ' . $strategy['cmd'],
                escapeshellarg($checkoutPath),
                escapeshellarg($diffFile)
            );

            $output = [];
            $ret = 0;
            exec($cmd, $output, $ret);
            $lastOutput = implode("\n", $output);

            if ($ret === 0) {
                verbose_log("applyPRDiff: diff applied successfully (strategy: {$strategy['label']})", 3);
                return true;
            }

            verbose_log("applyPRDiff: strategy {$strategy['label']} failed: " . substr($lastOutput, 0, 200), 3);
        }

        verbose_log("applyPRDiff: all strategies failed - last output: " . substr($lastOutput, 0, 500), 2);
        return false;
    } finally {
        // Always clean up temp file
        if ($diffFile !== false && file_exists($diffFile)) {
            unlink($diffFile);
        }
    }
}

// === Configuration ===
const GITHUB_TOKEN = 'GITHUB_TOKEN';
const CHECKOUT_ROOT = 'CHECKOUT_ROOT';
const OPENCODE_ANALYZE_CMD = 'OPENCODE_ANALYZE_CMD';
const MAX_RETRIES = 3;
// The well-known empty-tree object SHA — used as a baseline when reviewing a
// root commit that has no parent (brand-new branch's very first commit).
const EMPTY_TREE_SHA = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

// Comment posting uses raw curl with an explicit token (reads go through the
// gh CLI's own auth). Honor GITHUB_TOKEN, then GH_TOKEN, then (in the direct-run
// block below) fall back to whatever `gh` is authenticated with.
$githubToken = getenv(GITHUB_TOKEN) ?: (getenv('GH_TOKEN') ?: '');
$ghToken = getenv('GH_TOKEN') ?: '';
$checkoutRoot = getenv(CHECKOUT_ROOT) ?: '/tmp/pr-checkouts';
$opencodeAnalyzeCmd = getenv(OPENCODE_ANALYZE_CMD) ?: 'sh -c "cd {dir} && opencode run \"Analyze all PHP files in this directory tree. Find issues and return JSON array with fields: file, line, severity (error/warning/info), message for each issue\" --format json" 2>&1';

if ($__directRun) {
    // Check if logged into gh
    $authStatus = (string)shell_exec('gh auth status 2>&1');
    if (strpos($authStatus, 'authenticated') === false && strpos($authStatus, 'Logged in') === false) {
        error_log('github-code-review: Not logged into gh. Run "gh auth login" first.');
        exit(1);
    }

    // No token was exported, but `gh` is authenticated (checked above) — reuse
    // its credential so posting works with `gh auth login` alone instead of
    // 401ing with "Bad credentials".
    if ($githubToken === '') {
        $ghAuthToken = trim((string)shell_exec('gh auth token 2>/dev/null'));
        if ($ghAuthToken !== '') {
            $githubToken = $ghAuthToken;
        }
    }

    // Pass tokens to child processes if set
    if ($githubToken !== '') {
        putenv("GITHUB_TOKEN={$githubToken}");
    }
    if ($ghToken !== '') {
        putenv("GH_TOKEN={$ghToken}");
    }
}

// === Signal Handling ===

/**
 * Install SIGINT/SIGTERM handlers.
 *
 * First Ctrl-C: graceful — stop the in-flight opencode child, let the worker
 * finish cleanup, and exit. Second Ctrl-C: force quit immediately.
 */
function installSignalHandlers(): void
{
    if (!function_exists('pcntl_signal')) {
        return;
    }
    // Enable async signals so handlers fire during blocking exec()/BRPOP calls
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }
    createInterruptPipe();
    $shutdownHandler = function (int $signo): void {
        global $running;
        $running = false;
        $name = $signo === SIGTERM ? 'SIGTERM' : 'SIGINT';
        $count = ++$GLOBALS['signalCount'];
        if ($count === 1) {
            fwrite(STDERR, "\ngithub-code-review: received {$name}, shutting down (press Ctrl-C again to force quit)...\n");
            // Wake up any stream_select() immediately so loops notice $running
            if (is_resource($GLOBALS['interruptWriteFd'] ?? null)) {
                @fwrite($GLOBALS['interruptWriteFd'], 'i');
            }
            // Stop the in-flight opencode child (and its subagent process group)
            terminateChildGroup((int)$GLOBALS['activeChildPid'], SIGTERM);
        } else {
            fwrite(STDERR, "github-code-review: force quit\n");
            terminateChildGroup((int)$GLOBALS['activeChildPid'], SIGKILL);
            exit(130);
        }
    };
    pcntl_signal(SIGINT, $shutdownHandler);
    pcntl_signal(SIGTERM, $shutdownHandler);
}

if ($__directRun) {
    installSignalHandlers();
}

/**
 * Main worker loop
 */
function main(): void
{
    global $githubToken, $checkoutRoot, $opencodeAnalyzeCmd, $verbose, $waitIfEmpty, $reviewBots, $running, $onlyPrep;

    verbose_log("worker started (verbose level {$verbose})", 1);
    verbose_log('waitIfEmpty=' . ($waitIfEmpty ? 'true' : 'false') . ' - ' . ($waitIfEmpty ? 'will wait for jobs' : 'will exit when queue empty'), 1);
    verbose_log('reviewBots=' . ($reviewBots ? 'true' : 'false') . ' - ' . ($reviewBots ? 'will review bot PRs' : 'will skip bot PRs'), 1);
    if ($onlyPrep) {
        verbose_log('onlyPrep=true - will prep ONE job, print the opencode command, and exit without running it', 1);
    }

    $iteration = 0;
    while (true) {
        $iteration++;
        verbose_log("main loop iteration #{$iteration} - fetching job", 3);

        // When waitIfEmpty=false, use non-blocking dequeue (timeout=0).
        // When waitIfEmpty=true, block in short 5s slices so a Ctrl-C during
        // an idle BRPOP is noticed within seconds, not minutes.
        $timeout = $waitIfEmpty ? 5 : 0;
        $job = CodeReviewQueue::dequeue($timeout);

        if ($job === null) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if (!$running) {
                verbose_log("shutdown requested, exiting", 1);
                break;
            }
            if (!$waitIfEmpty) {
                verbose_log("queue empty, exiting", 1);
                break;
            }
            verbose_log("queue empty, waiting for jobs...", 3);
            continue;
        }

        $jobId = $job['id'] ?? 'unknown';
        $repo = $job['repo'] ?? 'unknown';
        $kind = CodeReviewQueue::jobKind($job);

        if ($kind === 'push') {
            $ref = $job['ref'] ?? 'unknown';
            $after = substr((string)($job['after_sha'] ?? ''), 0, 7);
            verbose_log("dequeued push job {$jobId}: repo={$repo} ref={$ref} after={$after}", 2);
            verbose_log("processing push job {$jobId} for {$repo} {$ref}", 1);
        } else {
            $prNumber = $job['pr_number'] ?? 'unknown';
            $branch = $job['head_branch'] ?? 'unknown';

            // If branch is "HEAD", look up the actual branch name from GitHub API
            if ($branch === 'HEAD' || $branch === 'unknown') {
                $actualBranch = getPRHeadBranch($repo, (int)$prNumber);
                if ($actualBranch) {
                    verbose_log("resolved HEAD to actual branch: {$actualBranch}", 2);
                    $branch = $actualBranch;
                    $job['head_branch'] = $branch; // Update for processJob
                } else {
                    verbose_log("could not resolve HEAD branch, using 'main'", 2);
                    $branch = 'main';
                    $job['head_branch'] = $branch;
                }
            }

            verbose_log("dequeued job {$jobId}: repo={$repo} pr={$prNumber} branch={$branch}", 2);
            verbose_log("processing job {$jobId} for {$repo}#{$prNumber}", 1);
        }

        try {
            $result = processJob($job);
            if ($result === 'true') {
                verbose_log("completed job {$jobId}", 1);
            } elseif ($result === 'prep_only') {
                // --only-prep: one job prepared and printed; stop here
                break;
            } elseif (is_string($result) && str_starts_with($result, 'no_issues')) {
                verbose_log("no issues found for {$repo}", 1);
            } elseif ($result === 'shutdown') {
                // Job was interrupted mid-flight — put it back so it isn't lost
                verbose_log("job {$jobId} interrupted by shutdown, requeueing and exiting", 1);
                CodeReviewQueue::requeue($job);
                break;
            } else {
                verbose_log("job {$jobId} failed: {$result}", 1);
                handleFailure($job, $result);
            }
        } catch (\Throwable $e) {
            verbose_log("job {$jobId} exception: {$e->getMessage()}", 1);
            handleFailure($job, $e->getMessage());
        }

        // Check if shutdown was requested (Ctrl-C pressed during job)
        if (!$running) {
            verbose_log("shutdown requested, exiting", 1);
            break;
        }

        // Small delay between jobs to avoid hammering
        verbose_log('sleeping 0.5s before next job', 3);
        usleep(500000); // 0.5s
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        // @phpstan-ignore-next-line
        if (!$running) {
            verbose_log("shutdown requested, exiting", 1);
            break;
        }
    }
}

/**
 * Process a single code review job
 *
 * @param array $job Envelope from CodeReviewQueue
 * @return string True on success, error message on failure, or "no_issues" string
 */
function processJob(array $job): string
{
    global $reviewBots, $running;

    // Check if shutdown was requested before starting work
    if (!$running) {
        verbose_log("shutdown requested, abandoning job", 2);
        return 'shutdown';
    }

    $kind = CodeReviewQueue::jobKind($job);
    $repo = $job['repo'] ?? '';
    $jobId = (string)($job['id'] ?? uniqid('job_'));
    $author = $job['author'] ?? '';
    $auditTypes = is_array($job['audit_types'] ?? null) ? $job['audit_types'] : ['full'];
    $options = is_array($job['options'] ?? null) ? $job['options'] : [];

    if ($repo === '') {
        return 'invalid job: missing repo';
    }
    // Reject repo names that could traverse outside the checkout root
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) || strpos($repo, '..') !== false) {
        return 'invalid job: bad repo name';
    }

    // Bot-skip applies to both PR and push jobs.
    $isBot = ($job['is_bot'] ?? false) || str_ends_with($author, '[bot]');
    if ($isBot && !$reviewBots) {
        verbose_log("skipping bot job from {$author}", 1);
        return 'skipped bot job';
    }

    if ($kind === 'push') {
        return processPushJob($job, $repo, $jobId, $auditTypes, $options);
    }

    return processPrJob($job, $repo, $jobId, $auditTypes, $options);
}

/**
 * Handle a PR review job (kind=pr). When options.commit is set the review is
 * scoped to exactly that one commit (_base=SHA^, HEAD=SHA); otherwise the PR
 * diff is applied and committed as a snapshot (HEAD=base+PR, _base=base). Either
 * way results are posted to the PR.
 *
 * @param array<string, mixed> $job
 * @param array<int, string> $auditTypes
 * @param array<string, mixed> $options
 */
function processPrJob(array $job, string $repo, string $jobId, array $auditTypes, array $options): string
{
    global $checkoutRoot, $running, $onlyPrep;

    $prNumber = (int)($job['pr_number'] ?? 0);
    $baseBranch = $job['base_branch'] ?? '';
    $sha = (string)($job['sha'] ?? '');

    // Commit-scoped PR review: review just one commit but still post to the PR.
    $commitSha = (isset($options['commit']) && is_string($options['commit'])) ? trim($options['commit']) : '';
    $commitScoped = $commitSha !== '';

    if ($prNumber === 0 || $baseBranch === '') {
        return 'invalid job: missing required fields';
    }

    // Step 1: Checkout the base branch (target branch - where PR is merging into).
    // The path is keyed by repo+branch (not PR number) so it matches the 24h
    // Redis checkout cache, which stores one path per repo:branch.
    $checkoutPath = buildCheckoutPath($checkoutRoot, $repo, $baseBranch);
    verbose_log("starting checkout: repo={$repo} base_branch={$baseBranch} path={$checkoutPath}", 2);

    $checkoutOk = checkoutBranch($repo, $baseBranch, $checkoutPath);
    if ($checkoutOk === 'shutdown') {
        verbose_log("shutdown during initial checkout, abandoning job", 2);
        return 'shutdown';
    }
    if ($checkoutOk !== 'true') {
        // Check if shutdown was requested before fallback attempt
        // @phpstan-ignore-next-line
        if (!$running) {
            verbose_log("shutdown requested, abandoning job before fallback", 2);
            return 'shutdown';
        }
        // Fallback: look up actual base branch from GitHub API and retry
        verbose_log("base branch '{$baseBranch}' checkout failed, looking up actual base branch", 2);
        // Check if shutdown was requested before API call
        // @phpstan-ignore-next-line
        if (!$running) {
            verbose_log("shutdown requested, abandoning job before base branch lookup", 2);
            return 'shutdown';
        }
        $actualBaseBranch = getPRBaseBranch($repo, $prNumber);
        if ($actualBaseBranch === null) {
            verbose_log("could not determine actual base branch from API", 1);
            return 'checkout failed: could not look up base branch';
        }
        verbose_log("retrying with actual base branch: {$actualBaseBranch}", 2);
        $checkoutPath = buildCheckoutPath($checkoutRoot, $repo, $actualBaseBranch);
        $checkoutOk = checkoutBranch($repo, $actualBaseBranch, $checkoutPath);
        if ($checkoutOk === 'shutdown') {
            verbose_log("shutdown during fallback checkout, abandoning job", 2);
            return 'shutdown';
        }
        if ($checkoutOk !== 'true') {
            verbose_log("checkout failed: {$checkoutOk}", 1);
            return 'checkout failed: ' . $checkoutOk;
        }
        // Update baseBranch for later use (e.g., diff application)
        $baseBranch = $actualBaseBranch;
    }
    verbose_log("checkout complete: base branch checked out", 2);

    try {
        // Step 2: Reset the checkout to a pristine base state BEFORE applying
        // the PR diff. (Resetting after the apply would silently wipe the PR
        // changes and make every analysis review an unchanged tree.)
        if (!resetCheckoutToCleanState($checkoutPath, $baseBranch)) {
            return 'checkout reset failed';
        }
        initGitRepo($checkoutPath);

        // Step 3: Establish the review baseline so `git diff _base HEAD` is the
        // change under review and a later plain `git diff` is only the agent's
        // in-place fixes.
        $baselineReady = false;
        if ($commitScoped) {
            // Review exactly one commit: _base = SHA^, HEAD = SHA.
            verbose_log("commit-scoped PR review for {$repo}#{$prNumber} at commit {$commitSha}", 2);
            $baseErr = setupCommitScopedBaseline($checkoutPath, $repo, $commitSha);
            if ($baseErr !== 'true') {
                verbose_log("commit-scoped baseline failed: {$baseErr}", 1);
                return 'commit-scoped baseline failed: ' . $baseErr;
            }
            $baselineReady = true;
            // Anchor inline comments on the reviewed commit.
            $sha = $commitSha;
        } else {
            // Pin the review to a specific head commit (mirrors the push path)
            // so a later synchronize push or a base branch that moves between
            // enqueue and processing cannot make us review the wrong tree while
            // anchoring comments to a stale SHA. Resolve the head SHA, then pin
            // _base = GitHub's 3-dot merge-base and HEAD = head SHA.
            $headSha = resolvePrHeadSha($job, $repo, $prNumber);
            if ($headSha !== '') {
                verbose_log("pinning PR review to head commit {$headSha} for {$repo}#{$prNumber}", 2);
                $baseErr = setupPrHeadBaseline($checkoutPath, $repo, $baseBranch, $headSha);
                if ($baseErr === 'true') {
                    $baselineReady = true;
                    // Anchor inline comments on the EXACT reviewed commit.
                    $sha = $headSha;
                    verbose_log("PR head baseline pinned (_base=merge-base, HEAD={$headSha})", 2);
                } else {
                    verbose_log("PR head baseline failed ({$baseErr}); falling back to live diff apply", 1);
                }
            } else {
                verbose_log("could not resolve PR head SHA; falling back to live diff apply", 1);
            }

            // Fallback: head SHA unresolved or the merge-base/head SHA could not
            // be fetched. Use the live `gh pr diff`, apply it, and commit it as a
            // snapshot (HEAD = base+PR, _base = base commit). This path is NOT
            // pinned to a specific commit — it reviews the current PR state.
            if (!$baselineReady) {
                $diff = getPRDiff($repo, $prNumber);
                if ($diff !== null && $diff !== '') {
                    verbose_log("applying PR diff for {$repo}#{$prNumber}", 2);
                    if (applyPRDiff($checkoutPath, $diff)) {
                        if (commitPrSnapshot($checkoutPath, $prNumber)) {
                            $baselineReady = true;
                            verbose_log("PR snapshot committed (HEAD=base+PR, _base=base)", 2);
                        } else {
                            verbose_log("failed to commit PR snapshot - agent fixes cannot be captured", 1);
                        }
                    } else {
                        verbose_log("failed to apply PR diff - analyzing base branch only", 2);
                    }
                } else {
                    verbose_log("no diff retrieved - analyzing base branch only", 2);
                }
            }
        }

        // --only-prep: checkout is ready. Print the opencode command to run by
        // hand and stop here without running it.
        if ($onlyPrep) {
            return prepOnly($checkoutPath, $repo, $prNumber, $jobId, $baseBranch);
        }

        $postCtx = [
            'kind' => 'pr',
            'repo' => $repo,
            'pr_number' => $prNumber,
            'commit_sha' => $sha,
        ];
        return runReviewAndPost($checkoutPath, $jobId, $postCtx, $auditTypes, $options, $baselineReady);
    } finally {
        if ($onlyPrep) {
            // Leave the prepared checkout in place for manual testing (prepOnly
            // printed a cleanup command). Drop the cache entry so a real worker
            // re-clones a clean tree instead of reusing this hand-modified
            // sandbox — both the fresh-clone and cache-hit paths self-heal, so
            // this only affects which one runs, never correctness.
            invalidateCachedCheckout($repo, $baseBranch);
            verbose_log("--only-prep: leaving checkout in place at {$checkoutPath}", 1);
        } elseif (getCachedCheckoutPath($repo, $baseBranch) === $checkoutPath) {
            // Checkout is cached for 24h reuse — keep it. The next job resets
            // it to a clean state before use, and analysis leftovers are wiped
            // by resetCheckoutToCleanState() at that point.
            verbose_log("keeping cached checkout for reuse: {$checkoutPath}", 3);
        } else {
            verbose_log("cleaning up checkout: {$checkoutPath}", 3);
            cleanupCheckout($checkoutPath);
        }
    }
}

/**
 * Handle a push review job (kind=push). Clones the pushed branch, materializes
 * the before..after commit range as `_base`..HEAD (the commits are already
 * real, so no diff apply or snapshot commit), reviews it, and posts commit
 * comments on the after SHA.
 *
 * @param array<string, mixed> $job
 * @param array<int, string> $auditTypes
 * @param array<string, mixed> $options
 */
function processPushJob(array $job, string $repo, string $jobId, array $auditTypes, array $options): string
{
    global $checkoutRoot, $running, $onlyPrep;

    $ref = (string)($job['ref'] ?? '');
    $beforeSha = (string)($job['before_sha'] ?? '');
    $afterSha = (string)($job['after_sha'] ?? '');

    if ($ref === '' || $afterSha === '') {
        return 'invalid job: push missing ref or after_sha';
    }

    $checkoutPath = buildCheckoutPath($checkoutRoot, $repo, $ref);
    verbose_log("starting push checkout: repo={$repo} ref={$ref} path={$checkoutPath}", 2);

    $checkoutOk = checkoutBranch($repo, $ref, $checkoutPath);
    if ($checkoutOk === 'shutdown') {
        verbose_log("shutdown during push checkout, abandoning job", 2);
        return 'shutdown';
    }
    if ($checkoutOk !== 'true') {
        verbose_log("push checkout failed: {$checkoutOk}", 1);
        return 'checkout failed: ' . $checkoutOk;
    }
    verbose_log("push checkout complete: {$ref} checked out", 2);

    try {
        if (!resetCheckoutToCleanState($checkoutPath, $ref)) {
            return 'checkout reset failed';
        }
        initGitRepo($checkoutPath);

        // Materialize the before..after range. The commits are real, so no diff
        // apply / snapshot commit: _base = before (or after^ for a new branch),
        // HEAD = after.
        $baseErr = setupPushBaseline($checkoutPath, $repo, $beforeSha, $afterSha);
        if ($baseErr !== 'true') {
            verbose_log("push baseline failed: {$baseErr}", 1);
            return 'push baseline failed: ' . $baseErr;
        }

        if ($onlyPrep) {
            return prepOnlyPush($checkoutPath, $repo, $ref, $jobId, $beforeSha, $afterSha);
        }

        $postCtx = [
            'kind' => 'push',
            'repo' => $repo,
            'pr_number' => 0,
            'commit_sha' => $afterSha,
        ];
        return runReviewAndPost($checkoutPath, $jobId, $postCtx, $auditTypes, $options, true);
    } finally {
        if ($onlyPrep) {
            invalidateCachedCheckout($repo, $ref);
            verbose_log("--only-prep: leaving push checkout in place at {$checkoutPath}", 1);
        } elseif (getCachedCheckoutPath($repo, $ref) === $checkoutPath) {
            verbose_log("keeping cached checkout for reuse: {$checkoutPath}", 3);
        } else {
            verbose_log("cleaning up checkout: {$checkoutPath}", 3);
            cleanupCheckout($checkoutPath);
        }
    }
}

/**
 * Shared review+post stage used by every job kind. Assumes the checkout's
 * baseline is already set (`git diff _base HEAD` is the change under review).
 * Runs opencode once, parses issues, captures the agent's in-place fixes, and
 * posts findings routed by $postCtx['kind'] and honoring $options.
 *
 * @param array{kind: string, repo: string, pr_number: int, commit_sha: string} $postCtx
 * @param array<int, string> $auditTypes
 * @param array<string, mixed> $options
 * @param bool $baselineReady Whether a real _base/HEAD baseline exists (so agent
 *                            fixes can be captured); false posts flag-only.
 */
function runReviewAndPost(string $checkoutPath, string $jobId, array $postCtx, array $auditTypes, array $options, bool $baselineReady): string
{
    global $githubToken, $opencodeAnalyzeCmd, $verbose, $showAgent, $showAllIssues, $opencodeTimeout;

    $repo = (string)$postCtx['repo'];
    $prNumber = (int)($postCtx['pr_number'] ?? 0);
    $label = $prNumber > 0 ? "{$repo}#{$prNumber}" : $repo;

    // Compose the prompt: full/all reviews everything, otherwise prepend a scope
    // line restricting the review to the selected audit categories.
    $promptText = composeReviewPrompt($auditTypes);

    verbose_log("starting opencode analysis for {$label} in {$checkoutPath}" . ($opencodeTimeout > 0 ? " (timeout: " . ($opencodeTimeout >= 3600 ? round($opencodeTimeout / 3600, 1) . "h" : round($opencodeTimeout / 60, 1) . "m") . ")" : ""), 2);
    $analysisOutput = runOpencodeAnalysis($checkoutPath, $jobId, $opencodeAnalyzeCmd, $showAgent, $opencodeTimeout, $promptText);
    verbose_log("analysis command completed", 3);

    $issues = parseAnalysisOutput($analysisOutput, $showAllIssues);
    verbose_log("found " . count($issues) . " issues", 2);

    // At DEBUG level, log the actual issues found
    if (!empty($issues) && $verbose >= 2) {
        foreach ($issues as $issue) {
            $issueJson = json_encode($issue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            verbose_log("analysis result: {$issueJson}", 2);
        }
    }

    if (empty($issues)) {
        return 'no_issues';
    }

    // Capture the agent's in-place fixes: a `git diff` of the working tree
    // against HEAD is ONLY the agent's edits. When no baseline exists there is
    // nothing to diff against, so every issue is posted flag-only.
    $fixDiff = '';
    $fixedFiles = [];
    if ($baselineReady) {
        ['diff' => $fixDiff, 'files' => $fixedFiles] = captureAgentFixes($checkoutPath);
        verbose_log('agent modified ' . count($fixedFiles) . ' file(s): ' . ($fixedFiles !== [] ? implode(', ', $fixedFiles) : '(none)'), 2);
        if ($fixDiff !== '' && $verbose >= 4) {
            verbose_raw('agent_fix_diff', $fixDiff);
        }
    }

    $posted = postReviewFindings($issues, $checkoutPath, $githubToken, $postCtx, $options, $fixDiff, $fixedFiles);
    verbose_log("completed processing: {$posted} comment(s) posted for {$label}", 2);
    return 'true';
}

/**
 * --only-prep handler for push jobs. Prints the opencode command to run by hand
 * for the prepared before..after checkout, then returns 'prep_only'.
 */
function prepOnlyPush(string $checkoutPath, string $repo, string $ref, string $jobId, string $beforeSha, string $afterSha): string
{
    ['cmd' => $opencodeCmd, 'promptFile' => $promptFile] = prepareOpencodeCommand($jobId);
    $changed = trim((string)shell_exec('cd ' . escapeshellarg($checkoutPath) . ' && git diff --name-only _base HEAD 2>/dev/null'));
    $range = ($beforeSha !== '' ? substr($beforeSha, 0, 7) : 'new') . '..' . substr($afterSha, 0, 7);
    $label = "{$ref} ({$range})";

    fwrite(STDOUT, buildPrepInstructions($checkoutPath, $repo, 0, $label, $promptFile, $opencodeCmd, $changed));

    verbose_log("--only-prep: prepared push {$repo} {$ref} at {$checkoutPath}, exiting without running opencode", 1);
    return 'prep_only';
}

/**
 * --only-prep handler. The checkout at $checkoutPath already has the base
 * branch checked out and the PR diff applied; write the opencode prompt file
 * and print the exact command to run by hand plus a cleanup command, then
 * return 'prep_only' so main() stops after this one job.
 */
function prepOnly(string $checkoutPath, string $repo, int $prNumber, string $jobId, string $baseBranch): string
{
    ['cmd' => $opencodeCmd, 'promptFile' => $promptFile] = prepareOpencodeCommand($jobId);
    // The PR is committed as a snapshot on top of _base, so the PR's changed
    // files are the `_base HEAD` range (a plain `git diff` would be empty).
    $changed = trim((string)shell_exec('cd ' . escapeshellarg($checkoutPath) . ' && git diff --name-only _base HEAD 2>/dev/null'));

    fwrite(STDOUT, buildPrepInstructions($checkoutPath, $repo, $prNumber, $baseBranch, $promptFile, $opencodeCmd, $changed));

    verbose_log("--only-prep: prepared {$repo}#{$prNumber} at {$checkoutPath}, exiting without running opencode", 1);
    return 'prep_only';
}

/**
 * Build the human-facing --only-prep instructions block (pure; no I/O).
 *
 * @param string $changed Newline-separated changed file list from `git diff --name-only`
 */
function buildPrepInstructions(string $checkoutPath, string $repo, int $prNumber, string $baseBranch, string $promptFile, string $opencodeCmd, string $changed): string
{
    $rule = str_repeat('=', 72);
    $out  = "\n{$rule}\n";
    $out .= " --only-prep: checkout is ready — opencode was NOT run\n";
    $out .= "{$rule}\n";
    $out .= " Repo / PR : {$repo}#{$prNumber}\n";
    $out .= " Base ref  : {$baseBranch}\n";
    $out .= " Checkout  : {$checkoutPath}\n";
    $out .= " Prompt    : {$promptFile}\n";
    $out .= " Changed   : " . ($changed !== '' ? str_replace("\n", ', ', $changed) : '(none — the PR diff may have failed to apply)') . "\n";
    $out .= "\n Run the review yourself (opencode uses the current dir as its cwd):\n\n";
    $out .= "   cd " . escapeshellarg($checkoutPath) . " \\\n";
    $out .= "     && {$opencodeCmd}\n";
    $out .= "\n Clean up when finished:\n\n";
    $out .= "   rm -rf " . escapeshellarg($checkoutPath) . " " . escapeshellarg($promptFile) . "\n";
    $out .= "{$rule}\n\n";
    return $out;
}

/**
 * Route the review findings to comments, honoring the submit-CLI options.
 *
 * - post_branch / issue_for_commits: branch/issue/PR creation is NOT implemented;
 *   warn and fall back to the default comment behavior (comments only).
 * - combine: one summary comment listing every issue plus the combined fix diff.
 * - split_changes: group the issues and post one summary comment per group.
 * - default (post_diffs): one comment per issue with its own fix diff.
 *
 * Comments are routed by $postCtx['kind']: PR comments for pr jobs, commit
 * comments for push jobs.
 *
 * @param array<int, array<string, mixed>> $issues
 * @param array{kind: string, repo: string, pr_number: int, commit_sha: string} $postCtx
 * @param array<string, mixed> $options
 * @param string[] $fixedFiles
 * @return int Number of comments successfully posted
 */
function postReviewFindings(array $issues, string $checkoutPath, string $token, array $postCtx, array $options, string $fixDiff, array $fixedFiles): int
{
    // Branch/issue/PR creation is not implemented — warn and fall back to comments.
    $unimplemented = [];
    if (!empty($options['post_branch'])) {
        $unimplemented[] = 'post_branch';
    }
    if (!empty($options['issue_for_commits'])) {
        $unimplemented[] = 'issue_for_commits';
    }
    if ($unimplemented !== []) {
        verbose_log('option(s) ' . implode('/', $unimplemented) . ' requested but branch/issue/PR creation is not yet implemented; falling back to default comment behavior', 1);
    }

    $combine = !empty($options['combine']);
    $split = !empty($options['split_changes']);

    // combine: one summary comment with the combined fix diff.
    if ($combine && !$split) {
        $body = buildCombinedComment($issues, $fixDiff);
        verbose_log('combine: posting one summary comment for ' . count($issues) . ' issue(s)', 2);
        return postSummaryComment($token, $postCtx, $body) ? 1 : 0;
    }

    // split_changes: one summary comment per group.
    if ($split) {
        $splitBy = (is_string($options['split_by'] ?? null) && $options['split_by'] !== '') ? $options['split_by'] : 'file';
        $batchSize = (int)($options['split_batch_size'] ?? 10);
        $splitLabel = is_string($options['split_label'] ?? null) ? $options['split_label'] : '';
        $groups = groupIssuesForSplit($issues, $splitBy, $batchSize);
        $total = count($groups);
        verbose_log("split_changes: posting {$total} group comment(s) (by {$splitBy}, batch {$batchSize})", 2);
        $posted = 0;
        foreach ($groups as $i => $group) {
            $body = buildGroupComment($group, $splitBy, (int)$i + 1, $total, $splitLabel);
            if (postSummaryComment($token, $postCtx, $body)) {
                $posted++;
            }
        }
        return $posted;
    }

    // Default (post_diffs): one comment per issue.
    $posted = 0;
    foreach ($issues as $index => $issue) {
        $file = $issue['file'] ?? $issue['path'] ?? 'unknown';
        $line = $issue['line'] ?? $issue['line_number'] ?? 0;
        $message = $issue['message'] ?? $issue['description'] ?? 'Issue';
        verbose_log("processing issue #{$index}: file={$file} line={$line} message=" . substr((string)$message, 0, 60), 3);
        if (processIssue($issue, $checkoutPath, $token, $postCtx, $fixedFiles)) {
            $posted++;
            verbose_log("posted comment for issue #{$index}", 3);
        }
    }
    return $posted;
}

/**
 * Post a single summary/group comment routed by job kind: a PR comment for pr
 * jobs, a commit comment for push jobs.
 *
 * @param array{kind: string, repo: string, pr_number: int, commit_sha: string} $postCtx
 */
function postSummaryComment(string $token, array $postCtx, string $body): bool
{
    if (($postCtx['kind'] ?? 'pr') === 'push') {
        return postCommitComment($token, (string)$postCtx['repo'], (string)$postCtx['commit_sha'], $body) === 'true';
    }
    return postPrComment($token, (string)$postCtx['repo'], (int)$postCtx['pr_number'], $body) === 'true';
}

/**
 * Process a single issue: post a comment, including the agent's in-place fix
 * diff when one was captured for the issue's file. The comment is routed by
 * $postCtx['kind'] — a PR review/issue comment for pr jobs, a commit comment
 * for push jobs.
 *
 * The proposed fix comes from the git working tree (opencode edited files in
 * place during the single review run), NOT from a second opencode pass.
 *
 * @param array<string, mixed> $issue Issue from opencode analysis
 * @param string $checkoutPath Local checkout path
 * @param string $token GitHub token
 * @param array{kind: string, repo: string, pr_number: int, commit_sha: string} $postCtx
 * @param string[] $fixedFiles Files the agent edited (from `git diff --name-only`)
 * @return bool True if the comment was posted, false otherwise
 */
function processIssue(array $issue, string $checkoutPath, string $token, array $postCtx, array $fixedFiles): bool
{
    $file = $issue['file'] ?? $issue['path'] ?? '';
    $line = (int)($issue['line'] ?? $issue['line_number'] ?? 0);

    if ($file === '') {
        verbose_log("skipping issue: no file specified", 2);
        return false;
    }

    $kind = (string)($postCtx['kind'] ?? 'pr');
    $repo = (string)$postCtx['repo'];
    $prNumber = (int)($postCtx['pr_number'] ?? 0);
    $commitSha = (string)($postCtx['commit_sha'] ?? '');

    // A proposed fix is available only when the agent edited this issue's file.
    $diff = null;
    $hasFix = in_array($file, $fixedFiles, true);
    if ($hasFix) {
        $diff = getAgentFileDiff($checkoutPath, $file);
        if (trim($diff) === '') {
            // Named in the diff but no actual hunks — treat as flag-only.
            $hasFix = false;
            $diff = null;
        }
    }

    $commentBody = buildIssueComment($issue, $diff, $repo, $prNumber, $hasFix);

    if ($kind === 'push') {
        if ($hasFix) {
            verbose_log("posting commit comment with agent fix for {$file}:{$line}", 2);
            $postResult = postCommitComment($token, $repo, $commitSha, $commentBody, $file, $line);
        } else {
            verbose_log("no agent fix for {$file}:{$line}, posting flag-only commit comment", 2);
            $postResult = postCommitComment($token, $repo, $commitSha, $commentBody);
        }
    } else {
        if ($hasFix) {
            verbose_log("posting review comment with agent fix for {$file}:{$line}", 2);
            // Post as a review comment (with line reference if possible)
            $postResult = postPrReviewComment($token, $repo, $prNumber, $commentBody, $commitSha, $file, $line);
        } else {
            verbose_log("no agent fix for {$file}:{$line}, posting flag-only comment", 2);
            $postResult = postPrComment($token, $repo, $prNumber, $commentBody);
        }
    }

    if ($postResult === 'true') {
        verbose_log("posted comment for {$file}:{$line}", 2);
    } else {
        verbose_log("failed to post comment: {$postResult}", 1);
    }

    return $postResult === 'true';
}

/**
 * Build a PR comment for a single issue
 *
 * @param array $issue Issue details
 * @param string|null $diff Git diff string (if fix available)
 * @param string $repo Repo name
 * @param int $prNumber PR number
 * @param bool $hasFix Whether a fix was generated
 * @return string Markdown comment body
 */
function buildIssueComment(array $issue, ?string $diff, string $repo, int $prNumber, bool $hasFix): string
{
    $file = $issue['file'] ?? $issue['path'] ?? 'unknown';
    $line = $issue['line'] ?? $issue['line_number'] ?? '-';
    $message = $issue['message'] ?? $issue['description'] ?? 'Issue found';
    $severity = $issue['severity'] ?? $issue['level'] ?? 'warning';
    $rule = $issue['rule'] ?? $issue['id'] ?? '';

    $severityEmoji = [
        'error' => '🔴',
        'warning' => '🟡',
        'info' => '🔵',
        'hint' => '💡',
    ];
    $emoji = $severityEmoji[strtolower($severity)] ?? '⚠️';

    $timestamp = date('Y-m-d H:i:s T');

    $body = "## {$emoji} {$severity}: {$message}\n\n";
    $body .= "**File:** `{$file}`";
    if ($line !== '-' && $line !== 0) {
        $body .= " **Line:** `{$line}`";
    }
    $body .= "\n";
    if ($rule !== '') {
        $body .= "**Rule:** `{$rule}`\n";
    }
    $body .= "**Reviewed:** {$timestamp}\n\n";

    if ($hasFix && $diff !== null && $diff !== '') {
        $body .= "### Proposed Fix\n\n";
        $body .= "```diff\n";
        $body .= $diff;
        $body .= "```\n\n";
        $body .= "---\n";
    }

    $body .= "*Automated review by Code Review Bot*\n";

    return $body;
}

/**
 * Map a severity label to a display emoji (shared by summary/group bodies).
 */
function reviewSeverityEmoji(string $severity): string
{
    $map = [
        'critical' => '🔴',
        'error' => '🔴',
        'blocker' => '🔴',
        'major' => '🟠',
        'minor' => '🟡',
        'warning' => '🟡',
        'warn' => '🟡',
        'info' => '🔵',
        'note' => '🔵',
        'nitpick' => '🟢',
        'hint' => '💡',
    ];
    return $map[strtolower($severity)] ?? '⚠️';
}

/**
 * Render one issue as a single markdown bullet line (pure).
 *
 * @param array<string, mixed> $issue
 */
function renderIssueBullet(array $issue): string
{
    $file = $issue['file'] ?? $issue['path'] ?? 'unknown';
    $line = $issue['line'] ?? $issue['line_number'] ?? '-';
    $message = $issue['message'] ?? $issue['description'] ?? 'Issue';
    $severity = (string)($issue['severity'] ?? $issue['level'] ?? 'warning');
    $emoji = reviewSeverityEmoji($severity);
    $loc = "`{$file}`" . (($line !== '-' && (int)$line !== 0) ? ":{$line}" : '');
    return "- {$emoji} **{$severity}** {$loc} — {$message}";
}

/**
 * Build ONE combined summary comment listing every issue plus the combined
 * agent fix diff (pure — no network/git). Used by the --combine option.
 *
 * @param array<int, array<string, mixed>> $issues
 */
function buildCombinedComment(array $issues, string $combinedDiff): string
{
    $count = count($issues);
    $body = "## Code Review Summary — {$count} issue" . ($count === 1 ? '' : 's') . "\n\n";
    foreach ($issues as $issue) {
        $body .= renderIssueBullet($issue) . "\n";
    }
    if (trim($combinedDiff) !== '') {
        $body .= "\n### Proposed Fixes\n\n```diff\n" . $combinedDiff;
        if (substr($combinedDiff, -1) !== "\n") {
            $body .= "\n";
        }
        $body .= "```\n";
    }
    $body .= "\n*Automated review by Code Review Bot*\n";
    return $body;
}

/**
 * Build ONE summary comment for a split group (pure — no network/git). Used by
 * the --split-changes option; posts grouped comments only (no branch/issue/PR).
 *
 * @param array<int, array<string, mixed>> $group
 */
function buildGroupComment(array $group, string $splitBy, int $index, int $total, string $splitLabel = ''): string
{
    $labelPart = $splitLabel !== '' ? " [{$splitLabel}]" : '';
    $count = count($group);
    $body = "## Code Review — group {$index}/{$total} (by {$splitBy}){$labelPart}\n\n";
    $body .= "{$count} issue" . ($count === 1 ? '' : 's') . " in this group:\n\n";
    foreach ($group as $issue) {
        $body .= renderIssueBullet($issue) . "\n";
    }
    $body .= "\n*Automated review by Code Review Bot*\n";
    return $body;
}

/**
 * Group parsed issues for the --split-changes option (pure — no network/git).
 *
 * Issues are first bucketed by strategy, then each bucket is chunked into
 * batches of at most $batchSize. The 'size' strategy skips bucketing and
 * chunks the whole list into fixed-size batches.
 *
 *   file     — bucket by issue file/path (default)
 *   audit    — bucket by audit/category (falls back to severity)
 *   severity — bucket by severity
 *   size     — no bucketing; fixed-size batches only
 *
 * @param array<int, array<string, mixed>> $issues
 * @return array<int, array<int, array<string, mixed>>> List of groups
 */
function groupIssuesForSplit(array $issues, string $splitBy = 'file', int $batchSize = 10): array
{
    $batchSize = $batchSize > 0 ? $batchSize : 10;
    $splitBy = in_array($splitBy, ['file', 'audit', 'severity', 'size'], true) ? $splitBy : 'file';

    if ($issues === []) {
        return [];
    }

    if ($splitBy === 'size') {
        return array_chunk($issues, $batchSize);
    }

    $buckets = [];
    foreach ($issues as $issue) {
        switch ($splitBy) {
            case 'severity':
                $key = (string)($issue['severity'] ?? $issue['level'] ?? 'unknown');
                break;
            case 'audit':
                $key = (string)($issue['audit'] ?? $issue['category'] ?? $issue['severity'] ?? 'general');
                break;
            case 'file':
            default:
                $key = (string)($issue['file'] ?? $issue['path'] ?? 'unknown');
                break;
        }
        $buckets[$key][] = $issue;
    }

    $groups = [];
    foreach ($buckets as $bucket) {
        foreach (array_chunk($bucket, $batchSize) as $chunk) {
            $groups[] = $chunk;
        }
    }
    return $groups;
}

/**
 * Commit the applied PR diff as a snapshot on top of the base commit.
 *
 * Before this runs, HEAD is at the base commit and the working tree holds the
 * PR changes (uncommitted). We force the `_base` ref at the current base commit,
 * stage everything, and commit with a fixed bot identity. Afterwards HEAD =
 * base+PR with a clean tree and `_base` points at the base commit, so
 * `git diff _base HEAD` is exactly the PR and a later `git diff` is exactly the
 * agent's in-place fixes.
 *
 * @return bool True on success, false if the snapshot could not be committed
 */
function commitPrSnapshot(string $checkoutPath, int $prNumber): bool
{
    $cmd = sprintf(
        'cd %s && git branch -f _base 2>&1 && git add -A 2>&1 && '
            . 'git -c user.email=%s -c user.name=%s commit -q -m %s 2>&1',
        escapeshellarg($checkoutPath),
        escapeshellarg('code-review-bot@webhooks.interserver.net'),
        escapeshellarg('Code Review Bot'),
        escapeshellarg("PR #{$prNumber} snapshot")
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        verbose_log('commitPrSnapshot: failed: ' . substr(implode("\n", $output), 0, 200), 1);
        return false;
    }
    verbose_log("commitPrSnapshot: committed PR #{$prNumber} snapshot in {$checkoutPath}", 3);
    return true;
}

/**
 * Capture the agent's in-place fixes: the git diff of the working tree against
 * the committed PR snapshot (HEAD), plus the list of files it touched.
 *
 * @return array{diff: string, files: string[]}
 */
function captureAgentFixes(string $checkoutPath): array
{
    $diff = (string)shell_exec('cd ' . escapeshellarg($checkoutPath) . ' && git diff 2>/dev/null');
    $namesRaw = trim((string)shell_exec('cd ' . escapeshellarg($checkoutPath) . ' && git diff --name-only 2>/dev/null'));
    $files = $namesRaw === '' ? [] : (preg_split('/\r?\n/', $namesRaw) ?: []);
    return ['diff' => $diff, 'files' => $files];
}

/**
 * Return the git diff for a single file (working tree vs the committed PR
 * snapshot) — i.e. just the agent's fix for that file.
 */
function getAgentFileDiff(string $checkoutPath, string $file): string
{
    return (string)shell_exec(
        'cd ' . escapeshellarg($checkoutPath) . ' && git diff -- ' . escapeshellarg($file) . ' 2>/dev/null'
    );
}

/**
 * True if a commit object is present in the local checkout.
 */
function gitCommitExists(string $checkoutPath, string $commit): bool
{
    if ($commit === '') {
        return false;
    }
    $output = [];
    $ret = 0;
    exec(
        'cd ' . escapeshellarg($checkoutPath) . ' && git rev-parse --verify -q '
            . escapeshellarg($commit . '^{commit}') . ' >/dev/null 2>&1',
        $output,
        $ret
    );
    return $ret === 0;
}

/**
 * Fetch a specific commit object into a (shallow) checkout. GitHub allows
 * fetching reachable SHAs directly; if that fails, deepen the shallow history
 * as a fallback. Returns true once the commit is present locally.
 */
function fetchCommitObject(string $checkoutPath, string $repo, string $commit, int $depth = 1): bool
{
    if ($commit === '') {
        return false;
    }
    $depth = max(1, $depth);
    $remoteUrl = "git@github.com:{$repo}.git";

    // Prefer fetching the exact SHA (works for commits reachable from a ref).
    $cmd = sprintf(
        'cd %s && git fetch --depth %d %s %s 2>&1',
        escapeshellarg($checkoutPath),
        $depth,
        escapeshellarg($remoteUrl),
        escapeshellarg($commit)
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    if (gitCommitExists($checkoutPath, $commit)) {
        return true;
    }
    verbose_log('fetchCommitObject: direct fetch of ' . substr($commit, 0, 12) . ' failed, deepening', 3);

    // Fallback: deepen the existing origin history and re-check.
    $cmd2 = sprintf(
        'cd %s && git fetch --deepen 50 origin 2>&1',
        escapeshellarg($checkoutPath)
    );
    $out2 = [];
    $ret2 = 0;
    exec($cmd2, $out2, $ret2);
    return gitCommitExists($checkoutPath, $commit);
}

/**
 * Ensure a commit is available locally, fetching it if the shallow clone lacks
 * it. Returns false when the commit cannot be obtained.
 */
function ensureCommitPresent(string $checkoutPath, string $repo, string $commit, int $depth = 1): bool
{
    if (gitCommitExists($checkoutPath, $commit)) {
        return true;
    }
    return fetchCommitObject($checkoutPath, $repo, $commit, $depth);
}

/**
 * Materialize a before..after commit range as `_base`..HEAD so the standard
 * review flow applies: `git diff _base HEAD` is exactly the change under review
 * and a later plain `git diff` is exactly the agent's in-place fixes.
 *
 * $baseSha empty means "use $headSha^" (brand-new branch or single commit). When
 * $headSha is a root commit with no parent, the well-known empty tree is used as
 * the baseline so the whole commit reads as additions.
 *
 * The checkout is a shallow clone, so both commits are fetched/deepened as
 * needed. Returns 'true' on success or an error message string.
 */
function setupCommitRangeBaseline(string $checkoutPath, string $repo, string $baseSha, string $headSha): string
{
    if ($headSha === '') {
        return 'missing head sha';
    }
    // depth 2 brings the head commit and (usually) its parent.
    if (!ensureCommitPresent($checkoutPath, $repo, $headSha, 2)) {
        return "head commit {$headSha} not available";
    }

    if ($baseSha !== '') {
        if (!ensureCommitPresent($checkoutPath, $repo, $baseSha, 1)) {
            return "base commit {$baseSha} not available";
        }
        $baseRef = $baseSha;
    } else {
        // Baseline is head^. Fetch a bit deeper if the parent is missing.
        $parent = trim((string)shell_exec(
            'cd ' . escapeshellarg($checkoutPath) . ' && git rev-parse --verify -q '
                . escapeshellarg($headSha . '^') . ' 2>/dev/null'
        ));
        if ($parent === '') {
            fetchCommitObject($checkoutPath, $repo, $headSha, 2);
            $parent = trim((string)shell_exec(
                'cd ' . escapeshellarg($checkoutPath) . ' && git rev-parse --verify -q '
                    . escapeshellarg($headSha . '^') . ' 2>/dev/null'
            ));
        }
        if ($parent === '') {
            // Root commit: diff against the empty tree, materialized as a commit
            // so `_base` can be a normal ref.
            $baseRef = trim((string)shell_exec(
                'cd ' . escapeshellarg($checkoutPath) . ' && git commit-tree ' . EMPTY_TREE_SHA . ' -m base 2>/dev/null'
            ));
            if ($baseRef === '') {
                return "cannot resolve baseline for root commit {$headSha}";
            }
        } else {
            $baseRef = $parent;
        }
    }

    // Point `_base` at the baseline and check out the head commit as HEAD.
    $cmd = sprintf(
        'cd %s && git branch -f _base %s 2>&1 && git checkout -f %s 2>&1',
        escapeshellarg($checkoutPath),
        escapeshellarg($baseRef),
        escapeshellarg($headSha)
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        return 'git baseline setup failed: ' . substr(implode("\n", $output), 0, 200);
    }
    verbose_log("setupCommitRangeBaseline: _base={$baseRef} HEAD={$headSha} in {$checkoutPath}", 3);
    return 'true';
}

/**
 * Push baseline: _base = before_sha (or after_sha^ when before is empty, i.e. a
 * brand-new branch), HEAD = after_sha. Returns 'true' or an error string.
 */
function setupPushBaseline(string $checkoutPath, string $repo, string $beforeSha, string $afterSha): string
{
    return setupCommitRangeBaseline($checkoutPath, $repo, $beforeSha, $afterSha);
}

/**
 * Commit-scoped baseline: review exactly one commit — _base = SHA^, HEAD = SHA.
 * Returns 'true' or an error string.
 */
function setupCommitScopedBaseline(string $checkoutPath, string $repo, string $sha): string
{
    return setupCommitRangeBaseline($checkoutPath, $repo, '', $sha);
}

/**
 * Resolve GitHub's true merge-base for a PR head against its base branch using
 * the compare API's 3-dot semantics — the same base the PR "Files changed" view
 * diffs against. Prefers .merge_base_commit.sha, falls back to .base_commit.sha
 * (a.k.a. .base.sha). Returns '' when the API is unavailable so the caller can
 * fall back to the base branch tip.
 */
function getPrMergeBaseSha(string $repo, string $baseBranch, string $headSha): string
{
    if ($repo === '' || $baseBranch === '' || $headSha === '') {
        return '';
    }
    $cmd = sprintf(
        'gh api %s 2>&1',
        escapeshellarg("repos/{$repo}/compare/{$baseBranch}...{$headSha}")
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        verbose_log('getPrMergeBaseSha: compare API failed: ' . substr(implode("\n", $output), 0, 200), 2);
        return '';
    }
    $json = json_decode(implode("\n", $output), true);
    if (!is_array($json)) {
        return '';
    }
    foreach (['merge_base_commit', 'base_commit', 'base'] as $key) {
        $sha = $json[$key]['sha'] ?? '';
        if (is_string($sha) && $sha !== '') {
            return $sha;
        }
    }
    return '';
}

/**
 * Pin a normal (non-commit-scoped) PR review to a specific head commit, mirroring
 * the push path. Resolves GitHub's 3-dot merge-base so `git diff _base HEAD`
 * matches the PR's Files-changed view exactly, then materializes
 * _base = merge-base and HEAD = $headSha via setupCommitRangeBaseline(). When the
 * merge-base cannot be fetched from the API, falls back to the base branch tip.
 * Returns 'true' or an error string.
 */
function setupPrHeadBaseline(string $checkoutPath, string $repo, string $baseBranch, string $headSha): string
{
    if ($headSha === '') {
        return 'missing head sha';
    }
    $mergeBase = getPrMergeBaseSha($repo, $baseBranch, $headSha);
    if ($mergeBase === '') {
        // API unavailable: use the base branch tip as the baseline. An empty
        // baseBranch degrades to headSha^ inside setupCommitRangeBaseline().
        $mergeBase = $baseBranch;
    }
    return setupCommitRangeBaseline($checkoutPath, $repo, $mergeBase, $headSha);
}

/**
 * Strip ANSI escape sequences (colors, cursor movement, OSC titles) from text.
 * opencode --format default may emit these; they break JSON/markdown parsing.
 */
function stripAnsi(string $text): string
{
    $text = (string)preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text); // CSI sequences
    $text = (string)preg_replace('/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)?/', '', $text); // OSC sequences
    return $text;
}

/**
 * Detect the failure mode where the model (e.g. MiniMax-M2 served via SGLang)
 * emits its tool-call markup as plain TEXT instead of a parsed tool call, then
 * ends the turn — leaving the review truncated mid-thought. Examples seen:
 *   <invoke name="bash">...</invoke></minimax:tool_call>
 *   <|tool_calls_begin|> ... <tool_call> ...
 *
 * Only the tail is inspected, so a report that merely *quotes* such markup
 * earlier in its body doesn't trigger a false positive — the leak-then-exit
 * pattern always leaves the markup at the very end of the output.
 */
function looksLikeLeakedToolCall(string $output): bool
{
    if ($output === '') {
        return false;
    }
    $tail = substr(stripAnsi($output), -1500);
    $markers = [
        'minimax:tool_call',    // <minimax:tool_call> / </minimax:tool_call>
        '<invoke name=',        // Anthropic-style function block leaked as text
        '<parameter name=',
        '<tool_call>',
        '</tool_call>',
        '<|tool_calls_begin|>',
        '<|tool_call_begin|>',
        '<|tool_outputs_begin|>',
        '<function_call',
        '<function=',
    ];
    foreach ($markers as $m) {
        if (stripos($tail, $m) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Remove leaked tool-call markup the model sometimes emits as plain text, so a
 * partial report we choose to keep (after retries are exhausted) doesn't carry
 * stray XML into the parsed output. Only well-formed paired blocks are removed;
 * an unterminated trailing tag is left as-is (harmless to the markdown parser).
 */
function stripLeakedToolCalls(string $text): string
{
    $patterns = [
        '#<minimax:tool_call>.*?</minimax:tool_call>#is',
        '#</?minimax:tool_call>#i',
        '#<invoke\b[^>]*>.*?</invoke>#is',
        '#</?antml:invoke\b[^>]*>#i',
        '#<parameter\b[^>]*>.*?</parameter>#is',
        '#</?tool_call>#i',
        '#<\|tool_(calls?|outputs?)_(begin|end)\|>#i',
        '#</?function_call\b[^>]*>#i',
    ];
    return (string)preg_replace($patterns, '', $text);
}

/**
 * Redis-backed checkout cache: reuse repo checkouts for up to 24 hours.
 *
 * Key:    github:checkout:v1:{repo}:{branch}  →  value: absolute path
 * TTL:    86400 seconds (24 hours)
 * Prefix: github:checkout:v1:
 */

// Singleton Redis connection for checkout cache
$checkoutRedis = null;

function getCheckoutRedis(): ?\Predis\Client
{
    global $checkoutRedis;
    if ($checkoutRedis !== null) {
        return $checkoutRedis;
    }
    try {
        $host = defined('REDIS_HOST') && REDIS_HOST !== '' ? REDIS_HOST : (getenv('REDIS_HOST') ?: '67.217.60.234');
        $port = defined('REDIS_PORT') && REDIS_PORT !== '' ? (int)REDIS_PORT : (int)(getenv('REDIS_PORT') ?: 6379);
        $checkoutRedis = new \Predis\Client(['host' => $host, 'port' => $port, 'read_write_timeout' => 2]);
        // Probe with a ping — if it throws, don't use the cache
        $checkoutRedis->ping();
        return $checkoutRedis;
    } catch (\Throwable $e) {
        verbose_log("checkout cache: Redis unavailable ({$e->getMessage()}), cache disabled", 1);
        $checkoutRedis = null;
        return null;
    }
}

/**
 * Build the working-copy path for a repo+branch checkout.
 *
 * Keyed by branch (not PR number) so it lines up with the Redis checkout
 * cache. Branch names are sanitized since they may contain slashes etc.
 */
function buildCheckoutPath(string $checkoutRoot, string $repo, string $branch): string
{
    $safeBranch = preg_replace('/[^A-Za-z0-9._-]/', '_', $branch);
    if ($safeBranch === null || $safeBranch === '') {
        $safeBranch = 'branch';
    }
    return "{$checkoutRoot}/{$repo}/base-{$safeBranch}";
}

/**
 * Get a cached checkout path for a repo+branch, or null if not cached / invalid.
 */
function getCachedCheckoutPath(string $repo, string $branch): ?string
{
    $redis = getCheckoutRedis();
    if ($redis === null) {
        return null;
    }
    $key = "github:checkout:v1:{$repo}:{$branch}";
    $path = $redis->get($key);
    if ($path === null || $path === false) {
        return null;
    }
    // Validate: path must exist, be a git repo, and have correct branch checked out
    if (!is_dir($path) || !is_dir("{$path}/.git")) {
        return null;
    }
    // Verify branch matches
    $currentBranch = trim((string)shell_exec("cd " . escapeshellarg($path) . " && git rev-parse --abbrev-ref HEAD 2>/dev/null"));
    if ($currentBranch !== $branch) {
        verbose_log("checkout cache: branch mismatch for {$repo}:{$branch} (cached: {$currentBranch}), invalidating", 2);
        $redis->del($key);
        return null;
    }
    return $path;
}

/**
 * Store a checkout path in the cache with 24h TTL.
 */
function cacheCheckoutPath(string $repo, string $branch, string $path): void
{
    $redis = getCheckoutRedis();
    if ($redis === null) {
        return;
    }
    $key = "github:checkout:v1:{$repo}:{$branch}";
    $redis->setex($key, 86400, $path);
    verbose_log("checkout cached: {$repo}:{$branch} → {$path} (TTL=24h)", 3);
}

/**
 * Invalidate a cached checkout for a repo+branch.
 */
function invalidateCachedCheckout(string $repo, string $branch): void
{
    $redis = getCheckoutRedis();
    if ($redis === null) {
        return;
    }
    $key = "github:checkout:v1:{$repo}:{$branch}";
    $redis->del($key);
}

/**
 * Reset a checkout to a pristine base state before the next job reuses it.
 *
 * A previous (possibly cached) job may have left THREE kinds of drift behind:
 * an in-place-edited working tree, a committed PR-snapshot commit, and a scratch
 * `_base` ref. This wipes all of them so the tree is pristine at the BASE commit
 * again:
 *
 *   1. git checkout -f <base>  — discard tracked-file edits, land on the base
 *   2. git reset --hard <base> — drop any snapshot commit / staged state
 *   3. git clean -fd           — remove untracked files the agent created
 *   4. git branch -D _base      — delete the stale scratch ref
 *
 * The reset target is the base BRANCH, not the leftover `_base` ref:
 * checkoutBranch() has already re-pointed the base branch at the freshly fetched
 * remote tip (fresh clone or 24h-cache sync) and left no snapshot commit on the
 * tip, so the base branch is the authoritative pristine base here. A leftover
 * `_base` from a prior job points at that job's (possibly older) base commit, so
 * resetting to it could drag the tree backward — the very drift this guards
 * against. We therefore reset to the base branch and delete `_base` outright;
 * commitPrSnapshot() recreates a fresh `_base` for each job.
 */
function resetCheckoutToCleanState(string $checkoutPath, string $baseBranch): bool
{
    $cmd = sprintf(
        'cd %s && git checkout -f %s 2>&1 && git reset --hard %s 2>&1 && git clean -fd 2>&1',
        escapeshellarg($checkoutPath),
        escapeshellarg($baseBranch),
        escapeshellarg($baseBranch)
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        verbose_log("resetCheckoutToCleanState: failed for {$checkoutPath}: " . substr(implode("\n", $output), 0, 200), 1);
        return false;
    }

    // Drop the scratch _base ref so a leftover ref can never point a future
    // cached job at a stale commit. commitPrSnapshot() recreates it per job.
    exec('cd ' . escapeshellarg($checkoutPath) . ' && git branch -D _base 2>/dev/null');

    verbose_log("resetCheckoutToCleanState: {$checkoutPath} reset to clean {$baseBranch}", 3);
    return true;
}

/**
 * Initialize git repo in checkout directory for diff generation
 */
function initGitRepo(string $checkoutPath): void
{
    // Set a git identity as a fallback for any local git operation. The PR
    // snapshot is committed separately by commitPrSnapshot() with an explicit
    // `-c user.email/-c user.name`, so this is belt-and-suspenders.
    $cmd = sprintf(
        'cd %s && git config user.email %s && git config user.name %s',
        escapeshellarg($checkoutPath),
        escapeshellarg('code-review-bot@webhooks.interserver.net'),
        escapeshellarg('Code Review Bot')
    );
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    if ($ret !== 0) {
        verbose_log("initGitRepo: git config failed with code {$ret}", 1);
    }
}

/**
 * Clean up a checkout directory
 */
function cleanupCheckout(string $path): void
{
    if (is_dir($path)) {
        $output = [];
        $ret = 0;
        exec("rm -rf " . escapeshellarg($path) . " 2>&1", $output, $ret);
        if ($ret !== 0) {
            verbose_log("cleanupCheckout: failed to remove {$path}", 1);
        }
    }
}

/**
 * Clone/fetch the repository and checkout the target branch using gh auth
 * @return string True on success, error message on failure
 */
function checkoutBranch(string $repo, string $branch, string $checkoutPath): string
{
    global $running;
    verbose_log("checkoutBranch: repo={$repo} branch={$branch} path={$checkoutPath}", 3);

    // Validate repo format to prevent path traversal
    if (!preg_match('/^[a-zA-Z0-9_.\/-]+\/[a-zA-Z0-9_.\/-]+$/', $repo)) {
        verbose_log("checkoutBranch: invalid repo format: {$repo}", 1);
        return 'invalid repo format';
    }

    // Check if shutdown was requested before starting
    // @phpstan-ignore-next-line
    if (!$running) {
        verbose_log("shutdown requested before checkout", 2);
        return 'shutdown';
    }

    // Try to reuse a cached checkout — caller will reset to clean state before analysis
    $cachedPath = getCachedCheckoutPath($repo, $branch);
    if ($cachedPath !== null) {
        // Cache hit: the path already has the correct branch checked out.
        // Sync with remote to pick up new commits, then use it.
        verbose_log("checkoutBranch: cache hit for {$repo}:{$branch} at {$cachedPath} - syncing with remote", 2);
        // Hard-reset to the freshly fetched remote tip — a plain checkout would
        // leave a stale local branch pointing at the old commit
        $syncCmd = sprintf(
            'cd %s && git fetch origin %s 2>&1 && git checkout -f %s 2>&1 && git reset --hard FETCH_HEAD 2>&1',
            escapeshellarg($cachedPath),
            escapeshellarg($branch),
            escapeshellarg($branch)
        );
        $syncOutput = [];
        $syncRet = 0;
        exec($syncCmd, $syncOutput, $syncRet);
        verbose_raw("checkout_cache_sync", "cmd: {$syncCmd}\noutput: " . implode("\n", $syncOutput) . "\nret: {$syncRet}");
        if ($syncRet === 0) {
            verbose_log("checkoutBranch: cache hit reused (synced) for {$repo}:{$branch}", 2);
            // Refresh TTL on reuse so frequently-used checkouts don't expire
            cacheCheckoutPath($repo, $branch, $cachedPath);
            return 'true';
        }
        // Sync failed — fall through to fresh clone
        verbose_log("checkoutBranch: cache sync failed for {$repo}:{$branch}, will re-clone", 2);
    }

    // No cache or cache miss — do a fresh clone
    // Create parent directory if needed
    $parentDir = dirname($checkoutPath);
    if (!is_dir($parentDir)) {
        @mkdir($parentDir, 0755, true);
        verbose_log("created parent directory: {$parentDir}", 3);
    }

    // Remove existing checkout if present
    if (is_dir($checkoutPath)) {
        verbose_log("removing existing checkout: {$checkoutPath}", 3);
        cleanupCheckout($checkoutPath);
    }

    // Use git clone directly (not gh repo clone) — gh repo clone creates
    // incomplete clones where commit objects are missing from the pack file,
    // causing "bad object HEAD" and "corrupt patch" errors during apply.
    // gh auth uses SSH, so use git@github.com: URL.
    $cloneCmd = sprintf(
        'git clone --depth 1 --branch %s -- %s %s 2>&1',
        escapeshellarg($branch),
        escapeshellarg("git@github.com:{$repo}.git"),
        escapeshellarg($checkoutPath)
    );

    verbose_log("executing: {$cloneCmd}", 3);

    $cloneOutput = [];
    $cloneRet = 0;

    // Check $running BEFORE first exec
    // @phpstan-ignore-next-line
    if (!$running) {
        verbose_log("shutdown requested before first clone exec", 2);
        return 'shutdown';
    }

    exec($cloneCmd, $cloneOutput, $cloneRet);

    // At level 4, log the raw clone output
    verbose_raw("gh_clone_attempt1", "cmd: {$cloneCmd}\noutput: " . implode("\n", $cloneOutput) . "\nret: {$cloneRet}");

    // After exec returns, check if signal arrived during exec
    // @phpstan-ignore-next-line
    if (!$running) {
        verbose_log("shutdown requested during first clone exec", 2);
        return 'shutdown';
    }

    if ($cloneRet !== 0) {
        verbose_log("first clone attempt failed, trying with --depth 1", 3);
        // Clean up partial checkout before retry
        if (is_dir($checkoutPath)) {
            verbose_log("cleaning up partial checkout before retry: {$checkoutPath}", 3);
            cleanupCheckout($checkoutPath);
        }

        // Check $running BEFORE retry exec
        // @phpstan-ignore-next-line
        if (!$running) {
            verbose_log("shutdown requested before retry clone exec", 2);
            return 'shutdown';
        }

        // Try git clone --depth 1 (full clone may have network issues)
        $cloneCmd = sprintf(
            'git clone --depth 1 --branch %s -- %s %s 2>&1',
            escapeshellarg($branch),
            escapeshellarg("git@github.com:{$repo}.git"),
            escapeshellarg($checkoutPath)
        );
        exec($cloneCmd, $cloneOutput, $cloneRet);

        // At level 4, log the raw clone output
        verbose_raw("gh_clone_attempt2", "cmd: {$cloneCmd}\noutput: " . implode("\n", $cloneOutput) . "\nret: {$cloneRet}");

        // After exec returns, check if signal arrived during exec
        // @phpstan-ignore-next-line
        if (!$running) {
            verbose_log("shutdown requested during retry clone exec", 2);
            return 'shutdown';
        }

        if ($cloneRet !== 0) {
            $error = 'gh repo clone failed: ' . implode("\n", $cloneOutput);
            verbose_log("checkoutBranch FAILED: {$error}", 1);
            return $error;
        }
    }

    // Validate checkout path is under checkoutRoot after clone
    $realPath = realpath($checkoutPath);
    if ($realPath === false) {
        verbose_log("checkoutBranch: realpath failed for {$checkoutPath}", 1);
        return 'checkout path validation failed';
    }
    global $checkoutRoot;
    if ($checkoutRoot !== '' && strpos($realPath, $checkoutRoot) !== 0) {
        verbose_log("checkoutBranch: path {$realPath} is not under {$checkoutRoot}", 1);
        return 'checkout path outside allowed root';
    }

    verbose_log("checkoutBranch: success", 2);
    // Cache the successful checkout for future reuse (24h TTL)
    cacheCheckoutPath($repo, $branch, $checkoutPath);
    return 'true';
}

/**
 * Run opencode to review AND fix the PR changes in place.
 *
 * The repository already has the PR committed as a snapshot (HEAD = base+PR,
 * `_base` = base commit). The review prompt tells opencode to diff `_base HEAD`
 * to see the PR and to edit files in place to fix issues; those edits stay
 * uncommitted in the working tree and are captured afterwards via `git diff`.
 *
 * @param string $dir Path to the git repository with the PR snapshot committed
 * @param string $jobId Unique job ID for temp file naming
 * @param string $cmdTemplate Unused, kept for signature compatibility
 * @return string Raw opencode output
 */
/**
 * Load the code-review prompt (from scripts/prompts/review.txt, with an inline
 * fallback). Cached after first read.
 */
function getReviewPrompt(): string
{
    static $reviewPrompt = null;
    if ($reviewPrompt !== null) {
        return $reviewPrompt;
    }
    $fallback = 'Analyze the uncommitted changes in this git repository. Run "git diff" to see what changed. For each issue found return JSON: {"file":"path","line":N,"severity":"error|warning|info","message":"description"}. Return empty array if no issues.';
    $file = __DIR__ . '/prompts/review.txt';
    if (file_exists($file)) {
        $loaded = file_get_contents($file);
        if ($loaded === false) {
            $reviewPrompt = $fallback;
            verbose_log("review prompt file read failed, using inline fallback", 2);
        } else {
            $reviewPrompt = $loaded;
            verbose_log("loaded review prompt from {$file} (" . strlen($loaded) . " bytes)", 3);
        }
    } else {
        $reviewPrompt = $fallback;
        verbose_log("review prompt file not found, using inline fallback", 2);
    }
    return $reviewPrompt;
}

/**
 * Compose the final review prompt for a set of audit types.
 *
 * The review.txt file itself stays generic (source-agnostic, all-categories).
 * When the job selects a subset of audit categories, prepend a scope line
 * naming ONLY those categories so opencode limits its review accordingly.
 * 'full'/'all' (or an unrecognized/empty selection) reviews everything.
 *
 * Pure: pass $basePrompt to test without touching the prompt file.
 *
 * @param array<int, mixed> $auditTypes
 */
function composeReviewPrompt(array $auditTypes, ?string $basePrompt = null): string
{
    $base = $basePrompt ?? getReviewPrompt();

    $valid = ['security', 'performance', 'documentation', 'logic', 'style'];
    $selected = [];
    foreach ($auditTypes as $type) {
        if (!is_string($type)) {
            continue;
        }
        $type = strtolower(trim($type));
        if ($type === 'full' || $type === 'all') {
            return $base; // everything — no scope restriction
        }
        if (in_array($type, $valid, true) && !in_array($type, $selected, true)) {
            $selected[] = $type;
        }
    }

    if ($selected === []) {
        return $base; // nothing recognized — review everything
    }

    $scope = 'REVIEW SCOPE: Limit this review to the following categories ONLY: '
        . implode(', ', $selected) . '. Ignore issues that fall outside these categories.'
        . "\n\n";
    return $scope . $base;
}

/**
 * Write the review prompt to a temp file and return the exact opencode command
 * the worker runs plus that file's path. Shared by the live analysis path and
 * --only-prep so the printed command is byte-for-byte what would run.
 *
 * The prompt is passed via `$(cat FILE)` to avoid shell-quoting issues with
 * special characters (parentheses, quotes, $, `, etc.) in the prompt text.
 * --format json makes opencode emit one NDJSON event per line, which the
 * parsers/streamer handle natively; the ~/.config/opencode/plugins/
 * subagent-reporter.ts plugin detects this flag and emits its own
 * {"type":"subagent",...} NDJSON events so sub-agent progress interleaves
 * cleanly instead of corrupting the stream.
 *
 * When $promptText is null the generic review prompt is used; callers pass a
 * composed prompt (e.g. with an audit-type scope prefix) to narrow the review.
 *
 * @return array{cmd: string, promptFile: string}
 */
function prepareOpencodeCommand(string $jobId, ?string $promptText = null): array
{
    $promptFile = '/tmp/opencode-prompt-' . $jobId . '.txt';
    file_put_contents($promptFile, $promptText ?? getReviewPrompt());
    $cmd = sprintf('opencode run "$(cat %s)" --format json', escapeshellarg($promptFile));
    return ['cmd' => $cmd, 'promptFile' => $promptFile];
}

function runOpencodeAnalysis(string $dir, string $jobId, string $cmdTemplate, bool $showAgent = false, int $timeout = 1800, ?string $promptText = null): string
{
    global $running, $verbose, $logFile;

    ['cmd' => $opencodeCmd, 'promptFile' => $promptFile] = prepareOpencodeCommand($jobId, $promptText);

    // Log what changed — kept OUT of the analyzed command output so diff
    // content can never be mistaken for review findings by the parsers
    if ($verbose >= 2) {
        $changed = trim((string)shell_exec('cd ' . escapeshellarg($dir) . ' && git diff --name-only 2>/dev/null'));
        verbose_log("runOpencodeAnalysis: changed files: " . ($changed !== '' ? str_replace("\n", ', ', $changed) : '(none)'), 2);
        if ($verbose >= 4) {
            verbose_raw('working_tree_diff', (string)shell_exec('cd ' . escapeshellarg($dir) . ' && git diff 2>/dev/null'));
        }
    }

    // Stream live when --show-agent is set, or at -vvvv (RAW = everything)
    $stream = $showAgent || $verbose >= 4;

    // The model (MiniMax-M2 via SGLang) occasionally emits its tool-call markup
    // as plain text and then ends the turn mid-review — a server-side tool-call
    // parse leak. When we detect that, re-run the review (the PR baseline is the
    // committed snapshot, so `git diff _base HEAD` is stable across attempts and
    // in-place fixes converge idempotently). Tunable via OPENCODE_MAX_ATTEMPTS
    // (default 3, i.e. 2 retries).
    $maxAttempts = max(1, (int)(getenv('OPENCODE_MAX_ATTEMPTS') ?: 3));
    $result = '';

    try {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $suffix = ($stream ? ' [streaming]' : '') . ($attempt > 1 ? " (attempt {$attempt}/{$maxAttempts})" : '');
            verbose_log("runOpencodeAnalysis: executing analysis in {$dir}{$suffix}", 3);

            $run = runStreamedCommand($opencodeCmd, $dir, $timeout, $stream);

            if (!$running || $run['interrupted']) {
                verbose_log("runOpencodeAnalysis: shutdown requested during analysis, discarding output", 2);
                return '';
            }
            if ($run['stderr'] !== '') {
                verbose_raw("opencode_stderr", $run['stderr']);
            }
            $result = $run['output'];

            if ($run['timedOut']) {
                // A timeout is not a parse leak — keep whatever partial we have
                verbose_log("runOpencodeAnalysis: analysis timed out after {$timeout}s, using partial output", 1);
                break;
            }

            if (looksLikeLeakedToolCall($result) && $attempt < $maxAttempts) {
                verbose_log("runOpencodeAnalysis: output ended on leaked tool-call markup (model/SGLang tool-parse leak); retrying fresh (" . ($attempt + 1) . "/{$maxAttempts})", 1);
                usleep(500000); // brief backoff before retry
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                // @phpstan-ignore-next-line — $running is mutated by the async signal handler
                if (!$running) {
                    return '';
                }
                continue;
            }

            if (looksLikeLeakedToolCall($result)) {
                verbose_log("runOpencodeAnalysis: output still shows leaked tool-call markup after {$maxAttempts} attempts; using best-effort partial", 1);
            }
            break;
        }
    } finally {
        @unlink($promptFile);
    }

    verbose_log("runOpencodeAnalysis: completed, output length=" . strlen($result), 3);

    // At level 4 (RAW) without live streaming, log the full opencode output
    // (when streaming, every line was already displayed and --log'd live)
    if (strlen($result) > 0 && $verbose >= 4 && !$stream) {
        verbose_markdown("opencode_output", $result);
    }

    return $result;
}

/**
 * Parse opencode JSON output into structured issues
 *
 * Opencode outputs JSON Lines (NDJSON) format - each line is a separate JSON object.
 * The AI produces markdown code review reports (not JSON) with emoji-prefixed issues:
 *   🔴 Critical | 🟠 Major | 🟡 Minor | 🟢 Nitpick
 *   e.g., "#### 🔴 SQL Injection Vulnerability — include/file.php:207"
 *
 * Also supports JSON code blocks for backward compatibility.
 */
function parseAnalysisOutput(string $rawOutput, bool $showAllIssues = false): array
{
    global $verbose, $showAgent;

    $issues = [];

    if ($rawOutput === '') {
        return $issues;
    }

    $rawOutput = stripLeakedToolCalls(stripAnsi($rawOutput));
    $hadValidParse = false;
    $fullText = '';

    $lines = explode("\n", $rawOutput);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $event = json_decode($line, true);
        if (!is_array($event)) {
            continue;
        }

        if (($event['type'] ?? '') === 'text') {
            $part = $event['part'] ?? [];
            $text = is_array($part) ? ($part['text'] ?? '') : '';
            $fullText .= $text . "\n";

            if (preg_match('/```json\s*(\[[\s\S]*?\]|\{[\s\S]*?\})\s*```/', $text, $matches)) {
                $json = json_decode($matches[1], true);
                if (is_array($json)) {
                    $hadValidParse = true;
                    if (isset($json[0]) && is_array($json[0])) {
                        foreach ($json as $issue) {
                            if (is_array($issue) && (isset($issue['severity']) || isset($issue['line']) || isset($issue['message']))) {
                                $issues[] = normalizeIssue($issue);
                            }
                        }
                    } elseif (isset($json['file']) || isset($json['line'])) {
                        $issues[] = normalizeIssue($json);
                    }
                }
            }
        }
    }

    // With --format default the output is plain text (no NDJSON events at
    // all) — treat the entire raw output as the review text
    if ($fullText === '') {
        $fullText = $rawOutput;
        // A ```json block may appear directly in the plain-text output too
        if (preg_match('/```json\s*(\[[\s\S]*?\]|\{[\s\S]*?\})\s*```/', $fullText, $matches)) {
            $json = json_decode($matches[1], true);
            if (is_array($json)) {
                $hadValidParse = true;
                if (isset($json[0]) && is_array($json[0])) {
                    foreach ($json as $issue) {
                        if (is_array($issue) && (isset($issue['severity']) || isset($issue['line']) || isset($issue['message']))) {
                            $issues[] = normalizeIssue($issue);
                        }
                    }
                } elseif (isset($json['file']) || isset($json['line'])) {
                    $issues[] = normalizeIssue($json);
                }
            }
        }
    }

    if (!$hadValidParse && $fullText !== '') {
        $issues = array_merge($issues, parseMarkdownIssues($fullText));
        if (!empty($issues)) {
            $hadValidParse = true;
        }
    }

    // Display full analysis markdown at verbosity 3+ when --show-agent is set
    if ($showAgent && $verbose >= 3 && $fullText !== '') {
        verbose_markdown("code_review_report", $fullText);
    }

    if (!$hadValidParse) {
        verbose_log('failed to parse opencode output - no valid JSON or markdown issues found', 1);
    } elseif (empty($issues)) {
        verbose_log('opencode output parsed but no issues found', 3);
    }

    // Filter issues: only keep those with BOTH file path AND line number,
    // unless --show-all-issues is passed (to include summary sections)
    if (!$showAllIssues) {
        $issues = array_filter($issues, function (array $issue): bool {
            $file = $issue['file'] ?? '';
            $line = $issue['line'] ?? 0;
            // Keep only issues that have BOTH file and line
            return $file !== '' && $line > 0;
        });
        $issues = array_values($issues); // Re-index array
    }

    return $issues;
}

/**
 * Normalize issue array to ensure consistent field names
 */
function normalizeIssue(array $issue): array
{
    $normalized = [
        'file' => $issue['file'] ?? $issue['path'] ?? '',
        'line' => isset($issue['line']) ? (int)$issue['line'] : ($issue['line_number'] ?? 0),
        'severity' => $issue['severity'] ?? 'warning',
        'message' => $issue['message'] ?? $issue['description'] ?? '',
    ];

    $sev = strtolower($normalized['severity']);
    if (in_array($sev, ['critical', 'crit', 'blocker', 'error'], true)) {
        $normalized['severity'] = 'critical';
    } elseif ($sev === 'major') {
        $normalized['severity'] = 'major';
    } elseif (in_array($sev, ['minor', 'warning', 'warn'], true)) {
        $normalized['severity'] = 'minor';
    } elseif (in_array($sev, ['nitpick', 'info', 'suggestion', 'note'], true)) {
        $normalized['severity'] = 'info';
    }

    return $normalized;
}

/**
 * Parse issues from markdown code review format
 *
 * Extracts issues from markdown like:
 *   #### 🔴 SQL Injection Vulnerability — include/file.php:207
 *   #### 🟠 Missing Error Handling — include/file.php:42
 *   #### 🟡 Code Style Issue — include/file.php:100
 */
function parseMarkdownIssues(string $text): array
{
    $issues = [];
    $severityMap = [
        '🔴' => 'critical',
        '🟠' => 'major',
        '🟡' => 'minor',
        '🟢' => 'info',
    ];

    $lines = explode("\n", $text);
    $currentIssue = null;
    $currentDescription = [];

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            if ($currentIssue !== null && !empty($currentDescription)) {
                $currentIssue['message'] = trim(implode("\n", $currentDescription));
                if ($currentIssue['message'] !== '') {
                    $issues[] = $currentIssue;
                }
                $currentIssue = null;
                $currentDescription = [];
            }
            continue;
        }

        if (preg_match('/^#{1,6}\s*([🔴🟠🟡🟢])\s+(.+?)(?:[\s—–-]+([^:\s]+):(\d+))?$/u', $line, $matches)) {
            // Save previous issue (if any) before starting new one
            if ($currentIssue !== null) {
                // Only overwrite message with description if there IS a description; otherwise keep title
                if (!empty($currentDescription)) {
                    $currentIssue['message'] = trim(implode("\n", $currentDescription));
                }
                if ($currentIssue['message'] !== '') {
                    $issues[] = $currentIssue;
                }
            }

            $emoji = $matches[1];
            $title = trim($matches[2]);
            $file = $matches[3] ?? '';
            $lineNum = isset($matches[4]) ? (int)$matches[4] : 0;

            $currentIssue = [
                'file' => $file,
                'line' => $lineNum,
                // @phpstan-ignore-line array_access.offsetAlwaysExists
                'severity' => $severityMap[$emoji],
                'message' => $title,
                'title' => $title,
            ];
            $currentDescription = [];
        } elseif ($currentIssue !== null) {
            if (preg_match('/^#{1,6}\s+[^🔴🟠🟡🟢]/u', $line)) {
                if (!empty($currentDescription)) {
                    $currentIssue['message'] = trim(implode("\n", $currentDescription));
                }
                if ($currentIssue['message'] !== '') {
                    $issues[] = $currentIssue;
                }
                $currentIssue = null;
                $currentDescription = [];
            } else {
                $cleanLine = ltrim($line, ' `-*');
                if ($cleanLine !== '' && !preg_match('/^\*\*[A-Z][a-z]+ [A-Z][a-z]+ \*\*$/', $cleanLine)) {
                    $currentDescription[] = $cleanLine;
                }
            }
        }
    }

    if ($currentIssue !== null) {
        if (!empty($currentDescription)) {
            $currentIssue['message'] = trim(implode("\n", $currentDescription));
        }
        if ($currentIssue['message'] !== '') {
            $issues[] = $currentIssue;
        }
    }

    return $issues;
}

/**
 * Post a regular comment to a GitHub PR (not a review comment)
 * @return string True on success, error message on failure
 */
function postPrComment(string $token, string $repo, int $prNumber, string $body): string
{
    [$owner, $repoName] = explode('/', $repo, 2);
    // General PR comments use the issues endpoint; POSTing a body-only payload
    // to /pulls/{n}/comments (a review-comment endpoint) is rejected with 422
    $url = "https://api.github.com/repos/{$owner}/{$repoName}/issues/{$prNumber}/comments";

    $data = ['body' => $body];
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: token ' . $token,
            'Content-Type: application/json',
            'Accept: application/vnd.github.v3+json',
            'User-Agent: webhooks.interserver.net-code-review',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        return 'curl error: ' . $error;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return "GitHub API returned HTTP {$httpCode}: {$response}";
    }

    return 'true';
}

/**
 * Post a review comment to a GitHub PR with line reference
 * @return string True on success, error message on failure
 */
function postPrReviewComment(string $token, string $repo, int $prNumber, string $body, string $commitSha, string $path, int $line): string
{
    if ($commitSha === '' || $path === '' || $line <= 0) {
        // Fall back to regular comment
        return postPrComment($token, $repo, $prNumber, $body);
    }

    [$owner, $repoName] = explode('/', $repo, 2);

    // Use the reviews API to post a line-specific comment
    $url = "https://api.github.com/repos/{$owner}/{$repoName}/pulls/{$prNumber}/comments";

    $data = [
        'body' => $body,
        'commit_id' => $commitSha,
        'path' => $path,
        'line' => $line,
        'side' => 'RIGHT', // Show on the right side (new code in PR)
    ];

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: token ' . $token,
            'Content-Type: application/json',
            'Accept: application/vnd.github.v3+json',
            'User-Agent: webhooks.interserver.net-code-review',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        return 'curl error: ' . $error;
    }

    // If line-specific comment fails (e.g., line doesn't exist in diff), fall back to regular comment
    if ($httpCode === 422) { // Unprocessable Entity - often means line not in diff
        return postPrComment($token, $repo, $prNumber, $body);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return "GitHub API returned HTTP {$httpCode}: {$response}";
    }

    return 'true';
}

/**
 * POST a JSON body to the GitHub API and return the raw disposition.
 *
 * @param array<string, mixed> $data
 * @return array{httpCode: int, response: string, error: string}
 */
function ghApiPost(string $url, string $token, array $data): array
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json !== false ? $json : '',
        CURLOPT_HTTPHEADER => [
            'Authorization: token ' . $token,
            'Content-Type: application/json',
            'Accept: application/vnd.github.v3+json',
            'User-Agent: webhooks.interserver.net-code-review',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'response' => is_string($response) ? $response : '',
        'error' => $error,
    ];
}

/**
 * Post a comment on a commit (push jobs). When $path and $line are given an
 * inline commit comment is attempted; if GitHub rejects the anchor (the line is
 * not in the commit's diff), it falls back to a plain commit-level comment so
 * the finding is never lost.
 *
 * @return string 'true' on success, error message on failure
 */
function postCommitComment(string $token, string $repo, string $sha, string $body, string $path = '', int $line = 0): string
{
    if ($sha === '') {
        return 'commit comment error: missing commit sha';
    }
    $parts = explode('/', $repo, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return 'commit comment error: bad repo name';
    }
    [$owner, $repoName] = $parts;
    $url = "https://api.github.com/repos/{$owner}/{$repoName}/commits/{$sha}/comments";

    $inline = $path !== '' && $line > 0;
    $data = ['body' => $body];
    if ($inline) {
        // `path` + `position` anchor the comment inline within the commit's diff.
        // `position` is the line index in the file's diff; we best-effort it with
        // the file line and fall back to a plain comment if GitHub rejects it.
        $data['path'] = $path;
        $data['position'] = $line;
    }

    $result = ghApiPost($url, $token, $data);
    if ($result['error'] !== '') {
        return 'curl error: ' . $result['error'];
    }

    // Inline anchor rejected (line not in the commit's diff) — retry plain.
    if ($inline && $result['httpCode'] >= 400 && $result['httpCode'] < 500) {
        verbose_log("postCommitComment: inline anchor rejected (HTTP {$result['httpCode']}) for {$path}:{$line}, posting plain commit comment", 2);
        $result = ghApiPost($url, $token, ['body' => $body]);
        if ($result['error'] !== '') {
            return 'curl error: ' . $result['error'];
        }
    }

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        return "GitHub API returned HTTP {$result['httpCode']}: {$result['response']}";
    }

    return 'true';
}

/**
 * Handle a failed job
 */
function handleFailure(array $job, string $reason): void
{
    $retryCount = (int)($job['retry_count'] ?? 0);

    if ($retryCount >= MAX_RETRIES) {
        error_log("github-code-review: job {$job['id']} exceeded max retries (" . MAX_RETRIES . "), discarding: {$reason}");
        return;
    }

    // Generate new UUID for requeued job to avoid deduplication issues
    $job['id'] = CodeReviewQueue::uuidV4();

    // Before requeueing a PR job, verify base_branch is correct to avoid
    // repeated fallback lookups. Push jobs have no PR/base_branch to correct.
    if (CodeReviewQueue::jobKind($job) === 'pr') {
        $storedBase = $job['base_branch'] ?? '';
        $correctBase = getPRRef($job['repo'] ?? '', (int)($job['pr_number'] ?? 0), 'base');
        if ($correctBase !== null && $correctBase !== $storedBase) {
            verbose_log("correcting base_branch from '{$storedBase}' to '{$correctBase}' before requeue", 2);
            $job['base_branch'] = $correctBase;
        }
    }

    error_log("github-code-review: requeueing job {$job['id']} (retry {$retryCount}/" . MAX_RETRIES . "): {$reason}");
    CodeReviewQueue::requeue($job);
}

// Run the worker (only when executed directly — not when include()d by tests)
if ($__directRun) {
    main();
}
