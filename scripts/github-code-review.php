<?php
declare(strict_types=1);

/**
 * GitHub PR Code Review Worker
 *
 * Processes jobs from the codereview:queue Redis list.
 * For each job:
 *   1. Checkout the PR's head branch
 *   2. Run opencode analyze on the checkout
 *   3. For each issue found:
 *      a. Run opencode improve to generate a fix
 *      b. Generate a git diff for that file
 *      c. Post a PR comment with the issue + inline diff
 *
 * Usage:
 *   php scripts/github-code-review.php [-v|--verbose]...
 *
 * Verbose levels:
 *   -v   [INFO]   Essential progress (job start/complete/errors)
 *   -vv  [DEBUG]  Detailed progress (checkpoints, function entry)
 *   -vvv [TRACE]  Full trace (loop iterations, issue details)
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

// === Verbose Logging ===
$verbose = 0;
$running = true;
$optind = 0;
$args = getopt('vhwb', ['verbose', 'help', 'wait-if-empty', 'review-bots'], $optind);
if (isset($args['h']) || isset($args['help'])) {
    fwrite(STDOUT, "Usage: php scripts/github-code-review.php [-v|--verbose]... [-w|--wait-if-empty] [-b|--review-bots]\n");
    fwrite(STDOUT, "  -v, --verbose      Increase verbosity (can stack: -vv, -vvv)\n");
    fwrite(STDOUT, "  -w, --wait-if-empty  Wait for jobs when queue is empty (default: exit)\n");
    fwrite(STDOUT, "  -b, --review-bots    Also review PRs from bots like dependabot (default: skip)\n");
    fwrite(STDOUT, "  -h, --help        Show this help message\n");
    fwrite(STDOUT, "\n  Press Ctrl-C to exit gracefully at any time.\n");
    exit(0);
}
if (isset($args['v'])) {
    $verbose = min(3, $verbose + count((array)$args['v']));
}
if (isset($args['verbose'])) {
    $verbose = min(3, $verbose + count((array)$args['verbose']));
}
$waitIfEmpty = isset($args['w']) || isset($args['wait-if-empty']);
$reviewBots = isset($args['b']) || isset($args['review-bots']);

/**
 * Emit verbose log message at the specified verbosity level.
 *
 * @param string $message Log message
 * @param int $level Verbosity level (1=INFO, 2=DEBUG, 3=TRACE)
 */
function verbose_log(string $message, int $level = 1): void
{
    global $verbose;
    if ($verbose >= $level) {
        $prefix = match ($level) {
            1 => '[INFO]',
            2 => '[DEBUG]',
            3 => '[TRACE]',
            default => '[VERBOSE]'
        };
        error_log("github-code-review: {$prefix} {$message}");
    }
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

    // Write diff to a temporary file
    $diffFile = tempnam(sys_get_temp_dir(), 'pr_diff_');
    if ($diffFile === false) {
        verbose_log("applyPRDiff: failed to create temp file", 1);
        return false;
    }

    try {
        file_put_contents($diffFile, $diff);

        // Apply the diff using git apply
        $cmd = sprintf(
            'cd %s && git apply --verbose %s 2>&1',
            escapeshellarg($checkoutPath),
            escapeshellarg($diffFile)
        );

        $output = [];
        $ret = 0;
        exec($cmd, $output, $ret);

        if ($ret !== 0) {
            verbose_log("applyPRDiff: git apply failed: " . implode("\n", $output), 2);
            return false;
        }

        verbose_log("applyPRDiff: diff applied successfully", 3);
        return true;
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
const OPENCODE_IMPROVE_CMD = 'OPENCODE_IMPROVE_CMD';
const MAX_RETRIES = 3;
const WORKER_TIMEOUT = 180; // seconds to wait for analysis per job (bigger repos need more time)

$githubToken = getenv(GITHUB_TOKEN) ?: '';
$ghToken = getenv('GH_TOKEN') ?: '';
$checkoutRoot = getenv(CHECKOUT_ROOT) ?: '/tmp/pr-checkouts';
$opencodeAnalyzeCmd = getenv(OPENCODE_ANALYZE_CMD) ?: 'sh -c "cd {dir} && opencode run \"Analyze all PHP files in this directory tree. Find issues and return JSON array with fields: file, line, severity (error/warning/info), message for each issue\" --format json" 2>&1';
$opencodeImproveCmd = getenv(OPENCODE_IMPROVE_CMD) ?: 'sh -c "cd {dir} && opencode run \"Fix the PHP issue at line {line} in {file}\" --format json" 2>&1';

// Check if logged into gh
$authStatus = (string)shell_exec('gh auth status 2>&1');
if (strpos($authStatus, 'authenticated') === false && strpos($authStatus, 'Logged in') === false) {
    error_log('github-code-review: Not logged into gh. Run "gh auth login" first.');
    exit(1);
}

// Pass tokens to child processes if set
if ($githubToken !== '') {
    putenv("GITHUB_TOKEN={$githubToken}");
}
if ($ghToken !== '') {
    putenv("GH_TOKEN={$ghToken}");
}

// === Signal Handling ===
// Enable async signals so signals can fire during blocking exec() calls
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () {
        global $running;
        $running = false;
        verbose_log("received SIGINT, shutting down...", 1);
    });
    pcntl_signal(SIGTERM, function () {
        global $running;
        $running = false;
        verbose_log("received SIGTERM, shutting down...", 1);
    });
}

