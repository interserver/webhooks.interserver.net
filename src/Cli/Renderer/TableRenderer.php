<?php
declare(strict_types=1);

namespace Webhooks\Cli\Renderer;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\TableCell;

/**
 * ANSI table output renderer for CLI commands.
 */
final class TableRenderer
{
    private const SEVERITY_ICONS = [
        'error' => '🔴',
        'warning' => '🟡',
        'info' => '🔵',
        'hint' => '💡',
    ];

    private const STATUS_ICONS = [
        'queued' => '⏳',
        'processing' => '🔄',
        'completed' => '✅',
        'failed' => '❌',
        'cancelled' => '🚫',
    ];

    /**
     * Render a list of queue entries as an ANSI table.
     *
     * @param array<array<string, mixed>> $entries
     */
    public function renderQueueList(
        OutputInterface $output,
        array $entries,
        bool $verbose = false
    ): void {
        if (empty($entries)) {
            $output->writeln('<comment>(no entries)</comment>');
            return;
        }

        $table = new Table($output);
        $table->setStyle('box-double');

        if ($verbose) {
            $table->setHeaders(['#', 'When', 'Repo', 'PR', 'Action', 'Branch', 'Author', 'SHA', 'ID']);
            $table->setRows($this->formatVerboseRows($entries));
        } else {
            $table->setHeaders(['#', 'When', 'Repo', 'PR', 'Action', 'Head → Base', 'Author']);
            $table->setRows($this->formatRows($entries));
        }

        $table->render();
    }

    /**
     * Render review status as an ANSI table.
     *
     * @param array<array<string, mixed>> $statuses
     */
    public function renderStatusList(
        OutputInterface $output,
        array $statuses
    ): void {
        $table = new Table($output);
        $table->setStyle('box-double');
        $table->setHeaders(['ID', 'Repo', 'PR', 'Status', 'Severity', 'Issues', 'Created']);
        $table->setRows($this->formatStatusRows($statuses));
        $table->render();
    }

    /**
     * Render a single status item.
     *
     * @param array<string, mixed> $status
     */
    public function renderStatusItem(
        OutputInterface $output,
        array $status
    ): void {
        $id = $status['id'] ?? 'unknown';
        $repo = $status['repo'] ?? '?';
        $prNumber = $status['pr_number'] ?? '?';
        $statusValue = $status['status'] ?? 'unknown';
        $severity = $status['severity'] ?? 'info';
        $issues = $status['issues_count'] ?? 0;
        $created = $status['created_at'] ?? time();
        $completedAt = $status['completed_at'] ?? null;

        $icon = self::STATUS_ICONS[$statusValue] ?? '⚪';
        $severityIcon = self::SEVERITY_ICONS[$severity] ?? '🔵';

        $output->writeln(sprintf('<info>%s</info> Review Status', $icon));
        $output->writeln(str_repeat('─', 60));
        $output->writeln(sprintf('  ID:         %s', $id));
        $output->writeln(sprintf('  Repository: %s', $repo));
        $output->writeln(sprintf('  PR Number:  %s', $prNumber));
        $output->writeln(sprintf('  Status:      %s %s', $icon, $statusValue));
        $output->writeln(sprintf('  Severity:    %s %s', $severityIcon, $severity));
        $output->writeln(sprintf('  Issues:      %d', $issues));
        $output->writeln(sprintf('  Created:     %s', date('Y-m-d H:i:s', (int)$created)));

        if ($completedAt !== null) {
            $output->writeln(sprintf('  Completed:   %s', date('Y-m-d H:i:s', (int)$completedAt)));
        }

        if (!empty($status['issues'])) {
            $output->writeln('');
            $output->writeln('  <comment>Issues:</comment>');
            foreach ($status['issues'] as $issue) {
                $this->renderIssue($output, $issue);
            }
        }
    }

