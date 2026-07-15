<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webhooks\Cli\Renderer\JsonRenderer;

/**
 * Submit PRs for code review.
 *
 * Handles enqueuing review jobs to the Redis queue with various options
 * for audit types, issue creation, branch creation, and change splitting.
 */
class SubmitCommand extends AbstractCommand
{
    private const VALID_AUDIT_TYPES = ['security', 'performance', 'documentation', 'logic', 'style', 'full'];

    private JsonRenderer $jsonRenderer;

    public function __construct()
    {
        parent::__construct();
        $this->jsonRenderer = new JsonRenderer();
    }

    protected static ?string $defaultName = 'submit';

    protected function configure(): void
    {
        $this->setName('submit');
        $this->setDescription('Submit a PR for code review');
        $this->setHelp(<<<'HELP'
The <info>submit</info> command enqueues a PR for code review.

  <info>%command.full_name% -r owner/repo --pr 42</info>
  <info>%command.full_name% -r owner/repo -p 42 --audit-types security,logic</info>
  <info>%command.full_name% -r owner/repo --all</info>
  <info>%command.full_name% -r owner/repo --pr 42 --post-diffs</info>
  <info>%command.full_name% -r owner/repo --pr 42 --dry-run</info>

Required:
  repo              Repository in owner/repo format (via -r/--repo)

Options:
  <info>-r, --repo OWNER/REPO</info>    Repository (owner/repo)
  <info>-p, --pr NUMBER</info>           PR number to review
  <info>-c, --commit SHA</info>          Review a specific commit SHA
  <info>-t, --audit-types TYPES</info>   Comma-separated: security,performance,
                                       documentation,logic,style,full (default: full)
  <info>--no-security</info>             Disable security audit
  <info>--no-performance</info>         Disable performance audit
  <info>--no-documentation</info>       Disable documentation audit
  <info>--no-logic</info>                Disable logic audit
  <info>--no-style</info>                Disable style audit
  <info>--issue-for-commits</info>       Create GitHub issue for problems found
  <info>--issue-label LABEL</info>        Labels for created issue (repeatable)
  <info>--issue-assignee USER</info>     Assignee for created issue
  <info>--post-diffs</info>               Post inline diffs as PR comments
  <info>--post-branch</info>             Create branch with fixes (mutually exclusive
                                       with --post-diffs)
  <info>--branch-name NAME</info>        Name for fix branch
  <info>--split-changes</info>           Split into multiple PRs
  <info>--split-batch-size N</info>      Max issues per split (default: 10)
  <info>--split-by STRATEGY</info>       Split by: file, audit, severity, size
  <info>--split-label LABEL</info>       Label for split PRs
  <info>--all</info>                     All open PRs from repo
  <info>--combine</info>                 Combine into single commit/comment
  <info>--dry-run</info>                 Preview without submitting
  <info>-n, --non-interactive</info>     Skip prompts
HELP
        );

        $this->addOption(
            'repo',
            'r',
            InputOption::VALUE_REQUIRED,
            'Repository (owner/repo)'
        );

        $this->addOption(
            'pr',
            'p',
            InputOption::VALUE_REQUIRED,
            'PR number'
        );

        $this->addOption(
            'commit',
            'c',
            InputOption::VALUE_REQUIRED,
            'Specific commit SHA to review'
        );

        $this->addOption(
            'audit-types',
            't',
            InputOption::VALUE_REQUIRED,
            'Comma-separated audit types (security,performance,documentation,logic,style,full)'
        );

        $this->addOption(
            'no-security',
            null,
            InputOption::VALUE_NONE,
            'Disable security audit'
        );

        $this->addOption(
            'no-performance',
            null,
            InputOption::VALUE_NONE,
            'Disable performance audit'
        );

        $this->addOption(
            'no-documentation',
            null,
            InputOption::VALUE_NONE,
            'Disable documentation audit'
        );

        $this->addOption(
            'no-logic',
            null,
            InputOption::VALUE_NONE,
            'Disable logic audit'
        );

        $this->addOption(
            'no-style',
            null,
            InputOption::VALUE_NONE,
            'Disable style audit'
        );

        $this->addOption(
            'issue-for-commits',
            null,
            InputOption::VALUE_NONE,
            'Create GitHub issue for problems in commits'
        );

        $this->addOption(
            'issue-label',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Labels for created issue'
        );

        $this->addOption(
            'issue-assignee',
            null,
            InputOption::VALUE_REQUIRED,
            'Assignee for created issue'
        );

        $this->addOption(
            'post-diffs',
            null,
            InputOption::VALUE_NONE,
            'Post inline diffs as PR comments'
        );

        $this->addOption(
            'post-branch',
            null,
            InputOption::VALUE_NONE,
            'Create branch with fixes (mutually exclusive with --post-diffs)'
        );

        $this->addOption(
            'branch-name',
            null,
            InputOption::VALUE_REQUIRED,
            'Name for fix branch'
        );

        $this->addOption(
            'split-changes',
            null,
            InputOption::VALUE_NONE,
            'Split into multiple PRs (mutually exclusive with --combine)'
        );

        $this->addOption(
            'split-batch-size',
            null,
            InputOption::VALUE_REQUIRED,
            'Max issues per split (default: 10)'
        );

        $this->addOption(
            'split-by',
            null,
            InputOption::VALUE_REQUIRED,
            'Split strategy: file, audit, severity, size'
        );

        $this->addOption(
            'split-label',
            null,
            InputOption::VALUE_REQUIRED,
            'Label for split PRs'
        );

        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'All open PRs from repository'
        );

