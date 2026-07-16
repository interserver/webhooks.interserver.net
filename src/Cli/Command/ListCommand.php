<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * List queued code review jobs.
 *
 * Read-only peek into the Redis queue (uses LRANGE, not RPOP).
 * Safe to run while the worker is processing.
 */
class ListCommand extends AbstractCommand
{
    protected static ?string $defaultName = 'list';

    protected function configure(): void
    {
        $this->setName('list');
        $this->setDescription('List queued code review jobs');
        $this->setHelp(<<<'HELP'
The <info>list</info> command shows pending code review jobs in the queue.

Read-only operation using LRANGE - safe to run while worker is processing.

  <info>%command.full_name%</info>
  <info>%command.full_name% --limit 20</info>
  <info>%command.full_name% --repo owner/repo</info>
  <info>%command.full_name% --json</info>
  <info>%command.full_name% --detailed</info>

Options:
  <info>--limit N</info>       Show at most N entries (default: 50, newest first)
  <info>--repo OWNER/REPO</info>  Filter to a single repository
  <info>--event-type TYPE</info>  Filter by source type (push, pr, check_run, etc.)
  <info>--action ACTION</info>    Filter by action (opened, synchronize, closed, etc.)
  <info>--json</info>          Output as JSON
  <info>-d, --detailed</info>  Include id and PR URL in output
HELP
        );

        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Maximum number of entries to show',
            '50'
        );

        $this->addOption(
            'repo',
            'r',
            InputOption::VALUE_REQUIRED,
            'Filter by repository (owner/repo)'
        );

        $this->addOption(
            'event-type',
            'e',
            InputOption::VALUE_REQUIRED,
            'Filter by source type'
        );

        $this->addOption(
            'action',
            'a',
            InputOption::VALUE_REQUIRED,
            'Filter by action'
        );

        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output as JSON'
        );

        $this->addOption(
            'detailed',
            'd',
            InputOption::VALUE_NONE,
            'Include id and PR URL in output'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            $output->writeln('<error>Redis connection failed</error>');
            return Command::FAILURE;
        }

        $limit = max(1, (int)$input->getOption('limit'));
        $repoFilter = $input->getOption('repo');
        $eventTypeFilter = $input->getOption('event-type');
        $actionFilter = $input->getOption('action');
        $asJson = (bool)$input->getOption('json');
        $verbose = $input->getOption('detailed') ?? false;

        try {
            $queueLen = (int)$redis->llen($this->getQueueKey());
            $enqueued = (int)($redis->get($this->getMetricsKey()) ?? 0);
        } catch (\Throwable $e) {
            $output->writeln('<error>Redis error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // LRANGE 0 N-1 — newest-first because workers RPOP and producers LPUSH
        $stop = $limit - 1;
        $raw = [];
        try {
            $raw = $redis->lrange($this->getQueueKey(), 0, $stop);
        } catch (\Throwable $e) {
            $output->writeln('<error>Redis LRANGE failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $entries = [];
        foreach ($raw as $json) {
            $env = json_decode($json, true);
            if (!is_array($env)) {
                continue;
            }

            // Apply filters
            if ($repoFilter !== null && ($env['repo'] ?? '') !== $repoFilter) {
                continue;
            }
            if ($actionFilter !== null && ($env['action'] ?? '') !== $actionFilter) {
                continue;
            }
            // event-type filter uses type field (GitHub event type like push, pull_request, etc.)
            if ($eventTypeFilter !== null && ($env['type'] ?? '') !== $eventTypeFilter) {
                continue;
            }

            $entries[] = $env;
        }

        if ($asJson) {
            $this->outputJson($output, $queueLen, $enqueued, $entries, $limit);
            return Command::SUCCESS;
        }

        $this->outputHuman($output, $queueLen, $enqueued, $entries, $limit, $verbose);

        return Command::SUCCESS;
    }

    /**
     * @param array<array<string, mixed>> $entries
     */
    private function outputJson(
        OutputInterface $output,
        int $queueLen,
        int $enqueued,
        array $entries,
        int $limit
    ): void {
        $data = [
            'queue_key' => $this->getQueueKey(),
            'queue_depth' => $queueLen,
            'total_enqueued' => $enqueued,
            'shown' => count($entries),
            'limit' => $limit,
            'entries' => $entries,
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $output->writeln($json !== false ? $json : '{}');
    }

    /**
     * @param array<array<string, mixed>> $entries
     */
    private function outputHuman(
        OutputInterface $output,
        int $queueLen,
        int $enqueued,
        array $entries,
        int $limit,
        bool $verbose
    ): void {
        $output->writeln(sprintf(
            'Queue: %s   depth=%d   total_enqueued=%d   shown=%d/%d',
            $this->getQueueKey(),
            $queueLen,
            $enqueued,
            count($entries),
            $limit
        ));
        $output->writeln(str_repeat('-', 100));

        if ($entries === []) {
            $output->writeln('(no entries)');
            return;
        }

        foreach ($entries as $i => $e) {
            $ts = (int)($e['ts'] ?? 0);
            $when = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '?';
            $age = $this->formatAge($ts);
            $action = $e['action'] ?? '?';
            $repo = $e['repo'] ?? '?';
            $prNum = $e['pr_number'] ?? '?';
            $head = $e['head_branch'] ?? '?';
            $base = $e['base_branch'] ?? '?';
            $author = $e['author'] ?? '?';
            $sha = substr((string)($e['sha'] ?? ''), 0, 7);
            $retries = (int)($e['retry_count'] ?? 0);
            $id = $e['id'] ?? '?';

            $output->writeln(sprintf('[%2d] %s  (%s ago)', $i, $when, $age));
            $output->writeln(sprintf(
                '     %s#%s  %s  %s -> %s  by %s%s',
                $repo,
                $prNum,
                $action,
                $head,
                $base,
                $author,
                $sha !== '' ? "  sha={$sha}" : ''
            ));

            if ($retries > 0) {
                $output->writeln(sprintf('     retry_count=%d', $retries));
            }

            if ($verbose) {
                $output->writeln(sprintf('     id=%s', $id));
                if (!empty($e['pr_url'])) {
                    $output->writeln(sprintf('     %s', $e['pr_url']));
                }
            }

            $output->writeln('');
        }
    }
}
