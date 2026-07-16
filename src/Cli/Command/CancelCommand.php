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
 * Cancel queued review jobs.
 */
class CancelCommand extends AbstractCommand
{
    private JsonRenderer $jsonRenderer;
    private TableRenderer $tableRenderer;

    public function __construct()
    {
        parent::__construct();
        $this->jsonRenderer = new JsonRenderer();
        $this->tableRenderer = new TableRenderer();
    }

    protected static ?string $defaultName = 'cancel';

    protected function configure(): void
    {
        $this->setName('cancel');
        $this->setDescription('Cancel queued review jobs');
        $this->setHelp(<<<'HELP'
The <info>cancel</info> command removes jobs from the queue.

  <info>%command.full_name% --id abc123</info>
  <info>%command.full_name% --id abc123 --id def456</info>
  <info>%command.full_name% --all</info>
  <info>%command.full_name% --repo owner/repo</info>
  <info>%command.full_name% --all --dry-run</info>

Options:
  <info>-i, --id UUID</info>       Job ID to cancel (repeatable)
  <info>--repo OWNER/REPO</info>    Cancel all jobs for repository
  <info>--all</info>               Cancel all jobs
  <info>--dry-run</info>           Show what would be cancelled
  <info>--json</info>              Output as JSON
HELP
        );

        $this->addOption(
            'id',
            'i',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Job ID(s) to cancel'
        );

        $this->addOption(
            'repo',
            'r',
            InputOption::VALUE_REQUIRED,
            'Cancel all jobs for repository'
        );

        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Cancel all queued jobs'
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be cancelled'
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

        $jobIds = $input->getOption('id') ?? [];
        $repo = $input->getOption('repo');
        $all = (bool)$input->getOption('all');
        $dryRun = (bool)$input->getOption('dry-run');
        $asJson = (bool)$input->getOption('json');

        // Validate
        if ($jobIds === [] && $repo === null && !$all) {
            $io->error('Specify --id, --repo, or --all');
            return Command::FAILURE;
        }

        if ($all && $repo !== null) {
            $io->error('--all and --repo are mutually exclusive');
            return Command::FAILURE;
        }

        $redis = $this->getRedis();
        if ($redis === null) {
            $io->error('Redis connection failed');
            return Command::FAILURE;
        }

        // Find jobs to cancel
        $jobs = $this->findJobs($redis, $jobIds, $repo, $all);

        if ($jobs === []) {
            $output->writeln('<info>No jobs found to cancel.</info>');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->showDryRun($output, $jobs, $asJson);
            return Command::SUCCESS;
        }

        // Perform cancellation
        $cancelled = $this->cancelJobs($redis, $jobs);

        if ($asJson) {
            $this->jsonRenderer->renderCancelResult($output, $cancelled, $all);
            return Command::SUCCESS;
        }

        $io->success(sprintf('Cancelled %d job(s)', count($cancelled)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string> $jobIds
     * @return array<array<string, mixed>>
     */
    private function findJobs(
        \Predis\Client $redis,
        array $jobIds,
        ?string $repo,
        bool $all
    ): array {
        $jobs = [];
        $raw = $redis->lrange(\CodeReviewQueue::QUEUE_KEY, 0, -1);

        foreach ($raw as $item) {
            $env = json_decode($item, true);
            if (!is_array($env)) {
                continue;
            }

            $jobId = $env['id'] ?? '';

            // Filter by specific IDs
            if ($jobIds !== []) {
                if (in_array($jobId, $jobIds, true)) {
                    $jobs[] = $env;
                }
                continue;
            }

            // Filter by repo
            if ($repo !== null) {
                if (($env['repo'] ?? '') === $repo) {
                    $jobs[] = $env;
                }
                continue;
            }

            // All jobs
            if ($all) {
                $jobs[] = $env;
            }
        }

        return $jobs;
    }

    /**
     * @param array<array<string, mixed>> $jobs
     * @return array<array<string, mixed>>
     */
    private function cancelJobs(\Predis\Client $redis, array $jobs): array
    {
        $cancelled = [];

        foreach ($jobs as $job) {
            $jobId = $job['id'] ?? '';
            if ($jobId === '') {
                continue;
            }

            // Remove from queue by re-reading and filtering
            // Since Redis LPUSH/LRANGE doesn't support direct removal by content,
            // we need to rebuild the list
            try {
                $raw = $redis->lrange(\CodeReviewQueue::QUEUE_KEY, 0, -1);
                $newList = [];

                foreach ($raw as $item) {
                    $env = json_decode($item, true);
                    if (!is_array($env)) {
                        continue;
                    }

                    if (($env['id'] ?? '') === $jobId) {
                        $cancelled[] = [
                            'id' => $jobId,
                            'repo' => $env['repo'] ?? 'unknown',
                            'pr_number' => $env['pr_number'] ?? 0,
                            'cancelled_at' => time(),
                        ];
                        continue;
                    }

                    $newList[] = $item;
                }

                // Replace the entire list (atomic operation)
                if ($newList === []) {
                    $redis->del(\CodeReviewQueue::QUEUE_KEY);
                } else {
                    $redis->del(\CodeReviewQueue::QUEUE_KEY);
                    $redis->lpush(\CodeReviewQueue::QUEUE_KEY, $newList);
                }
            } catch (\Throwable $e) {
                // Log but continue with other jobs
                error_log('Cancel failed for job ' . $jobId . ': ' . $e->getMessage());
            }
        }

        return $cancelled;
    }

    /**
     * @param array<array<string, mixed>> $jobs
     */
    private function showDryRun(
        OutputInterface $output,
        array $jobs,
        bool $asJson
    ): void {
        if ($asJson) {
            $this->jsonRenderer->renderDryRun($output, [
                'would_cancel' => $jobs,
                'count' => count($jobs),
            ]);
            return;
        }

        $output->writeln('<comment>Dry Run: Would cancel the following jobs:</comment>');
        $output->writeln('');
        $this->tableRenderer->renderCancelList($output, $jobs, false);
    }
}
