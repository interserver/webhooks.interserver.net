<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webhooks\Cli\Interactor\InteractiveTui;
use Webhooks\Cli\Renderer\TableRenderer;
use Webhooks\Cli\Service\GithubActivityService;

/**
 * Show recent GitHub activity (commits, PRs) across repos.
 *
 * Displays activity from the authenticated user's repos and their organizations,
 * with filtering options for type, time window, and specific repos/users/orgs.
 */
class ActivityCommand extends AbstractCommand
{
    private GithubActivityService $activityService;
    private TableRenderer $tableRenderer;

    protected static ?string $defaultName = 'activity';

    public function __construct()
    {
        parent::__construct();
        $this->activityService = new GithubActivityService();
        $this->tableRenderer = new TableRenderer();
    }

    protected function configure(): void
    {
        $this->setName('activity');
        $this->setDescription('Show recent GitHub activity (commits, PRs) across repos');
        $this->setHelp(<<<'HELP'
The <info>activity</info> command shows recent GitHub activity (commits and PRs)
across repositories accessible to the authenticated user.

Default scope includes the user's repos plus organizations:
interserver, provirted, sugarcraft, detain

  <info>%command.full_name%</info>
  <info>%command.full_name% --since 24h</info>
  <info>%command.full_name% --type push</info>
  <info>%command.full_name% --type pr</info>
  <info>%command.full_name% --repo owner/repo</info>
  <info>%command.full_name% --org myorg</info>
  <info>%command.full_name% --user username</info>
  <info>%command.full_name% --limit 50</info>
  <info>%command.full_name% --json</info>

Options:
  <info>-r, --repo OWNER/REPO</info>   Specific repository
  <info>-t, --type TYPE</info>         Activity type: push, pr, or all (default: all)
  <info>-s, --since TIME</info>        Time window: 24h, 7d, 30d (default: 24h)
  <info>-l, --limit N</info>           Max items per repo (default: 30)
  <info>-o, --org ORG</info>           Filter by organization
  <info>-u, --user USER</info>         Filter by user/author
  <info>-n, --non-interactive</info>   Non-interactive mode (JSON/table output)
  <info>--json</info>                  Output as JSON
  <info>--include-private</info>       Include private repos (slower - queries each repo individually)

Interactive Mode:
  - Arrow/cursor through activity items
  - Press Enter to see details and trigger review
  - Press ESC or 'q' to go back/exit
  - Press 'r' to refresh

Non-Interactive Mode:
  - Outputs activity as JSON or table
  - Use --json for JSON output
HELP
        );

        $this->addOption(
            'repo',
            'r',
            InputOption::VALUE_REQUIRED,
            'Specific repository (owner/repo)'
        );

        $this->addOption(
            'type',
            't',
            InputOption::VALUE_REQUIRED,
            'Activity type: push, pr, or all (default: all)'
        );

        $this->addOption(
            'since',
            's',
            InputOption::VALUE_REQUIRED,
            'Time window: 24h, 7d, 30d (default: 24h)',
            '24h'
        );

        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Max items per repo (default: 30)',
            '30'
        );

        $this->addOption(
            'org',
            'o',
            InputOption::VALUE_REQUIRED,
            'Filter by organization'
        );

        $this->addOption(
            'user',
            'u',
            InputOption::VALUE_REQUIRED,
            'Filter by user/author'
        );

