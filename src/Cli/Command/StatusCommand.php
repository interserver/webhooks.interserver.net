<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webhooks\Cli\Renderer\JsonRenderer;
use Webhooks\Cli\Renderer\TableRenderer;

/**
 * Poll for review completion and show status.
 */
class StatusCommand extends AbstractCommand
{
    private JsonRenderer $jsonRenderer;
    private TableRenderer $tableRenderer;

    public function __construct()
    {
        parent::__construct();
        $this->jsonRenderer = new JsonRenderer();
        $this->tableRenderer = new TableRenderer();
    }

    protected static ?string $defaultName = 'status';

    protected function configure(): void
    {
        $this->setName('status');
        $this->setDescription('Check review status');
        $this->setHelp(<<<'HELP'
The <info>status</info> command shows the status of submitted reviews.

  <info>%command.full_name% --id abc123</info>
  <info>%command.full_name% --repo owner/repo</info>
  <info>%command.full_name% --repo owner/repo --pr 42</info>
  <info>%command.full_name% --watch</info>
  <info>%command.full_name% --json</info>

Options:
  <info>-i, --id UUID</info>       Check specific job by ID
  <info>-r, --repo OWNER/REPO</info>  Filter by repository
  <info>-p, --pr NUMBER</info>       Filter by PR number
  <info>-w, --watch</info>          Poll for updates continuously
  <info>--json</info>               Output as JSON
HELP
        );

        $this->addOption(
            'id',
            'i',
            InputOption::VALUE_REQUIRED,
            'Check specific job by ID'
        );

        $this->addOption(
            'repo',
            'r',
            InputOption::VALUE_REQUIRED,
            'Filter by repository'
        );

        $this->addOption(
            'pr',
            'p',
            InputOption::VALUE_REQUIRED,
            'Filter by PR number'
        );

        $this->addOption(
            'watch',
            'w',
            InputOption::VALUE_NONE,
            'Poll for updates continuously'
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

        $jobId = $input->getOption('id');
        $repo = $input->getOption('repo');
        $prNumber = $input->getOption('pr');
        $watch = (bool)$input->getOption('watch');
        $asJson = (bool)$input->getOption('json');

        if ($watch && $jobId === null) {
            $io->error('--watch requires --id');
            return Command::FAILURE;
        }

        $redis = $this->getRedis();
        if ($redis === null) {
            $io->error('Redis connection failed');
            return Command::FAILURE;
        }

        if ($jobId !== null) {
            return $this->showById($input, $output, $redis, $jobId, $watch, $asJson);
        }

        return $this->showByFilters($input, $output, $redis, $repo, $prNumber, $asJson);
    }

    private function showById(
        InputInterface $input,
        OutputInterface $output,
        \Predis\Client $redis,
        string $jobId,
        bool $watch,
        bool $asJson
    ): int {
        $io = new SymfonyStyle($input, $output);

        if ($watch) {
            return $this->watchById($input, $output, $redis, $jobId, $asJson);
        }

        $status = $this->fetchById($redis, $jobId);
        if ($status === null) {
            $io->warning("Job not found: {$jobId}");
            return Command::FAILURE;
        }

        if ($asJson) {
            $this->jsonRenderer->renderStatusItem($output, $status);
            return Command::SUCCESS;
        }

        $this->tableRenderer->renderStatusItem($output, $status);
        return Command::SUCCESS;
    }

    private function watchById(
        InputInterface $input,
        OutputInterface $output,
        \Predis\Client $redis,
        string $jobId,
        bool $asJson
    ): int {
        $io = new SymfonyStyle($input, $output);
        $lastStatus = null;

        while (true) {
            $status = $this->fetchById($redis, $jobId);

            if ($status === null) {
                $io->warning("Job not found: {$jobId}");
                break;
            }

            $currentStatus = $status['status'] ?? 'unknown';

            if ($currentStatus !== $lastStatus) {
                if (!$asJson) {
                    $output->writeln('');
                    $output->writeln(sprintf(
                        '<info>Status changed to: %s</info>',
                        $currentStatus
                    ));
                }
                $lastStatus = $currentStatus;
            }

            if (in_array($currentStatus, ['completed', 'failed', 'cancelled'], true)) {
                if ($asJson) {
                    $this->jsonRenderer->renderStatusItem($output, $status);
                } else {
                    $this->tableRenderer->renderStatusItem($output, $status);
                }
                return Command::SUCCESS;
            }

            if (!$asJson) {
                $output->write("\r  Watching... " . date('H:i:s'));
            }

            sleep(5);
        }

        return Command::SUCCESS;
    }

    private function showByFilters(
        InputInterface $input,
        OutputInterface $output,
        \Predis\Client $redis,
        ?string $repo,
        ?string $prNumber,
        bool $asJson
    ): int {
        $since = $input->getOption('since') ?? null;

        $statuses = $this->fetchByFilters($redis, $repo, $prNumber, $since);

        if ($statuses === []) {
            $output->writeln('<info>No review jobs found.</info>');
            return Command::SUCCESS;
        }

        if ($asJson) {
            $this->jsonRenderer->renderStatusList($output, $statuses);
            return Command::SUCCESS;
        }

        $this->tableRenderer->renderStatusList($output, $statuses);
        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchById(\Predis\Client $redis, string $jobId): ?array
    {
        // Check status store - separate key for job status
        $statusKey = 'codereview:status:' . $jobId;
        $json = $redis->get($statusKey);

        if ($json !== null) {
            $status = json_decode($json, true);
            if (is_array($status)) {
                return $status;
            }
        }

        // Fall back to scanning queue
        $raw = $redis->lrange(\CodeReviewQueue::QUEUE_KEY, 0, -1);

        foreach ($raw as $item) {
            $env = json_decode($item, true);
            if (!is_array($env)) {
                continue;
            }

            if (($env['id'] ?? '') === $jobId) {
                return [
                    'id' => $env['id'] ?? $jobId,
                    'repo' => $env['repo'] ?? 'unknown',
                    'pr_number' => $env['pr_number'] ?? 0,
                    'status' => 'queued',
                    'severity' => 'info',
                    'issues_count' => 0,
                    'created_at' => $env['ts'] ?? time(),
                    'source' => $env['source'] ?? 'unknown',
                ];
            }
        }

        return null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function fetchByFilters(
        \Predis\Client $redis,
        ?string $repo,
        ?string $prNumber,
        ?int $since
    ): array {
        $statuses = [];
        $raw = $redis->lrange(\CodeReviewQueue::QUEUE_KEY, 0, 999);

        foreach ($raw as $item) {
            $env = json_decode($item, true);
            if (!is_array($env)) {
                continue;
            }

            // Apply filters
            if ($repo !== null && ($env['repo'] ?? '') !== $repo) {
                continue;
            }

            if ($prNumber !== null && (string)($env['pr_number'] ?? '') !== (string)$prNumber) {
                continue;
            }

            if ($since !== null && (int)($env['ts'] ?? 0) < $since) {
                continue;
            }

            $statuses[] = [
                'id' => $env['id'] ?? '',
                'repo' => $env['repo'] ?? 'unknown',
                'pr_number' => $env['pr_number'] ?? 0,
                'status' => 'queued',
                'severity' => 'info',
                'issues_count' => 0,
                'created_at' => $env['ts'] ?? time(),
                'source' => $env['source'] ?? 'unknown',
                'head_branch' => $env['head_branch'] ?? '',
                'base_branch' => $env['base_branch'] ?? '',
                'action' => $env['action'] ?? 'submitted',
            ];
        }

        // Also check completed status from status store
        $pattern = 'codereview:status:*';
        $keys = $redis->keys($pattern);

        foreach ($keys as $key) {
            $json = $redis->get($key);
            if ($json === null) {
                continue;
            }

            $status = json_decode($json, true);
            if (!is_array($status)) {
                continue;
            }

            if ($repo !== null && ($status['repo'] ?? '') !== $repo) {
                continue;
            }

            if ($prNumber !== null && (string)($status['pr_number'] ?? '') !== (string)$prNumber) {
                continue;
            }

            $statuses[] = $status;
        }

        return $statuses;
    }
}