/**
 * Main worker loop
 */
function main(): void
{
    global $githubToken, $checkoutRoot, $opencodeAnalyzeCmd, $opencodeImproveCmd, $verbose, $waitIfEmpty, $reviewBots, $running;

    verbose_log("worker started (verbose level {$verbose})", 1);
    verbose_log('waitIfEmpty=' . ($waitIfEmpty ? 'true' : 'false') . ' - ' . ($waitIfEmpty ? 'will wait for jobs' : 'will exit when queue empty'), 1);
    verbose_log('reviewBots=' . ($reviewBots ? 'true' : 'false') . ' - ' . ($reviewBots ? 'will review bot PRs' : 'will skip bot PRs'), 1);

    $iteration = 0;
    while (true) {
        $iteration++;
        verbose_log("main loop iteration #{$iteration} - fetching job", 3);

        // When waitIfEmpty=false, use non-blocking dequeue (timeout=0)
        // When waitIfEmpty=true, use blocking dequeue with WORKER_TIMEOUT
        $timeout = $waitIfEmpty ? WORKER_TIMEOUT : 0;
        $job = CodeReviewQueue::dequeue($timeout);

        if ($job === null) {
            if (!$waitIfEmpty) {
                verbose_log("queue empty, exiting", 1);
                break;
            }
            verbose_log("queue empty, waiting for jobs...", 1);
            usleep(500000); // sleep 0.5s
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if (!$running) {
                verbose_log("shutdown requested, exiting", 1);
                break;
            }
            continue;
        }

        $jobId = $job['id'] ?? 'unknown';
        $repo = $job['repo'] ?? 'unknown';
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

        try {
            $result = processJob($job);
            if ($result === 'true') {
                verbose_log("completed job {$jobId}", 1);
            } elseif (is_string($result) && str_starts_with($result, 'no_issues')) {
                verbose_log("no issues found for {$repo}#{$prNumber}", 1);
            } elseif ($result === 'shutdown') {
                verbose_log("job {$jobId} shutdown requested, exiting", 1);
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
    global $githubToken, $checkoutRoot, $opencodeAnalyzeCmd, $opencodeImproveCmd, $verbose, $reviewBots, $running;

    // Check if shutdown was requested before starting work
    if (!$running) {
        verbose_log("shutdown requested, abandoning job", 2);
        return 'shutdown';
    }

    $repo = $job['repo'] ?? '';
    $prNumber = (int)($job['pr_number'] ?? 0);
    $jobId = $job['id'] ?? uniqid('job_');
    $headBranch = $job['head_branch'] ?? '';
    $baseBranch = $job['base_branch'] ?? '';
    $prUrl = $job['pr_url'] ?? '';
    $author = $job['author'] ?? '';
    $isBot = ($job['is_bot'] ?? false) || str_ends_with($author, '[bot]');
    if ($isBot && !$reviewBots) {
        verbose_log("skipping bot PR from {$author}", 1);
        return 'skipped bot PR';
    }

    $sha = $job['sha'] ?? '';
    $action = $job['action'] ?? '';

    if ($repo === '' || $prNumber === 0 || $baseBranch === '') {
        return 'invalid job: missing required fields';
    }

    // Step 1: Checkout the base branch (target branch - where PR is merging into)
    $checkoutPath = "{$checkoutRoot}/{$repo}/{$prNumber}";
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
        if ($actualBaseBranch !== null && $actualBaseBranch !== $baseBranch) {
            verbose_log("retrying with actual base branch: {$actualBaseBranch}", 2);
            $checkoutOk = checkoutBranch($repo, $actualBaseBranch, $checkoutPath);
            if ($checkoutOk === 'shutdown') {
                verbose_log("shutdown during fallback checkout, abandoning job", 2);
                return 'shutdown';
            }
        }
        if ($checkoutOk !== 'true') {
            verbose_log("checkout failed: {$checkoutOk}", 1);
            return 'checkout failed: ' . $checkoutOk;
        }
        // Update baseBranch for later use (e.g., diff application)
        if (isset($actualBaseBranch)) {
            $baseBranch = $actualBaseBranch;
        }
    }
    verbose_log("checkout complete: base branch checked out", 2);

    try {
        // Step 2: Get the PR diff and apply it (files become modified/uncommitted)
        $diff = getPRDiff($repo, $prNumber);
        if ($diff !== null && $diff !== '') {
            verbose_log("applying PR diff for {$repo}#{$prNumber}", 2);
            $applyOk = applyPRDiff($checkoutPath, $diff);
            if ($applyOk) {
                verbose_log("PR diff applied successfully - files now modified", 2);
            } else {
                verbose_log("failed to apply PR diff - analyzing base branch only", 2);
            }
        } else {
            verbose_log("no diff retrieved - analyzing base branch only", 2);
        }

        // Step 3: Initialize git repo and run opencode analysis on the modified working dir
        initGitRepo($checkoutPath);

        verbose_log("starting opencode analysis for {$repo}#{$prNumber} in {$checkoutPath}", 2);
        $analysisOutput = runOpencodeAnalysis($checkoutPath, $jobId, $opencodeAnalyzeCmd);
        verbose_log("analysis command completed", 3);

        $issues = parseAnalysisOutput($analysisOutput);
        verbose_log("found " . count($issues) . " issues", 2);

        // At DEBUG level, log the actual JSON issues found
        if (!empty($issues) && $verbose >= 2) {
            foreach ($issues as $issue) {
                $issueJson = json_encode($issue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                verbose_log("analysis result: {$issueJson}", 2);
            }
        }

        if (empty($issues)) {
            return 'no_issues';
        }

        $issuesPosted = 0;
        $issuesFixed = 0;

        // Process each issue individually
        foreach ($issues as $index => $issue) {
            $file = $issue['file'] ?? $issue['path'] ?? 'unknown';
            $line = $issue['line'] ?? $issue['line_number'] ?? 0;
            $message = $issue['message'] ?? $issue['description'] ?? 'Issue';
            verbose_log("processing issue #{$index}: file={$file} line={$line} message=" . substr($message, 0, 60), 3);

            $issueResult = processIssue($issue, $checkoutPath, $githubToken, $repo, $prNumber, $sha, $opencodeImproveCmd);
            if ($issueResult === true) {
                $issuesFixed++;
                verbose_log("posted fix for issue #{$index}", 3);
            }
            $issuesPosted++;
        }

        verbose_log("completed processing: {$issuesFixed}/{$issuesPosted} fixes posted for {$repo}#{$prNumber}", 2);
    } finally {
        // Always clean up checkout
        verbose_log("cleaning up checkout: {$checkoutPath}", 3);
        exec("rm -rf " . escapeshellarg($checkoutPath));
    }

    return 'true';
}

/**
 * Process a single issue: generate fix and post PR comment
 *
 * @param array $issue Issue from opencode analysis
 * @param string $checkoutPath Local checkout path
 * @param string $token GitHub token
 * @param string $repo Repo name
 * @param int $prNumber PR number
 * @param string $sha Commit SHA
 * @param string $improveCmd OpenCode improve command template
 * @return bool True if fix was posted, false otherwise
 */
function processIssue(array $issue, string $checkoutPath, string $token, string $repo, int $prNumber, string $sha, string $improveCmd): bool
{
    $file = $issue['file'] ?? $issue['path'] ?? '';
    $line = (int)($issue['line'] ?? $issue['line_number'] ?? 0);
    $message = $issue['message'] ?? $issue['description'] ?? 'Issue found';
    $severity = $issue['severity'] ?? $issue['level'] ?? 'warning';
    $rule = $issue['rule'] ?? $issue['id'] ?? '';

    if ($file === '') {
        verbose_log("skipping issue: no file specified", 2);
        return false;
    }

    // Save original file content for diff
    $originalFilePath = $checkoutPath . '/' . $file;
    if (!file_exists($originalFilePath)) {
        verbose_log("file {$file} does not exist in checkout, skipping", 2);
        return false;
    }
    $originalContent = file_get_contents($originalFilePath);
    if ($originalContent === false) {
        verbose_log("file {$file} could not be read, skipping", 2);
        return false;
    }

    verbose_log("running opencode improve for {$file}:{$line}", 2);
    // Run opencode improve to generate a fix
    $improveOutput = runOpencodeImprove($checkoutPath, $file, $line, $improveCmd);
    $fixResult = parseImproveOutput($improveOutput, $file, $originalContent);

    if (!$fixResult['success']) {
        // No fix available or failed to generate
        // Post issue without fix
        verbose_log("no fix generated for {$file}:{$line}, posting issue without fix", 2);
        $commentBody = buildIssueComment($issue, null, $repo, $prNumber, false);
        $postResult = postPrComment($token, $repo, $prNumber, $commentBody);
        if ($postResult === 'true') {
            verbose_log("posted issue comment for {$file}:{$line}", 2);
        } else {
            verbose_log("failed to post issue comment: {$postResult}", 1);
        }
        return $postResult === 'true';
    }

    verbose_log("fix generated for {$file}:{$line}, applying and posting diff", 2);

    // Apply the fix to the file
    $fixedContent = $fixResult['content'];
    if ($fixedContent === null) {
        verbose_log("fix content is null, skipping", 2);
        return false;
    }
    if (file_put_contents($originalFilePath, $fixedContent) === false) {
        verbose_log("failed to write fixed content to {$file}, skipping", 1);
        return false;
    }

    // Get the git diff for this specific file
    $diff = getFileDiff($checkoutPath, $file, $originalContent, $fixedContent);

    // Restore original (we don't actually commit, just use the diff for the comment)
    if (file_put_contents($originalFilePath, $originalContent) === false) {
        verbose_log("failed to restore original content in {$file}", 1);
    }

    // Build the comment with the diff
    $commentBody = buildIssueComment($issue, $diff, $repo, $prNumber, true);

    verbose_log("posting diff comment for {$file}:{$line}", 2);
    // Post as a review comment (with line reference if possible)
    $postResult = postPrReviewComment($token, $repo, $prNumber, $commentBody, $sha, $file, $line);

    if ($postResult === 'true') {
        verbose_log("posted diff comment for {$file}:{$line}", 2);
    } else {
        verbose_log("failed to post diff comment: {$postResult}", 1);
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
 * Run opencode improve on a directory to generate fixes
 *
 * @param string $dir Checkout directory
 * @param string $file Specific file to fix
 * @param int $line Line number of the issue
 * @param string $cmd Command template
 * @return string Raw output from opencode
 */
function runOpencodeImprove(string $dir, string $file, int $line, string $cmd): string
{
    // Replace placeholders
    $cmd = str_replace('{dir}', escapeshellarg($dir), $cmd);
    $cmd = str_replace('{file}', escapeshellarg($file), $cmd);
    $cmd = str_replace('{line}', (string)$line, $cmd);

    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);

    return implode("\n", $output);
}

/**
 * Run a command with a timeout using proc_open and stream_select
 *
 * @param string $cmd Command to run
 * @param int $timeoutSecs Timeout in seconds
 * @return array{output: string, exitCode: int, timedOut: bool}
 */
function runCommandWithTimeout(string $cmd, int $timeoutSecs = 30): array
{
    $desc = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $proc = proc_open($cmd, $desc, $pipes);

    if ($proc === false) {
        return ['output' => '', 'exitCode' => -1, 'timedOut' => true];
    }

    // Set stdout and stderr to non-blocking
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = [];
    $startTime = time();
    $timedOut = false;

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $secsRemaining = max(1, $timeoutSecs - (time() - $startTime));

        $ready = @stream_select($read, $write, $except, 1, 0);

        if ($ready === false) {
            break;
        }

        foreach ($read as $pipe) {
            $line = fgets($pipe);
            if ($line !== false) {
                $output[] = $line;
            }
        }

        // Check if process has finished
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }

        // Check for timeout
        if ((time() - $startTime) >= $timeoutSecs) {
            $timedOut = true;
            proc_terminate($proc, SIGKILL);
            break;
        }
    }

    // Read any remaining output
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line !== false) {
            $output[] = $line;
        }
    }
    while (!feof($pipes[2])) {
        $line = fgets($pipes[2]);
        if ($line !== false) {
            $output[] = $line;
        }
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($proc);

    return [
        'output' => implode('', $output),
        'exitCode' => $exitCode,
        'timedOut' => $timedOut,
    ];
}

/**
 * Parse opencode improve output to extract fixed content
 *
 * @param string $rawOutput Raw JSON/text output from opencode
 * @param string $file File path
 * @param string $originalContent Original file content
 * @return array{success: bool, content: string|null}
 */
function parseImproveOutput(string $rawOutput, string $file, string $originalContent): array
{
    if ($rawOutput === '') {
        return ['success' => false, 'content' => null];
    }

    $data = json_decode($rawOutput, true);
    if (!is_array($data)) {
        // Try to extract JSON from output
        if (preg_match('/\{.*\}/s', $rawOutput, $matches)) {
            $data = json_decode($matches[0], true);
        }
    }

    if (!is_array($data)) {
        return ['success' => false, 'content' => null];
    }

    // Look for fixed file content in various output structures
    // Pattern 1: data.files[filename].content
    if (isset($data['files'][$file]['content'])) {
        return ['success' => true, 'content' => $data['files'][$file]['content']];
    }

    // Pattern 2: data[filename] = fixed content string
    if (isset($data[$file]) && is_string($data[$file])) {
        return ['success' => true, 'content' => $data[$file]];
    }

    // Pattern 3: data.content = the fixed content
    if (isset($data['content']) && is_string($data['content'])) {
        return ['success' => true, 'content' => $data['content']];
    }

    // Pattern 4: data.fixes[] array with file+content
    if (isset($data['fixes']) && is_array($data['fixes'])) {
        foreach ($data['fixes'] as $fix) {
            if (($fix['file'] ?? '') === $file && isset($fix['content'])) {
                return ['success' => true, 'content' => $fix['content']];
            }
        }
    }

    // Pattern 5: data.improved[filename]
    if (isset($data['improved'][$file])) {
        return ['success' => true, 'content' => $data['improved'][$file]];
    }

    // Pattern 6: data.diff - individual patch
    if (isset($data['diff']) && is_string($data['diff'])) {
        return ['success' => true, 'content' => $data['diff']];
    }

    return ['success' => false, 'content' => null];
}

/**
 * Initialize git repo in checkout directory for diff generation
 */
function initGitRepo(string $checkoutPath): void
{
    // Set git identity (needed for diff)
    $cmd = sprintf(
        'cd %s && git config user.email %s && git config user.name %s && git add -A 2>/dev/null',
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
 * Get git diff for a specific file
 *
 * @param string $checkoutPath Checkout directory
 * @param string $file File path relative to checkout
 * @param string $originalContent Original content
 * @param string $fixedContent Fixed content
 * @return string Unified diff
 */
function getFileDiff(string $checkoutPath, string $file, string $originalContent, string $fixedContent): string
{
    // Create a simple unified diff
    $originalLines = explode("\n", $originalContent);
    $fixedLines = explode("\n", $fixedContent);

    $diff = "--- a/{$file}\n";
    $diff .= "+++ b/{$file}\n";

    // Use diff command for proper unified diff
    $originalTmp = tempnam(sys_get_temp_dir(), 'orig_');
    $fixedTmp = tempnam(sys_get_temp_dir(), 'fixed_');

    // Ensure temp files are always cleaned up
    $cleanup = function () use ($originalTmp, $fixedTmp): void {
        if ($originalTmp !== false && file_exists($originalTmp)) {
            unlink($originalTmp);
        }
        if ($fixedTmp !== false && file_exists($fixedTmp)) {
            unlink($fixedTmp);
        }
    };

    try {
        file_put_contents($originalTmp, $originalContent);
        file_put_contents($fixedTmp, $fixedContent);

        $diffCmd = sprintf(
            'diff -u %s %s 2>/dev/null | tail -n +4',
            escapeshellarg($originalTmp),
            escapeshellarg($fixedTmp)
        );

        $diffOutput = [];
        $ret = 0;
        exec($diffCmd, $diffOutput, $ret);

        if (!empty($diffOutput)) {
            // Remove the --- and +++ lines from diff output and use our own
            $diffLines = array_slice($diffOutput, 2); // Skip --- and +++ lines
            $diff .= implode("\n", $diffLines);
        } else {
            // Files are identical or diff failed
            return '';
        }

        return $diff;
    } finally {
        $cleanup();
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
    if (!preg_match('/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $repo)) {
        verbose_log("checkoutBranch: invalid repo format: {$repo}", 1);
        return 'invalid repo format';
    }

    // Check if shutdown was requested before starting
    // @phpstan-ignore-next-line
    if (!$running) {
        verbose_log("shutdown requested before checkout", 2);
        return 'shutdown';
    }

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

    // Use gh repo clone which automatically uses gh authentication
    // Correct syntax: gh repo clone <repository> [<directory>] [-- <gitflags>...]
    // Directory must come BEFORE '--', branch must come AFTER '--'
    $cloneCmd = sprintf(
        'gh repo clone %s %s -- --branch %s 2>&1',
        escapeshellarg($repo),
        escapeshellarg($checkoutPath),
        escapeshellarg($branch)
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

        // Try with --depth 1 passed through to git (after --)
        $cloneCmd = sprintf(
            'gh repo clone %s %s -- --depth 1 --branch %s 2>&1',
            escapeshellarg($repo),
            escapeshellarg($checkoutPath),
            escapeshellarg($branch)
        );
        exec($cloneCmd, $cloneOutput, $cloneRet);

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
    return 'true';
}

/**
 * Run opencode analysis on a repository with modified (uncommitted) files
 *
 * The repository should already have the PR diff applied (files modified/uncommitted).
 * Opencode will analyze the working directory and see changes via git diff.
 *
 * @param string $dir Path to the git repository with PR changes applied
 * @param string $jobId Unique job ID for temp file naming
 * @param string $cmdTemplate Unused, kept for signature compatibility
 * @return string Raw opencode output
 */
function runOpencodeAnalysis(string $dir, string $jobId, string $cmdTemplate): string
{
    // Load review prompt from file (allows easy tweaking without code changes)
    static $reviewPrompt = null;
    if ($reviewPrompt === null) {
        $promptFile = __DIR__ . '/prompts/review.txt';
        if (file_exists($promptFile)) {
            $reviewPrompt = file_get_contents($promptFile);
            if ($reviewPrompt === false) {
                $reviewPrompt = 'Analyze the uncommitted changes in this git repository. Run "git diff" to see what changed. For each issue found return JSON: {"file":"path","line":N,"severity":"error|warning|info","message":"description"}. Return empty array if no issues.';
                verbose_log("review prompt file read failed, using inline fallback", 2);
            } else {
                verbose_log("loaded review prompt from {$promptFile} (" . strlen($reviewPrompt) . " bytes)", 3);
            }
        } else {
            $reviewPrompt = 'Analyze the uncommitted changes in this git repository. Run "git diff" to see what changed. For each issue found return JSON: {"file":"path","line":N,"severity":"error|warning|info","message":"description"}. Return empty array if no issues.';
            verbose_log("review prompt file not found, using inline fallback", 2);
        }
    }

    // Build command: opencode analyzes the working directory's changes
    $opencodeCmd = sprintf(
        'sh -c "cd %s && git diff --name-only && git diff && opencode run %s --format json" 2>&1',
        escapeshellarg($dir),
        escapeshellarg($reviewPrompt)
    );

    verbose_log("runOpencodeAnalysis: executing analysis in {$dir}", 3);

    $output = [];
    $ret = 0;
    exec($opencodeCmd, $output, $ret);

    $result = implode("\n", $output);
    verbose_log("runOpencodeAnalysis: completed, output length=" . strlen($result), 3);

    // Debug: log full raw output when parsing will likely fail
    if (strlen($result) > 0) {
        verbose_log("RAW_OUTPUT_START\n" . substr($result, 0, 8000) . "\nRAW_OUTPUT_END", 2);
    }

    return $result;
}

/**
 * Parse opencode JSON output into structured issues
 *
 * Opencode outputs JSON Lines (NDJSON) format - each line is a separate JSON object.
 * The actual issues are embedded in 'text' events, wrapped in markdown code blocks.
 */
function parseAnalysisOutput(string $rawOutput): array
{
    $issues = [];

    if ($rawOutput === '') {
        return $issues;  // No output = no issues (not an error)
    }

    $hadValidParse = false;

    // Split by lines and parse each as JSON (JSON Lines format)
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

        // Look for text events that might contain JSON in code blocks
        if (($event['type'] ?? '') === 'text') {
            $text = $event['part']['text'] ?? '';
            // Extract JSON from markdown code blocks
            if (preg_match('/```json\s*(\[[\s\S]*?\]|\{[\s\S]*?\})\s*```/', $text, $matches)) {
                $json = json_decode($matches[1], true);
                if (is_array($json)) {
                    $hadValidParse = true;
                    // If it's an array of issues, merge them
                    if (isset($json[0]) && is_array($json[0])) {
                        foreach ($json as $issue) {
                            if (is_array($issue) && (isset($issue['severity']) || isset($issue['line']) || isset($issue['message']))) {
                                $issues[] = $issue;
                            }
                        }
                    } elseif (isset($json['file']) || isset($json['line'])) {
                        // Single issue object
                        $issues[] = $json;
                    }
                }
            }
        }
    }

    if (!$hadValidParse) {
        verbose_log('failed to parse opencode output - no valid JSON found in output', 1);
    } elseif (empty($issues)) {
        verbose_log('opencode output parsed but no issues found', 3);
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
    $url = "https://api.github.com/repos/{$owner}/{$repoName}/pulls/{$prNumber}/comments";

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
 * Handle a failed job
 */
function handleFailure(array $job, string $reason): void
{
    $retryCount = (int)($job['retry_count'] ?? 0);

    if ($retryCount >= MAX_RETRIES) {
        error_log("github-code-review: job {$job['id']} exceeded max retries ({MAX_RETRIES}), discarding: {$reason}");
        return;
    }

    // Generate new UUID for requeued job to avoid deduplication issues
    $job['id'] = CodeReviewQueue::uuidV4();

    // Before requeueing, verify base_branch is correct to avoid repeated fallback lookups
    $storedBase = $job['base_branch'] ?? '';
    $correctBase = getPRRef($job['repo'] ?? '', (int)($job['pr_number'] ?? 0), 'base');
    if ($correctBase !== null && $correctBase !== $storedBase) {
        verbose_log("correcting base_branch from '{$storedBase}' to '{$correctBase}' before requeue", 2);
        $job['base_branch'] = $correctBase;
    }

    error_log("github-code-review: requeueing job {$job['id']} (retry {$retryCount}/" . MAX_RETRIES . "): {$reason}");
    CodeReviewQueue::requeue($job);
}

// Run the worker
main();