        $this->addOption(
            'combine',
            null,
            InputOption::VALUE_NONE,
            'Combine into single commit/comment (mutually exclusive with --split-changes)'
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Preview without submitting'
        );

        $this->addOption(
            'non-interactive',
            null,
            InputOption::VALUE_NONE,
            'Skip prompts'
        );

        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output as JSON'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validate mutual exclusivity
        if ($input->getOption('post-diffs') && $input->getOption('post-branch')) {
            $io->error('--post-diffs and --post-branch are mutually exclusive.');
            return Command::FAILURE;
        }

        if ($input->getOption('split-changes') && $input->getOption('combine')) {
            $io->error('--split-changes and --combine are mutually exclusive.');
            return Command::FAILURE;
        }

        // Get repository
        $repo = $this->resolveRepo($input, $io);
        if ($repo === null) {
            return Command::FAILURE;
        }

        // Get PR numbers
        $prNumbers = $this->resolvePrNumbers($input, $io, $repo);
        if ($prNumbers === []) {
            return Command::FAILURE;
        }

        // Build audit types
        $auditTypes = $this->resolveAuditTypes($input);
        if ($auditTypes === []) {
            $io->error('No audit types enabled. Use --audit-types or remove --no-* flags.');
            return Command::FAILURE;
        }

        // Build job options
        $options = $this->buildJobOptions($input, $auditTypes);

        // Dry run mode
        if ($input->getOption('dry-run')) {
            $asJson = (bool)$input->getOption('json');
            $this->renderDryRun($output, $repo, $prNumbers, $auditTypes, $options, $asJson);
            return Command::SUCCESS;
        }

        // Enqueue jobs
        $enqueued = 0;
        $failed = 0;

        $redis = $this->getRedis();
        if ($redis === null) {
            $io->error('Redis connection failed');
            return Command::FAILURE;
        }

        foreach ($prNumbers as $prNumber) {
            $result = $this->enqueueReview($redis, $repo, (int)$prNumber, $auditTypes, $options, $input);
            if ($result) {
                $enqueued++;
            } else {
                $failed++;
            }
        }

        // Output result
        if ($input->getOption('json')) {
            $this->jsonRenderer->renderSubmitResult($output, [
                'submitted' => $enqueued,
                'failed' => $failed,
                'repository' => $repo,
                'pr_numbers' => $prNumbers,
                'audit_types' => $auditTypes,
                'options' => $options,
            ]);
            return Command::SUCCESS;
        }

