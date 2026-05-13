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
 *   php scripts/github-code-review.php
 *
 * Run as a systemd service or cron loop:
 *   while true; do php scripts/github-code-review.php; sleep 1; done
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/CodeReviewQueue.php';

// Load .env if present
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// === Configuration ===
const GITHUB_TOKEN = 'GITHUB_TOKEN';
const CHECKOUT_ROOT = 'CHECKOUT_ROOT';
const OPENCODE_ANALYZE_CMD = 'OPENCODE_ANALYZE_CMD';
const OPENCODE_IMPROVE_CMD = 'OPENCODE_IMPROVE_CMD';
const MAX_RETRIES = 3;
const WORKER_TIMEOUT = 60; // seconds to wait on queue per iteration

$githubToken = getenv(GITHUB_TOKEN) ?: '';
$checkoutRoot = getenv(CHECKOUT_ROOT) ?: '/tmp/pr-checkouts';
$opencodeAnalyzeCmd = getenv(OPENCODE_ANALYZE_CMD) ?: 'opencode analyze --dir {dir} --output json 2>/dev/null';
$opencodeImproveCmd = getenv(OPENCODE_IMPROVE_CMD) ?: 'opencode improve --dir {dir} --output json 2>/dev/null';

if ($githubToken === '') {
    error_log('github-code-review: GITHUB_TOKEN not set, exiting');
    exit(1);
}

/**
 * Main worker loop
 */
function main(): void
{
    global $githubToken, $checkoutRoot, $opencodeAnalyzeCmd, $opencodeImproveCmd;

    error_log('github-code-review: worker started');

    while (true) {
        $job = CodeReviewQueue::dequeue(WORKER_TIMEOUT);

        if ($job === null) {
            // Timeout or error, loop and try again
            continue;
        }

        $jobId = $job['id'] ?? 'unknown';
        error_log("github-code-review: processing job {$jobId} for {$job['repo']}#{$job['pr_number']}");

        try {
            $result = processJob($job);
            if ($result === true) {
                error_log("github-code-review: completed job {$jobId}");
            } elseif (is_string($result) && str_starts_with($result, 'no_issues')) {
                error_log("github-code-review: no issues found for {$job['repo']}#{$job['pr_number']}");
            } else {
                error_log("github-code-review: job {$jobId} failed: {$result}");
                handleFailure($job, $result);
            }
        } catch (\Throwable $e) {
            error_log("github-code-review: job {$jobId} exception: {$e->getMessage()}");
            handleFailure($job, $e->getMessage());
        }

        // Small delay between jobs to avoid hammering
        usleep(500000); // 0.5s
    }
}

/**
 * Process a single code review job
 *
 * @param array $job Envelope from CodeReviewQueue
 * @return bool|string True on success, error message on failure, or "no_issues" string
 */
function processJob(array $job): bool|string
{
    global $githubToken, $checkoutRoot, $opencodeAnalyzeCmd, $opencodeImproveCmd;

    $repo = $job['repo'] ?? '';
    $prNumber = (int)($job['pr_number'] ?? 0);
    $headBranch = $job['head_branch'] ?? '';
    $baseBranch = $job['base_branch'] ?? '';
    $prUrl = $job['pr_url'] ?? '';
    $author = $job['author'] ?? '';
    $sha = $job['sha'] ?? '';
    $action = $job['action'] ?? '';

    if ($repo === '' || $prNumber === 0 || $headBranch === '') {
        return 'invalid job: missing required fields';
    }

    // Checkout the PR branch
    $checkoutPath = "{$checkoutRoot}/{$repo}/{$prNumber}";
    $checkoutOk = checkoutBranch($repo, $headBranch, $checkoutPath);
    if ($checkoutOk !== true) {
        return 'checkout failed: ' . $checkoutOk;
    }

    try {
        // Initialize git repo for diffs
        initGitRepo($checkoutPath);

        // Run opencode analysis
        $analysisOutput = runOpencodeAnalysis($checkoutPath, $opencodeAnalyzeCmd);
        $issues = parseAnalysisOutput($analysisOutput);

        if (empty($issues)) {
            return 'no_issues';
        }

        $issuesPosted = 0;
        $issuesFixed = 0;

        // Process each issue individually
        foreach ($issues as $issue) {
            $issueResult = processIssue($issue, $checkoutPath, $githubToken, $repo, $prNumber, $sha, $opencodeImproveCmd);
            if ($issueResult === true) {
                $issuesFixed++;
            }
            $issuesPosted++;
        }

        error_log("github-code-review: posted {$issuesFixed}/{$issuesPosted} fixes for {$repo}#{$prNumber}");
    } finally {
        // Always clean up checkout
        exec("rm -rf " . escapeshellarg($checkoutPath));
    }

    return true;
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
        error_log("github-code-review: skipping issue with no file");
        return false;
    }

    // Save original file content for diff
    $originalFilePath = $checkoutPath . '/' . $file;
    if (!file_exists($originalFilePath)) {
        error_log("github-code-review: file {$file} does not exist in checkout");
        return false;
    }
    $originalContent = file_get_contents($originalFilePath);

    // Run opencode improve to generate a fix
    $improveOutput = runOpencodeImprove($checkoutPath, $file, $line, $improveCmd);
    $fixResult = parseImproveOutput($improveOutput, $file, $originalContent);

    if (!$fixResult['success']) {
        // No fix available or failed to generate
        // Post issue without fix
        $commentBody = buildIssueComment($issue, null, $repo, $prNumber, false);
        $postResult = postPrComment($token, $repo, $prNumber, $commentBody);
        return $postResult === true;
    }

    // Apply the fix to the file
    $fixedContent = $fixResult['content'];
    file_put_contents($originalFilePath, $fixedContent);

    // Get the git diff for this specific file
    $diff = getFileDiff($checkoutPath, $file, $originalContent, $fixedContent);

    // Restore original (we don't actually commit, just use the diff for the comment)
    file_put_contents($originalFilePath, $originalContent);

    // Build the comment with the diff
    $commentBody = buildIssueComment($issue, $diff, $repo, $prNumber, true);

    // Post as a review comment (with line reference if possible)
    $postResult = postPrReviewComment($token, $repo, $prNumber, $commentBody, $sha, $file, $line);

    return $postResult === true;
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
    exec("cd " . escapeshellarg($checkoutPath) . " && git config user.email 'code-review-bot@webhooks.interserver.net' && git config user.name 'Code Review Bot' && git add -A 2>/dev/null");
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

    unlink($originalTmp);
    unlink($fixedTmp);

    if (!empty($diffOutput)) {
        // Remove the --- and +++ lines from diff output and use our own
        $diffLines = array_slice($diffOutput, 2); // Skip --- and +++ lines
        $diff .= implode("\n", $diffLines);
    } else {
        // Files are identical or diff failed
        return '';
    }

    return $diff;
}

