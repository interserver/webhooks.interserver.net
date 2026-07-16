<?php
declare(strict_types=1);

namespace Webhooks\Cli\Interactor;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputStream;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Handles interactive browsing through GitHub activity items.
 *
 * Provides cursor-based navigation through activity list with
 * detail views and action handling.
 */
class ActivityInteractor
{
    private const ACTIVITY_TYPE_ICONS = [
        'commit' => '📦',
        'pr' => '🔀',
    ];

    private const STATE_ICONS = [
        'open' => '🟢',
        'closed' => '🔴',
        'merged' => '🟣',
    ];

    private ?QuestionHelper $questionHelper = null;
    private InputInterface $input;
    private OutputInterface $output;
    private bool $isInteractive = true;

    /** @var array<int, array<string, mixed>> */
    private array $activity = [];

    private int $currentIndex = 0;
    private int $pageSize = 20;

    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->detectInteractiveMode();
    }

    /**
     * Set whether to run in interactive mode.
     */
    public function setInteractive(bool $interactive): void
    {
        $this->isInteractive = $interactive;
    }

    /**
     * Check if running in interactive mode.
     */
    public function isInteractive(): bool
    {
        return $this->isInteractive;
    }

    /**
     * Set the question helper (for testing).
     */
    public function setQuestionHelper(QuestionHelper $helper): void
    {
        $this->questionHelper = $helper;
    }

    /**
     * Set page size for pagination.
     */
    public function setPageSize(int $size): void
    {
        $this->pageSize = max(1, $size);
    }

    /**
     * Browse activity items interactively.
     *
     * @param array<array<string, mixed>> $activity List of activity items
     * @return array{action: string, item?: array<string, mixed>}|null Returns selection or null to exit
     */
    public function browseActivity(array $activity): ?array
    {
        $this->activity = $activity;
        $this->currentIndex = 0;

        if (empty($activity)) {
            $this->output->writeln('<comment>(no activity found)</comment>');
            return null;
        }

        if (!$this->isInteractive) {
            return null;
        }

        return $this->runBrowseLoop();
    }

    /**
     * Show details for a specific item.
     *
     * @param array<string, mixed> $item
     */
    public function showDetail(array $item): void
    {
        $this->renderDetail($item);
    }

    /**
     * Handle user selection and return action.
     *
     * @param array<string, mixed> $item
     * @return string|null 'review', 'back', 'refresh', or null to exit
     */
    public function handleSelection(array $item): ?string
    {
        if (!$this->isInteractive) {
            return null;
        }

        $this->output->writeln('');
        $this->output->writeln('<info>Actions:</info>');
        $this->output->writeln('  <info>[Enter]</info> - Submit this for review');
        $this->output->writeln('  <info>[r]</info>     - Refresh');
        $this->output->writeln('  <info>[q/ESC]</info> - Go back');
        $this->output->writeln('');

        $question = new ChoiceQuestion(
            'Select action',
            ['Submit for review', 'Refresh', 'Go back'],
            0
        );
        $question->setErrorMessage('Invalid choice');

        $helper = $this->getQuestionHelper();
        try {
            /** @var string $answer */
            $answer = $helper->ask($this->input, $this->output, $question);
        } catch (\Throwable $e) {
            return 'back';
        }

        switch ($answer) {
            case 'Submit for review':
                return 'review';
            case 'Refresh':
                return 'refresh';
            case 'Go back':
            default:
                return 'back';
        }
    }

    /**
     * Render a single activity item.
     *
     * @param array<string, mixed> $item
     */
    public function renderItem(array $item, int $index, bool $isSelected = false): void
    {
        $type = $item['type'] ?? 'unknown';
        $repo = $item['repo'] ?? '?';
        $title = $item['title'] ?? 'No title';
        $author = $item['author'] ?? 'unknown';
        $date = $this->formatDate($item['date'] ?? '');
        $url = $item['url'] ?? '';

        $icon = self::ACTIVITY_TYPE_ICONS[$type] ?? '⚪';
        $prefix = $isSelected ? '→ ' : '  ';
        $marker = $isSelected ? '<info>' : '';
        $markerClose = $isSelected ? '</info>' : '';

        $this->output->writeln(sprintf(
            '%s[%2d] %s %s <comment>%s</comment>',
            $prefix,
            $index + 1,
            $icon,
            $marker . $title . $markerClose,
            $repo
        ));

        $this->output->writeln(sprintf(
            '%s     by %s  •  %s',
            $prefix,
            $author,
            $date
        ));

        // For PRs, show state
        if ($type === 'pr') {
            $state = $item['state'] ?? 'open';
            $stateIcon = self::STATE_ICONS[$state] ?? '⚪';
            $number = $item['number'] ?? '';
            $this->output->writeln(sprintf(
                '%s     %s PR #%s',
                $prefix,
                $stateIcon,
                $number
            ));
        }

        // Show branch for commits
        if ($type === 'commit' && !empty($item['branch'])) {
            $sha = substr((string)($item['sha'] ?? ''), 0, 7);
            $this->output->writeln(sprintf(
                '%s     <info>%s</info> %s',
                $prefix,
                $item['branch'],
                $sha ? "({$sha})" : ''
            ));
        }

        // Show additions/deletions stats
        if (isset($item['additions']) && isset($item['deletions'])) {
            $additions = (int)$item['additions'];
            $deletions = (int)$item['deletions'];
            $this->output->writeln(sprintf(
                '%s     <info>+%d</info> <comment>−%d</comment>',
                $prefix,
                $additions,
                $deletions
            ));
        }
    }

    /**
     * Render detail view for an item.
     *
     * @param array<string, mixed> $item
     */
    public function renderDetail(array $item): void
    {
        $type = $item['type'] ?? 'unknown';
        $icon = self::ACTIVITY_TYPE_ICONS[$type] ?? '⚪';

        $this->output->writeln(str_repeat('═', 60));
        $this->output->writeln(sprintf('%s <info>%s</info>', $icon, $item['title'] ?? 'No title'));
        $this->output->writeln(str_repeat('═', 60));

        // Common fields
        $this->output->writeln(sprintf('  Repository:  <comment>%s</comment>', $item['repo'] ?? '?'));
        $this->output->writeln(sprintf('  Author:      [%s](%s)', $item['author'] ?? '?', $item['author_url'] ?? '#'));
        $this->output->writeln(sprintf('  Date:        %s', $this->formatDate($item['date'] ?? '')));
        $this->output->writeln(sprintf('  URL:         %s', $item['url'] ?? '?'));

        // Type-specific fields
        if ($type === 'commit') {
            $this->renderCommitDetail($item);
        } elseif ($type === 'pr') {
            $this->renderPRDetail($item);
        }

        // Description/body
        $description = $item['description'] ?? $item['body'] ?? '';
        if ($description !== '') {
            $this->output->writeln('');
            $this->output->writeln('  <info>Description:</info>');
            foreach (explode("\n", $description) as $line) {
                $this->output->writeln(sprintf('    %s', $line));
            }
        }
    }

    /**
     * Render commit-specific detail fields.
     *
     * @param array<string, mixed> $item
     */
    private function renderCommitDetail(array $item): void
    {
        $sha = (string)($item['sha'] ?? '');
        $this->output->writeln(sprintf('  SHA:         <info>%s</info>', substr($sha, 0, 7)));

        if (!empty($item['branch'])) {
            $this->output->writeln(sprintf('  Branch:      <info>%s</info>', $item['branch']));
        }

        if (isset($item['files_changed'])) {
            $this->output->writeln(sprintf('  Files:       %d', (int)$item['files_changed']));
        }

        if (isset($item['additions']) && isset($item['deletions'])) {
            $this->output->writeln(sprintf(
                '  Changes:     <info>+%d</info> / <comment>−%d</comment>',
                (int)$item['additions'],
                (int)$item['deletions']
            ));
        }
    }

    /**
     * Render PR-specific detail fields.
     *
     * @param array<string, mixed> $item
     */
    private function renderPRDetail(array $item): void
    {
        $number = $item['number'] ?? 0;
        $state = $item['state'] ?? 'open';
        $stateIcon = self::STATE_ICONS[$state] ?? '⚪';

        $this->output->writeln(sprintf('  PR Number:   %s %s (#%d)', $stateIcon, $state, $number));

        if (!empty($item['head_branch']) && !empty($item['base_branch'])) {
            $this->output->writeln(sprintf(
                '  Branch:      <info>%s</info> → <comment>%s</comment>',
                $item['head_branch'],
                $item['base_branch']
            ));
        }

        if (isset($item['changed_files'])) {
            $this->output->writeln(sprintf('  Changed:     %d file(s)', (int)$item['changed_files']));
        }

        if (isset($item['additions']) && isset($item['deletions'])) {
            $this->output->writeln(sprintf(
                '  Changes:     <info>+%d</info> / <comment>−%d</comment>',
                (int)$item['additions'],
                (int)$item['deletions']
            ));
        }

        // Labels
        $labels = $item['labels'] ?? [];
        if (!empty($labels)) {
            $labelStr = implode(', ', array_map(function ($l) {
                return "<comment>{$l}</comment>";
            }, $labels));
            $this->output->writeln(sprintf('  Labels:      %s', $labelStr));
        }

        // Reviews
        $reviews = $item['reviews'] ?? [];
        if (!empty($reviews)) {
            $this->output->writeln('');
            $this->output->writeln('  <info>Reviews:</info>');
            foreach ($reviews as $review) {
                if (!is_array($review)) {
                    continue;
                }
                $rAuthor = $review['author'] ?? '?';
                $rState = $review['state'] ?? '?';
                $rDate = $this->formatDate($review['submitted_at'] ?? '');
                $this->output->writeln(sprintf('    - %s: <info>%s</info> (%s)', $rAuthor, $rState, $rDate));
            }
        }
    }

    /**
     * Format date string for display.
     */
    private function formatDate(string $dateStr): string
    {
        if ($dateStr === '') {
            return '?';
        }

        try {
            $timestamp = strtotime($dateStr);
            if ($timestamp === false) {
                return $dateStr;
            }

            $diff = time() - $timestamp;

            if ($diff < 60) {
                return 'just now';
            }
            if ($diff < 3600) {
                $mins = (int)($diff / 60);
                return "{$mins}m ago";
            }
            if ($diff < 86400) {
                $hours = (int)($diff / 3600);
                return "{$hours}h ago";
            }
            if ($diff < 604800) {
                $days = (int)($diff / 86400);
                return "{$days}d ago";
            }

            return date('Y-m-d H:i', $timestamp);
        } catch (\Throwable $e) {
            return $dateStr;
        }
    }

    /**
     * Run the main browse loop.
     *
     * @return array{action: string, item?: array<string, mixed>}|null
     */
    private function runBrowseLoop(): ?array
    {
        $totalPages = (int)ceil(count($this->activity) / $this->pageSize);
        $currentPage = 0;

        while (true) {
            $this->output->writeln("\n" . str_repeat('═', 60));
            $this->output->writeln(sprintf(
                ' <info>GitHub Activity</info>  (Page %d/%d, %d items)',
                $currentPage + 1,
                $totalPages,
                count($this->activity)
            ));
            $this->output->writeln(str_repeat('═', 60));

            $startIdx = $currentPage * $this->pageSize;
            $endIdx = min($startIdx + $this->pageSize, count($this->activity));

            // Render page header
            $this->output->writeln('');
            $this->output->writeln('<comment>Use arrow keys to navigate, Enter to select, q to quit</comment>');
            $this->output->writeln('');

            // Render items
            for ($i = $startIdx; $i < $endIdx; $i++) {
                $this->renderItem($this->activity[$i], $i, $i === $this->currentIndex);
            }

            // Navigation hint
            $navOptions = [];
            if ($currentPage > 0) {
                $navOptions[] = 'p: previous page';
            }
            if ($endIdx < count($this->activity)) {
                $navOptions[] = 'n: next page';
            }
            $navOptions[] = 'q: quit';

            $this->output->writeln('');
            $this->output->writeln('  <info>' . implode('  │  ', $navOptions) . '</info>');

            // Prompt for selection
            $this->output->writeln('');
            $question = new ChoiceQuestion(
                'Select item',
                array_map(function ($i) {
                    $item = $this->activity[$i];
                    $type = $item['type'] ?? '?';
                    $title = $item['title'] ?? 'No title';
                    return sprintf('[%2d] %s: %s', $i + 1, $type, substr($title, 0, 40));
                }, range($startIdx, $endIdx - 1)),
                0
            );
            $question->setErrorMessage('Invalid selection');

            $helper = $this->getQuestionHelper();

            try {
                /** @var string $answer */
                $answer = $helper->ask($this->input, $this->output, $question);

                // Parse selection
                if (preg_match('/^\[(\d+)\]/', $answer, $matches)) {
                    $selectedIdx = (int)$matches[1] - 1;

                    if ($selectedIdx >= 0 && $selectedIdx < count($this->activity)) {
                        $this->currentIndex = $selectedIdx;
                        $selectedItem = $this->activity[$selectedIdx];

                        // Show detail and get action
                        $this->showDetail($selectedItem);
                        $action = $this->handleSelection($selectedItem);

                        if ($action === 'review') {
                            return ['action' => 'review', 'item' => $selectedItem];
                        }

                        if ($action === 'refresh') {
                            return ['action' => 'refresh'];
                        }

                        if ($action === 'back') {
                            continue;
                        }

                        // Exit
                        return null;
                    }
                }

                // Handle navigation
                if ($answer === 'n: next page' && $endIdx < count($this->activity)) {
                    $currentPage++;
                    continue;
                }

                if ($answer === 'p: previous page' && $currentPage > 0) {
                    $currentPage--;
                    continue;
                }

                if ($answer === 'q: quit') {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }
    }

    /**
     * Get the question helper.
     */
    private function getQuestionHelper(): QuestionHelper
    {
        if ($this->questionHelper === null) {
            $this->questionHelper = new QuestionHelper();
        }

        return $this->questionHelper;
    }

    /**
     * Detect if we're running in interactive mode.
     */
    private function detectInteractiveMode(): void
    {
        // Check for explicit non-interactive flag
        if ($this->input->hasOption('non-interactive')) {
            $nonInteractive = $this->input->getOption('non-interactive');
            if ($nonInteractive === true) {
                $this->isInteractive = false;
                return;
            }
        }

        // Check for --no-interaction flag (Symfony convention)
        if ($this->input->hasParameterOption(['--no-interaction', '-n'])) {
            $this->isInteractive = false;
            return;
        }

        // Check for CI environment
        if (getenv('CI') !== false) {
            $this->isInteractive = false;
            return;
        }

        // Check if we have a TTY
        if (function_exists('posix_isatty')) {
            $this->isInteractive = posix_isatty(STDOUT) && posix_isatty(STDIN);
        }
    }
}
