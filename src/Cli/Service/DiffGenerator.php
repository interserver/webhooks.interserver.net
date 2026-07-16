<?php
declare(strict_types=1);

namespace Webhooks\Cli\Service;

/**
 * Generates unified diffs for suggested code changes.
 */
final class DiffGenerator
{
    private const DEFAULT_CONTEXT_LINES = 3;

    private int $contextLines;

    public function __construct(int $contextLines = self::DEFAULT_CONTEXT_LINES)
    {
        $this->contextLines = $contextLines;
    }

    /**
     * Generate a unified diff for a file change.
     *
     * @param string $filePath Original file path
     * @param string $originalContent Original file content
     * @param string $newContent Modified file content
     * @param string|null $newFilePath Path for the new file (if creating new file)
     * @return string Unified diff output
     */
    public function generateDiff(
        string $filePath,
        string $originalContent,
        string $newContent,
        ?string $newFilePath = null
    ): string {
        // Create temp files for diff
        $originalFile = $this->createTempFile($originalContent);
        $newFile = $this->createTempFile($newContent);

        $originalLabel = $filePath;
        $newLabel = $newFilePath ?? $filePath;

        $command = sprintf(
            'diff -U %d --label %s --label %s %s %s 2>&1 || true',
            $this->contextLines,
            $this->escapeShellArg($originalLabel),
            $this->escapeShellArg($newLabel),
            $this->escapeShellArg($originalFile),
            $this->escapeShellArg($newFile)
        );

        $output = [];
        @exec($command, $output, $_);
        $diff = implode("\n", $output);

        // Clean up temp files
        @unlink($originalFile);
        @unlink($newFile);

        // Format as proper unified diff if diff command didn't produce one
        if (!str_contains($diff, '---') && !str_contains($diff, '+++')) {
            return $this->formatUnifiedDiff($originalLabel, $newLabel, $originalContent, $newContent);
        }

        return $diff;
    }

    /**
     * Generate a diff from original file to suggested fix.
     *
     * @param string $filePath File path
     * @param array<string, string> $lineChanges Map of line number => replacement content
     * @param array<int, string> $originalContent Original file content (lines as array)
     * @return string Unified diff
     */
    public function generateLineChangesDiff(
        string $filePath,
        array $lineChanges,
        array $originalContent
    ): string {
        if (empty($lineChanges)) {
            return '';
        }

        // Build new content by applying changes
        $newContent = $originalContent;
        krsort($lineChanges); // Apply from highest line to lowest to preserve line numbers

        foreach ($lineChanges as $lineNum => $replacement) {
            $index = (int) $lineNum - 1; // Convert to 0-based index
            if (isset($newContent[$index])) {
                $newContent[$index] = $replacement;
            }
        }

        return $this->generateDiff($filePath, implode("\n", $originalContent), implode("\n", $newContent));
    }

    /**
     * Generate a diff for a new file.
     *
     * @param string $newFilePath Path for the new file
     * @param string $newContent Content of the new file
     * @return string Unified diff showing file creation
     */
    public function generateNewFileDiff(string $newFilePath, string $newContent): string
    {
        // Create unified diff header for new file
        $timestamp = date('Y-m-d H:i:s');
        $diff = sprintf(
            "diff --git a/%s b/%s\nnew file mode 100644\n--- /dev/null\n+++ b/%s%s\t%s\n",
            $newFilePath,
            $newFilePath,
            $newFilePath,
            $this->contextLines > 0 ? sprintf(" (context lines: %d)", $this->contextLines) : '',
            $timestamp
        );

        $lines = explode("\n", $newContent);
        foreach ($lines as $line) {
            $diff .= '+' . $line . "\n";
        }

        return $diff;
    }

    /**
     * Generate a diff for a deleted file.
     *
     * @param string $filePath Path to the deleted file
     * @param string $originalContent Original content of the file
     * @return string Unified diff showing file deletion
     */
    public function generateDeletedFileDiff(string $filePath, string $originalContent): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $diff = sprintf(
            "diff --git a/%s b/%s\ndeleted file mode 100644\n--- a/%s\n+++ /dev/null\t%s\n",
            $filePath,
            $filePath,
            $filePath,
            $timestamp
        );