    /**
     * Render a summary table for metrics.
     *
     * @param array<string, int> $repoBreakdown
     * @param array<string, int> $actionBreakdown
     */
    public function renderMetrics(
        OutputInterface $output,
        int $queueDepth,
        int $totalEnqueued,
        array $repoBreakdown,
        array $actionBreakdown
    ): void {
        $table = new Table($output);
        $table->setStyle('box-double');

        $table->setHeaders(['Metric', 'Value']);
        $table->setRows([
            ['Queue Depth', (string)$queueDepth],
            ['Total Enqueued', (string)$totalEnqueued],
        ]);

        $table->render();

        if ($repoBreakdown !== []) {
            $output->writeln('');
            $this->renderBreakdown($output, 'By Repository', $repoBreakdown);
        }

        if ($actionBreakdown !== []) {
            $output->writeln('');
            $this->renderBreakdown($output, 'By Action', $actionBreakdown);
        }
    }

    /**
     * Render a cancel confirmation table.
     *
     * @param array<array<string, mixed>> $jobs
     */
    public function renderCancelList(
        OutputInterface $output,
        array $jobs,
        bool $cancelled = false
    ): void {
        $table = new Table($output);
        $table->setStyle('box-double');

        $table->setHeaders(['ID', 'Repo', 'PR', 'Action', 'Branch']);
        $table->setRows($this->formatCancelRows($jobs));

        $table->render();

        $output->writeln(sprintf(
            '<info>%d job(s) %s.</info>',
            count($jobs),
            $cancelled ? 'cancelled' : 'found'
        ));
    }

