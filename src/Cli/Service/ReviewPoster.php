<?php
declare(strict_types=1);

namespace Webhooks\Cli\Service;

/**
 * Posts review comments to GitHub Pull Requests.
 *
 * Supports both general PR comments via `gh pr comment` and
 * inline review comments via the GitHub API.
 */
final class ReviewPoster
{
    private const API_REVIEW_FORMAT = 'repos/%s/pulls/%d/reviews';

    private string $githubToken;
    private bool $ghCliAvailable;

    public function __construct(?string $githubToken = null)
    {
        $this->githubToken = $githubToken ?? (
            defined('GITHUB_TOKEN') && GITHUB_TOKEN !== ''
                ? GITHUB_TOKEN
                : (getenv('GITHUB_TOKEN') ?: '')
        );

        $this->ghCliAvailable = $this->checkGhCliAvailability();
    }

    /**
     * Post a general comment on a PR.
     *
     * @param string $repo Repository in owner/repo format
     * @param int $prNumber PR number
     * @param string $body Comment body (markdown supported)
     * @return array{success: bool, id?: int, error?: string}
     */
    public function postComment(string $repo, int $prNumber, string $body): array
    {
        if ($this->ghCliAvailable) {
            return $this->postCommentViaGh($repo, $prNumber, $body);
        }

        return $this->postCommentViaApi($repo, $prNumber, $body);
    }

    /**
     * Post an inline review comment on a specific line.
     *
     * @param string $repo Repository in owner/repo format
     * @param int $prNumber PR number
     * @param string $body Comment body
     * @param string $commitId Commit SHA
     * @param string $path File path in the PR
     * @param int $line Line number (1-indexed)
     * @param string $side Side of the diff: 'LEFT' or 'RIGHT' (usually 'RIGHT' for additions)
     * @return array{success: bool, id?: int, error?: string}
     */
    public function postReviewComment(
        string $repo,
        int $prNumber,
        string $body,
        string $commitId,
        string $path,
        int $line,
        string $side = 'RIGHT'
    ): array {
        return $this->postReviewCommentViaApi(
            $repo,
            $prNumber,
            $body,
            $commitId,
            $path,
            $line,
            $side
        );
    }

    /**
     * Post a review with multiple inline comments.
     *
     * @param string $repo Repository in owner/repo format
     * @param int $prNumber PR number
     * @param string $body Review summary body
     * @param array<array{path: string, line: int, side?: string, body: string, commit_id?: string}> $comments
     * @param string $event Review event: 'COMMENT', 'APPROVE', 'REQUEST_CHANGES'
     * @return array{success: bool, id?: int, error?: string}
     */
    public function postReview(
        string $repo,
        int $prNumber,
        string $body,
        array $comments,
        string $event = 'COMMENT'
    ): array {
        [$owner, $repoName] = $this->parseRepo($repo);

        $data = [
            'body' => $body,
            'event' => $event,
            'comments' => array_map(function ($comment) {
                return [
                    'path' => $comment['path'],
                    'line' => (int)$comment['line'],
                    'side' => $comment['side'] ?? 'RIGHT',
                    'body' => $comment['body'],
                ];
            }, $comments),
        ];

        $url = sprintf('https://api.github.com/%s', sprintf(self::API_REVIEW_FORMAT, $owner, $prNumber));

        return $this->makeApiRequest('POST', $url, $data);
    }

    /**
     * Check if gh CLI is available.
     */
    public function isGhCliAvailable(): bool
    {
        return $this->ghCliAvailable;
    }

    /**
     * Post a comment via gh CLI.
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    private function postCommentViaGh(string $repo, int $prNumber, string $body): array
    {
        $escapedRepo = $this->escapeShellArg($repo);
        $escapedBody = $this->escapeShellArg($body);

        $command = sprintf(
            'gh pr comment %d --repo %s --body %s 2>&1',
            $prNumber,
            $escapedRepo,
            $escapedBody
        );

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => implode("\n", $output),
            ];
        }

        return ['success' => true];
    }

    /**
     * Post a comment via GitHub API.
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    private function postCommentViaApi(string $repo, int $prNumber, string $body): array
    {
        [$owner, $repoName] = $this->parseRepo($repo);

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/issues/%d/comments',
            $owner,
            $repoName,
            $prNumber
        );

        return $this->makeApiRequest('POST', $url, ['body' => $body]);
    }

    /**
     * Post an inline review comment via GitHub API.
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    private function postReviewCommentViaApi(
        string $repo,
        int $prNumber,
        string $body,
        string $commitId,
        string $path,
        int $line,
        string $side
    ): array {
        [$owner, $repoName] = $this->parseRepo($repo);

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/pulls/%d/comments',
            $owner,
            $repoName,
            $prNumber
        );

        $data = [
            'body' => $body,
            'commit_id' => $commitId,
            'path' => $path,
            'line' => $line,
            'side' => $side,
        ];

        return $this->makeApiRequest('POST', $url, $data);
    }

    /**
     * Make an authenticated API request to GitHub.
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    private function makeApiRequest(string $method, string $url, array $data): array
    {
        if ($this->githubToken === '') {
            return [
                'success' => false,
                'error' => 'GitHub token not configured',
            ];
        }

        $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            return [
                'success' => false,
                'error' => 'Failed to encode request data',
            ];
        }

        $header = [
            'Authorization: token ' . $this->githubToken,
            'Accept: application/vnd.github.v3+json',
            'Content-Type: application/json',
            'User-Agent: Code-Review-CLI/1.0',
        ];

        $ch = curl_init();
        if ($ch === false) {
            return [
                'success' => false,
                'error' => 'Failed to initialize curl',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $jsonData,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'error' => 'curl error: ' . $error,
            ];
        }

        if (!is_string($response)) {
            return [
                'success' => false,
                'error' => 'Invalid response from curl',
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? "HTTP {$httpCode}";
            return [
                'success' => false,
                'error' => $message,
            ];
        }

        // Extract ID from response
        $id = $decoded['id'] ?? null;

        return [
            'success' => true,
            'id' => $id,
        ];
    }

    /**
     * Check if gh CLI is available.
     */
    private function checkGhCliAvailability(): bool
    {
        $output = [];
        @exec('which gh 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Parse repository string into owner and repo name.
     *
     * @return array{string, string} [owner, repo]
     */
    private function parseRepo(string $repo): array
    {
        $parts = explode('/', $repo, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /**
     * Escape a string for shell execution.
     */
    private function escapeShellArg(string $arg): string
    {
        return escapeshellarg($arg);
    }
}
