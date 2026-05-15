#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * GitHub Webhook JSON Field Analyzer - Parallel/Forked Version
 *
 * Analyzes log files to determine which fields appear across all events/actions
 * and which fields are exclusive to certain groups.
 *
 * Usage: php analyze_fields.php [log.txt] [--limit=N] [--sample] [--out-dir=DIR] [--forks=N]
 */

declare(ticks = 1);

class ParallelFieldAnalyzer
{
    private string $logFile;
    private string $outDir;
    private int $limit = 0;
    private bool $sample = false;
    private int $forks = 10;
    private string $tempDir;

    public function __construct(string $logFile = 'log.txt', string $outDir = 'out')
    {
        $this->logFile = $logFile;
        $this->outDir = $outDir;
        $this->tempDir = sys_get_temp_dir() . '/field_analyzer_' . getmypid();
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function setSample(bool $sample): self
    {
        $this->sample = $sample;
        return $this;
    }

    public function setForks(int $forks): self
    {
        $this->forks = max(1, $forks);
        return $this;
    }

    public function analyze(): void
    {
        // Create temp directory for intermediate results
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        $files = $this->loadFileList();
        $total = count($files);

        if ($this->limit > 0 && count($files) > $this->limit) {
            if ($this->sample) {
                shuffle($files);
                $files = array_slice($files, 0, $this->limit);
                echo "Sampling {$this->limit} files from {$total} total...\n";
            } else {
                $files = array_slice($files, 0, $this->limit);
                echo "Processing first {$this->limit} of {$total} files...\n";
            }
        } else {
            echo "Processing {$total} files...\n";
        }

        // Split files into chunks for parallel processing
        $chunks = $this->splitIntoChunks($files, $this->forks);
        $numChunks = count($chunks);

        echo "Splitting work into {$numChunks} parallel tasks...\n";

        // Fork child processes
        $pids = [];
        $status = [];

        for ($i = 0; $i < $numChunks; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                fwrite(STDERR, "Failed to fork process {$i}\n");
                continue;
            }

            if ($pid == 0) {
                // Child process
                $this->childProcess($i, $chunks[$i]);
                exit(0);
            }

            $pids[$i] = $pid;
        }

        // Wait for all children to complete
        echo "Waiting for {$numChunks} child processes to complete...\n";
        $completed = 0;
        foreach ($pids as $i => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $completed++;
            if ($completed % 2 === 0 || $completed === $numChunks) {
                echo "  Completed {$completed}/{$numChunks} child processes...\n";
            }
        }

        echo "All child processes completed. Collating results...\n";

        // Collate results from all children
        $this->collateResults($numChunks);

        // Cleanup temp directory
        $this->cleanup();

        echo "Done. Output written to: {$this->outDir}/\n";
    }

