#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * GitHub Webhook JSON Filter - Batch Edition
 *
 * Reads log.txt, filters each JSON log file, and writes to log.new/
 * Processes in parallel using 10 forks.
 *
 * Usage:
 *   php filter_webhook_batch.php [options]
 *
 * Options:
 *   --workers=N   Number of parallel workers (default: 10)
 *   --help, -h    Show this help
 */

declare(ticks = 1);

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "Error: pcntl extension not available\n");
    exit(1);
}

// CLI args
$workers = 10;
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--workers=') === 0) {
        $workers = (int)substr($arg, 10);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php filter_webhook_batch.php [options]\n";
        echo "Options:\n";
        echo "  --workers=N   Number of parallel workers (default: 10)\n";
        echo "  --help, -h    Show this help\n";
        exit(0);
    }
}

// Paths
$logTxt = __DIR__ . '/../log.txt';
$logDir = __DIR__ . '/../log';
$outputDir = __DIR__ . '/../log.new';
$categoriesFile = __DIR__ . '/../field_analysis_full/field_categories.php';

// Check prerequisites
if (!file_exists($logTxt)) {
    fwrite(STDERR, "Error: log.txt not found\n");
    exit(1);
}
if (!file_exists($categoriesFile)) {
    fwrite(STDERR, "Error: field_categories.php not found: $categoriesFile\n");
    exit(1);
}

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Load categories and build whitelist
$categories = require $categoriesFile;
$whitelist = buildWhitelist($categories);

// Read log files
$logFiles = file($logTxt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$totalFiles = count($logFiles);

echo "Processing $totalFiles log files with $workers workers\n";

// Split into chunks
$chunkSize = (int)ceil($totalFiles / $workers);
$chunks = array_chunk($logFiles, $chunkSize);

$startTime = microtime(true);
$childPids = [];

// Fork workers
for ($i = 0; $i < $workers && $i < count($chunks); $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "Error: Failed to fork\n");
        exit(1);
    } elseif ($pid === 0) {
        // Child process
        $workerId = $i + 1;
        $files = $chunks[$i];
        $processed = 0;
        $errors = 0;

        foreach ($files as $logFile) {
            $basename = basename($logFile);
            $outputFile = $outputDir . '/' . $basename;

            // Skip if output already exists (resume support)
            if (file_exists($outputFile)) {
                $processed++;
                continue;
            }

            $json = file_get_contents($logDir . '/' . $basename);
            if ($json === false) {
                $errors++;
                continue;
            }

            $data = json_decode($json, true);
            if ($data === null) {
                $errors++;
                continue;
            }

            $filtered = filterByWhitelist($data, $whitelist, $categories);
            $outputJson = json_encode(
                $filtered,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if (file_put_contents($outputFile, $outputJson) === false) {
                $errors++;
            } else {
                $processed++;
            }
        }

        // Exit child with stats
        exit($errors > 0 ? 1 : 0);
    } else {
        // Parent process
        $childPids[] = $pid;
    }
}

// Wait for all children and track errors
$hasErrors = false;
foreach ($childPids as $pid) {
    $status = 0;
    pcntl_waitpid($pid, $status);
    if ($status !== 0) {
        $hasErrors = true;
    }
}

$elapsed = microtime(true) - $startTime;
echo sprintf(
    "Completed in %.2fs (%.2f files/sec)\n",
    $elapsed,
    $totalFiles / $elapsed
);

if ($hasErrors) {
    echo "Some files had errors (see stderr)\n";
    exit(1);
}

/**
 * Build complete whitelist of field paths to keep
 */
function buildWhitelist(array $categories): array
{
    $whitelist = [];

    foreach ($categories['universal']['useful'] ?? [] as $field) {
        $whitelist[$field] = true;
    }

    foreach ($categories['correlation_ids'] ?? [] as $field) {
        $whitelist[$field] = true;
    }

    foreach ($categories['per_event'] ?? [] as $event => $fields) {
        foreach ($fields as $field) {
            $whitelist[$field] = true;
        }
    }

    foreach ($categories['grouping_strategy'] ?? [] as $field) {
        $whitelist[$field] = true;
    }

    return $whitelist;
}

/**
 * Filter data by whitelist
 */
function filterByWhitelist(array $data, array $whitelist, array $categories): array
{
    $event = $data['event'] ?? 'unknown';
    $result = [];

    $result['repo'] = $data['repo'] ?? null;
    $result['event'] = $event;

    $eventFields = $categories['per_event'][$event] ?? [];
    $eventWhitelist = buildWhitelistForEvent($event, $whitelist, $eventFields);

    $result['data'] = filterRecursive($data['data'] ?? [], $eventWhitelist, 'data');

    if (isset($data['data']['action'])) {
        $result['data']['action'] = $data['data']['action'];
    }

    return $result;
}

