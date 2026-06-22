<?php
declare(strict_types=1);

/**
 * GitHub PR Code Review Queue — Listing CLI
 *
 * Read-only peek into the `codereview:queue` Redis list. Does NOT consume
 * (uses LRANGE, not RPOP). Safe to run while the worker is processing.
 *
 * Usage:
 *   php scripts/github-code-review-list.php
 *   php scripts/github-code-review-list.php --limit 20
 *   php scripts/github-code-review-list.php --json
 *   php scripts/github-code-review-list.php --verbose
 *   php scripts/github-code-review-list.php --repo owner/name
 *   php scripts/github-code-review-list.php --metrics
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/CodeReviewQueue.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

$opts = parseArgs($argv);
if (!empty($opts['help'])) {
    printUsage();
    exit(0);
}

$host = getenv('REDIS_HOST') ?: '67.217.60.234';
$port = (int)(getenv('REDIS_PORT') ?: 6379);

try {
    $redis = new \Predis\Client([
        'scheme'  => 'tcp',
        'host'    => $host,
        'port'    => $port,
        'timeout' => 2.0,
    ]);
    $redis->connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Redis connect failed ({$host}:{$port}): {$e->getMessage()}\n");
    exit(2);
}

$queueLen = (int)$redis->llen(CodeReviewQueue::QUEUE_KEY);
$enqueued = (int)($redis->get(CodeReviewQueue::METRICS_KEY) ?? 0);

if (!empty($opts['metrics'])) {
    echo json_encode([
        'queue_key'      => CodeReviewQueue::QUEUE_KEY,
        'queue_depth'    => $queueLen,
        'total_enqueued' => $enqueued,
        'redis_host'     => $host,
        'redis_port'     => $port,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// LRANGE 0 N-1 — newest-first because workers RPOP and producers LPUSH
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 50;
$stop  = $limit - 1;
$raw   = $redis->lrange(CodeReviewQueue::QUEUE_KEY, 0, $stop);

$entries = [];
foreach ($raw as $idx => $json) {
    $env = json_decode($json, true);
    if (!is_array($env)) {
        fwrite(STDERR, "skip: index {$idx} is not valid JSON\n");
        continue;
    }
    if (!empty($opts['repo']) && ($env['repo'] ?? '') !== $opts['repo']) {
        continue;
    }
    $entries[] = $env;
}

if (!empty($opts['json'])) {
    echo json_encode([
        'queue_depth'    => $queueLen,
        'total_enqueued' => $enqueued,
        'shown'          => count($entries),
        'limit'          => $limit,
        'entries'        => $entries,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

printf("Queue: %s   depth=%d   total_enqueued=%d   shown=%d/%d\n",
    CodeReviewQueue::QUEUE_KEY, $queueLen, $enqueued, count($entries), $limit);
echo str_repeat('-', 100) . "\n";

if ($entries === []) {
    echo "(no entries)\n";
    exit(0);
}

foreach ($entries as $i => $e) {
    $ts      = (int)($e['ts'] ?? 0);
    $when    = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '?';
    $age     = $ts > 0 ? humanAge(time() - $ts) : '?';
    $action  = $e['action'] ?? '?';
    $repo    = $e['repo'] ?? '?';
    $prNum   = $e['pr_number'] ?? '?';
    $head    = $e['head_branch'] ?? '?';
    $base    = $e['base_branch'] ?? '?';
    $author  = $e['author'] ?? '?';
    $sha     = substr((string)($e['sha'] ?? ''), 0, 7);
    $retries = (int)($e['retry_count'] ?? 0);
    $id      = $e['id'] ?? '?';

    printf("[%2d] %s  (%s ago)\n", $i, $when, $age);
    printf("     %s#%s  %s  %s -> %s  by %s%s\n",
        $repo, $prNum, $action, $head, $base, $author,
        $sha !== '' ? "  sha={$sha}" : '');
    if ($retries > 0) {
        printf("     retry_count=%d\n", $retries);
    }
    if (!empty($opts['verbose'])) {
        printf("     id=%s\n", $id);
        if (!empty($e['pr_url'])) {
            printf("     %s\n", $e['pr_url']);
        }
    }
    echo "\n";
}

// ---------- helpers ----------

function parseArgs(array $argv): array
{
    $o = [];
    for ($i = 1, $n = count($argv); $i < $n; $i++) {
        $a = $argv[$i];
        switch ($a) {
            case '-h':
            case '--help':
                $o['help'] = true;
                break;
            case '--json':
                $o['json'] = true;
                break;
            case '--verbose':
            case '-v':
                $o['verbose'] = true;
                break;
            case '--metrics':
                $o['metrics'] = true;
                break;
            case '--limit':
                $o['limit'] = $argv[++$i] ?? '';
                break;
            case '--repo':
                $o['repo'] = $argv[++$i] ?? '';
                break;
            default:
                fwrite(STDERR, "unknown option: {$a}\n");
                printUsage();
                exit(1);
        }
    }
    return $o;
}

function printUsage(): void
{
    echo <<<TXT
Usage: php scripts/github-code-review-list.php [options]

Peek (non-destructive) at the codereview:queue Redis list.

Options:
  --limit N      Show at most N entries (default 50, newest first).
  --repo OWNER/REPO
                 Filter to a single repository.
  --json         Output the full envelopes as JSON (machine-readable).
  --metrics      Print only queue depth + total_enqueued counter.
  -v, --verbose  Include envelope id and PR URL in the human output.
  -h, --help     Show this help.

Env: REDIS_HOST, REDIS_PORT (defaults match CodeReviewQueue).

TXT;
}

function humanAge(int $secs): string
{
    if ($secs < 60)    return $secs . 's';
    if ($secs < 3600)  return intdiv($secs, 60) . 'm';
    if ($secs < 86400) return intdiv($secs, 3600) . 'h';
    return intdiv($secs, 86400) . 'd';
}
