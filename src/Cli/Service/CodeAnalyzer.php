<?php
declare(strict_types=1);

namespace Webhooks\Cli\Service;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Integrates with opencode CLI for code analysis and improvement suggestions.
 *
 * Provides graceful degradation when opencode is not available.
 */
final class CodeAnalyzer
{
    private const DEFAULT_ANALYZE_CMD = 'opencode analyze --dir {dir} --output json';
    private const DEFAULT_IMPROVE_CMD = 'opencode improve --dir {dir} --file {file} --line {line} --output json';

    private string $analyzeCommandTemplate;
    private string $improveCommandTemplate;
    private bool $opencodeAvailable;

    /** @var array<string, mixed>|null Cache for analyze results */
    private ?array $analyzeCache = null;

    public function __construct(
        ?string $analyzeCommand = null,
        ?string $improveCommand = null
    ) {
        $this->analyzeCommandTemplate = $analyzeCommand ?? (
            defined('OPENCODE_ANALYZE_CMD') && OPENCODE_ANALYZE_CMD !== ''
                ? OPENCODE_ANALYZE_CMD
                : (getenv('OPENCODE_ANALYZE_CMD') ?: self::DEFAULT_ANALYZE_CMD)
        );

        $this->improveCommandTemplate = $improveCommand ?? (
            defined('OPENCODE_IMPROVE_CMD') && OPENCODE_IMPROVE_CMD !== ''
                ? OPENCODE_IMPROVE_CMD
                : (getenv('OPENCODE_IMPROVE_CMD') ?: self::DEFAULT_IMPROVE_CMD)
        );

        $this->opencodeAvailable = $this->checkOpencodeAvailability();
    }

    /**
     * Run opencode analyze on a directory.
     *
     * @param string $dir Directory to analyze
     * @param bool $useCache Use cached results if available
     * @return array<string, mixed> Analysis results with 'issues', 'summary', etc.
     * @throws \RuntimeException If opencode is not available or analysis fails
     */
    public function analyze(string $dir, bool $useCache = true): array
    {
        if (!$this->opencodeAvailable) {
            return $this->createUnavailableResult('opencode not available');
        }

        if ($useCache && $this->analyzeCache !== null) {
            return $this->analyzeCache;
        }

        $command = str_replace('{dir}', $this->escapeShellArg($dir), $this->analyzeCommandTemplate);

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        $rawOutput = implode("\n", $output);

        if ($exitCode !== 0) {
            return $this->createErrorResult("opencode analyze failed with exit code {$exitCode}: {$rawOutput}");
        }

        $result = $this->parseJsonOutput($rawOutput);
        if ($result === null) {
            return $this->createErrorResult('Failed to parse opencode JSON output');
        }

        $this->analyzeCache = $result;
        return $result;
    }

    /**
     * Run opencode improve for a specific file and line.
     *
     * @param string $dir Directory containing the file
     * @param string $file File path relative to dir (or absolute)
     * @param int $line Line number to get improvement suggestion for
     * @return array<string, mixed> Improvement result with 'suggestion', 'diff', etc.
     * @throws \RuntimeException If opencode is not available or improve fails
     */
    public function improve(string $dir, string $file, int $line): array
    {
        if (!$this->opencodeAvailable) {
            return $this->createUnavailableResult('opencode not available');
        }

        // Resolve absolute path
        if (!self::isAbsolutePath($file)) {
            $file = rtrim($dir, '/') . '/' . $file;
        }

        $command = str_replace(
            ['{dir}', '{file}', '{line}'],
            [
                $this->escapeShellArg($dir),
                $this->escapeShellArg($file),
                (int)$line,
            ],
            $this->improveCommandTemplate
        );

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        $rawOutput = implode("\n", $output);

        if ($exitCode !== 0) {
            return $this->createErrorResult("opencode improve failed with exit code {$exitCode}: {$rawOutput}");
        }

        $result = $this->parseJsonOutput($rawOutput);
        if ($result === null) {
            return $this->createErrorResult('Failed to parse opencode JSON output');
        }

        return $result;
    }

    /**
     * Check if opencode is available.
     */
    public function isAvailable(): bool
    {
        return $this->opencodeAvailable;
    }

    /**
     * Clear the analysis cache.
     */
    public function clearCache(): void
    {
        $this->analyzeCache = null;
    }

    /**
     * Get analysis results formatted for PR comment.
     *
     * @param array<string, mixed> $analysis Raw analysis from analyze()
     * @return array<string, mixed> Formatted results with 'issues_by_file', 'summary', etc.
     */
    public function formatForComment(array $analysis): array
    {
        $formatted = [
            'total_issues' => 0,
            'issues_by_severity' => [
                'error' => 0,
                'warning' => 0,
                'info' => 0,
                'hint' => 0,
            ],
            'issues_by_type' => [],
            'issues_by_file' => [],
            'files_changed' => [],
            'issues' => [],
        ];

        if (!isset($analysis['issues']) || !is_array($analysis['issues'])) {
            return $formatted;
        }

        foreach ($analysis['issues'] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $formatted['total_issues']++;

            $severity = $issue['severity'] ?? $issue['level'] ?? 'info';
            if (isset($formatted['issues_by_severity'][$severity])) {
                $formatted['issues_by_severity'][$severity]++;
            }

            $type = $issue['type'] ?? $issue['category'] ?? 'unknown';
            if (!isset($formatted['issues_by_type'][$type])) {
                $formatted['issues_by_type'][$type] = 0;
            }
            $formatted['issues_by_type'][$type]++;

            $file = $issue['file'] ?? $issue['path'] ?? 'unknown';
            if (!isset($formatted['issues_by_file'][$file])) {
                $formatted['issues_by_file'][$file] = [];
                $formatted['files_changed'][] = $file;
            }
            $formatted['issues_by_file'][$file][] = $issue;
            $formatted['issues'][] = $issue;
        }

        return $formatted;
    }

    /**
     * Parse JSON output from opencode.
     *
     * @return array<string, mixed>|null Parsed JSON or null if invalid
     */
    private function parseJsonOutput(string $rawOutput): ?array
    {
        // Try to extract JSON from output (in case there's extra text)
        $jsonStart = strpos($rawOutput, '{');
        if ($jsonStart !== false) {
            $rawOutput = substr($rawOutput, $jsonStart);
        }

        $jsonEnd = strrpos($rawOutput, '}');
        if ($jsonEnd !== false) {
            $rawOutput = substr($rawOutput, 0, $jsonEnd + 1);
        }

        $data = json_decode($rawOutput, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
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
     * Create an unavailable result.
     *
     * @return array<string, mixed>
     */
    private function createUnavailableResult(string $message): array
    {
        return [
            'success' => false,
            'error' => true,
            'message' => $message,
            'available' => false,
        ];
    }

    /**
     * Create an error result.
     *
     * @return array<string, mixed>
     */
    private function createErrorResult(string $message): array
    {
        return [
            'success' => false,
            'error' => true,
            'message' => $message,
            'available' => true,
        ];
    }

    /**
     * Escape a string for shell execution.
     */
    private function escapeShellArg(string $arg): string
    {
        return escapeshellarg($arg);
    }

    /**
     * Check if a path is absolute.
     */
    private static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' ||
            (strlen($path) > 2 && $path[1] === ':'));
    }
}