/**
 * Build whitelist for specific event
 */
function buildWhitelistForEvent(string $event, array $baseWhitelist, array $eventFields): array
{
    $whitelist = $baseWhitelist;

    foreach ($eventFields as $field) {
        $whitelist[$field] = true;
    }

    $universal = [
        'repo', 'event', 'data',
        'data.repository.full_name', 'data.repository.name',
        'data.repository.html_url', 'data.repository.description',
        'data.repository.language', 'data.repository.default_branch',
        'data.repository.pushed_at', 'data.repository.stargazers_count',
        'data.repository.watchers_count', 'data.repository.forks_count',
        'data.repository.open_issues_count',
        'data.sender.login', 'data.sender.html_url',
        'data.sender.avatar_url', 'data.sender.id',
        'data.action',
    ];

    foreach ($universal as $field) {
        $whitelist[$field] = true;
    }

    return $whitelist;
}

/**
 * Recursively filter array by whitelist
 */
function filterRecursive(array $data, array $whitelist, string $prefix = 'data'): array
{
    $result = [];

    foreach ($data as $key => $value) {
        $currentPath = $prefix === '' ? $key : $prefix . '.' . $key;
        $arrayPath = $currentPath . '[]';

        $isWhitelisted = isset($whitelist[$currentPath]) || isset($whitelist[$arrayPath]);

        if (!$isWhitelisted) {
            $prefixCheck = $prefix . '.' . $key;
            foreach ($whitelist as $wlPath => $_) {
                $hasChildPath = strpos($wlPath, $prefixCheck . '.') === 0;
                $hasArrayPath = strpos($wlPath, $prefixCheck . '[]') === 0;
                if ($hasChildPath || $hasArrayPath) {
                    $isWhitelisted = true;
                    break;
                }
            }
        }

        if ($isWhitelisted) {
            if (is_array($value)) {
                if (isSequentialArray($value)) {
                    $filtered = [];
                    foreach ($value as $idx => $element) {
                        if (is_array($element)) {
                            $subWl = [];
                            foreach ($whitelist as $wlPath => $_) {
                                if (strpos($wlPath, $arrayPath) === 0) {
                                    $subWl[substr($wlPath, strlen($arrayPath) + 1)] = true;
                                }
                            }
                            if (!empty($subWl)) {
                                $extracted = extractSubset($element, $subWl);
                                if (!empty($extracted)) {
                                    $filtered[$idx] = $extracted;
                                }
                            } else {
                                $filtered[$idx] = $element;
                            }
                        } else {
                            $filtered[$idx] = $element;
                        }
                    }
                    if (!empty($filtered)) {
                        $result[$key] = $filtered;
                    }
                } else {
                    $childWl = [];
                    foreach ($whitelist as $wlPath => $_) {
                        if (strpos($wlPath, $currentPath . '.') === 0) {
                            $childWl[substr($wlPath, strlen($currentPath) + 1)] = true;
                        }
                    }
                    $childResult = filterRecursive($value, $childWl, '');
                    if (!empty($childResult)) {
                        $result[$key] = $childResult;
                    }
                }
            } else {
                $result[$key] = $value;
            }
        } elseif (is_array($value) && !isSequentialArray($value)) {
            $childWl = [];
            foreach ($whitelist as $wlPath => $_) {
                if (strpos($wlPath, $currentPath . '.') === 0) {
                    $childWl[substr($wlPath, strlen($currentPath) + 1)] = true;
                }
            }
            if (!empty($childWl)) {
                $childResult = filterRecursive($value, $childWl, '');
                if (!empty($childResult)) {
                    $result[$key] = $childResult;
                }
            }
        }
    }

    return $result;
}

/**
 * Extract specific fields from an element based on whitelist
 */
function extractSubset(array $element, array $whitelist): array
{
    $result = [];

    foreach ($element as $key => $value) {
        $isMatch = isset($whitelist[$key]);
        if (!$isMatch) {
            foreach ($whitelist as $wlPath => $_) {
                if (strpos($wlPath, $key . '.') === 0) {
                    $isMatch = true;
                    break;
                }
            }
        }

        if ($isMatch) {
            if (is_array($value) && !isSequentialArray($value)) {
                $childWl = [];
                foreach ($whitelist as $wlPath => $_) {
                    if (strpos($wlPath, $key . '.') === 0) {
                        $childWl[substr($wlPath, strlen($key) + 1)] = true;
                    }
                }
                $childResult = extractSubset($value, $childWl);
                if (!empty($childResult)) {
                    $result[$key] = $childResult;
                }
            } else {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}

/**
 * Check if array is sequential (list) vs associative (dict)
 */
function isSequentialArray(array $arr): bool
{
    if (empty($arr)) {
        return false;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
}

exit(0);
