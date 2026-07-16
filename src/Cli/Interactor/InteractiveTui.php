<?php
declare(strict_types=1);

namespace Webhooks\Cli\Interactor;

use Symfony\Component\Console\Output\OutputInterface;
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Table\Table;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Dash\Layout\VStack;
use SugarCraft\Dash\Layout\Panel;
use SugarCraft\Dash\Components\StatusBar\StatusBar;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Toast\Toast;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Dash\Layout\Frame;
use SugarCraft\Dash\Foundation\Item;

/**
 * Interactive TUI for browsing GitHub activity with arrow key navigation.
 *
 * Features:
 * - Real arrow key navigation (Up/Down for list, Left/Right for filter modes)
 * - Vim-style j/k navigation for list traversal
 * - Enter to select item for detail view
 * - q or ESC to quit
 * - Filter modes: Type, User, Org, Repo, Time
 * - Repo toggle list with checkboxes
 * - Status bar showing current filter state
 * - Load more pagination (press l) for single repo queries
 *
 * Uses SugarCraft libraries for input handling and rendering:
 * - candy-input: EscapeDecoder for keyboard input
 * - sugar-table: Interactive table with sorting, filtering, pagination
 * - sugar-dash: Dashboard layout (VStack, HStack, Panel, StatusBar, Frame)
 * - sugar-sprinkles: Style and Border for styled boxes
 * - candy-forms: ItemList for scrollable lists
 * - sugar-toast: Toast notifications
 * - sugar-bits/Spinner: Loading spinner
 */
final class InteractiveTui
{
    // Type filter options
    private const TYPE_OPTIONS = ['all', 'push', 'pr', 'issue'];

    // Time filter options (in hours)
    private const TIME_OPTIONS = [1, 6, 12, 24, 48, 72, 168, 336, 720];

    /** @var OutputInterface */
    private OutputInterface $output;

    /** @var array<int, array<string, mixed>> */
    private array $activity = [];

    /** @var array<int, array<string, mixed>> */
    private array $filteredActivity = [];

    /** @var array<string, bool> */
    private array $selectedRepos = [];

    /** @var array<string, bool> */
    private array $availableRepos = [];

    private string $filterType = 'all';
    private string $filterUser = '';
    private string $filterOrg = '';
    private int $filterTimeHours = 24;

    private int $cursorIndex = 0;
    private int $scrollOffset = 0;
    private Pane $currentPane = Pane::Browse;
    private bool $running = true;
    private bool $isAtty = false;

    /** @var resource|false|null */
    private $stdin = null;

    private int $pageSize = 15;

    /** @var array{cols: int, rows: int}|null */
    private ?array $currentSize = null;

    /** @var EscapeDecoder */
    private EscapeDecoder $decoder;

    /** Dirty flag for flicker-free rendering - only re-render when state changes */
    private bool $needsRender = true;

    // Pagination state
    private int $currentPage = 0;
    private int $itemsPerPage = 50;
    private bool $isLoadingMore = false;
    private bool $hasMoreToLoad = true;

