<?php
declare(strict_types=1);

namespace Webhooks\Cli\Service;

/**
 * Service for fetching GitHub activity (commits, PRs) across repos.
 *
 * Uses `gh api` commands to retrieve activity and merges results
 * from multiple repositories, sorted by date.
 */
final class GithubActivityService
{
    private const DEFAULT_ORGS = ['interserver', 'provirted', 'sugarcraft', 'detain'];

    private bool $ghCliAvailable;
    private int $rateLimitRemaining = 5000;
    private int $rateLimitReset = 0;
    private int $requestCount = 0;
    private bool $verbose = false;

    private const RATE_LIMIT_WARNING_THRESHOLD = 1000;
    private const DELAY_BETWEEN_REQUESTS_MS = 100000; // 100ms in microseconds
    private const MAX_RETRIES = 3;
    private const REPO_CACHE_TTL_SECONDS = 3600; // 1 hour cache

    /** @var array<string, array{repos: array, cached_at: int}> */
    private array $orgRepoCache = [];

    /** @var array<string, array{repos: array, cached_at: int}> */
    private array $userRepoCache = [];

    public function __construct(bool $verbose = false)
    {
        $this->ghCliAvailable = $this->checkGhCliAvailability();
        $this->verbose = $verbose;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * Get authenticated user info.
     *
     * @return array{login: string, name: string, url: string}|null
     */
    public function getUser(): ?array
    {
        if (!$this->ghCliAvailable) {
            return null;
        }

        $command = 'gh api user 2>/dev/null';

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'login' => (string)($data['login'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'url' => (string)($data['url'] ?? ''),
        ];
    }

    /**
     * Get user's organizations.
     *
     * @return array<array{login: string, url: string}>
     */
    public function getOrgs(): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        $command = 'gh api user/orgs 2>/dev/null';

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        $orgs = [];
        foreach ($data as $org) {
            if (!is_array($org)) {
                continue;
            }
            $orgs[] = [
                'login' => (string)($org['login'] ?? ''),
                'url' => (string)($org['url'] ?? ''),
            ];
        }

        return $orgs;
    }

    /**
     * Get repos for given orgs (or user repos if no orgs specified).
     *
     * @param array<array{login: string, url: string}> $orgs
     * @return array<array{full_name: string, name: string, owner: string, url: string}>
     */
    public function getRepos(array $orgs = []): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        $repos = [];

        if ($orgs === []) {
            // Get authenticated user's repos
            $repos = $this->fetchUserRepos();
        } else {
            // Get repos for each org
            foreach ($orgs as $org) {
                $login = $org['login'];
                $orgRepos = $this->fetchOrgRepos($login);
                $repos = array_merge($repos, $orgRepos);
            }
        }

        return $repos;
    }

    /**
     * Get all repos (public + private) for an org with minimal API calls.
     * Uses gh repo list which supports --json and handles pagination.
     *
     * @return array<array{full_name: string, name: string, owner: string, url: string, visibility: string}>
     */
    public function getOrgRepos(string $org, bool $useCache = true): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        if ($useCache && isset($this->orgRepoCache[$org])) {
            $cached = $this->orgRepoCache[$org];
            if (time() - $cached['cached_at'] < self::REPO_CACHE_TTL_SECONDS) {
                return $cached['repos'];
            }
        }

        $escapedOrg = escapeshellarg($org);
        $command = sprintf(
            'gh repo list %s --json name,owner,visibility,url --limit 1000 2>/dev/null',
            $escapedOrg
        );

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        $repos = $this->parseReposWithVisibility($data);

        $this->orgRepoCache[$org] = [
            'repos' => $repos,
            'cached_at' => time(),
        ];