        $lines = explode("\n", $originalContent);
        foreach ($lines as $line) {
            $diff .= '-' . $line . "\n";
        }

        return $diff;
    }

    /**
     * Generate a diff from git for uncommitted changes.
     *
     * @param string $dir Directory containing the git repository
     * @param string|null $filePath Specific file path (optional)
     * @return string Unified diff
     */
    public function generateGitDiff(string $dir, ?string $filePath = null): string
    {
        $escapedDir = $this->escapeShellArg($dir);

        if ($filePath !== null) {
            $escapedFile = $this->escapeShellArg($filePath);
            $command = sprintf(
                'cd %s && git diff --no-color -U %d -- %s 2>&1',
                $escapedDir,
                $this->contextLines,
                $escapedFile
            );
        } else {
            $command = sprintf(
                'cd %s && git diff --no-color -U %d 2>&1',
                $escapedDir,
                $this->contextLines
            );
        }

        $output = [];
        @exec($command, $output, $_);

        return implode("\n", $output);
    }

    /**
     * Generate a patch-compatible diff.
     *
     * @param string $filePath Original file path
     * @param string $originalContent Original content
     * @param string $newContent New content
     * @return string Patch-style diff
     */
    public function generatePatch(string $filePath, string $originalContent, string $newContent): string
    {
        $diff = $this->generateDiff($filePath, $originalContent, $newContent);

        // Add patch header
        return sprintf(
            "From: Code Review Bot <code-review-bot@webhooks.interserver.net>\nSubject: [PATCH] %s\n\n%s",
            basename($filePath),
            $diff
        );
    }

    /**
     * Format diff as a markdown code block.
     *
     * @param string $diff Raw diff output
     * @return string Markdown formatted diff
     */
    public function formatAsMarkdown(string $diff): string
    {
        return "```diff\n{$diff}\n```";
    }

    /**
     * Check if a diff has actual changes.
     */
    public function diffHasChanges(string $diff): bool
    {
        // Check if diff has proper headers
        if (!str_contains($diff, '---') || !str_contains($diff, '+++')) {
            return false;
        }

        // Check for actual change lines (+ or - that aren't part of +++ or ---)
        // Split into lines and look for lines starting with + or - (but not +++ or ---)
        $lines = explode("\n", $diff);
        foreach ($lines as $line) {
            if (strlen($line) > 0 && ($line[0] === '+' || $line[0] === '-')) {
                // Skip header lines (+++ or --- at start of line)
                if (strpos($line, '+++') === 0 || strpos($line, '---') === 0) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Create a temporary file with content.
     */
    private function createTempFile(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'diff_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp file');
        }
        file_put_contents($tmpFile, $content);
        return $tmpFile;
    }

    /**
     * Format a unified diff manually when diff command fails.
     */
    private function formatUnifiedDiff(
        string $originalLabel,
        string $newLabel,
        string $originalContent,
        string $newContent
    ): string {
        $originalLines = explode("\n", $originalContent);
        $newLines = explode("\n", $newContent);

        $timestamp = date('Y-m-d H:i:s');
        $diff = sprintf(
            "--- a/%s\t%s\n+++ b/%s\t%s\n",
            $originalLabel,
            $timestamp,
            $newLabel,
            $timestamp
        );

        // Simple line-by-line diff
        $maxLines = max(count($originalLines), count($newLines));
        $oldLine = 1;
        $newLine = 1;

        for ($i = 0; $i < $maxLines; $i++) {
            $oldLineStr = $originalLines[$i] ?? null;
            $newLineStr = $newLines[$i] ?? null;

            if ($oldLineStr === $newLineStr) {
                $diff .= ' ' . ($oldLineStr ?? '') . "\n";
                $oldLine++;
                $newLine++;
            } else {
                if ($oldLineStr !== null) {
                    $diff .= '-' . $oldLineStr . "\n";
                }
                if ($newLineStr !== null) {
                    $diff .= '+' . $newLineStr . "\n";
                }
                $oldLine++;
                $newLine++;
            }
        }

        return $diff;
    }

    /**
     * Escape a string for shell execution.
     */
    private function escapeShellArg(string $arg): string
    {
        return escapeshellarg($arg);
    }
}