    private function childProcess(int $workerId, array $files): void
    {
        $fieldCounts = [];
        $groupCounts = [];
        $fieldPresence = [];
        $allFields = [];

        $processed = 0;
        $errors = 0;

        foreach ($files as $file) {
            $result = $this->processFile($file, $fieldCounts, $groupCounts, $fieldPresence, $allFields);

            if ($result === true) {
                $processed++;
            } elseif ($result === false) {
                $errors++;
            }
        }

        // Write results to temp file
        $resultFile = "{$this->tempDir}/worker_{$workerId}.json";
        $results = [
            'worker_id' => $workerId,
            'processed' => $processed,
            'errors' => $errors,
            'field_counts' => $fieldCounts,
            'group_counts' => $groupCounts,
            'field_presence' => $fieldPresence,
            'all_fields' => $allFields
        ];

        file_put_contents($resultFile, json_encode($results, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, int>> $groupCounts
     * @param array<string, array<string, array<string, bool>>> $fieldPresence
     * @param array<string> $allFields
     */
    private function processFile(
        string $filePath,
        array &$fieldCounts,
        array &$groupCounts,
        array &$fieldPresence,
        array &$allFields
    ): ?bool {
        $fullPath = $filePath;
        $scriptDir = dirname(__FILE__);

        $basePaths = ['', __DIR__ . '/', $scriptDir . '/', $scriptDir . '/../'];

        $found = false;
        foreach ($basePaths as $base) {
            $testPath = $base . $filePath;
            if (file_exists($testPath)) {
                $fullPath = $testPath;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        $content = file_get_contents($fullPath);
        if ($content === false || strlen($content) < 10) {
            return false;
        }

        $json = json_decode($content, true, 512, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === null || !is_array($json)) {
            return false;
        }

        $event = $json['event'] ?? 'unknown';
        $action = $json['data']['action'] ?? 'none';

        if (!isset($groupCounts[$event])) {
            $groupCounts[$event] = [];
        }
        if (!isset($groupCounts[$event][$action])) {
            $groupCounts[$event][$action] = 0;
        }
        $groupCounts[$event][$action]++;

        if (!isset($fieldPresence[$event])) {
            $fieldPresence[$event] = [];
        }
        if (!isset($fieldPresence[$event][$action])) {
            $fieldPresence[$event][$action] = [];
        }

        $fields = $this->extractFields($json);

        foreach ($fields as $field) {
            if (!isset($fieldCounts[$event])) {
                $fieldCounts[$event] = [];
            }
            if (!isset($fieldCounts[$event][$action])) {
                $fieldCounts[$event][$action] = [];
            }
            if (!isset($fieldCounts[$event][$action][$field])) {
                $fieldCounts[$event][$action][$field] = 0;
                if (!in_array($field, $allFields, true)) {
                    $allFields[] = $field;
                }
            }
            $fieldCounts[$event][$action][$field]++;

            $fieldPresence[$event][$action][$field] = true;
        }

        return true;
    }

    /**
     * @return array<string>
     */
    private function loadFileList(): array
    {
        $content = file_get_contents($this->logFile);
        if ($content === false) {
            throw new RuntimeException("Cannot read log.txt");
        }

        $lines = explode("\n", trim($content));
        $files = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (preg_match('/^\d+:\s*(.+)$/', $line, $matches)) {
                $line = $matches[1];
            }
            $files[] = $line;
        }

        return $files;
    }

    /**
     * @param array<string> $files
     * @return array<array<string>>
     */
    private function splitIntoChunks(array $files, int $numChunks): array
    {
        $chunks = array_fill(0, $numChunks, []);
        $fileCount = count($files);

        if ($fileCount === 0) {
            return $chunks;
        }

        $filesPerChunk = (int)ceil($fileCount / $numChunks);

        for ($i = 0; $i < $fileCount; $i++) {
            $chunkIndex = (int)floor($i / $filesPerChunk);
            if ($chunkIndex >= $numChunks) {
                $chunkIndex = $numChunks - 1;
            }
            $chunks[$chunkIndex][] = $files[$i];
        }

        return $chunks;
    }

    private function extractFields(array $data, string $prefix = ''): array
    {
        static $fieldList = [];

        if (empty($prefix)) {
            $fieldList = [];
        }

        foreach ($data as $key => $value) {
            $path = empty($prefix) ? (string)$key : $prefix . '.' . (string)$key;

            if (is_array($value)) {
                $fieldList[] = $path;

                if ($this->isSequentialArray($value)) {
                    $fieldList[] = $path . '[]';
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $this->extractFields($item, $path);
                        } else {
                            $fieldList[] = $path . '[]';
                        }
                    }
                } else {
                    $this->extractFields($value, $path);
                }
            } else {
                $fieldList[] = $path;
            }
        }

        if (empty($prefix)) {
            return array_values(array_unique($fieldList));
        }

        return [];
    }

    private function isSequentialArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function collateResults(int $numChunks): void
    {
        // Initialize accumulators
        $fieldCounts = [];
        $groupCounts = [];
        $fieldPresence = [];
        $allFields = [];
        $totalProcessed = 0;
        $totalErrors = 0;

        // Load and merge results from each worker
        for ($i = 0; $i < $numChunks; $i++) {
            $resultFile = "{$this->tempDir}/worker_{$i}.json";

            if (!file_exists($resultFile)) {
                fwrite(STDERR, "Warning: Result file missing for worker {$i}\n");
                continue;
            }

            $content = file_get_contents($resultFile);
            $results = json_decode($content, true, 512, JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($results === null) {
                fwrite(STDERR, "Warning: Failed to decode results from worker {$i}\n");
                continue;
            }

            $totalProcessed += $results['processed'] ?? 0;
            $totalErrors += $results['errors'] ?? 0;

            // Merge field_counts
            foreach ($results['field_counts'] ?? [] as $event => $actions) {
                if (!isset($fieldCounts[$event])) {
                    $fieldCounts[$event] = [];
                }
                foreach ($actions as $action => $fields) {
                    if (!isset($fieldCounts[$event][$action])) {
                        $fieldCounts[$event][$action] = [];
                    }
                    foreach ($fields as $field => $count) {
                        if (!isset($fieldCounts[$event][$action][$field])) {
                            $fieldCounts[$event][$action][$field] = 0;
                        }
                        $fieldCounts[$event][$action][$field] += $count;
                    }
                }
            }

            // Merge group_counts
            foreach ($results['group_counts'] ?? [] as $event => $actions) {
                if (!isset($groupCounts[$event])) {
                    $groupCounts[$event] = [];
                }
                foreach ($actions as $action => $count) {
                    if (!isset($groupCounts[$event][$action])) {
                        $groupCounts[$event][$action] = 0;
                    }
                    $groupCounts[$event][$action] += $count;
                }
            }

            // Merge field_presence (OR operation)
            foreach ($results['field_presence'] ?? [] as $event => $actions) {
                if (!isset($fieldPresence[$event])) {
                    $fieldPresence[$event] = [];
                }
                foreach ($actions as $action => $fields) {
                    if (!isset($fieldPresence[$event][$action])) {
                        $fieldPresence[$event][$action] = [];
                    }
                    foreach ($fields as $field => $present) {
                        $fieldPresence[$event][$action][$field] = true;
                    }
                }
            }

            // Merge all_fields
            foreach ($results['all_fields'] ?? [] as $field) {
                if (!in_array($field, $allFields, true)) {
                    $allFields[] = $field;
                }
            }
        }

        echo "Total processed: {$totalProcessed}, Total errors: {$totalErrors}\n";

        // Now generate the JSON output using the merged data
        $this->generateJsonOutput($fieldCounts, $groupCounts, $fieldPresence, $allFields);
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, int>> $groupCounts
     * @param array<string, array<string, array<string, bool>>> $fieldPresence
     * @param array<string> $allFields
     */
    private function generateJsonOutput(
        array $fieldCounts,
        array $groupCounts,
        array $fieldPresence,
        array $allFields
    ): void {
        // Create output directory
        if (!is_dir($this->outDir)) {
            mkdir($this->outDir, 0755, true);
        }

        $perGroupDir = $this->outDir . '/per_group';
        if (!is_dir($perGroupDir)) {
            mkdir($perGroupDir, 0755, true);
        }

        echo "Writing JSON output to: {$this->outDir}/\n";

        // 1. Summary
        $this->writeJsonFile($this->outDir . '/summary.json', $this->buildSummary($fieldCounts, $groupCounts, $allFields));

        // 2. All fields with presence counts
        $this->writeJsonFile($this->outDir . '/all_fields.json', $this->buildAllFieldsData($fieldCounts, $fieldPresence, $allFields));

        // 3. Universal fields across ALL groups
        $this->writeJsonFile($this->outDir . '/universal_all_groups.json', $this->buildUniversalAllGroups($fieldPresence));

        // 4. Universal fields per event
        $this->writeJsonFile($this->outDir . '/universal_per_event.json', $this->buildUniversalPerEvent($fieldCounts, $fieldPresence));

        // 5. Exclusive fields per group
        $this->writeJsonFile($this->outDir . '/exclusive_per_group.json', $this->buildExclusivePerGroup($fieldCounts));

        // 6. Frequency matrix
        $this->writeJsonFile($this->outDir . '/frequency_matrix.json', $this->buildFrequencyMatrix($fieldPresence, $allFields));

        // 7. Per-group detailed analysis
        $this->buildPerGroupAnalysis($fieldCounts, $groupCounts, $fieldPresence, $perGroupDir);

        echo "Files created:\n";
        echo "  {$this->outDir}/summary.json\n";
        echo "  {$this->outDir}/all_fields.json\n";
        echo "  {$this->outDir}/universal_all_groups.json\n";
        echo "  {$this->outDir}/universal_per_event.json\n";
        echo "  {$this->outDir}/exclusive_per_group.json\n";
        echo "  {$this->outDir}/frequency_matrix.json\n";
        echo "  {$this->outDir}/per_group/ (directory with detailed group analysis)\n";
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, int>> $groupCounts
     * @param array<string> $allFields
     */
    private function buildSummary(array $fieldCounts, array $groupCounts, array $allFields): array
    {
        $events = array_keys($fieldCounts);
        $groups = $this->getAllGroupKeys($fieldCounts);

        return [
            'meta' => [
                'log_file' => $this->logFile,
                'generated_at' => date('c'),
                'parallel_forks' => $this->forks,
                'temp_dir' => $this->tempDir
            ],
            'total_unique_fields' => count($allFields),
            'events' => $events,
            'event_count' => count($events),
            'group_count' => count($groups),
            'groups' => $groups,
            'group_counts' => $groupCounts,
            'events_summary' => $this->buildEventsSummary($fieldCounts, $groupCounts)
        ];
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, int>> $groupCounts
     */
    private function buildEventsSummary(array $fieldCounts, array $groupCounts): array
    {
        $summary = [];

        foreach ($fieldCounts as $event => $actions) {
            $totalFiles = 0;
            $totalFields = 0;
            $actionsData = [];

            foreach ($actions as $action => $fields) {
                $fileCount = $groupCounts[$event][$action] ?? 0;
                $totalFiles += $fileCount;
                $totalFields += count($fields);

                $universal = [];
                foreach ($fields as $field => $count) {
                    if ($count >= $fileCount) {
                        $universal[] = $field;
                    }
                }

                $actionsData[$action] = [
                    'files' => $fileCount,
                    'unique_fields' => count($fields),
                    'universal_fields_count' => count($universal)
                ];
            }

            $summary[$event] = [
                'total_files' => $totalFiles,
                'total_unique_fields' => $totalFields,
                'actions_count' => count($actions),
                'actions' => $actionsData
            ];
        }

        return $summary;
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, array<string, bool>>> $fieldPresence
     * @param array<string> $allFields
     */
    private function buildAllFieldsData(
        array $fieldCounts,
        array $fieldPresence,
        array $allFields
    ): array {
        $groups = $this->getAllGroupKeys($fieldCounts);
        $groupCount = count($groups);
        $result = [];

        foreach ($allFields as $field) {
            $presence = [];
            $totalCount = 0;
            $presentInGroups = 0;

            foreach ($fieldCounts as $event => $actions) {
                foreach ($actions as $action => $fields) {
                    $groupKey = $event . '/' . $action;
                    if (isset($fields[$field])) {
                        $presence[$groupKey] = $fields[$field];
                        $totalCount += $fields[$field];
                        $presentInGroups++;
                    }
                }
            }

            $result[$field] = [
                'total_occurrences' => $totalCount,
                'groups_present_in' => $presentInGroups,
                'groups_total' => $groupCount,
                'coverage_percent' => $groupCount > 0 ? round(($presentInGroups / $groupCount) * 100, 2) : 0,
                'presence_per_group' => $presence
            ];
        }

        // Sort by coverage then name
        uasort($result, function ($a, $b) {
            if ($b['coverage_percent'] !== $a['coverage_percent']) {
                return $b['coverage_percent'] <=> $a['coverage_percent'];
            }
            return count($a['presence_per_group']) <=> count($b['presence_per_group']);
        });

        return $result;
    }

    private function buildUniversalAllGroups(array $fieldPresence): array
    {
        if (empty($fieldPresence)) {
            return ['description' => 'Fields present in ALL event/action groups', 'count' => 0, 'fields' => []];
        }

        $firstGroupFields = null;
        foreach ($fieldPresence as $event => $actions) {
            foreach ($actions as $action => $fields) {
                $firstGroupFields = array_keys($fields);
                break 2;
            }
        }

        if ($firstGroupFields === null) {
            return ['description' => 'Fields present in ALL event/action groups', 'count' => 0, 'fields' => []];
        }

        $universal = $firstGroupFields;

        foreach ($fieldPresence as $event => $actions) {
            foreach ($actions as $action => $fields) {
                $universal = array_intersect($universal, array_keys($fields));
            }
        }

        return [
            'description' => 'Fields present in ALL event/action groups',
            'count' => count($universal),
            'fields' => array_values($universal)
        ];
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, array<string, bool>>> $fieldPresence
     */
    private function buildUniversalPerEvent(array $fieldCounts, array $fieldPresence): array
    {
        $result = [];

        foreach ($fieldCounts as $event => $actions) {
            $firstActionFields = null;
            foreach ($fieldPresence[$event] as $action => $fields) {
                $firstActionFields = array_keys($fields);
                break;
            }

            if ($firstActionFields === null) {
                continue;
            }

            $universal = $firstActionFields;

            foreach ($fieldPresence[$event] as $action => $fields) {
                $universal = array_intersect($universal, array_keys($fields));
            }

            $result[$event] = [
                'description' => "Fields present in ALL actions of event '{$event}'",
                'count' => count($universal),
                'fields' => array_values($universal)
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     */
    private function buildExclusivePerGroup(array $fieldCounts): array
    {
        $exclusive = [];

        foreach ($fieldCounts as $event => $actions) {
            foreach ($actions as $action => $fields) {
                $groupKey = "$event/$action";
                $exclusive[$groupKey] = [];

                foreach (array_keys($fields) as $field) {
                    $inOtherGroup = false;

                    foreach ($fieldCounts as $otherEvent => $otherActions) {
                        foreach ($otherActions as $otherAction => $otherFields) {
                            if ($otherEvent === $event && $otherAction === $action) {
                                continue;
                            }
                            if (isset($otherFields[$field])) {
                                $inOtherGroup = true;
                                break 2;
                            }
                        }
                    }

                    if (!$inOtherGroup) {
                        $exclusive[$groupKey][] = $field;
                    }
                }
            }
        }

        return $exclusive;
    }

    /**
     * @param array<string, array<string, array<string, bool>>> $fieldPresence
     * @param array<string> $allFields
     */
    private function buildFrequencyMatrix(array $fieldPresence, array $allFields): array
    {
        $groups = $this->getAllGroupKeys($fieldPresence);
        $matrix = [];

        $matrix['groups'] = $groups;

        foreach ($allFields as $field) {
            $row = [];
            foreach ($groups as $group) {
                [$event, $action] = explode('/', $group, 2);
                $row[$group] = isset($fieldPresence[$event][$action][$field]);
            }
            $matrix['fields'][$field] = $row;
        }

        return $matrix;
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, int>> $groupCounts
     * @param array<string, array<string, array<string, bool>>> $fieldPresence
     */
    private function buildPerGroupAnalysis(
        array $fieldCounts,
        array $groupCounts,
        array $fieldPresence,
        string $dir
    ): void {
        $index = [];

        foreach ($fieldCounts as $event => $actions) {
            foreach ($actions as $action => $fields) {
                $groupKey = "$event/$action";
                $fileCount = $groupCounts[$event][$action];

                $universal = [];
                $partial = [];

                foreach ($fields as $field => $count) {
                    if ($count >= $fileCount) {
                        $universal[] = $field;
                    } else {
                        $partial[] = [
                            'field' => $field,
                            'count' => $count,
                            'coverage_percent' => $fileCount > 0 ? round(($count / $fileCount) * 100, 2) : 0
                        ];
                    }
                }

                usort($partial, fn($a, $b) => $b['coverage_percent'] - $a['coverage_percent']);

                $analysis = [
                    'meta' => [
                        'event' => $event,
                        'action' => $action,
                        'group_key' => $groupKey
                    ],
                    'file_count' => $fileCount,
                    'total_unique_fields' => count($fields),
                    'universal_fields' => [
                        'count' => count($universal),
                        'fields' => $universal
                    ],
                    'partial_fields' => [
                        'count' => count($partial),
                        'fields' => $partial
                    ],
                    'all_fields_detailed' => $this->buildGroupFieldsDetail($fieldCounts, $groupCounts, $event, $action)
                ];

                $safeFilename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $groupKey);
                $this->writeJsonFile($dir . '/' . $safeFilename . '.json', $analysis);
                $index[$groupKey] = [
                    'file' => $safeFilename . '.json',
                    'file_count' => $fileCount,
                    'field_count' => count($fields)
                ];
            }
        }

        $this->writeJsonFile($dir . '/_index.json', $index);
    }

    /**
     * @param array<string, array<string, array<string, int>>> $fieldCounts
     * @param array<string, array<string, int>> $groupCounts
     */
    private function buildGroupFieldsDetail(array $fieldCounts, array $groupCounts, string $event, string $action): array
    {
        $fields = $fieldCounts[$event][$action] ?? [];
        $fileCount = $groupCounts[$event][$action] ?? 1;
        $detail = [];

        foreach ($fields as $field => $count) {
            $detail[$field] = [
                'count' => $count,
                'coverage_percent' => $fileCount > 0 ? round(($count / $fileCount) * 100, 2) : 0,
                'present_in_all' => $count >= $fileCount
            ];
        }

        return $detail;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $fieldCounts
     */
    private function getAllGroupKeys(array $fieldCounts): array
    {
        $groups = [];
        foreach ($fieldCounts as $event => $actions) {
            foreach ($actions as $action => $fields) {
                $groups[] = "$event/$action";
            }
        }
        sort($groups);
        return $groups;
    }

    private function writeJsonFile(string $path, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            fwrite(STDERR, "Error encoding JSON for: $path\n");
            return;
        }

        file_put_contents($path, $json);
    }

    private function cleanup(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }
}

// CLI parsing
$logFile = 'log.txt';
$outDir = 'out';
$limit = 0;
$sample = false;
$forks = 10;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--sample') {
        $sample = true;
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--out-dir=') === 0) {
        $outDir = substr($arg, 10);
    } elseif (strpos($arg, '--forks=') === 0) {
        $forks = (int)substr($arg, 8);
    } elseif ($arg[0] !== '-') {
        $logFile = $arg;
    }
}

echo "GitHub Webhook JSON Field Analyzer (Parallel)\n";
echo "==============================================\n";
echo "Log file: $logFile\n";
echo "Output dir: $outDir\n";
echo "Parallel forks: $forks\n";

if ($limit > 0) {
    echo "Limit: $limit files";
    if ($sample) {
        echo " (sampling)\n";
    } else {
        echo " (first N)\n";
    }
}

echo "\n";

// Check for pcntl extension
if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "Error: pcntl extension not available. Cannot use parallel mode.\n");
    exit(1);
}

try {
    $analyzer = new ParallelFieldAnalyzer($logFile, $outDir);
    $analyzer
        ->setForks($forks)
        ->setLimit($limit)
        ->setSample($sample)
        ->analyze();
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
