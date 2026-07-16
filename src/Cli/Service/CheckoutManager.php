<?php
declare(strict_types=1);

namespace Webhooks\Cli\Service;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manages git repository checkouts for PR review.
 *
 * Handles cloning/fetching repositories at specific PR branches,
 * cleanup of checkout directories, signal handling for SIGINT/SIGTERM,
 * periodic cleanup of old checkouts (>24 hours), and disk space management.
 */
final class CheckoutManager
{
    private const DEFAULT_CHECKOUT_ROOT = '/tmp/pr-checkouts';
    private const MAX_CHECKOUT_AGE_SECONDS = 86400; // 24 hours
    private const MIN_DISK_SPACE_MB = 100;
    private const DISK_SPACE_CHECK_THRESHOLD_MB = 500;

    private string $checkoutRoot;
    private bool $opencodeAvailable;

    /** @var array<string, string> Track active checkouts: jobId => checkoutPath */
    private array $activeCheckouts = [];

    /** @var array<string, int> Track checkout creation times: checkoutPath => timestamp */
    private array $checkoutTimestamps = [];

    public function __construct(?string $checkoutRoot = null)
    {
        $this->checkoutRoot = $checkoutRoot ?? (
            defined('CHECKOUT_ROOT') && CHECKOUT_ROOT !== ''
                ? CHECKOUT_ROOT
                : (getenv('CHECKOUT_ROOT') ?: self::DEFAULT_CHECKOUT_ROOT)
        );
        $this->opencodeAvailable = $this->checkOpencodeAvailability();
    }

    /**
     * Clone/fetch repository at PR branch.
     *
     * @param string $repo Repository in owner/repo format
     * @param int $prNumber PR number
     * @param string $branch Branch to checkout (defaults to PR head branch)
     * @param string|null $sha Specific commit SHA (optional)
     * @return string Path to checkout directory
     * @throws \RuntimeException If checkout fails
     */
    public function checkout(string $repo, int $prNumber, string $branch = '', ?string $sha = null): string
    {
        $checkoutPath = $this->getCheckoutPath($repo, $prNumber);

        // Ensure checkout root exists
        if (!is_dir($this->checkoutRoot)) {
            if (!mkdir($this->checkoutRoot, 0755, true) && !is_dir($this->checkoutRoot)) {
                throw new \RuntimeException("Failed to create checkout root: {$this->checkoutRoot}");
            }
        }

        // Check disk space before checkout
        $this->ensureDiskSpace();

        // Check for stale checkout and remove
        if (is_dir($checkoutPath)) {
            $this->removeDirectory($checkoutPath);
        }

        // Clone the repository
        $escapedRepo = $this->escapeShellArg($repo);
        $escapedPath = $this->escapeShellArg($checkoutPath);

        if ($branch !== '') {
            // Shallow clone with specific branch
            $command = sprintf(
                'git clone --depth 1 --branch %s https://github.com/%s.git %s 2>&1',
                $this->escapeShellArg($branch),
                $escapedRepo,
                $escapedPath
            );
        } else {
            // Full clone if no branch specified
            $command = sprintf(
                'git clone --depth 1 https://github.com/%s.git %s 2>&1',
                $escapedRepo,
                $escapedPath
            );
        }

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $errorMessage = implode("\n", $output);
            throw new \RuntimeException("Git clone failed: {$errorMessage}");
        }

        // If specific SHA provided, checkout that commit
        if ($sha !== null && $sha !== '') {
            $this->checkoutSha($checkoutPath, $sha);
        }

        // Register cleanup handler for signals
        $this->registerSignalHandlers();

        // Track this checkout
        $jobId = $this->generateJobId($repo, $prNumber);
        $this->activeCheckouts[$jobId] = $checkoutPath;
        $this->checkoutTimestamps[$checkoutPath] = time();