    /**
     * @param array<array<string, mixed>> $entries
     * @return array<array<string|TableCell>>
     */
    private function formatRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $i => $e) {
            $ts = (int)($e['ts'] ?? 0);
            $when = $ts > 0 ? date('Y-m-d H:i', $ts) : '?';
            $action = $e['action'] ?? '?';
            $repo = $e['repo'] ?? '?';
            $prNum = $e['pr_number'] ?? '?';
            $head = $e['head_branch'] ?? '?';
            $base = $e['base_branch'] ?? '?';
            $author = $e['author'] ?? '?';

            $rows[] = [
                (string)($i + 1),
                $when,
                $repo,
                (string)$prNum,
                $action,
                sprintf('%s → %s', $head, $base),
                $author,
            ];
        }
        return $rows;
    }

    /**
     * @param array<array<string, mixed>> $entries
     * @return array<array<string|TableCell>>
     */
    private function formatVerboseRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $i => $e) {
            $ts = (int)($e['ts'] ?? 0);
            $when = $ts > 0 ? date('Y-m-d H:i', $ts) : '?';
            $action = $e['action'] ?? '?';
            $repo = $e['repo'] ?? '?';
            $prNum = $e['pr_number'] ?? '?';
            $head = $e['head_branch'] ?? '?';
            $base = $e['base_branch'] ?? '?';
            $author = $e['author'] ?? '?';
            $sha = substr((string)($e['sha'] ?? ''), 0, 7);
            $id = substr((string)($e['id'] ?? ''), 0, 8);

            $rows[] = [
                (string)($i + 1),
                $when,
                $repo,
                (string)$prNum,
                $action,
                sprintf('%s → %s', $head, $base),
                $author,
                $sha ?: '-',
                $id,
            ];
        }
        return $rows;
    }

    /**
     * @param array<array<string, mixed>> $statuses
     * @return array<array<string|TableCell>>
     */
    private function formatStatusRows(array $statuses): array
    {
        $rows = [];
        foreach ($statuses as $s) {
            $id = substr((string)($s['id'] ?? ''), 0, 8);
            $repo = $s['repo'] ?? '?';
            $prNumber = $s['pr_number'] ?? '?';
            $statusValue = $s['status'] ?? 'unknown';
            $severity = $s['severity'] ?? 'info';
            $issues = $s['issues_count'] ?? 0;
            $created = $s['created_at'] ?? time();

            $icon = self::STATUS_ICONS[$statusValue] ?? '⚪';
            $severityIcon = self::SEVERITY_ICONS[$severity] ?? '🔵';

            $rows[] = [
                $id,
                $repo,
                (string)$prNumber,
                sprintf('%s %s', $icon, $statusValue),
                sprintf('%s %s', $severityIcon, $severity),
                (string)$issues,
                date('Y-m-d H:i', (int)$created),
            ];
        }
        return $rows;
    }

    /**
     * @param array<array<string, mixed>> $jobs
     * @return array<array<string|TableCell>>
     */
    private function formatCancelRows(array $jobs): array
    {
        $rows = [];
        foreach ($jobs as $j) {
            $id = substr((string)($j['id'] ?? ''), 0, 8);
            $repo = $j['repo'] ?? '?';
            $prNum = $j['pr_number'] ?? '?';
            $action = $j['action'] ?? '?';
            $branch = $j['head_branch'] ?? '?';

            $rows[] = [$id, $repo, (string)$prNum, $action, $branch];
        }
        return $rows;
    }

    /**
     * @param array<string, int> $breakdown
     */
    private function renderBreakdown(
        OutputInterface $output,
        string $title,
        array $breakdown
    ): void {
        arsort($breakdown);
        $table = new Table($output);
        $table->setStyle('box-double');
        $table->setHeaders([$title, 'Count']);

        foreach ($breakdown as $key => $count) {
            $table->addRow([$key, (string)$count]);
        }

        $table->render();
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function renderIssue(OutputInterface $output, array $issue): void
    {
        $severity = $issue['severity'] ?? 'info';
        $file = $issue['file'] ?? 'unknown';
        $line = $issue['line'] ?? 0;
        $message = $issue['message'] ?? 'No message';
        $rule = $issue['rule'] ?? '';

        $icon = self::SEVERITY_ICONS[$severity] ?? '🔵';

        $output->writeln(sprintf(
            '    %s <comment>%s:%d</comment> %s',
            $icon,
            $file,
            $line,
            $message
        ));

        if ($rule !== '') {
            $output->writeln(sprintf('        <info>%s</info>', $rule));
        }
    }

    /**
     * Render activity list as a table.
     *
     * @param array<array<string, mixed>> $activity
     */
    public function renderActivityList(OutputInterface $output, array $activity): void
    {
        if (empty($activity)) {
            $output->writeln('<comment>(no activity found)</comment>');
            return;
        }

        $table = new Table($output);
        $table->setStyle('box-double');

        $table->setHeaders(['#', 'Type', 'Repo', 'Title', 'Author', 'Age']);

        $rows = [];
        foreach ($activity as $i => $item) {
            $type = $item['type'] ?? '?';
            $icon = match ($type) {
                'commit', 'push' => '📦',
                'pr', 'PullRequestEvent' => '🔀',
                'issue', 'IssuesEvent' => '🐛',
                'create', 'CreateEvent' => '✨',
                'delete', 'DeleteEvent' => '🗑️',
                'fork', 'ForkEvent' => '🍴',
                default => '⚪',
            };
            $repo = $item['repo'] ?? '?';
            $title = $item['title'] ?? 'No title';
            $author = $item['author'] ?? '?';
            $date = $item['date'] ?? '';

            // Truncate title
            if (strlen($title) > 50) {
                $title = substr($title, 0, 47) . '...';
            }

            $rows[] = [
                (string)($i + 1),
                $icon,
                $repo,
                $title,
                $author,
                $this->formatAge($date),
            ];
        }

        $table->setRows($rows);
        $table->render();

        $output->writeln(sprintf('<info>%d item(s)</info>', count($activity)));
    }

    /**
     * Format a date string as an age string.
     */
    private function formatAge(string $dateStr): string
    {
        if ($dateStr === '') {
            return '?';
        }

        try {
            $timestamp = strtotime($dateStr);
            if ($timestamp === false) {
                return '?';
            }

            $diff = time() - $timestamp;

            if ($diff < 60) {
                return 'just now';
            }
            if ($diff < 3600) {
                return (int)($diff / 60) . 'm';
            }
            if ($diff < 86400) {
                return (int)($diff / 3600) . 'h';
            }
            if ($diff < 604800) {
                return (int)($diff / 86400) . 'd';
            }

            return date('m/d', $timestamp);
        } catch (\Throwable $e) {
            return '?';
        }
    }
}