        $this->addOption(
            'non-interactive',
            null,
            InputOption::VALUE_NONE,
            'Non-interactive mode'
        );

        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output as JSON'
        );

        $this->addOption(
            'debug',
            'd',
            InputOption::VALUE_NONE,
            'Verbose debug output showing API calls and responses'
        );

        $this->addOption(
            'include-private',
            null,
            InputOption::VALUE_NONE,
            'Include private repos by querying each repo directly (slower but includes private activity)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check gh CLI availability
        if (!$this->activityService->isGhCliAvailable()) {
            $io->error('GitHub CLI (gh) is not installed or not in PATH.');
            return Command::FAILURE;
        }

        // Get user info
        $user = $this->activityService->getUser();
        if ($user === null) {
            $io->error('Not authenticated with GitHub. Run "gh auth login" first.');
            return Command::FAILURE;
        }

        $io->writeln(sprintf('<info>Authenticated as:</info> %s (%s)', $user['name'], $user['login']));

        // Enable verbose/debug output if requested
        $debug = (bool)$input->getOption('debug');
        if ($debug) {
            $this->activityService->setVerbose(true);
            $io->writeln('<info>Debug mode enabled</info>');
        }

        // Build options
        $options = $this->buildOptions($input);

        // Check --include-private flag early (before resolveRepos)
        $includePrivate = (bool)$input->getOption('include-private');

        // Get repos to query
        $repos = $this->resolveRepos($input, $io, $user, $includePrivate);

        if ($repos === [] && !$input->getOption('org') && !$input->getOption('repo')) {
            $io->warning('No repositories found.');
            return Command::SUCCESS;
        }

        // Use optimized path for org-wide queries (single API call via org events)
        // when no specific --repo is given
        // NOTE: Org events API only returns PUBLIC events - if most activity is in
        // private repos, use --include-private to query each repo directly
        $orgOption = $input->getOption('org');
        if (!$input->getOption('repo')) {
            // Fast path: use org events for each org (1 API call per org instead of per-repo)
            $orgs = is_string($orgOption) && $orgOption !== ''
                ? [$orgOption]
                : GithubActivityService::getDefaultOrgs();

            $io->writeln(sprintf('<info>Using fast org events endpoint for %d org(s) (public events only)...</info>', count($orgs)));

            // When filtering by type, we need more events since most org events are IssuesEvent
            // Use higher limit to ensure we get enough events of the requested type
            $typeFilter = $input->getOption('type');
            $isTypeFiltered = is_string($typeFilter) && $typeFilter !== '' && $typeFilter !== 'all';
            $fetchLimit = $isTypeFiltered ? 300 : (int)($options['limit'] ?? 100);

            $allActivity = [];
            foreach ($orgs as $org) {
                $orgActivity = $this->activityService->getOrgActivity($org, $fetchLimit);
                foreach ($orgActivity as $item) {
                    $allActivity[] = $item;
                }
                // Small delay between org queries to avoid rate limits
                usleep(100000); // 100ms
            }

            // Sort by date descending
            usort($allActivity, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });

            // Apply time filter (since option)
            $since = (string)($options['since'] ?? '24h');
            $allActivity = $this->filterByTime($allActivity, $since);

            // Apply type filter BEFORE overall limit
            if ($isTypeFiltered) {
                $allActivity = $this->filterByType($allActivity, $typeFilter);
            }

            // Apply limit
            $limit = (int)($options['limit'] ?? 100);
            $activity = array_slice($allActivity, 0, $limit);
        } else {
            // Slow path: per-repo queries
            $io->writeln(sprintf('<info>Querying %d repository(s)...</info>', count($repos)));
            $activity = $this->activityService->getActivity($repos, $options);

            // Apply type filter for slow path (getActivity doesn't filter by type internally)
            $typeFilter = $input->getOption('type');
            if (is_string($typeFilter) && $typeFilter !== '' && $typeFilter !== 'all') {
                $activity = $this->filterByType($activity, $typeFilter);
            }
        }

        // Apply user filter if specified (both paths)
        $userFilter = $input->getOption('user');
        if (is_string($userFilter) && $userFilter !== '') {
            $activity = $this->filterByUser($activity, $userFilter);
        }

        if ($activity === []) {
            $io->warning('No activity found in the specified time window.');
            return Command::SUCCESS;
        }

        // Output
        $asJson = (bool)$input->getOption('json');
        $nonInteractive = (bool)$input->getOption('non-interactive');

        if ($asJson || $nonInteractive) {
            return $this->outputNonInteractive($output, $activity, $asJson);
        }

        return $this->outputInteractive($input, $output, $activity);
    }

    /**
     * Build options array from input.
     *
     * @return array<string, mixed>
     */
    private function buildOptions(InputInterface $input): array
    {
        $type = $input->getOption('type');
        if (!is_string($type)) {
            $type = 'all';
        }

        if (!in_array($type, ['push', 'pr', 'all'], true)) {
            $type = 'all';
        }

        return [
            'type' => $type,
            'since' => (string)($input->getOption('since') ?? '24h'),
            'limit' => (int)($input->getOption('limit') ?? 30),
        ];
    }

    /**
     * Resolve repositories to query.
     *
     * @param array<string, mixed> $user
     * @return array<array{full_name: string, name: string, owner: string, url: string}>
     */
    private function resolveRepos(InputInterface $input, SymfonyStyle $io, array $user, bool $includePrivate = false): array
    {
        // Single repo specified
        $repoOption = $input->getOption('repo');
        if (is_string($repoOption) && $repoOption !== '') {
            return [[
                'full_name' => $repoOption,
                'name' => basename($repoOption),
                'owner' => dirname($repoOption),
                'url' => "https://github.com/{$repoOption}",
            ]];
        }

        // Single org specified
        $orgOption = $input->getOption('org');
        if (is_string($orgOption) && $orgOption !== '') {
            // Use getOrgRepos if includePrivate to get both public and private repos
            if ($includePrivate) {
                $allRepos = $this->activityService->getOrgRepos($orgOption, false);
                // Filter out the visibility field for compatibility
                return array_map(function ($repo) {
                    return [
                        'full_name' => $repo['full_name'],
                        'name' => $repo['name'],
                        'owner' => $repo['owner'],
                        'url' => $repo['url'],
                    ];
                }, $allRepos);
            }
            return $this->activityService->getRepos([['login' => $orgOption, 'url' => '']]);
        }

        // Default: user's repos + default orgs
        $orgs = [];
        foreach (GithubActivityService::getDefaultOrgs() as $orgLogin) {
            $orgs[] = ['login' => $orgLogin, 'url' => ''];
        }

        // When includePrivate, use getOrgRepos to get all repos including private ones
        if ($includePrivate) {
            $repos = [];
            foreach (GithubActivityService::getDefaultOrgs() as $orgLogin) {
                $orgRepos = $this->activityService->getOrgRepos($orgLogin, false);
                foreach ($orgRepos as $repo) {
                    $repos[] = [
                        'full_name' => $repo['full_name'],
                        'name' => $repo['name'],
                        'owner' => $repo['owner'],
                        'url' => $repo['url'],
                    ];
                }
            }
        } else {
            $repos = $this->activityService->getRepos($orgs);
        }

        // Also add user's repos
        $userRepos = $this->activityService->getRepos([]);
        foreach ($userRepos as $repo) {
            $exists = false;
            foreach ($repos as $existing) {
                if ($existing['full_name'] === $repo['full_name']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $repos[] = $repo;
            }
        }

        return $repos;
    }

    /**
     * Filter activity by user/author.
     *
     * @param array<array<string, mixed>> $activity
     * @return array<array<string, mixed>>
     */
    private function filterByUser(array $activity, string $user): array
    {
        $userLower = strtolower($user);

        return array_values(array_filter($activity, function ($item) use ($userLower) {
            $author = strtolower((string)($item['author'] ?? ''));
            return strpos($author, $userLower) !== false;
        }));
    }

    /**
     * Filter activity by type.
     *
     * @param array<array<string, mixed>> $activity
     * @return array<array<string, mixed>>
     */
    private function filterByType(array $activity, string $type): array
    {
        // Normalize type for matching
        $normalizedType = match ($type) {
            'push', 'commit' => 'push',
            'pr', 'pullrequest' => 'pr',
            'issue', 'issues' => 'issue',
            default => $type,
        };

        return array_values(array_filter($activity, function ($item) use ($normalizedType) {
            $itemType = (string)($item['type'] ?? '');
            return $itemType === $normalizedType;
        }));
    }

    /**
     * Filter activity by time window.
     *
     * @param array<array<string, mixed>> $activity
     * @return array<array<string, mixed>>
     */
    private function filterByTime(array $activity, string $since): array
    {
        $cutoff = $this->parseTimeWindow($since);

        return array_values(array_filter($activity, function ($item) use ($cutoff) {
            $dateStr = (string)($item['date'] ?? '');
            if ($dateStr === '') {
                return false;
            }
            $timestamp = strtotime($dateStr);
            return $timestamp !== false && $timestamp >= $cutoff;
        }));
    }

    /**
     * Parse time window string to timestamp.
     */
    private function parseTimeWindow(string $timeWindow): int
    {
        $timeWindow = trim($timeWindow);
        $unit = substr($timeWindow, -1);
        $value = (int)substr($timeWindow, 0, -1);

        if ($value <= 0) {
            $value = 24;
        }

        $multiplier = match ($unit) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
            default => 3600,
        };

        return time() - ($value * $multiplier);
    }

    /**
     * Output in non-interactive mode.
     */
    private function outputNonInteractive(OutputInterface $output, array $activity, bool $asJson): int
    {
        if ($asJson) {
            $json = json_encode([
                'activity' => $activity,
                'count' => count($activity),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln($json !== false ? $json : '{}');
            return Command::SUCCESS;
        }

        // Table output
        $this->tableRenderer->renderActivityList($output, $activity);
        return Command::SUCCESS;
    }

    /**
     * Output in interactive mode.
     */
    private function outputInteractive(InputInterface $input, OutputInterface $output, array $activity): int
    {
        // Check if we should enter interactive mode
        if (!$input->isInteractive()) {
            $this->tableRenderer->renderActivityList($output, $activity);
            return Command::SUCCESS;
        }

        // Use the new InteractiveTui for full keyboard navigation
        $tui = new InteractiveTui($output);

        // Determine if we're in single-repo mode (enables load more pagination)
        $repoOption = $input->getOption('repo');
        $isSingleRepo = is_string($repoOption) && $repoOption !== '';

        $loadMoreCallback = null;
        if ($isSingleRepo) {
            // Capture repos and options for the closure
            $repos = $this->resolveRepos($input, new SymfonyStyle($input, $output), $this->activityService->getUser() ?? [], false);
            $options = $this->buildOptions($input);

            $loadMoreCallback = function (int $page) use ($repos, $options): array {
                $options['page'] = $page;
                return $this->activityService->getActivity($repos, $options);
            };
        }

        $selection = $tui->run($activity, $loadMoreCallback);

        if ($selection === null) {
            return Command::SUCCESS;
        }

        $action = $selection['action'];

        if ($action === 'refresh') {
            // Re-run the command
            return $this->execute($input, $output);
        }

        if ($action === 'review' && isset($selection['item'])) {
            $item = $selection['item'];
            return $this->handleReviewAction($input, $output, $item);
        }

        return Command::SUCCESS;
    }

    /**
     * Handle review action for selected item.
     *
     * @param array<string, mixed> $item
     */
    private function handleReviewAction(InputInterface $input, OutputInterface $output, array $item): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $item['type'] ?? 'unknown';
        $repo = $item['repo'] ?? '';
        $io->writeln(sprintf('<info>Submitting %s for review:</info> %s', $type, $repo));

        if ($type === 'pr') {
            $number = $item['number'] ?? 0;
            $io->writeln(sprintf('  PR #%d: %s', $number, $item['title'] ?? ''));
            $io->writeln(sprintf('  URL: %s', $item['url'] ?? ''));

            // Call submit command internally
            $submitCommand = new SubmitCommand();
            $submitInput = new \Symfony\Component\Console\Input\ArrayInput([
                '--repo' => $repo,
                '--pr' => (string)$number,
                '--non-interactive' => true,
            ]);

            return $submitCommand->run($submitInput, $output);
        }

        if ($type === 'commit') {
            $sha = $item['sha'] ?? '';
            $io->writeln(sprintf('  Commit: %s', substr($sha, 0, 7)));
            $io->writeln(sprintf('  Branch: %s', $item['branch'] ?? 'unknown'));
            $io->writeln(sprintf('  URL: %s', $item['url'] ?? ''));

            // For commits, we need a PR number or we can create one
            $io->warning('Commit review requires a PR. Consider using the submit command directly:');
            $io->writeln(sprintf('  <info>github-review submit %s --commit %s</info>', $repo, $sha));
        }

        return Command::SUCCESS;
    }
}