        return $repos;
    }

    /**
     * Get all repos (public + private) for a user with minimal API calls.
     *
     * @return array<array{full_name: string, name: string, owner: string, url: string, visibility: string}>
     */
    public function getUserRepos(string $username, bool $useCache = true): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        if ($useCache && isset($this->userRepoCache[$username])) {
            $cached = $this->userRepoCache[$username];
            if (time() - $cached['cached_at'] < self::REPO_CACHE_TTL_SECONDS) {
                return $cached['repos'];
            }
        }

        $escapedUsername = escapeshellarg($username);
        $command = sprintf(
            'gh repo list %s --json name,owner,visibility,url --limit 1000 2>/dev/null',
            $escapedUsername
        );

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        $repos = $this->parseReposWithVisibility($data);

        $this->userRepoCache[$username] = [
            'repos' => $repos,
            'cached_at' => time(),
        ];

        return $repos;
    }

    /**
     * Get activity for an entire org using single Org Events endpoint.
     * Returns ALL public activity across ALL repos in 1 API call.
     *
     * @return array<array{type: string, repo: string, title: string, author: string, date: string, action: string, payload: array}>
     */
    public function getOrgActivity(string $org, int $limit = 100): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        $escapedOrg = escapeshellarg($org);
        $command = sprintf(
            'gh api orgs/%s/events --paginate --slurp 2>/dev/null',
            $escapedOrg
        );

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Fetching org events: gh api orgs/{$org}/events --paginate --slurp\n");
        }

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            if ($this->verbose) {
                fwrite(STDERR, "[DEBUG] Empty response from org events API\n");
            }
            return [];
        }

        if ($this->verbose) {
            $data = json_decode($output, true);
            fwrite(STDERR, "[DEBUG] Received " . (is_array($data) ? count($data) : 0) . " event pages\n");
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        // Flatten pages: --slurp returns array of pages, each page is array of events
        $allEvents = [];
        foreach ($data as $page) {
            if (is_array($page)) {
                foreach ($page as $event) {
                    $allEvents[] = $event;
                }
            }
        }

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Flattened to " . count($allEvents) . " total events, limiting to {$limit}\n");
        }

        $events = $this->parseOrgEvents($allEvents, $limit);

        return $events;
    }

    /**
     * Get activity for a specific repo using the repo events endpoint.
     * Unlike org events (which only returns public events), this endpoint
     * returns both public AND private events when authenticated.
     *
     * @return array<array{type: string, repo: string, title: string, author: string, date: string, action: string, payload: array}>
     */
    public function getRepoActivity(string $repo, int $limit = 100): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        $escapedRepo = escapeshellarg($repo);
        $command = sprintf(
            'gh api repos/%s/events --paginate --slurp 2>/dev/null',
            $escapedRepo
        );

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Fetching repo events: gh api repos/{$repo}/events --paginate --slurp\n");
        }

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            if ($this->verbose) {
                fwrite(STDERR, "[DEBUG] Empty response from repo events API\n");
            }
            return [];
        }

        if ($this->verbose) {
            $data = json_decode($output, true);
            fwrite(STDERR, "[DEBUG] Received " . (is_array($data) ? count($data) : 0) . " event pages\n");
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        // Flatten pages: --slurp returns array of pages, each page is array of events
        $allEvents = [];
        foreach ($data as $page) {
            if (is_array($page)) {
                foreach ($page as $event) {
                    $allEvents[] = $event;
                }
            }
        }

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Flattened to " . count($allEvents) . " total events, limiting to {$limit}\n");
        }

        // Use the same parsing logic as org events
        $events = $this->parseOrgEvents($allEvents, $limit);

        return $events;
    }

    /**
     * Get recent commits across repos.
     *
     * @param array<array{full_name: string, name: string, owner: string, url: string}> $repos
     * @param string $since Time window (e.g., "24h", "7d", "30d")
     * @param int $limit Max items per repo
     * @param int $offset Pagination offset
     * @return array<array{type: string, repo: string, sha: string, message: string, author: string, author_url: string, date: string, url: string, branch?: string, files_changed?: int, additions?: int, deletions?: int}>
     */
    public function getRecentCommits(array $repos, string $since = '24h', int $limit = 30, int $offset = 0): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        $commits = [];
        $timestamp = $this->parseTimeWindow($since);
        $totalRepos = count($repos);
        $currentRepo = 0;

        if ($this->verbose) {
            fwrite(STDERR, "Querying {$totalRepos} repositories for commits...\n");
        }

        foreach ($repos as $repo) {
            $currentRepo++;
            $repoName = $repo['full_name'];

            if ($this->verbose) {
                fwrite(STDERR, "  [{$currentRepo}/{$totalRepos}] {$repoName}\n");
            }

            $this->checkRateLimit();
            $repoCommits = $this->fetchCommitsForRepo($repoName, $timestamp, $limit, $offset);
            $commits = array_merge($commits, $repoCommits);
            $this->requestCount++;
            $this->applyRequestDelay();
        }

        // Sort by date, newest first
        usort($commits, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $commits;
    }

    /**
     * Get recent PRs across repos.
     *
     * @param array<array{full_name: string, name: string, owner: string, url: string}> $repos
     * @param string $since Time window (e.g., "24h", "7d", "30d")
     * @param int $limit Max items per repo
     * @param int $offset Pagination offset
     * @return array<array{type: string, repo: string, number: int, title: string, state: string, author: string, author_url: string, date: string, url: string, head_branch?: string, base_branch?: string, additions?: int, deletions?: int, changed_files?: int, body?: string, reviews?: array, labels?: array}>
     */
    public function getRecentPRs(array $repos, string $since = '24h', int $limit = 30, int $offset = 0): array
    {
        if (!$this->ghCliAvailable) {
            return [];
        }

        $prs = [];
        $timestamp = $this->parseTimeWindow($since);
        $totalRepos = count($repos);
        $currentRepo = 0;

        if ($this->verbose) {
            fwrite(STDERR, "Querying {$totalRepos} repositories for PRs...\n");
        }

        foreach ($repos as $repo) {
            $currentRepo++;
            $repoName = $repo['full_name'];

            if ($this->verbose) {
                fwrite(STDERR, "  [{$currentRepo}/{$totalRepos}] {$repoName}\n");
            }

            $this->checkRateLimit();
            $repoPRs = $this->fetchPRsForRepo($repoName, $timestamp, $limit, $offset);
            $prs = array_merge($prs, $repoPRs);
            $this->requestCount++;
            $this->applyRequestDelay();
        }

        // Sort by date, newest first
        usort($prs, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $prs;
    }

    /**
     * Get combined activity (commits and PRs) sorted by date.
     *
     * @param array<array{full_name: string, name: string, owner: string, url: string}> $repos
     * @param array<string, mixed> $options
     * @return array<array{type: string, repo: string, title: string, description: string, author: string, author_url: string, date: string, url: string}>
     */
    public function getActivity(array $repos, array $options = []): array
    {
        $this->syncRateLimitFromGh();

        $type = $options['type'] ?? 'all';
        $since = $options['since'] ?? '24h';
        $limit = (int)($options['limit'] ?? 30);
        $page = (int)($options['page'] ?? 0);

        // For single-repo queries, use higher default limit to provide more comprehensive initial load
        $isSingleRepo = count($repos) === 1;
        if ($isSingleRepo && !isset($options['limit'])) {
            $limit = 50; // Higher default for single repo
        }

        // Calculate offset for pagination (page 0 = no offset, page 1 = offset by limit, etc.)
        $offset = $page * $limit;

        $activity = [];

        if ($type === 'all' || $type === 'push') {
            $commits = $this->getRecentCommits($repos, $since, $limit, $offset);
            foreach ($commits as $commit) {
                $activity[] = [
                    'type' => 'commit',
                    'repo' => $commit['repo'],
                    'title' => $this->truncateMessage($commit['message'], 72),
                    'description' => $commit['message'],
                    'author' => $commit['author'],
                    'author_url' => $commit['author_url'],
                    'date' => $commit['date'],
                    'url' => $commit['url'],
                    'sha' => $commit['sha'],
                    'branch' => $commit['branch'] ?? null,
                    'files_changed' => $commit['files_changed'] ?? null,
                    'additions' => $commit['additions'] ?? null,
                    'deletions' => $commit['deletions'] ?? null,
                ];
            }
        }

        if ($type === 'all' || $type === 'pr') {
            $prs = $this->getRecentPRs($repos, $since, $limit, $offset);
            foreach ($prs as $pr) {
                $activity[] = [
                    'type' => 'pr',
                    'repo' => $pr['repo'],
                    'number' => $pr['number'],
                    'title' => $pr['title'],
                    'description' => $pr['body'] ?? '',
                    'author' => $pr['author'],
                    'author_url' => $pr['author_url'],
                    'date' => $pr['date'],
                    'url' => $pr['url'],
                    'state' => $pr['state'],
                    'head_branch' => $pr['head_branch'] ?? null,
                    'base_branch' => $pr['base_branch'] ?? null,
                    'additions' => $pr['additions'] ?? null,
                    'deletions' => $pr['deletions'] ?? null,
                    'changed_files' => $pr['changed_files'] ?? null,
                    'reviews' => $pr['reviews'] ?? [],
                    'labels' => $pr['labels'] ?? [],
                ];
            }
        }

        // Sort combined activity by date, newest first
        usort($activity, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $activity;
    }

    /**
     * Check if gh CLI is available.
     */
    public function isGhCliAvailable(): bool
    {
        return $this->ghCliAvailable;
    }

    /**
     * Fetch commits for a specific repo.
     *
     * Uses --paginate --slurp to fetch ALL pages of commits, then applies
     * jq filtering for the offset/limit. This ensures pagination works correctly
     * when there are more commits than fit in a single API page.
     *
     * @return array<array{type: string, repo: string, sha: string, message: string, author: string, author_url: string, date: string, url: string, branch: string, files_changed: int, additions: int, deletions: int}>
     */
    private function fetchCommitsForRepo(string $repo, int $sinceTimestamp, int $limit, int $offset = 0): array
    {
        $escapedRepo = escapeshellarg($repo);
        $since = date('Y-m-d\TH:i:s\Z', $sinceTimestamp);

        // Use --paginate --slurp to get ALL commits across all pages,
        // then apply jq filtering for offset/limit (server-side since works)
        $command = sprintf(
            "GH_AGGRESSIVE_CACHING_TTL=60s gh api repos/%s/commits?since=%s --paginate --slurp 2>/dev/null",
            $escapedRepo,
            $since
        );

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Fetching commits: gh api repos/{$repo}/commits?since={$since} --paginate --slurp\n");
        }

        $output = $this->executeCommandWithRetry($command);
        if ($output === '' || $output === null) {
            if ($this->verbose) {
                fwrite(STDERR, "[DEBUG] Empty response from commits API\n");
            }
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            if ($this->verbose) {
                fwrite(STDERR, "[DEBUG] Commits response is not an array: " . substr($output, 0, 200) . "\n");
            }
            return [];
        }

        // Flatten pages: --slurp returns array of pages, each page is array of commits
        $allCommits = [];
        foreach ($data as $page) {
            if (is_array($page)) {
                foreach ($page as $commit) {
                    if (is_array($commit)) {
                        $allCommits[] = $commit;
                    }
                }
            }
        }

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Total commits fetched: " . count($allCommits) . ", applying limit {$limit} with offset {$offset}\n");
        }

        // Apply pagination via array_slice after fetching all pages
        $paginatedCommits = array_slice($allCommits, $offset, $limit);

        $commits = [];
        foreach ($paginatedCommits as $commit) {
            $sha = (string)($commit['sha'] ?? '');
            $commitData = $commit['commit'] ?? [];
            $message = (string)($commitData['message'] ?? '');
            $author = $commit['author'] ?? [];
            $authorName = is_array($author) ? (string)($author['login'] ?? $author['name'] ?? 'unknown') : 'unknown';
            $authorUrl = is_array($author) ? (string)($author['html_url'] ?? '') : '';
            $date = (string)($commitData['committer']['date'] ?? $commitData['author']['date'] ?? '');
            $url = (string)($commit['html_url'] ?? '');
            $files = $commit['files'] ?? [];
            $filesChanged = is_array($files) ? count($files) : 0;
            $additions = 0;
            $deletions = 0;

            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_array($file)) {
                        $additions += (int)($file['additions'] ?? 0);
                        $deletions += (int)($file['deletions'] ?? 0);
                    }
                }
            }

            // Extract branch name from commit URL
            $branch = $this->extractBranchFromCommitUrl($url, $repo);

            $commits[] = [
                'type' => 'commit',
                'repo' => $repo,
                'sha' => $sha,
                'message' => $message,
                'author' => $authorName,
                'author_url' => $authorUrl,
                'date' => $date,
                'url' => $url,
                'branch' => $branch,
                'files_changed' => $filesChanged,
                'additions' => $additions,
                'deletions' => $deletions,
            ];
        }

        return $commits;
    }

    /**
     * Fetch PRs for a specific repo.
     *
     * Note: GitHub's /pulls endpoint does NOT support the "since" parameter (unlike /commits).
     * We use --paginate --slurp to fetch ALL pages, then filter client-side by timestamp.
     *
     * @return array<array{type: string, repo: string, number: int, title: string, state: string, author: string, author_url: string, date: string, url: string, head_branch: string, base_branch: string, additions: int, deletions: int, changed_files: int, body: string, reviews: array, labels: array}>
     */
    private function fetchPRsForRepo(string $repo, int $sinceTimestamp, int $limit, int $offset = 0): array
    {
        $escapedRepo = escapeshellarg($repo);
        // Use --paginate --slurp to fetch all PRs across all pages
        // Note: /pulls endpoint does NOT support "since" parameter - filter client-side
        $command = sprintf(
            "GH_AGGRESSIVE_CACHING_TTL=60s gh api repos/%s/pulls --paginate --slurp 2>/dev/null",
            $escapedRepo
        );

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Fetching PRs: gh api repos/{$repo}/pulls --paginate --slurp\n");
        }

        $output = $this->executeCommandWithRetry($command);
        if ($output === '' || $output === null) {
            if ($this->verbose) {
                fwrite(STDERR, "[DEBUG] Empty response from PRs API\n");
            }
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            if ($this->verbose) {
                fwrite(STDERR, "[DEBUG] PRs response is not an array: " . substr($output, 0, 200) . "\n");
            }
            return [];
        }

        // Flatten pages: --slurp returns array of pages, each page is array of PRs
        $allPRs = [];
        foreach ($data as $page) {
            if (is_array($page)) {
                foreach ($page as $pr) {
                    if (is_array($pr)) {
                        $allPRs[] = $pr;
                    }
                }
            }
        }

        if ($this->verbose) {
            fwrite(STDERR, "[DEBUG] Total PRs fetched: " . count($allPRs) . ", applying limit {$limit} with offset {$offset}\n");
        }

        // Apply client-side timestamp filtering (since /pulls doesn't support "since" param)
        $since = date('Y-m-d\TH:i:s\Z', $sinceTimestamp);
        $filteredPRs = [];
        foreach ($allPRs as $pr) {
            $createdAt = (string)($pr['created_at'] ?? '');
            if ($createdAt !== '' && $createdAt >= $since) {
                $filteredPRs[] = $pr;
            }
        }

        // Apply pagination via array_slice after filtering
        $paginatedPRs = array_slice($filteredPRs, $offset, $limit);

        $prs = [];
        foreach ($paginatedPRs as $pr) {
            $number = (int)($pr['number'] ?? 0);
            $title = (string)($pr['title'] ?? '');
            $state = (string)($pr['state'] ?? 'open');
            $author = $pr['author'] ?? [];
            $authorName = is_array($author) ? (string)($author['login'] ?? 'unknown') : (is_string($author) ? $author : 'unknown');
            $authorUrl = is_array($author) ? (string)($author['html_url'] ?? '') : '';
            $createdAt = (string)($pr['created_at'] ?? date('c'));
            $url = (string)($pr['html_url'] ?? '');
            $head = $pr['head'] ?? [];
            $base = $pr['base'] ?? [];
            $headBranch = is_array($head) ? (string)($head['ref'] ?? '') : '';
            $baseBranch = is_array($base) ? (string)($base['ref'] ?? '') : '';
            $additions = (int)($pr['additions'] ?? 0);
            $deletions = (int)($pr['deletions'] ?? 0);
            $changedFiles = (int)($pr['changed_files'] ?? 0);
            $body = (string)($pr['body'] ?? '');
            $labelsData = $pr['labels'] ?? [];
            $reviewsData = $pr['reviews'] ?? [];

            $labels = [];
            foreach ($labelsData as $label) {
                if (is_array($label)) {
                    $labels[] = (string)($label['name'] ?? '');
                }
            }

            $reviews = [];
            foreach ($reviewsData as $review) {
                if (is_array($review)) {
                    $reviews[] = [
                        'author' => (string)($review['author'] ?? ''),
                        'state' => (string)($review['state'] ?? ''),
                        'submitted_at' => (string)($review['submitted_at'] ?? ''),
                    ];
                }
            }

            $prs[] = [
                'type' => 'pr',
                'repo' => $repo,
                'number' => $number,
                'title' => $title,
                'state' => $state,
                'author' => $authorName,
                'author_url' => $authorUrl,
                'date' => $createdAt,
                'url' => $url,
                'head_branch' => $headBranch,
                'base_branch' => $baseBranch,
                'additions' => $additions,
                'deletions' => $deletions,
                'changed_files' => $changedFiles,
                'body' => $body,
                'reviews' => $reviews,
                'labels' => $labels,
            ];
        }

        return $prs;
    }

    /**
     * Fetch authenticated user's repos.
     *
     * @return array<array{full_name: string, name: string, owner: string, url: string}>
     */
    private function fetchUserRepos(): array
    {
        $command = 'gh repo list --json name,owner,url --limit 100 2>/dev/null';

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        return $this->parseRepos($data);
    }

    /**
     * Fetch repos for an org.
     *
     * @return array<array{full_name: string, name: string, owner: string, url: string}>
     */
    private function fetchOrgRepos(string $org): array
    {
        $escapedOrg = escapeshellarg($org);
        $command = sprintf('gh repo list %s --json name,owner,url --limit 100 2>/dev/null', $escapedOrg);

        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return [];
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return [];
        }

        return $this->parseRepos($data);
    }

    /**
     * Parse repo data from gh output.
     *
     * @param array<array<string, mixed>> $data
     * @return array<array{full_name: string, name: string, owner: string, url: string}>
     */
    private function parseRepos(array $data): array
    {
        $repos = [];
        foreach ($data as $repo) {
            if (!is_array($repo)) {
                continue;
            }

            $owner = $repo['owner'] ?? [];
            $ownerLogin = is_array($owner) ? (string)($owner['login'] ?? '') : (is_string($owner) ? $owner : '');

            $repos[] = [
                'full_name' => $ownerLogin !== '' ? $ownerLogin . '/' . (string)($repo['name'] ?? '') : '',
                'name' => (string)($repo['name'] ?? ''),
                'owner' => $ownerLogin,
                'url' => (string)($repo['url'] ?? ''),
            ];
        }

        return $repos;
    }

    /**
     * Parse repo data from gh output with visibility field.
     *
     * @param array<array<string, mixed>> $data
     * @return array<array{full_name: string, name: string, owner: string, url: string, visibility: string}>
     */
    private function parseReposWithVisibility(array $data): array
    {
        $repos = [];
        foreach ($data as $repo) {
            if (!is_array($repo)) {
                continue;
            }

            $owner = $repo['owner'] ?? [];
            $ownerLogin = is_array($owner) ? (string)($owner['login'] ?? '') : (is_string($owner) ? $owner : '');

            $repos[] = [
                'full_name' => $ownerLogin !== '' ? $ownerLogin . '/' . (string)($repo['name'] ?? '') : '',
                'name' => (string)($repo['name'] ?? ''),
                'owner' => $ownerLogin,
                'url' => (string)($repo['url'] ?? ''),
                'visibility' => (string)($repo['visibility'] ?? 'public'),
            ];
        }

        return $repos;
    }

    /**
     * Parse org events from GitHub API response.
     *
     * @param array<array<string, mixed>> $data
     * @return array<array{type: string, repo: string, title: string, author: string, date: string, action: string, payload: array}>
     */
    private function parseOrgEvents(array $data, int $limit): array
    {
        $events = [];
        $count = 0;

        foreach ($data as $event) {
            if (!is_array($event)) {
                continue;
            }

            if ($count >= $limit) {
                break;
            }

            $type = (string)($event['type'] ?? '');
            $repo = $event['repo'] ?? [];
            $repoName = is_array($repo) ? (string)($repo['name'] ?? '') : (is_string($repo) ? $repo : '');
            $actor = $event['actor'] ?? [];
            $actorLogin = is_array($actor) ? (string)($actor['login'] ?? '') : (is_string($actor) ? $actor : '');
            $actorUrl = is_array($actor) ? (string)($actor['html_url'] ?? '') : '';
            $payload = $event['payload'] ?? [];
            $createdAt = (string)($event['created_at'] ?? '');

            // Skip non-push events for activity (PushEvent is the main activity type)
            // Include other meaningful event types
            $meaningfulTypes = ['PushEvent', 'PullRequestEvent', 'IssuesEvent', 'CreateEvent', 'DeleteEvent', 'ForkEvent'];
            if (!in_array($type, $meaningfulTypes, true)) {
                continue;
            }

            $action = $this->extractEventAction($type, $payload);

            // Build title and author based on event type
            $title = $this->extractEventTitle($type, $payload);
            $author = $actorLogin ?: $this->extractEventAuthor($type, $payload);

            $events[] = [
                'type' => $this->normalizeEventType($type),
                'repo' => $repoName,
                'title' => $title,
                'author' => $author,
                'date' => $createdAt,
                'action' => $action,
                'payload' => is_array($payload) ? $payload : [],
            ];

            $count++;
        }

        return $events;
    }

    /**
     * Normalize event type to human-readable format.
     */
    private function normalizeEventType(string $type): string
    {
        return match ($type) {
            'PushEvent' => 'push',
            'PullRequestEvent' => 'pr',
            'IssuesEvent' => 'issue',
            'CreateEvent' => 'create',
            'DeleteEvent' => 'delete',
            'ForkEvent' => 'fork',
            default => strtolower(str_replace('Event', '', $type)),
        };
    }

    /**
     * Extract action description from event payload.
     */
    private function extractEventAction(string $type, array $payload): string
    {
        return match ($type) {
            'PushEvent' => $this->extractPushAction($payload),
            'PullRequestEvent' => (string)($payload['action'] ?? 'opened'),
            'IssuesEvent' => (string)($payload['action'] ?? 'opened'),
            'CreateEvent' => (string)($payload['ref_type'] ?? 'created'),
            'DeleteEvent' => (string)($payload['ref_type'] ?? 'deleted'),
            'ForkEvent' => 'forked',
            default => '',
        };
    }

    /**
     * Extract push action summary.
     */
    private function extractPushAction(array $payload): string
    {
        $commits = $payload['commits'] ?? [];
        $size = is_array($commits) ? count($commits) : 0;

        if ($size === 0) {
            return 'pushed';
        }

        if ($size === 1) {
            return 'pushed 1 commit';
        }

        return "pushed {$size} commits";
    }

    /**
     * Extract title from event payload.
     */
    private function extractEventTitle(string $type, array $payload): string
    {
        return match ($type) {
            'PushEvent' => $this->extractPushTitle($payload),
            'PullRequestEvent' => (string)($payload['pull_request']['title'] ?? 'PR'),
            'IssuesEvent' => (string)($payload['issue']['title'] ?? 'Issue'),
            'CreateEvent' => (string)($payload['ref'] ?? 'created'),
            'DeleteEvent' => (string)($payload['ref'] ?? 'deleted'),
            'ForkEvent' => (string)($payload['forkee']['full_name'] ?? 'forked'),
            default => '',
        };
    }

    /**
     * Extract author from event payload.
     */
    private function extractEventAuthor(string $type, array $payload): string
    {
        return match ($type) {
            'PushEvent' => (string)($payload['commits'][0]['author']['name'] ?? ''),
            'PullRequestEvent' => (string)($payload['pull_request']['user']['login'] ?? ''),
            'IssuesEvent' => (string)($payload['issue']['user']['login'] ?? ''),
            'CreateEvent', 'DeleteEvent' => (string)($payload['actor']['login'] ?? ''),
            'ForkEvent' => (string)($payload['forkee']['owner']['login'] ?? ''),
            default => '',
        };
    }

    /**
     * Extract push commit title (first commit message or sha).
     */
    private function extractPushTitle(array $payload): string
    {
        $commits = $payload['commits'] ?? [];
        if (is_array($commits) && count($commits) > 0) {
            $msg = (string)($commits[0]['message'] ?? '');
            $firstLine = explode("\n", $msg)[0];
            return $firstLine ?: 'pushed commits';
        }

        return 'pushed commits';
    }

    /**
     * Parse time window string to timestamp.
     *
     * Examples: "24h" -> 24 hours ago, "7d" -> 7 days ago, "30d" -> 30 days ago
     */
    private function parseTimeWindow(string $timeWindow): int
    {
        $timeWindow = trim($timeWindow);
        $unit = substr($timeWindow, -1);
        $value = (int)substr($timeWindow, 0, -1);

        if ($value <= 0) {
            $value = 24;
        }

        switch ($unit) {
            case 'm': // minutes
                return time() - ($value * 60);
            case 'h': // hours
                return time() - ($value * 3600);
            case 'd': // days
                return time() - ($value * 86400);
            case 'w': // weeks
                return time() - ($value * 604800);
            default:
                // If no unit, assume hours
                return time() - (((int)$timeWindow) * 3600);
        }
    }

    /**
     * Extract branch name from commit URL.
     *
     * Note: Commit URLs (e.g., https://github.com/owner/repo/commit/abc123) do not
     * contain branch information. Branch info would require calling the GitHub API
     * to determine which branch contains the commit. For activity display purposes,
     * branch extraction is not necessary, so this method returns empty string.
     */
    private function extractBranchFromCommitUrl(string $url, string $repo): string
    {
        return '';
    }

    /**
     * Truncate message to specified length.
     */
    private function truncateMessage(string $message, int $length): string
    {
        // First line only
        $firstLine = strtok($message, "\n");
        if ($firstLine === false) {
            $firstLine = $message;
        }

        if (strlen($firstLine) <= $length) {
            return $firstLine;
        }

        return substr($firstLine, 0, $length - 3) . '...';
    }

    /**
     * Execute a shell command and return output.
     */
    private function executeCommand(string $command): ?string
    {
        // Pass through GitHub token if set to ensure gh CLI authentication works
        $token = $_ENV['GH_TOKEN'] ?? $_ENV['GITHUB_TOKEN'] ?? getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN') ?: null;
        if ($token !== null && $token !== '') {
            $escapedToken = escapeshellarg($token);
            $command = "GH_TOKEN={$escapedToken} GITHUB_TOKEN={$escapedToken} {$command}";
        }

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }

        return implode("\n", $output);
    }

    /**
     * Execute a command with retry logic for rate limit errors.
     */
    private function executeCommandWithRetry(string $command): ?string
    {
        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            $output = $this->executeCommand($command);

            // Check for rate limit errors
            if ($this->isRateLimitError($output)) {
                $retries++;
                if ($retries >= self::MAX_RETRIES) {
                    fwrite(STDERR, "Max retries exceeded for rate limit. Giving up.\n");
                    return null;
                }
                $wait = max(60, $this->rateLimitReset - time());
                fwrite(STDERR, "Rate limited (429/403). Waiting {$wait}s before retry {$retries}/" . self::MAX_RETRIES . "...\n");
                sleep($wait);
                continue;
            }

            return $output;
        }

        return null;
    }

    /**
     * Check if the output indicates a rate limit error.
     */
    private function isRateLimitError(?string $output): bool
    {
        if ($output === null || $output === '') {
            return false;
        }

        // Check for common rate limit error messages
        $rateLimitIndicators = [
            'rate limit',
            '403 Forbidden',
            '429 Too Many Requests',
            'API rate limit exceeded',
            'You have exceeded a secondary rate limit',
        ];

        $lowerOutput = strtolower($output);
        foreach ($rateLimitIndicators as $indicator) {
            if (str_contains($lowerOutput, strtolower($indicator))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check rate limit before making a request.
     */
    private function checkRateLimit(): void
    {
        // If we know we're rate limited, wait for reset
        if ($this->rateLimitRemaining === 0 && $this->rateLimitReset > time()) {
            $wait = $this->rateLimitReset - time();
            fwrite(STDERR, "Rate limit exhausted. Waiting {$wait}s for reset...\n");
            sleep($wait);
            $this->rateLimitRemaining = 5000; // Reset assumption
        }

        // Warn if approaching limit
        if ($this->rateLimitRemaining < self::RATE_LIMIT_WARNING_THRESHOLD && $this->rateLimitRemaining > 0) {
            fwrite(STDERR, "Warning: GitHub API rate limit low ({$this->rateLimitRemaining} remaining). Consider pausing.\n");
        }
    }

    /**
     * Sync rate limit state from gh CLI.
     */
    private function syncRateLimitFromGh(): void
    {
        $command = 'gh api rate_limit 2>/dev/null';
        $output = $this->executeCommand($command);
        if ($output === '' || $output === null) {
            return;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return;
        }

        $resources = $data['resources'] ?? [];
        $core = $resources['core'] ?? [];
        $this->rateLimitRemaining = (int)($core['remaining'] ?? 5000);
        $this->rateLimitReset = (int)($core['reset'] ?? 0);
    }

    /**
     * Apply delay between requests to avoid secondary rate limits.
     */
    private function applyRequestDelay(): void
    {
        usleep(self::DELAY_BETWEEN_REQUESTS_MS);
    }

    /**
     * Get the total number of API requests made.
     */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Get current rate limit information.
     *
     * @return array{remaining: int, reset: int, request_count: int}
     */
    public function getRateLimitInfo(): array
    {
        return [
            'remaining' => $this->rateLimitRemaining,
            'reset' => $this->rateLimitReset,
            'request_count' => $this->requestCount,
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
     * Get default organizations for this project.
     *
     * @return array<string>
     */
    public static function getDefaultOrgs(): array
    {
        return self::DEFAULT_ORGS;
    }
}
