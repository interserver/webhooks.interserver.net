<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Show queue statistics and metrics.
 */
class MetricsCommand extends AbstractCommand
{
    protected static ?string $defaultName = 'metrics';

    protected function configure(): void
    {
        $this->setName('metrics');
        $this->setDescription('Show queue statistics and metrics');
        $this->setHelp(<<<'HELP'
The <info>metrics</info> command shows queue statistics.

  <info>%command.full_name%</info>
  <info>%command.full_name% --json</info>

Options:
  <info>--json</info>  Output metrics as JSON

The metrics include:
  - Queue depth (current number of pending jobs)
  - Total enqueued (lifetime counter)
  - Repository breakdown (count per repository)
  - Action breakdown (count per action type)
HELP
        );

        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output metrics as JSON'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            $output->writeln('<error>Redis connection failed</error>');
            return Command::FAILURE;
        }

        try {
            $queueLen = (int)$redis->llen($this->getQueueKey());
            $enqueued = (int)($redis->get($this->getMetricsKey()) ?? 0);
        } catch (\Throwable $e) {
            $output->writeln('<error>Redis error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Get all entries for breakdown stats (limited to 1000 for performance)
        $raw = [];
        try {
            $raw = $redis->lrange($this->getQueueKey(), 0, 999);
        } catch (\Throwable $e) {
            // Non-fatal - we still have queue depth and total
        }

        $repoBreakdown = [];
        $actionBreakdown = [];
        $totalRetries = 0;

        foreach ($raw as $json) {
            $env = json_decode($json, true);
            if (!is_array($env)) {
                continue;
            }

            $repo = $env['repo'] ?? 'unknown';
            $action = $env['action'] ?? 'unknown';
            $retryCount = (int)($env['retry_count'] ?? 0);

            $repoBreakdown[$repo] = ($repoBreakdown[$repo] ?? 0) + 1;
            $actionBreakdown[$action] = ($actionBreakdown[$action] ?? 0) + 1;
            $totalRetries += $retryCount;
        }

        $asJson = (bool)$input->getOption('json');

        if ($asJson) {
            $this->outputJson($output, $queueLen, $enqueued, $repoBreakdown, $actionBreakdown, $totalRetries);
            return Command::SUCCESS;
        }

        $this->outputHuman($output, $queueLen, $enqueued, $repoBreakdown, $actionBreakdown, $totalRetries);

        return Command::SUCCESS;
    }

    private function outputJson(
        OutputInterface $output,
        int $queueLen,
        int $enqueued,
        array $repoBreakdown,
        array $actionBreakdown,
        int $totalRetries
    ): void {
        $data = [
            'queue_key' => $this->getQueueKey(),
            'queue_depth' => $queueLen,
            'total_enqueued' => $enqueued,
            'redis_host' => $this->getRedisHost(),
            'redis_port' => $this->getRedisPort(),
            'by_repository' => $repoBreakdown,
            'by_action' => $actionBreakdown,
            'total_retries' => $totalRetries,
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $output->writeln($json !== false ? $json : '{}');
    }

    private function outputHuman(
        OutputInterface $output,
        int $queueLen,
        int $enqueued,
        array $repoBreakdown,
        array $actionBreakdown,
        int $totalRetries
    ): void {
        $output->writeln(sprintf('Queue: %s', $this->getQueueKey()));
        $output->writeln(sprintf('Redis: %s:%d', $this->getRedisHost(), $this->getRedisPort()));
        $output->writeln('');
        $output->writeln(sprintf('  Queue Depth:     %d', $queueLen));
        $output->writeln(sprintf('  Total Enqueued:  %d', $enqueued));
        $output->writeln(sprintf('  Total Retries:   %d', $totalRetries));

        if ($repoBreakdown !== []) {
            $output->writeln('');
            $output->writeln('  By Repository:');
            arsort($repoBreakdown);
            foreach ($repoBreakdown as $repo => $count) {
                $output->writeln(sprintf('    %-40s %d', $repo, $count));
            }
        }

        if ($actionBreakdown !== []) {
            $output->writeln('');
            $output->writeln('  By Action:');
            arsort($actionBreakdown);
            foreach ($actionBreakdown as $action => $count) {
                $output->writeln(sprintf('    %-20s %d', $action, $count));
            }
        }
    }
}