        return $checkoutPath;
    }

    /**
     * Remove checkout directory after review.
     *
     * @param string $checkoutDir Path to checkout directory
     * @return bool True if cleanup successful or directory didn't exist
     */
    public function cleanup(string $checkoutDir): bool
    {
        if (!is_dir($checkoutDir)) {
            return true;
        }

        // Remove from tracking
        foreach ($this->activeCheckouts as $jobId => $path) {
            if ($path === $checkoutDir) {
                unset($this->activeCheckouts[$jobId]);
                break;
            }
        }
        unset($this->checkoutTimestamps[$checkoutDir]);

        return $this->removeDirectory($checkoutDir);
    }

    /**
     * Cleanup all checkouts older than MAX_CHECKOUT_AGE_SECONDS.
     *
     * @return int Number of directories removed
     */
    public function cleanupOldCheckouts(): int
    {
        if (!is_dir($this->checkoutRoot)) {
            return 0;
        }

        $removed = 0;
        $cutoffTime = time() - self::MAX_CHECKOUT_AGE_SECONDS;

        $entries = @scandir($this->checkoutRoot);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->checkoutRoot . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            // Check if directory is older than 24 hours
            $mtime = @filemtime($fullPath);
            if ($mtime !== false && $mtime < $cutoffTime) {
                if ($this->removeDirectory($fullPath)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Cleanup oldest checkouts when disk space is low.
     *
     * @param int $requiredMb Minimum space required in MB
     * @return int Number of directories removed
     */
    public function cleanupForDiskSpace(int $requiredMb = 500): int
    {
        if (!is_dir($this->checkoutRoot)) {
            return 0;
        }

        $availableSpace = $this->getAvailableDiskSpaceMb();
        if ($availableSpace >= $requiredMb + self::MIN_DISK_SPACE_MB) {
            return 0;
        }

        // Get all checkout directories sorted by age (oldest first)
        $checkouts = $this->getCheckoutDirectoriesByAge();
        $removed = 0;
        $targetSpace = $availableSpace + ($requiredMb * 2); // Free up 2x required

        foreach ($checkouts as $path => $age) {
            if ($availableSpace >= $targetSpace) {
                break;
            }

            $size = $this->getDirectorySizeMb($path);
            if ($this->removeDirectory($path)) {
                $removed++;
                $availableSpace += $size;
                unset($this->checkoutTimestamps[$path]);

                // Also remove from active checkouts if present
                foreach ($this->activeCheckouts as $jobId => $activePath) {
                    if ($activePath === $path) {
                        unset($this->activeCheckouts[$jobId]);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Get the checkout path for a repo/PR combination.
     *
     * @param string $repo Repository in owner/repo format
     * @param int $prNumber PR number
     * @return string Full path to checkout directory
     */
    public function getCheckoutPath(string $repo, int $prNumber): string
    {
        return sprintf('%s/%s/%d', $this->checkoutRoot, $repo, $prNumber);
    }

    /**
     * Check if opencode is available.
     */
    public function isOpencodeAvailable(): bool
    {
        return $this->opencodeAvailable;
    }

    /**
     * Get list of active checkouts.
     *
     * @return array<string, string> jobId => checkoutPath
     */
    public function getActiveCheckouts(): array
    {
        return $this->activeCheckouts;
    }

    /**
     * Register SIGINT/SIGTERM handlers for cleanup.
     */
    private function registerSignalHandlers(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        $manager = $this;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($manager) {
                $manager->cleanupAll();
                exit(1);
            });
            pcntl_signal(SIGTERM, function () use ($manager) {
                $manager->cleanupAll();
                exit(1);
            });
        }
        $registered = true;
    }

    /**
     * Cleanup all active checkouts (called on signals).
     */
    public function cleanupAll(): void
    {
        foreach ($this->activeCheckouts as $path) {
            $this->cleanup($path);
        }
        $this->activeCheckouts = [];
        $this->checkoutTimestamps = [];
    }

    /**
     * Checkout a specific SHA in an existing repository.
     */
    private function checkoutSha(string $repoPath, string $sha): void
    {
        $escapedSha = $this->escapeShellArg($sha);
        $command = sprintf(
            'cd %s && git fetch origin %s --depth=1 && git checkout %s 2>&1',
            $this->escapeShellArg($repoPath),
            $escapedSha,
            $escapedSha
        );

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Git checkout SHA failed: " . implode("\n", $output));
        }
    }

    /**
     * Remove a directory and its contents recursively.
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $command = sprintf('rm -rf %s', $this->escapeShellArg($dir));
        @exec($command, $_, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Get available disk space in MB.
     */
    private function getAvailableDiskSpaceMb(): int
    {
        if (PHP_OS === 'Linux' && is_dir($this->checkoutRoot)) {
            $command = sprintf('df -m %s | tail -1 | awk \'{print $4}\'', $this->escapeShellArg($this->checkoutRoot));
            $output = [];
            @exec($command, $output, $_);
            if (!empty($output[0]) && is_numeric($output[0])) {
                return (int)$output[0];
            }
        }

        // Fallback: assume plenty of space
        return 1000;
    }

    /**
     * Ensure sufficient disk space is available.
     *
     * @throws \RuntimeException If disk space is critically low
     */
    private function ensureDiskSpace(): void
    {
        $available = $this->getAvailableDiskSpaceMb();
        if ($available < self::DISK_SPACE_CHECK_THRESHOLD_MB) {
            // Try to free up space first
            $freed = $this->cleanupForDiskSpace(self::MIN_DISK_SPACE_MB);
            if ($freed > 0) {
                $available = $this->getAvailableDiskSpaceMb();
            }
        }

        if ($available < self::MIN_DISK_SPACE_MB) {
            throw new \RuntimeException(
                "Insufficient disk space: {$available}MB available, {$this->checkoutRoot} requires at least " . self::MIN_DISK_SPACE_MB . "MB"
            );
        }
    }

    /**
     * Get all checkout directories sorted by age (oldest first).
     *
     * @return array<string, int> path => age in seconds
     */
    private function getCheckoutDirectoriesByAge(): array
    {
        $checkouts = [];

        if (!is_dir($this->checkoutRoot)) {
            return $checkouts;
        }

        $entries = @scandir($this->checkoutRoot);
        if ($entries === false) {
            return $checkouts;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!is_dir($this->checkoutRoot . '/' . $entry)) {
                continue;
            }

            $path = $this->checkoutRoot . '/' . $entry;
            $mtime = @filemtime($path);
            $age = $mtime !== false ? time() - $mtime : 0;
            $checkouts[$path] = $age;
        }

        asort($checkouts);
        return $checkouts;
    }

    /**
     * Get directory size in MB.
     */
    private function getDirectorySizeMb(string $path): int
    {
        $command = sprintf('du -sm %s 2>/dev/null | cut -f1', $this->escapeShellArg($path));
        $output = [];
        @exec($command, $output, $_);
        return !empty($output[0]) && is_numeric($output[0]) ? (int)$output[0] : 0;
    }

    /**
     * Check if opencode CLI is available.
     */
    private function checkOpencodeAvailability(): bool
    {
        $output = [];
        @exec('which opencode 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Generate a job ID for tracking.
     */
    private function generateJobId(string $repo, int $prNumber): string
    {
        return sprintf('%s-%d-%d', $repo, $prNumber, time());
    }

    /**
     * Escape a string for shell execution.
     */
    private function escapeShellArg(string $arg): string
    {
        return escapeshellarg($arg);
    }
}
