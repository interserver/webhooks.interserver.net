<?php
declare(strict_types=1);

/**
 * @link https://docs.github.com/en/developers/webhooks-and-events/webhooks/webhook-events-and-payloads
 * @link https://github.com/github/docs/blob/main/content/developers/webhooks-and-events/webhooks/webhook-events-and-payloads.md
 * @link https://github.com/organizations/interserver/settings/hooks/359889086?tab=deliveries
 */

header('Content-Type: text/plain; charset=utf-8');
http_response_code(500);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/GithubWebhook.php';
require_once __DIR__ . '/../src/GithubMessageBuilder.php';
require_once __DIR__ . '/../src/IgnoredEventException.php';
require_once __DIR__ . '/../src/NotImplementedException.php';
require_once __DIR__ . '/../src/NotificationQueue.php';
require_once __DIR__ . '/../src/CodeReviewQueue.php';

const GITHUB_LOG_DIR = __DIR__ . '/../log';

// Load field categories for payload filtering
$fieldCategoriesFile = __DIR__ . '/../field_analysis_full/field_categories.php';
$fieldCategories = file_exists($fieldCategoriesFile) ? require $fieldCategoriesFile : null;

/**
 * Build whitelist from field categories
 */
function buildWebhookWhitelist(array $categories): array
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
 * Add universal repository fields to whitelist for a specific event
 */
function addUniversalRepoFields(array $whitelist): array
{
    $universal = [
        'repo', 'event', 'data',
        'data.repository.full_name',
        'data.sender.login',
        'data.sender.html_url',
        'data.sender.avatar_url',
        'data.action',
    ];

    foreach ($universal as $field) {
        $whitelist[$field] = true;
    }

    return $whitelist;
}

$Hook = new GithubWebhook();
try {
    if (!$Hook->ValidateHubSignature(GITHUB_WEBHOOKS_SECRET)) {
        throw new Exception('Secret validation failed.');
    }
    $Hook->ProcessRequest();

    $RepositoryName = $Hook->GetFullRepositoryName();
    $EventType = $Hook->GetEventType();
    $Payload = $Hook->GetPayload();

    if (empty($Payload)) {
        throw new Exception('Empty payload, not sending.');
    }

    // Filter payload immediately - remove all fields not useful for notifications
    if ($fieldCategories !== null) {
        $whitelist = buildWebhookWhitelist($fieldCategories);
        $whitelist = addUniversalRepoFields($whitelist);
        $eventFields = $fieldCategories['per_event'][$EventType] ?? [];
        foreach ($eventFields as $field) {
            $whitelist[$field] = true;
        }
        $Payload = filterWebhookPayload($Payload, $whitelist, $EventType);
    }

    $action = $Payload['action'] ?? null;
    $room = pickRoom($RepositoryName);
    $dedupKey = buildDedupKey($EventType, $RepositoryName, $Payload);
    $logLevel = strtolower((string)(getenv('LOG_LEVEL') ?: 'info'));
    if ($logLevel === 'debug') {
        github_log_event($EventType, $RepositoryName, $action, $Payload['sender']['login'] ?? 'unknown', ['payload_size' => strlen(json_encode($Payload))]);
    }

    // The bot decides whether each event is interesting enough to actually
    // post to Teams (see server/queue/filters.js). We just queue everything
    // here so the bot has both the prebuilt text AND the raw payload for
    // downstream reformatting.
    //
    // Producer-side rate-limiting is off by default so the "queue it all"
    // intent holds. Set RATE_LIMIT_WINDOW=<seconds> in the webhook .env to
    // re-enable per-dedup-key suppression at the producer (rare bursts only).
    $rateWindow = (int)(getenv('RATE_LIMIT_WINDOW') ?: 0);
    if ($rateWindow > 0 && $dedupKey !== '') {
        if (!NotificationQueue::shouldSendOrSuppress($dedupKey, $rateWindow)) {
            http_response_code(200);
            error_log("github: rate-limited {$dedupKey} (not queued)");
            return;
        }
    }

    $Builder = new GithubMessageBuilder($EventType, $Payload);
    try {
        $Built = $Builder->build();
        $text = $Built['text'] ?? '';
    } catch (\Throwable $e) {
        // Builder may not handle every event; carry an empty text and let
        // the bot decide what to do with the raw data.
        $text = '';
    }

    NotificationQueue::enqueueMessage($text, $room, [
        'dedup_key' => $dedupKey,
        'level' => 'info',
        'event_type' => $EventType,
        'action' => $action,
        'repo' => $RepositoryName,
        'data' => $Payload,
        'source' => 'webhooks/github.php',
    ]);

    // Enqueue PR opened/sync events to code review queue
    if ($EventType === 'pull_request' && in_array($action, ['opened', 'synchronize'], true)) {
        $pr = $Payload['pull_request'] ?? [];
        $author = $pr['user'] ?? [];
        CodeReviewQueue::enqueue([
            'repo' => $RepositoryName,
            'pr_number' => (int)($pr['number'] ?? 0),
            'action' => $action,
            'head_branch' => $pr['head']['ref'] ?? '',
            'base_branch' => $pr['base']['ref'] ?? '',
            'pr_url' => $pr['html_url'] ?? '',
            'author' => $author['login'] ?? '',
            'author_url' => $author['html_url'] ?? '',
            'sha' => $pr['head']['sha'] ?? '',
        ]);
    }

    $disposition = NotificationQueue::getLastStatus();
    $previewText = $text !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 100) : '<no-text>';
    error_log(sprintf(
        'github: disp=%s %s(%s) repo=%s room=%s dedup=%s text="%s"',
        $disposition,
        $EventType,
        $action ?? '-',
        $RepositoryName,
        $room,
        $dedupKey,
        $previewText
    ));

    http_response_code(202);
} catch (IgnoredEventException $e) {
    http_response_code(200);
    error_log('github: ignored event: ' . $e->getMessage());
} catch (NotImplementedException $e) {
    http_response_code(501);
    error_log('github: unsupported event: ' . $e->EventName);
} catch (Throwable $e) {
    error_log('github exception: ' . $e->getMessage());
}