        if ($failed === 0) {
            $io->success(sprintf(
                'Enqueued %d PR(s) for review: %s',
                $enqueued,
                $repo
            ));
        } else {
            $io->warning(sprintf(
                'Enqueued %d, failed %d: %s',
                $enqueued,
                $failed,
                $repo
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string>
     */
    private function resolveAuditTypes(InputInterface $input): array
    {
        $auditTypesOption = $input->getOption('audit-types');
        $explicitTypes = is_string($auditTypesOption) ? explode(',', $auditTypesOption) : [];

        $disabledTypes = [];
        if ($input->getOption('no-security')) {
            $disabledTypes[] = 'security';
        }
        if ($input->getOption('no-performance')) {
            $disabledTypes[] = 'performance';
        }
        if ($input->getOption('no-documentation')) {
            $disabledTypes[] = 'documentation';
        }
        if ($input->getOption('no-logic')) {
            $disabledTypes[] = 'logic';
        }
        if ($input->getOption('no-style')) {
            $disabledTypes[] = 'style';
        }

        // If explicit types specified, use those
        if ($explicitTypes !== []) {
            return array_map('trim', $explicitTypes);
        }

        // Otherwise start with full and remove disabled
        $types = self::VALID_AUDIT_TYPES;
        if (($key = array_search('full', $types)) !== false) {
            unset($types[$key]);
        }

        return array_values(array_diff($types, $disabledTypes));
    }

    /**
     * @return array<int>
     */
    private function resolvePrNumbers(
        InputInterface $input,
        SymfonyStyle $io,
        string $repo
    ): array {
        $prOption = $input->getOption('pr');
        $all = (bool)$input->getOption('all');

        if ($all) {
            // Get all open PRs via gh CLI
            return $this->fetchOpenPrNumbers($io, $repo);
        }

        if ($prOption !== null) {
            return [(int)$prOption];
        }

        // Interactive mode
        if (!$input->getOption('non-interactive') && $input->isInteractive()) {
            $prNumber = $io->ask('Enter PR number', null, function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                if (!is_numeric($value)) {
                    throw new \RuntimeException('PR number must be numeric');
                }
                return (int)$value;
            });

            if ($prNumber === null) {
                $io->error('PR number is required');
                return [];
            }

            return [$prNumber];
        }

        $io->error('PR number is required (use --pr or --all)');
        return [];
    }

    /**
     * @return array<int>
     */
    private function fetchOpenPrNumbers(SymfonyStyle $io, string $repo): array
    {
        $escapedRepo = escapeshellarg($repo);
        $command = "gh pr list --repo {$escapedRepo} --state open --json number --jq '.[].number' 2>/dev/null";

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            $io->warning("Could not fetch PRs for {$repo} via gh CLI");
            return [];
        }

        $prNumbers = [];
        foreach ($output as $line) {
            $prNumbers[] = (int)trim($line);
        }

        return $prNumbers;
    }

    private function resolveRepo(InputInterface $input, SymfonyStyle $io): ?string
    {
        $repo = $input->getOption('repo');

        if (is_string($repo) && $repo !== '') {
            if (!$this->isValidRepoFormat($repo)) {
                $io->error("Invalid repository format: {$repo}. Expected owner/repo");
                return null;
            }
            return $repo;
        }

        // Interactive mode
        if (!$input->getOption('non-interactive') && $input->isInteractive()) {
            $repo = $io->ask('Enter repository (owner/repo)', null, function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                if (!$this->isValidRepoFormat($value)) {
                    throw new \RuntimeException('Invalid format. Use owner/repo');
                }
                return $value;
            });

            if ($repo === null) {
                $io->error('Repository is required');
                return null;
            }

            return $repo;
        }

        $io->error('Repository is required (use --repo or pass as argument)');
        return null;
    }

