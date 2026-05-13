<?php
declare(strict_types=1);

/**
 * Redis-backed queue for GitHub PR code review jobs.
 *
 * Envelopes are produced by web/github.php when a pull_request event fires
 * (opened, synchronize) and consumed by scripts/github-code-review.php.
 *
 * Queue key:  codereview:queue (RPOP / BRPOP)
 *
 * Envelope shape:
 * {
 *   "v": 1,
 *   "id": "uuid-v4",
 *   "ts": 1715628000,
 *   "repo": "owner/repo",
 *   "pr_number": 42,
 *   "action": "opened",          // opened | synchronize
 *   "head_branch": "feature/xyz",
 *   "base_branch": "main",
 *   "pr_url": "https://github.com/owner/repo/pull/42",
 *   "author": "username",
 *   "author_url": "https://github.com/username",
 *   "sha": "abc123...",          // commit SHA (empty for opened)
 *   "source": "webhooks/github.php"
 * }
 */
class CodeReviewQueue
{
    public const ENVELOPE_VERSION = 1;
    public const QUEUE_KEY = 'codereview:queue';
    public const METRICS_KEY = 'codereview:metrics:enqueued';

    private static ?\Predis\Client $redis = null;
    private static bool $redisProbed = false;
    /** Disposition of the most recent enqueue call: queued|skipped|failed */
    private static string $lastStatus = 'none';

    public static function getLastStatus(): string
    {
        return self::$lastStatus;
    }

    /**
     * Enqueue a PR for code review.
     *
     * @param array{
     *   repo: string,
     *   pr_number: int,
     *   action: string,
     *   head_branch: string,
     *   base_branch: string,
     *   pr_url: string,
     *   author: string,
     *   author_url: string,
     *   sha: string,
     * } $job
     */
    public static function enqueue(array $job): bool
    {
        $envelope = self::buildEnvelope($job);

        $r = self::redis();
        if ($r === null) {
            self::$lastStatus = 'failed_no_redis';
            error_log('CodeReviewQueue: no Redis client available');
            return false;
        }

        try {
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                self::$lastStatus = 'failed_json_encode';
                return false;
            }
            $r->lpush(self::QUEUE_KEY, [$json]);
            $r->incr(self::METRICS_KEY);
            self::$lastStatus = 'queued';
            return true;
        } catch (\Throwable $e) {
            error_log('CodeReviewQueue lpush failed: ' . $e->getMessage());
            self::$lastStatus = 'failed_redis_exception';
            return false;
        }
    }

    /**
     * Dequeue a single job from the queue (blocking RPOP).
     *
     * @param int $timeout Seconds to block waiting (0 = infinite)
     * @return array|null Decoded envelope or null if timeout/signals
     */
    public static function dequeue(int $timeout = 5): ?array
    {
        $r = self::redis();
        if ($r === null) {
            error_log('CodeReviewQueue: no Redis client available for dequeue');
            return null;
        }

        try {
            // BRPOP blocks until an element is available or timeout
            $result = $r->brpop([self::QUEUE_KEY], $timeout);
            if ($result === null) {
                return null;
            }
            // $result is [key, value]
            $json = $result[1] ?? null;
            if (!is_string($json)) {
                return null;
            }
            $envelope = json_decode($json, true);
            if (!is_array($envelope)) {
                error_log('CodeReviewQueue: failed to decode envelope JSON');
                return null;
            }
            return $envelope;
        } catch (\Throwable $e) {
            error_log('CodeReviewQueue brpop failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Re-enqueue a job (e.g., on failure after max retries).
     */
    public static function requeue(array $envelope): bool
    {
        $r = self::redis();
        if ($r === null) {
            return false;
        }

        try {
            // Increment retry count
            $envelope['retry_count'] = ((int)($envelope['retry_count'] ?? 0)) + 1;
            $envelope['last_retry_ts'] = time();

            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }
            $r->lpush(self::QUEUE_KEY, [$json]);
            return true;
        } catch (\Throwable $e) {
            error_log('CodeReviewQueue requeue failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function buildEnvelope(array $job): array
    {
        return [
            'v' => self::ENVELOPE_VERSION,
            'id' => self::uuidV4(),
            'ts' => time(),
            'repo' => $job['repo'],
            'pr_number' => (int)$job['pr_number'],
            'action' => $job['action'],
            'head_branch' => $job['head_branch'],
            'base_branch' => $job['base_branch'],
            'pr_url' => $job['pr_url'],
            'author' => $job['author'],
            'author_url' => $job['author_url'],
            'sha' => $job['sha'] ?? '',
            'source' => 'webhooks/github.php',
            'retry_count' => 0,
        ];
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private static function redis(): ?\Predis\Client
    {
        if (self::$redisProbed) {
            return self::$redis;
        }
        self::$redisProbed = true;
        if (!class_exists(\Predis\Client::class)) {
            error_log('CodeReviewQueue: predis/predis not installed');
            return null;
        }
        $host = defined('REDIS_HOST') && REDIS_HOST !== '' ? REDIS_HOST : (getenv('REDIS_HOST') ?: '67.217.60.234');
        $port = defined('REDIS_PORT') && REDIS_PORT !== '' ? (int)REDIS_PORT : (int)(getenv('REDIS_PORT') ?: 6379);
        $params = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'timeout' => 2.0,
        ];
        try {
            $client = new \Predis\Client($params);
            $client->connect();
            self::$redis = $client;
        } catch (\Throwable $e) {
            error_log('CodeReviewQueue: redis connect failed (' . $host . ':' . $port . '): ' . $e->getMessage());
            self::$redis = null;
        }
        return self::$redis;
    }
}