/**
 * Clone/fetch the repository and checkout the target branch
 */
function checkoutBranch(string $repo, string $branch, string $checkoutPath): bool|string
{
    $repoUrl = "https://github.com/{$repo}.git";

    // Create parent directory if needed
    $parentDir = dirname($checkoutPath);
    if (!is_dir($parentDir)) {
        @mkdir($parentDir, 0755, true);
    }

    // Remove existing checkout if present
    if (is_dir($checkoutPath)) {
        exec("rm -rf " . escapeshellarg($checkoutPath));
    }

    // Clone with depth 1
    $cloneCmd = sprintf(
        'git clone --depth 1 --branch %s %s %s 2>&1',
        escapeshellarg($branch),
        escapeshellarg($repoUrl),
        escapeshellarg($checkoutPath)
    );

    $cloneOutput = [];
    $cloneRet = 0;
    exec($cloneCmd, $cloneOutput, $cloneRet);

    if ($cloneRet !== 0) {
        // Try without specifying branch
        $fetchCmd = sprintf(
            'git clone --depth 1 %s %s 2>&1',
            escapeshellarg($repoUrl),
            escapeshellarg($checkoutPath)
        );
        exec($fetchCmd, $fetchOutput, $fetchRet);

        if ($fetchRet !== 0) {
            return 'git clone failed: ' . implode("\n", $cloneOutput);
        }

        // Try to checkout the branch
        $checkoutCmd = sprintf(
            'cd %s && git fetch origin %s && git checkout %s 2>&1',
            escapeshellarg($checkoutPath),
            escapeshellarg($branch),
            escapeshellarg($branch)
        );
        $checkoutOutput = [];
        $checkoutRet = 0;
        exec($checkoutCmd, $checkoutOutput, $checkoutRet);

        if ($checkoutRet !== 0) {
            return 'git checkout failed: ' . implode("\n", $checkoutOutput);
        }
    }

    return true;
}

/**
 * Run opencode analysis on the checkout directory
 */
function runOpencodeAnalysis(string $dir, string $cmd): string
{
    $cmd = str_replace('{dir}', escapeshellarg($dir), $cmd);

    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);

    return implode("\n", $output);
}

/**
 * Parse opencode JSON output into structured issues
 */
function parseAnalysisOutput(string $rawOutput): array
{
    $issues = [];

    if ($rawOutput === '') {
        return $issues;
    }

    $data = json_decode($rawOutput, true);
    if (!is_array($data)) {
        if (preg_match('/\{.*\}/s', $rawOutput, $matches)) {
            $data = json_decode($matches[0], true);
        }
    }

    if (!is_array($data)) {
        error_log('github-code-review: failed to parse opencode output');
        return $issues;
    }

    // Normalize the output structure
    foreach (['issues', 'problems', 'findings', 'bugs', 'errors'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $issues = $data[$key];
            break;
        }
    }

    // If still empty, treat top-level items as issues
    if (empty($issues) && !empty($data)) {
        foreach ($data as $item) {
            if (is_array($item) && (isset($item['severity']) || isset($item['line']) || isset($item['message']))) {
                $issues[] = $item;
            }
        }
    }

    return $issues;
}

/**
 * Post a regular comment to a GitHub PR (not a review comment)
 */
function postPrComment(string $token, string $repo, int $prNumber, string $body): bool|string
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

    return true;
}

/**
 * Post a review comment to a GitHub PR with line reference
 */
function postPrReviewComment(string $token, string $repo, int $prNumber, string $body, string $commitSha, string $path, int $line): bool|string
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

    return true;
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

    error_log("github-code-review: requeueing job {$job['id']} (retry {$retryCount}/" . MAX_RETRIES . "): {$reason}");
    CodeReviewQueue::requeue($job);
}

// Run the worker
main();