    /** @var \Closure(int): array<array<string, mixed>>|null Returns activity items for the given page */
    private ?\Closure $loadMoreCallback = null;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->isAtty = $this->detectAtty();
        $this->decoder = new EscapeDecoder();
    }

    /**
     * Get terminal size as array using Tty facade.
     *
     * @return array{cols: int, rows: int}
     */
    private function getTerminalSize(): array
    {
        try {
            // @phpstan-ignore-next-line Tty is from candy-core external package
            $size = (new Tty(STDOUT))->size();
            if ($size['cols'] > 0 && $size['rows'] > 0) {
                return $size;
            }
        } catch (\Throwable) {
        }

        return ['cols' => 120, 'rows' => 40];
    }

    /**
     * Run the interactive TUI.
     *
     * @param array<array<string, mixed>> $activity List of activity items
     * @param \Closure(int): array<array<string, mixed>>|null $loadMoreCallback Optional callback to load more items
     * @return array{action: string, item?: array<string, mixed>}|null
     */
    public function run(array $activity, ?\Closure $loadMoreCallback = null): ?array
    {
        $this->activity = $activity;
        $this->filteredActivity = $activity;
        $this->loadMoreCallback = $loadMoreCallback;
        $this->currentPage = 0;
        $this->hasMoreToLoad = $loadMoreCallback !== null;
        $this->buildAvailableRepos();

        if ($activity === []) {
            $this->renderEmpty();
            return null;
        }

        if (!$this->isAtty) {
            $this->renderNonInteractiveFallback();
            return null;
        }

        // Enable raw mode for stdin
        $this->stdin = fopen('php://stdin', 'r');
        if ($this->stdin === false) {
            $this->renderNonInteractiveFallback();
            return null;
        }

        // Enable raw mode so keys are sent immediately without needing Enter
        $this->enableRawMode();

        // Save terminal settings
        $originalTermSettings = $this->saveTerminalSettings();

        try {
            $this->runMainLoop();
        } finally {
            $this->restoreTerminalSettings($originalTermSettings);
            if ($this->stdin !== null && $this->stdin !== false) {
                fclose($this->stdin);
            }
        }

        return $this->finalResult;
    }

    /**
     * Main event loop for the TUI.
     *
     * Uses dirty flag pattern to avoid constant refresh flicker.
     * Only re-renders when state has actually changed.
     */
    private function runMainLoop(): void
    {
        $this->applyFilters();

        while ($this->running) {
            // Only re-render when state has changed
            if ($this->needsRender) {
                $this->render();
                $this->needsRender = false;
            }

            $key = $this->handleInput();

            if ($key === 'q' || $key === 'escape') {
                $this->running = false;
                $this->finalResult = null;
                continue;
            }

            if ($key !== '') {
                $this->handleKey($key);
                $this->needsRender = true;
            }
        }
    }

    /**
     * Map raw key strings from EscapeDecoder to normalized internal key names.
     *
     * EscapeDecoder returns strings like 'ArrowUp', 'Enter', ' ' for space.
     * We normalize these to lowercase canonical forms for consistent handling.
     */
    private function mapKey(string $key): string
    {
        return match ($key) {
            'ArrowUp' => 'up',
            'ArrowDown' => 'down',
            'ArrowLeft' => 'left',
            'ArrowRight' => 'right',
            'Enter' => 'enter',
            'Escape' => 'escape',
            ' ' => 'space',
            'Tab' => 'tab',
            'Backspace' => 'backspace',
            default => $key,
        };
    }

    /**
     * Handle input using EscapeDecoder from candy-input.
     *
     * Uses fread() with a buffer to capture multi-byte escape sequences
     * (arrow keys, function keys, etc.). EscapeDecoder handles partial sequences
     * via its remainder() method - we prepend any remainder to the next decode call.
     *
     * Uses blocking stream_select (no timeout) so we wait indefinitely for input.
     * This works because raw mode is enabled, so keys are sent immediately.
     */
    private function handleInput(): string
    {
        if ($this->stdin === null || $this->stdin === false) {
            return '';
        }

        // Check if EscapeDecoder has buffered partial sequence bytes
        $remainder = $this->decoder->remainder();

        $read = [$this->stdin];
        $write = null;
        $except = null;
        $bytes = '';

        // If we have a remainder from a previous partial escape sequence, process it
        // independently - we skip stream_select when we already have buffered data
        if ($remainder !== '') {
            $bytes = $remainder;
        } else {
            // Use 100ms timeout to avoid locking up when no input is available
            $result = @stream_select($read, $write, $except, 0, 100000);
            if ($result === false) {
                // Error on stdin (e.g., EINTR from signal)
                return '';
            }
            if ($result === 0) {
                // Timeout - no input available yet
                if (!$this->running) {
                    return 'q';  // Signal quit
                }
                usleep(1000);  // 1ms sleep to prevent CPU spinning
                return '';
            }

            // Read up to 64 bytes at once to capture escape sequences
            $chunk = fread($this->stdin, 64);
            if ($chunk === false || $chunk === '') {
                return '';
            }
            $bytes = $chunk;
        }

        // Decode the bytes through EscapeDecoder
        $events = $this->decoder->decode($bytes);

        foreach ($events as $event) {
            if ($event instanceof KeyEvent) {
                return $this->mapKey($event->key);
            }
        }

        return '';
    }

    /**
     * Handle keypress based on current pane.
     */
    private function handleKey(string $key): void
    {
        if ($key === '') {
            return;
        }

        match ($this->currentPane) {
            Pane::Browse => $this->handleBrowseKey($key),
            Pane::FilterType => $this->handleFilterTypeKey($key),
            Pane::FilterUser => $this->handleFilterUserKey($key),
            Pane::FilterOrg => $this->handleFilterOrgKey($key),
            Pane::FilterRepo => $this->handleFilterRepoKey($key),
            Pane::FilterTime => $this->handleFilterTimeKey($key),
            Pane::Detail => $this->handleDetailKey($key),
            Pane::Help => $this->handleHelpKey($key),
        };
    }

    /**
     * Handle keypress in browse pane - includes vim-style j/k navigation.
     */
    private function handleBrowseKey(string $key): void
    {
        switch ($key) {
            case 'up':
            case 'k':
                $this->moveCursorUp();
                break;
            case 'down':
            case 'j':
                $this->moveCursorDown();
                break;
            case 'enter':
                $this->enterSelectedItem();
                break;
            case 't':
                $this->currentPane = Pane::FilterType;
                break;
            case 'u':
                $this->currentPane = Pane::FilterUser;
                break;
            case 'o':
                $this->currentPane = Pane::FilterOrg;
                break;
            case 'r':
                $this->currentPane = Pane::FilterRepo;
                break;
            case 'd':
                $this->currentPane = Pane::FilterTime;
                break;
            case '?':
                $this->currentPane = Pane::Help;
                break;
            case 's':
            case 'S':
                $this->submitCurrentItem();
                break;
            case 'h':
                // Left arrow or h - go to previous pane
                $this->currentPane = $this->currentPane->previous();
                break;
            case 'l':
                // l - load more pagination (when callback is available)
                if ($this->loadMoreCallback !== null && !$this->isLoadingMore && $this->hasMoreToLoad) {
                    $this->loadMoreItems();
                } else {
                    // Right arrow or l - go to next pane (if no more to load)
                    $this->currentPane = $this->currentPane->next();
                }
                break;
        }
    }

    /**
     * Handle keypress in type filter pane.
     */
    private function handleFilterTypeKey(string $key): void
    {
        $currentIndex = array_search($this->filterType, self::TYPE_OPTIONS, true);

        switch ($key) {
            case 'up':
            case 'k':
                $currentIndex = $this->wrapIndex($currentIndex - 1, count(self::TYPE_OPTIONS));
                $this->filterType = self::TYPE_OPTIONS[$currentIndex];
                $this->applyFilters();
                break;
            case 'down':
            case 'j':
                $currentIndex = $this->wrapIndex($currentIndex + 1, count(self::TYPE_OPTIONS));
                $this->filterType = self::TYPE_OPTIONS[$currentIndex];
                $this->applyFilters();
                break;
            case 'enter':
            case 'tab':
            case 'space':
                $this->currentPane = Pane::Browse;
                break;
            case 'q':
            case 'escape':
                $this->currentPane = Pane::Browse;
                break;
            case 'h':
            case 'l':
                // Navigate to previous/next pane
                $this->currentPane = $key === 'h' ? $this->currentPane->previous() : $this->currentPane->next();
                break;
        }
    }

    /**
     * Handle keypress in user filter pane.
     */
    private function handleFilterUserKey(string $key): void
    {
        switch ($key) {
            case 'escape':
            case 'enter':
            case 'q':
                $this->currentPane = Pane::Browse;
                break;
            case 'backspace':
                if ($this->filterUser !== '') {
                    $this->filterUser = substr($this->filterUser, 0, -1);
                    $this->applyFilters();
                }
                break;
            case 'h':
            case 'l':
                $this->currentPane = $key === 'h' ? $this->currentPane->previous() : $this->currentPane->next();
                break;
            default:
                if (strlen($key) === 1 && ctype_print($key)) {
                    $this->filterUser .= $key;
                    $this->applyFilters();
                }
                break;
        }
    }

    /**
     * Handle keypress in org filter pane.
     */
    private function handleFilterOrgKey(string $key): void
    {
        switch ($key) {
            case 'escape':
            case 'enter':
            case 'q':
                $this->currentPane = Pane::Browse;
                break;
            case 'backspace':
                if ($this->filterOrg !== '') {
                    $this->filterOrg = substr($this->filterOrg, 0, -1);
                    $this->applyFilters();
                }
                break;
            case 'h':
            case 'l':
                $this->currentPane = $key === 'h' ? $this->currentPane->previous() : $this->currentPane->next();
                break;
            default:
                if (strlen($key) === 1 && ctype_print($key)) {
                    $this->filterOrg .= $key;
                    $this->applyFilters();
                }
                break;
        }
    }

    /**
     * Handle keypress in repo filter pane.
     */
    private function handleFilterRepoKey(string $key): void
    {
        $repoKeys = array_keys($this->availableRepos);
        $currentRepoIndex = 0;

        foreach ($repoKeys as $i => $repo) {
            if ($this->selectedRepos[$repo] === false) {
                $currentRepoIndex = $i;
                break;
            }
        }

        switch ($key) {
            case 'up':
            case 'k':
                foreach ($repoKeys as $i => $repo) {
                    if ($this->selectedRepos[$repo] === false) {
                        if ($i > 0) {
                            $this->selectedRepos[$repoKeys[$i - 1]] = false;
                        }
                        break;
                    }
                }
                break;
            case 'down':
            case 'j':
                foreach ($repoKeys as $i => $repo) {
                    if ($this->selectedRepos[$repo] === false) {
                        if ($i < count($repoKeys) - 1) {
                            $this->selectedRepos[$repoKeys[$i + 1]] = false;
                        }
                        break;
                    }
                }
                break;
            case 'space':
            case 'enter':
            case 'x':
            case 'X':
                foreach ($repoKeys as $i => $repo) {
                    if ($this->selectedRepos[$repo] === false) {
                        $this->selectedRepos[$repo] = true;
                        $this->applyFilters();
                        break;
                    }
                }
                break;
            case 'a':
            case 'A':
                foreach ($this->selectedRepos as $repo => $selected) {
                    $this->selectedRepos[$repo] = false;
                }
                $this->applyFilters();
                break;
            case 'n':
            case 'N':
                foreach ($this->selectedRepos as $repo => $selected) {
                    $this->selectedRepos[$repo] = true;
                }
                $this->applyFilters();
                break;
            case 'escape':
            case 'q':
            case 'h':
            case 'l':
                $this->currentPane = Pane::Browse;
                break;
        }
    }

    /**
     * Handle keypress in time filter pane.
     */
    private function handleFilterTimeKey(string $key): void
    {
        $currentIndex = array_search($this->filterTimeHours, self::TIME_OPTIONS, true);

        switch ($key) {
            case 'up':
            case 'k':
                $currentIndex = $this->wrapIndex($currentIndex - 1, count(self::TIME_OPTIONS));
                $this->filterTimeHours = self::TIME_OPTIONS[$currentIndex];
                $this->applyFilters();
                break;
            case 'down':
            case 'j':
                $currentIndex = $this->wrapIndex($currentIndex + 1, count(self::TIME_OPTIONS));
                $this->filterTimeHours = self::TIME_OPTIONS[$currentIndex];
                $this->applyFilters();
                break;
            case 'enter':
            case 'tab':
            case 'space':
                $this->currentPane = Pane::Browse;
                break;
            case 'q':
            case 'escape':
                $this->currentPane = Pane::Browse;
                break;
            case 'h':
            case 'l':
                $this->currentPane = $key === 'h' ? $this->currentPane->previous() : $this->currentPane->next();
                break;
        }
    }

    /**
     * Handle keypress in detail pane.
     */
    private function handleDetailKey(string $key): void
    {
        switch ($key) {
            case 'escape':
            case 'q':
                $this->currentPane = Pane::Browse;
                break;
            case 'enter':
                $this->submitCurrentItem();
                break;
        }
    }

    /**
     * Handle keypress in help pane - any key closes it.
     */
    private function handleHelpKey(string $key): void
    {
        $this->currentPane = Pane::Browse;
    }

    /**
     * Submit the currently selected item for review.
     */
    private function submitCurrentItem(): void
    {
        if ($this->filteredActivity === []) {
            return;
        }

        $index = $this->cursorIndex;
        if (!isset($this->filteredActivity[$index])) {
            return;
        }

        $item = $this->filteredActivity[$index];
        $this->running = false;
        $this->finalResult = ['action' => 'review', 'item' => $item];
    }

    /**
     * Move cursor up - supports vim-style k.
     */
    private function moveCursorUp(): void
    {
        if ($this->cursorIndex > 0) {
            $this->cursorIndex--;
            if ($this->cursorIndex < $this->scrollOffset) {
                $this->scrollOffset = $this->cursorIndex;
            }
        }
    }

    /**
     * Move cursor down - supports vim-style j.
     */
    private function moveCursorDown(): void
    {
        $maxIndex = count($this->filteredActivity) - 1;
        if ($this->cursorIndex < $maxIndex) {
            $this->cursorIndex++;
            if ($this->cursorIndex >= $this->scrollOffset + $this->pageSize) {
                $this->scrollOffset = $this->cursorIndex - $this->pageSize + 1;
            }
        }
    }

    /**
     * Enter the selected item (show detail or submit).
     */
    private function enterSelectedItem(): void
    {
        if ($this->filteredActivity === []) {
            return;
        }

        $index = $this->cursorIndex;
        if (!isset($this->filteredActivity[$index])) {
            return;
        }

        $item = $this->filteredActivity[$index];
        $this->currentPane = Pane::Detail;
        $this->detailItem = $item;

        // Get dimensions based on stored size or terminal size
        $size = $this->currentSize ?? $this->getTerminalSize();

        $this->output->writeln($this->renderDetailContent($item, $size['cols'], $size['rows']));
    }

    /**
     * Apply all active filters to the activity list.
     */
    private function applyFilters(): void
    {
        $this->filteredActivity = array_values(array_filter(
            $this->activity,
            function (array $item): bool {
                if ($this->filterType !== 'all') {
                    $itemType = (string)($item['type'] ?? '');
                    if ($itemType !== $this->filterType) {
                        return false;
                    }
                }

                if ($this->filterUser !== '') {
                    $author = strtolower((string)($item['author'] ?? ''));
                    if (!str_contains($author, strtolower($this->filterUser))) {
                        return false;
                    }
                }

                if ($this->filterOrg !== '') {
                    $repo = (string)($item['repo'] ?? '');
                    $repoLower = strtolower($repo);
                    $orgLower = strtolower($this->filterOrg);
                    if (!str_starts_with($repoLower, $orgLower . '/')) {
                        return false;
                    }
                }

                $repo = (string)($item['repo'] ?? '');
                if (isset($this->selectedRepos[$repo]) && $this->selectedRepos[$repo] === true) {
                    return false;
                }

                $dateStr = (string)($item['date'] ?? '');
                if ($dateStr !== '') {
                    $timestamp = strtotime($dateStr);
                    if ($timestamp !== false) {
                        $cutoff = time() - ($this->filterTimeHours * 3600);
                        if ($timestamp < $cutoff) {
                            return false;
                        }
                    }
                }

                return true;
            }
        ));

        $this->cursorIndex = min($this->cursorIndex, max(0, count($this->filteredActivity) - 1));
        $this->scrollOffset = min($this->scrollOffset, max(0, $this->cursorIndex - $this->pageSize + 1));
    }

    /**
     * Load more items via the callback and append to activity list.
     */
    private function loadMoreItems(): void
    {
        if ($this->loadMoreCallback === null || $this->isLoadingMore) {
            return;
        }

        $this->isLoadingMore = true;
        $this->needsRender = true;

        // Show loading indicator briefly
        $this->renderLoadingIndicator();

        // Calculate next page number
        $nextPage = $this->currentPage + 1;

        // Call the callback to fetch more items
        $callback = $this->loadMoreCallback;
        if ($callback === null) {
            $newItems = [];
        } else {
            $newItems = $callback($nextPage);
        }

        $this->isLoadingMore = false;

        if ($newItems === [] || $newItems === null) {
            $this->hasMoreToLoad = false;
            return;
        }

        // Append new items to activity and filteredActivity
        $originalCount = count($this->activity);
        foreach ($newItems as $item) {
            $this->activity[] = $item;
        }

        // Also append to filteredActivity (filters will be re-applied)
        foreach ($newItems as $item) {
            $this->filteredActivity[] = $item;
        }

        $this->currentPage = $nextPage;
        $this->hasMoreToLoad = count($newItems) >= $this->itemsPerPage;

        // Re-apply filters to the extended activity list
        $this->applyFilters();

        // Adjust cursor position if it would be out of bounds
        if ($this->cursorIndex >= count($this->filteredActivity)) {
            $this->cursorIndex = max(0, count($this->filteredActivity) - 1);
        }
    }

    /**
     * Render a brief loading indicator.
     */
    private function renderLoadingIndicator(): void
    {
        $size = $this->currentSize ?? $this->getTerminalSize();
        $loadingText = '  Loading more items...';

        // Position cursor and show loading text
        $this->output->write("\033[{$size['rows']};0H");
        $this->output->write("\033[K");
        $this->output->write($loadingText);
        $this->output->write(str_repeat(' ', max(0, $size['cols'] - strlen($loadingText) - 1)));
    }

    /**
     * Build the list of available repos from activity.
     */
    private function buildAvailableRepos(): void
    {
        $this->availableRepos = [];
        $this->selectedRepos = [];

        foreach ($this->activity as $item) {
            $repo = (string)($item['repo'] ?? '');
            if ($repo !== '' && !isset($this->availableRepos[$repo])) {
                $this->availableRepos[$repo] = true;
                $this->selectedRepos[$repo] = false;
            }
        }

        ksort($this->availableRepos);
        ksort($this->selectedRepos);
    }

    /**
     * Detect if we're running in a TTY.
     */
    private function detectAtty(): bool
    {
        if (!is_resource(STDOUT) || !is_resource(STDIN)) {
            return false;
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT) && @posix_isatty(STDIN);
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT) && @stream_isatty(STDIN);
        }

        return false;
    }

    /**
     * Save terminal settings.
     *
     * @return array<string, mixed>|null
     */
    private function saveTerminalSettings(): ?array
    {
        if (!$this->isAtty) {
            return null;
        }

        $settings = @proc_open('stty -g 2>/dev/null', [], $pipes);

        if ($settings === false || !is_resource($settings)) {
            return null;
        }

        if (!isset($pipes[0]) || !is_resource($pipes[0])) {
            proc_close($settings);
            return null;
        }

        $output = stream_get_contents($pipes[0]);
        fclose($pipes[0]);

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        proc_close($settings);

        if (is_string($output) && $output !== '' && strpos($output, 'unknown') === false) {
            return ['stty' => trim($output)];
        }

        return null;
    }

    /**
     * Restore terminal settings.
     *
     * @param array<string, mixed>|null $settings
     */
    private function restoreTerminalSettings(?array $settings): void
    {
        if (!$this->isAtty) {
            return;
        }

        // Restore terminal settings via saved stty string
        if ($settings !== null && isset($settings['stty'])) {
            @proc_open('stty ' . $settings['stty'] . ' 2>/dev/null', [], $pipes);
        }

        // Reset stdin to canonical mode
        @shell_exec('stty icanon echo 2>/dev/null');
    }

    /**
     * Enable raw mode for stdin.
     *
     * Raw mode disables canonical mode (line buffering) so individual keypresses
     * (including arrow keys) are sent immediately without needing Enter.
     */
    private function enableRawMode(): void
    {
        if (!$this->isAtty) {
            return;
        }

        // Direct stty call - disable canonical mode (line buffering)
        // This makes keys available immediately without Enter
        @shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');

        // Make stdin non-blocking so stream_select timeout works properly
        if ($this->stdin !== null && is_resource($this->stdin)) {
            stream_set_blocking($this->stdin, false);
        }
    }

    /**
     * Wrap an index to stay within bounds.
     */
    private function wrapIndex(int $index, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        $index = $index % $max;
        if ($index < 0) {
            $index += $max;
        }

        return $index;
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * Main render dispatcher with Frame wrapper.
     */
    private function render(): void
    {
        $clearScreen = "\033[2J\033[H";
        $this->output->write($clearScreen);

        $size = $this->getTerminalSize();
        $this->currentSize = $size;

        // Check minimum terminal size
        if ($size['cols'] < 40 || $size['rows'] < 12) {
            $this->output->writeln('Terminal too small. Minimum size: 40x12');
            return;
        }

        $content = match ($this->currentPane) {
            Pane::Browse => $this->renderBrowseContent($size['cols'], $size['rows']),
            Pane::FilterType => $this->renderFilterTypeContent($size['cols'], $size['rows']),
            Pane::FilterUser => $this->renderFilterUserContent($size['cols'], $size['rows']),
            Pane::FilterOrg => $this->renderFilterOrgContent($size['cols'], $size['rows']),
            Pane::FilterRepo => $this->renderFilterRepoContent($size['cols'], $size['rows']),
            Pane::FilterTime => $this->renderFilterTimeContent($size['cols'], $size['rows']),
            Pane::Detail => $this->renderDetailContent($this->detailItem ?? [], $size['cols'], $size['rows']),
            Pane::Help => $this->renderHelpContent($size['cols'], $size['rows']),
        };

        $this->output->writeln(
            Frame::new(new RawContent($content))
                ->withTitle('GitHub Activity Browser')
                ->withBorder(Border::rounded())
                ->withBorderColor(Color::hex('#874BFD'))
                ->setSize($size['cols'], $size['rows'])
                ->render()
        );
    }

    /**
     * Render browse pane content using ItemList and SugarCraft styling.
     *
     * @param int $availableWidth Total available width for content
     * @param int $availableHeight Total available height for content
     */
    private function renderBrowseContent(int $availableWidth, int $availableHeight): string
    {
        // Reserve 2 rows for filter bar and status bar
        $listHeight = max(5, $availableHeight - 2);
        $listItems = $this->buildActivityList($availableWidth - 4, $listHeight);
        return $this->renderActivityList($listItems, $availableWidth);
    }

    /**
     * Build the activity list using ItemList.
     *
     * @param int $width Available width for the list
     * @param int $height Available height for the list
     */
    private function buildActivityList(int $width, int $height): ItemList
    {
        // Calculate field widths based on available space
        // Fixed overhead: prefix(2) + icon(1) + spaces(3) = 6 chars
        // Author field: fixed 12 chars (for longest username display)
        $fixedOverhead = 6;
        $authorWidth = 12;
        $minRepoWidth = 20;
        $maxRepoWidth = 35;

        $availableForDynamic = max(50, $width - $fixedOverhead - $authorWidth);
        $repoWidth = min($maxRepoWidth, max($minRepoWidth, (int)($availableForDynamic * 0.30)));
        $titleWidth = max(25, $width - $fixedOverhead - $repoWidth - $authorWidth);

        $items = [];
        foreach ($this->filteredActivity as $i => $item) {
            $prefix = ($i === $this->cursorIndex) ? '▶ ' : '  ';
            $icon = $this->getTypeIcon($item['type'] ?? '');
            $repo = mb_strimwidth($item['repo'] ?? '?', 0, $repoWidth, '…');
            $title = mb_strimwidth($item['title'] ?? '', 0, $titleWidth, '…');
            $author = mb_strimwidth($item['author'] ?? '?', 0, $authorWidth, '…');
            $line = sprintf('%s%s %s %s %s',
                $prefix,
                $icon,
                str_pad($repo, $repoWidth),
                str_pad($title, $titleWidth),
                str_pad($author, $authorWidth)
            );
            $items[] = new StringItem($line);
        }

        return ItemList::new($items, $width, $height)
            ->select($this->cursorIndex);
    }

    /**
     * Render the activity list view.
     *
     * @param ItemList $list The list to render
     * @param int $availableWidth Total available width for the content
     */
    private function renderActivityList(ItemList $list, int $availableWidth): string
    {
        $selectedReposCount = count(array_filter($this->selectedRepos, static fn($v) => $v === false));
        $timeLabel = $this->formatTimeLabel($this->filterTimeHours);

        $filterBar = sprintf(
            '[T]ype:%s [U]ser:%s [O]rg:%s [R]epos:%d [D]ate:%s | [?] Help | [Q] Quit',
            str_pad($this->filterType, 4),
            str_pad($this->filterUser ?: '-', 10),
            str_pad($this->filterOrg ?: '-', 10),
            $selectedReposCount,
            $timeLabel
        );

        $statusBar = sprintf(
            'Items: %d | Row: %d%s',
            count($this->filteredActivity),
            $this->cursorIndex + 1,
            $this->loadMoreCallback !== null ? ($this->hasMoreToLoad ? ' | [L] More' : ' | End') : ''
        );

        // Truncate filter bar if it exceeds available width
        if (mb_strlen($filterBar, 'UTF-8') > $availableWidth) {
            $filterBar = mb_substr($filterBar, 0, $availableWidth - 3) . '...';
        }

        $content = $list->view() . "\n" . $filterBar . "\n" . $statusBar;

        return $content;
    }

    /**
     * Get the icon for a given activity type.
     */
    private function getTypeIcon(string $type): string
    {
        return match ($type) {
            'push', 'commit' => '📦',
            'pr' => '🔀',
            'issue' => '🐛',
            'create' => '✨',
            'delete' => '🗑️',
            'fork' => '🍴',
            default => '⚪',
        };
    }

    /**
     * Render type filter content using Style/Border.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderFilterTypeContent(int $availableWidth, int $availableHeight): string
    {
        return $this->renderFilterOverlay('Activity Type', self::TYPE_OPTIONS, $this->filterType, $availableWidth, $availableHeight);
    }

    /**
     * Render time filter content using Style/Border.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderFilterTimeContent(int $availableWidth, int $availableHeight): string
    {
        $timeLabels = array_map(fn($h) => $this->formatTimeLabel($h), self::TIME_OPTIONS);
        $currentLabel = $this->formatTimeLabel($this->filterTimeHours);
        return $this->renderFilterOverlay('Time Window', $timeLabels, $currentLabel, $availableWidth, $availableHeight);
    }

    /**
     * Render a filter overlay using SugarCraft Style and Border.
     *
     * @param array<string> $options
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderFilterOverlay(string $title, array $options, string $currentValue, int $availableWidth, int $availableHeight): string
    {
        // Bound width to available space (overlay width = available - 4 for centering margin)
        $width = min(60, max(30, $availableWidth - 4));
        $halfWidth = (int)(($availableWidth - $width) / 2);

        $lines = [];

        foreach ($options as $option) {
            $isSelected = ($option === $currentValue);
            $marker = $isSelected ? '[x]' : '[ ]';
            $prefix = $isSelected ? '**' : '';
            $suffix = $isSelected ? '**' : '';

            $line = str_repeat(' ', max(0, $halfWidth)) . '│  ';
            $line .= $prefix . str_pad($marker, 5) . ' ' . $option . $suffix;
            $line .= str_repeat(' ', $width - strlen($marker . ' ' . $option) - 7);
            $line .= '│';
            $lines[] = $line;
        }

        $headerLine = str_repeat(' ', max(0, $halfWidth)) . '│' . str_pad(" FILTER: {$title} ", $width, ' ', STR_PAD_BOTH) . '│';
        $separatorLine = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';

        $resultLines = [];
        $resultLines[] = str_repeat(' ', max(0, $halfWidth)) . '┌' . str_repeat('─', $width) . '┐';
        $resultLines[] = $headerLine;
        $resultLines[] = $separatorLine;
        foreach ($lines as $line) {
            $resultLines[] = $line;
        }
        $resultLines[] = str_repeat(' ', max(0, $halfWidth)) . '└' . str_repeat('─', $width) . '┘';
        $resultLines[] = '';
        $resultLines[] = str_repeat(' ', max(0, $halfWidth)) . 'Use ↑↓ or j/k to select, Space/Enter to confirm, Q/ESC to cancel';

        return implode("\n", $resultLines);
    }

    /**
     * Render user filter content.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderFilterUserContent(int $availableWidth, int $availableHeight): string
    {
        return $this->renderTextFilterOverlay('User Filter', $this->filterUser, $availableWidth, $availableHeight);
    }

    /**
     * Render org filter content.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderFilterOrgContent(int $availableWidth, int $availableHeight): string
    {
        return $this->renderTextFilterOverlay('Organization Filter', $this->filterOrg, $availableWidth, $availableHeight);
    }

    /**
     * Render text filter input overlay using Style/Border.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderTextFilterOverlay(string $title, string $currentValue, int $availableWidth, int $availableHeight): string
    {
        // Bound width to available space
        $width = min(60, max(30, $availableWidth - 4));
        $halfWidth = (int)(($availableWidth - $width) / 2);

        $lines = [];
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '┌' . str_repeat('─', $width) . '┐';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│' . str_pad(" FILTER: {$title} ", $width, ' ', STR_PAD_BOTH) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  Current: ' . $currentValue . str_repeat(' ', max(0, $width - strlen($currentValue) - 14)) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  Type to filter, Backspace to delete' . str_repeat(' ', $width - 40) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '└' . str_repeat('─', $width) . '┘';
        $lines[] = '';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . 'Press Enter or Q/ESC to cancel';

        return implode("\n", $lines);
    }

    /**
     * Render repo filter toggle list using Style/Border.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderFilterRepoContent(int $availableWidth, int $availableHeight): string
    {
        // Bound width to available space
        $width = min(60, max(30, $availableWidth - 4));
        $halfWidth = (int)(($availableWidth - $width) / 2);

        $lines = [];
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '┌' . str_repeat('─', $width) . '┐';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│' . str_pad(' FILTER: Repository Toggle ', $width, ' ', STR_PAD_BOTH) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  [ ] = hidden, [x] = visible (shown)' . str_repeat(' ', $width - 38) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  Press Space/X to toggle, A=all visible, N=none' . str_repeat(' ', $width - 48) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';

        $repoKeys = array_keys($this->availableRepos);
        $hiddenCount = 0;

        foreach ($repoKeys as $i => $repo) {
            $isHidden = $this->selectedRepos[$repo] === true;
            $marker = $isHidden ? '[ ]' : '[x]';
            $prefix = $isHidden ? '' : '';
            $suffix = $isHidden ? '' : '';
            $hiddenCount += $isHidden ? 1 : 0;

            $line = str_repeat(' ', max(0, $halfWidth)) . '│  ';
            $line .= $prefix . str_pad($marker, 5) . ' ' . $repo . $suffix;
            $line .= str_repeat(' ', $width - strlen($marker . ' ' . $repo) - 7);
            $line .= '│';
            $lines[] = $line;
        }

        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . sprintf('│  Showing: %d repos, Hidden: %d%s', count($repoKeys) - $hiddenCount, $hiddenCount, str_repeat(' ', $width - 40)) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '└' . str_repeat('─', $width) . '┘';
        $lines[] = '';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . 'Use ↑↓ or j/k to select, Space/X to toggle, Q/ESC to close';

        return implode("\n", $lines);
    }

    /**
     * Render detail view content.
     *
     * @param array<string, mixed> $item
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderDetailContent(array $item, int $availableWidth, int $availableHeight): string
    {
        if ($item === []) {
            return '';
        }

        $type = (string)($item['type'] ?? 'unknown');
        $icon = $this->getTypeIcon($type);

        $lines = [];
        $lines[] = str_repeat('═', $availableWidth);
        $lines[] = sprintf('%s %s', $icon, $item['title'] ?? 'No title');
        $lines[] = str_repeat('═', $availableWidth);

        $lines[] = sprintf('  Repository:  %s', $item['repo'] ?? '?');
        $lines[] = sprintf('  Author:      %s', $item['author'] ?? '?');
        $lines[] = sprintf('  Date:        %s', $this->formatAge((string)($item['date'] ?? '')));
        $lines[] = sprintf('  URL:         %s', $item['url'] ?? '?');

        if ($type === 'pr') {
            $state = $item['state'] ?? 'open';
            $number = $item['number'] ?? '';
            $lines[] = sprintf('  PR Number:   %s (#%s)', $state, $number);
        }

        if ($type === 'commit') {
            $sha = substr((string)($item['sha'] ?? ''), 0, 7);
            $lines[] = sprintf('  SHA:         %s', $sha);
        }

        $description = (string)($item['description'] ?? $item['body'] ?? '');
        if ($description !== '') {
            $lines[] = '';
            $lines[] = '  Description:';
            $descLines = explode("\n", $description);
            $maxLines = min(20, $availableHeight - 12); // Reserve space for header and footer
            foreach (array_slice($descLines, 0, $maxLines) as $line) {
                if (mb_strlen($line, 'UTF-8') > $availableWidth - 8) {
                    $line = mb_substr($line, 0, $availableWidth - 12, 'UTF-8') . '...';
                }
                $lines[] = sprintf('    %s', $line);
            }
            if (count($descLines) > $maxLines) {
                $lines[] = sprintf('    ... (%d more lines)', count($descLines) - $maxLines);
            }
        }

        $lines[] = '';
        $lines[] = '  Actions:';
        $lines[] = '    [Enter] - Submit for review';
        $lines[] = '    [Q/ESC] - Back to list';

        return implode("\n", $lines);
    }

    /**
     * Render help overlay content.
     *
     * @param int $availableWidth Available width
     * @param int $availableHeight Available height
     */
    private function renderHelpContent(int $availableWidth, int $availableHeight): string
    {
        // Bound width to available space
        $width = min(60, max(30, $availableWidth - 4));
        $halfWidth = (int)(($availableWidth - $width) / 2);

        $lines = [];
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '┌' . str_repeat('─', $width) . '┐';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│' . str_pad(' Keyboard Shortcuts ', $width, ' ', STR_PAD_BOTH) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  Navigation:' . str_repeat(' ', $width - 18) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    ↑/↓ or j/k  Navigate list' . str_repeat(' ', $width - 28) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    ←/→ or h/l  Switch panes' . str_repeat(' ', $width - 30) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    Enter       Select item / Confirm' . str_repeat(' ', $width - 36) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    Q/ESC      Back / Quit' . str_repeat(' ', $width - 28) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  Filters:' . str_repeat(' ', $width - 14) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    T          Filter by type' . str_repeat(' ', $width - 26) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    U          Filter by user' . str_repeat(' ', $width - 26) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    O          Filter by organization' . str_repeat(' ', $width - 34) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    R          Toggle repositories' . str_repeat(' ', $width - 32) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    D          Filter by time window' . str_repeat(' ', $width - 34) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  Pagination (single repo mode):' . str_repeat(' ', $width - 32) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    L          Load more items' . str_repeat(' ', $width - 28) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '├' . str_repeat('─', $width) . '┤';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│  In Filter Mode:' . str_repeat(' ', $width - 20) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    Space      Toggle selection' . str_repeat(' ', $width - 30) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    A          Select all' . str_repeat(' ', $width - 22) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '│    N          Deselect all' . str_repeat(' ', $width - 24) . '│';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . '└' . str_repeat('─', $width) . '┘';
        $lines[] = '';
        $lines[] = str_repeat(' ', max(0, $halfWidth)) . 'Press any key to close...';

        return implode("\n", $lines);
    }

    /**
     * Render fallback for non-interactive mode.
     */
    private function renderNonInteractiveFallback(): void
    {
        $this->output->writeln('Non-interactive mode detected. Use --json for JSON output.');
    }

    /**
     * Render empty state.
     */
    private function renderEmpty(): void
    {
        $this->output->writeln('(no activity found)');
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
                return 'just';
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
        } catch (\Throwable) {
            return '?';
        }
    }

    /**
     * Format time hours into a readable label.
     */
    private function formatTimeLabel(int $hours): string
    {
        if ($hours < 24) {
            return $hours . 'h';
        }

        $days = (int)($hours / 24);
        if ($days < 7) {
            return $days . 'd';
        }

        $weeks = (int)($days / 7);
        return $weeks . 'w';
    }

    // -----------------------------------------------------------------------
    // State for main loop
    // -----------------------------------------------------------------------

    /** @var array{action: string, item?: array<string, mixed>}|null */
    private ?array $finalResult = null;

    /** @var array<string, mixed>|null */
    private ?array $detailItem = null;
}

/**
 * Simple content wrapper that implements Item for Frame.
 * Frame handles all bounds calculation internally.
 */
final class RawContent implements \SugarCraft\Dash\Foundation\Item, \SugarCraft\Dash\Foundation\Sizer
{
    public function __construct(
        private readonly string $content,
        private int $width = 0,
        private int $height = 0,
    ) {}

    public function render(): string
    {
        if ($this->width <= 0 || $this->height <= 0) {
            return $this->content;
        }

        $lines = explode("\n", $this->content);
        $adjusted = [];

        foreach ($lines as $line) {
            $lineWidth = mb_strlen($line, 'UTF-8');
            if ($lineWidth > $this->width) {
                // Truncate with ellipsis
                $line = mb_substr($line, 0, $this->width - 3, 'UTF-8') . '…';
            } elseif ($lineWidth < $this->width) {
                // Pad with spaces
                $line = $line . str_repeat(' ', $this->width - $lineWidth);
            }
            $adjusted[] = $line;
        }

        // Pad height with empty lines
        while (count($adjusted) < $this->height) {
            $adjusted[] = str_repeat(' ', $this->width);
        }

        return implode("\n", array_slice($adjusted, 0, $this->height));
    }

    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        return new self($this->content, $width, $height);
    }
}