function pickRoom(string $repo): string
{
    if (strpos($repo, 'sugarcraft/') === 0
        || in_array($repo, ['detain/CandyCore', 'detain/scoop-emulators', 'detain/detain', 'detain/sugarcraft', 'detain/watchable', 'detain/php-dup-finder'], true)) {
        return 'int-dev-announce';
    }
    return 'notifications';
}

function buildDedupKey(string $eventType, string $repo, array $payload): string
{
    // Helper to extract short SHA from payload
    $getSha = function(array $payload, string $shaKey): string {
        $sha = (string)($payload[$shaKey] ?? '');
        return $sha !== '' ? substr($sha, 0, 7) : '';
    };

    switch ($eventType) {
        // All events that carry a commit SHA now use github:commit:{repo}:{sha7}
        // This allows the consumer to group push + check_run + workflow_job for the same commit.
        case 'check_run':
            $sha = $getSha($payload['check_run'] ?? [], 'head_sha');
            return $sha !== '' ? "github:commit:{$repo}:{$sha}" : "github:checkrun:{$repo}";
        case 'check_suite':
            $sha = $getSha($payload['check_suite'] ?? [], 'head_sha');
            return $sha !== '' ? "github:commit:{$repo}:{$sha}" : "github:check:{$repo}";
        case 'workflow_run':
            $sha = $getSha($payload['workflow_run'] ?? [], 'head_sha');
            return $sha !== '' ? "github:commit:{$repo}:{$sha}" : "github:wf:{$repo}";
        case 'workflow_job':
            $sha = $getSha($payload['workflow_job'] ?? [], 'head_sha');
            return $sha !== '' ? "github:commit:{$repo}:{$sha}" : "github:wfjob:{$repo}";
        case 'push':
            // Use 'after' SHA (the commit that was pushed), not the branch
            $sha = $getSha($payload, 'after');
            if ($sha === '' && !empty($payload['commits'])) {
                $lastCommit = end($payload['commits']);
                $sha = substr((string)($lastCommit['id'] ?? ''), 0, 7);
            }
            return $sha !== '' ? "github:commit:{$repo}:{$sha}" : "github:push:{$repo}";
        case 'issues':
            $num = (int)($payload['issue']['number'] ?? 0);
            return "github:issue:{$repo}:{$num}";
        case 'pull_request':
            $num = (int)($payload['pull_request']['number'] ?? 0);
            return "github:pr:{$repo}:{$num}";
        case 'gollum':
            return "github:wiki:{$repo}";
        case 'status':
            $sha = $getSha($payload, 'sha');
            return $sha !== '' ? "github:commit:{$repo}:{$sha}" : "github:status:{$repo}";
        case 'star':
        case 'watch':
        case 'fork':
            return "github:{$eventType}:{$repo}";
        case 'ping':
            return "github:ping:{$repo}";
    }
    return "github:{$eventType}:{$repo}";
}

function github_log_event(string $eventType, string $repo, ?string $action, string $sender, array $extra = []): void
{
    $dir = GITHUB_LOG_DIR . '/' . date('Y/m/d');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = sprintf(
        '%s_%s%s_%s_%s.json',
        date('His'),
        $eventType,
        $action !== null ? '_' . $action : '',
        $sender,
        str_replace(['/', '-', ' '], ['_', '_', '_'], $repo)
    );
    @file_put_contents($dir . '/' . $name, json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Recursively filter webhook payload by whitelist
 */
function filterWebhookPayload(array $data, array $whitelist, string $event): array
{
    $result = [];

    foreach ($data as $key => $value) {
        $currentPath = 'data.' . $key;
        $arrayPath = $currentPath . '[]';

        // Check if this path is whitelisted
        $isWhitelisted = isset($whitelist[$currentPath]) || isset($whitelist[$arrayPath]);

        // Also check if any child path is whitelisted
        if (!$isWhitelisted) {
            foreach ($whitelist as $wlPath => $_) {
                if (strpos($wlPath, $currentPath . '.') === 0 || strpos($wlPath, $currentPath . '[]') === 0) {
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
                    $childResult = filterRecursive($value, $childWl);
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
                $childResult = filterRecursive($value, $childWl);
                if (!empty($childResult)) {
                    $result[$key] = $childResult;
                }
            }
        }
    }

    return $result;
}

/**
 * Recursively filter array with stripped whitelist (prefix already removed)
 */
function filterRecursive(array $data, array $whitelist): array
{
    $result = [];

    foreach ($data as $key => $value) {
        $currentPath = $key;
        $arrayPath = $key . '[]';

        $isWhitelisted = isset($whitelist[$currentPath]) || isset($whitelist[$arrayPath]);

        if (!$isWhitelisted) {
            foreach ($whitelist as $wlPath => $_) {
                if (strpos($wlPath, $currentPath . '.') === 0 || strpos($wlPath, $currentPath . '[]') === 0) {
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
                    $childResult = filterRecursive($value, $childWl);
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
                $childResult = filterRecursive($value, $childWl);
                if (!empty($childResult)) {
                    $result[$key] = $childResult;
                }
            }
        }
    }

    return $result;
}

/**
 * Extract specific fields from an element based on stripped whitelist
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