    private function isValidRepoFormat(string $repo): bool
    {
        return preg_match('#^[^/]+/[^/]+$#', $repo) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJobOptions(InputInterface $input, array $auditTypes): array
    {
        $options = [
            'audit_types' => $auditTypes,
            'commit' => $input->getOption('commit'),
            'issue_for_commits' => $input->getOption('issue-for-commits'),
            'issue_labels' => $input->getOption('issue-label') ?? [],
            'issue_assignee' => $input->getOption('issue-assignee'),
            'post_diffs' => $input->getOption('post-diffs'),
            'post_branch' => $input->getOption('post-branch'),
            'branch_name' => $input->getOption('branch-name'),
            'split_changes' => $input->getOption('split-changes'),
            'split_batch_size' => (int)($input->getOption('split-batch-size') ?? 10),
            'split_by' => $input->getOption('split-by'),
            'split_label' => $input->getOption('split-label'),
            'combine' => $input->getOption('combine'),
        ];

        return array_filter($options, function ($value) {
            return $value !== null && $value !== false && $value !== '';
        });
    }

    /**
     * @param array<string> $auditTypes
     * @param array<string, mixed> $options
     * @return array{success: bool, id?: string, error?: string}
     */
    private function enqueueReview(
        \Predis\Client $redis,
        string $repo,
        int $prNumber,
        array $auditTypes,
        array $options,
        InputInterface $input
    ): array {
        // Look up the actual base branch from GitHub API instead of hardcoding
        $actualBaseBranch = $this->getPRBaseBranch($repo, $prNumber);

        $job = [
            'repo' => $repo,
            'pr_number' => $prNumber,
            'action' => 'submitted',
            'head_branch' => $options['commit'] ?? 'HEAD',
            'base_branch' => $actualBaseBranch,
            'pr_url' => sprintf('https://github.com/%s/pull/%d', $repo, $prNumber),
            'author' => 'cli-user',
            'author_url' => 'https://github.com/cli-user',
            'sha' => $options['commit'] ?? '',
            'source' => 'cli/submit',
            'audit_types' => $auditTypes,
            'options' => $options,
        ];

        $envelope = [
            'v' => \CodeReviewQueue::ENVELOPE_VERSION,
            'id' => $this->generateUuid(),
            'ts' => time(),
            'repo' => $job['repo'],
            'pr_number' => $job['pr_number'],
            'action' => $job['action'],
            'head_branch' => $job['head_branch'],
            'base_branch' => $job['base_branch'],
            'pr_url' => $job['pr_url'],
            'author' => $job['author'],
            'author_url' => $job['author_url'],
            'sha' => $job['sha'],
            'source' => $job['source'],
            'retry_count' => 0,
            'audit_types' => $auditTypes,
            'options' => $options,
        ];

        try {
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return ['success' => false, 'error' => 'JSON encode failed'];
            }

            $redis->lpush(\CodeReviewQueue::QUEUE_KEY, [$json]);
            $redis->incr(\CodeReviewQueue::METRICS_KEY);

            return ['success' => true, 'id' => $envelope['id']];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<int> $prNumbers
     * @param array<string> $auditTypes
     * @param array<string, mixed> $options
     */
    private function renderDryRun(
        OutputInterface $output,
        string $repo,
        array $prNumbers,
        array $auditTypes,
        array $options,
        bool $asJson = false
    ): void {
        if ($asJson) {
            $preview = [
                'repository' => $repo,
                'pr_numbers' => $prNumbers,
                'audit_types' => $auditTypes,
                'options' => $options,
            ];
            $this->jsonRenderer->renderDryRun($output, $preview);
            return;
        }

        $io = new SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $output
        );

        $io->section('Dry Run: Would Submit');
        $io->text([
            sprintf('Repository: %s', $repo),
            sprintf('PR Number(s): %s', implode(', ', $prNumbers)),
            sprintf('Audit Types: %s', implode(', ', $auditTypes)),
        ]);

        if ($options !== []) {
            $io->text('Options:');
            foreach ($options as $key => $value) {
                $io->text(sprintf('  %s: %s', $key, is_array($value) ? implode(', ', $value) : (string)$value));
            }
        }
    }

    /**
     * Look up the actual base branch for a PR via GitHub API
     */
    private function getPRBaseBranch(string $repo, int $prNumber): string
    {
        $output = [];
        $ret = 0;
        $cmd = sprintf(
            'gh api repos/%s/pulls/%d --jq .base.ref 2>&1',
            escapeshellarg($repo),
            $prNumber
        );
        exec($cmd, $output, $ret);
        if ($ret === 0 && !empty($output)) {
            $branch = trim(implode("\n", $output));
            if ($branch !== '') {
                return $branch;
            }
        }
        return 'main';
    }

    private function generateUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
