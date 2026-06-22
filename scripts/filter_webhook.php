#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * GitHub Webhook JSON Field Filter
 *
 * Reads a JSON log file and outputs a filtered version containing only
 * fields useful for team chat notifications.
 *
 * Usage:
 *   php filter_webhook.php log.json > filtered.json
 *   cat log.json | php filter_webhook.php > filtered.json
 *   php filter_webhook.php --categories=path/to/field_categories.php log.json
 */

declare(ticks = 1);

// Default categories file
$categoriesFile = __DIR__ . '/../field_analysis_full/field_categories.php';

// CLI args
$inputFile = null;
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--categories=') === 0) {
        $categoriesFile = substr($arg, 12);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php filter_webhook.php [options] [input_file]\n";
        echo "Options:\n";
        echo "  --categories=PATH  Path to field_categories.php (default: ./field_analysis_full/field_categories.php)\n";
        echo "  --help, -h         Show this help\n";
        echo "\nIf no input_file specified, reads from stdin.\n";
        exit(0);
    } else {
        $inputFile = $arg;
    }
}

// Load categories
if (!file_exists($categoriesFile)) {
    fwrite(STDERR, "Error: Categories file not found: $categoriesFile\n");
    exit(1);
}

$categories = require $categoriesFile;

// Build the complete whitelist of field paths to keep
$whitelist = buildWhitelist($categories);

// Read input
if ($inputFile) {
    $json = file_get_contents($inputFile);
    if ($json === false) {
        fwrite(STDERR, "Error: Cannot read input file: $inputFile\n");
        exit(1);
    }
} else {
    $json = file_get_contents('php://stdin');
}

// Parse JSON
$data = json_decode($json, true);
if ($data === null) {
    fwrite(STDERR, "Error: Invalid JSON in input\n");
    exit(1);
}

// Filter the data
$filtered = filterByWhitelist($data, $whitelist, $categories);

// Output
echo json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/**
 * Build complete whitelist of field paths to keep
 */
function buildWhitelist(array $categories): array
{
    $whitelist = [];

    // Add universal useful fields
    foreach ($categories['universal']['useful'] ?? [] as $field) {
        $whitelist[$field] = true;
    }

    // Add correlation IDs
    foreach ($categories['correlation_ids'] ?? [] as $field) {
        $whitelist[$field] = true;
    }

    // Add per-event fields
    foreach ($categories['per_event'] ?? [] as $event => $fields) {
        foreach ($fields as $field) {
            $whitelist[$field] = true;
        }
    }

    // Add grouping strategy fields
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

    // Always keep repo and event
    $result['repo'] = $data['repo'] ?? null;
    $result['event'] = $event;

    // Get event-specific fields
    $eventFields = $categories['per_event'][$event] ?? [];
    $eventWhitelist = buildWhitelistForEvent($event, $whitelist, $eventFields);

    // Filter data section
    $result['data'] = filterRecursive($data['data'] ?? [], $eventWhitelist, 'data');

    // Ensure action is always present if it exists
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

    // Add event-specific fields
    foreach ($eventFields as $field) {
        $whitelist[$field] = true;
    }

    // Add universal repository and sender fields
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
function filterRecursive(array $data, array $whitelist, string $prefix): array
{
    $result = [];

    foreach ($data as $key => $value) {
        $currentPath = $prefix === '' ? $key : $prefix . '.' . $key;
        $arrayPath = $currentPath . '[]';

        // Check if this path or array variant is whitelisted
        $isWhitelisted = isset($whitelist[$currentPath]) || isset($whitelist[$arrayPath]);

        // Also check if this key matches any whitelist entry ending with this key
        if (!$isWhitelisted) {
            // Check for direct children of data (like data.workflow_job when we want data.workflow_job.name)
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
                    // Filter each element of sequential array
                    $filtered = [];
                    foreach ($value as $idx => $element) {
                        if (is_array($element)) {
                            // Build sub-whitelist for this array element
                            $subWl = [];
                            foreach ($whitelist as $wlPath => $_) {
                                // Match paths like data.commits[].message
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
                                // No subfield filtering needed - keep entire element
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
                    // Associative array - recurse
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
            // Not whitelisted but is an object - check for nested whitelisted fields
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
        // Check if this key or any path starting with this key is whitelisted
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
                // Recurse into nested object
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
