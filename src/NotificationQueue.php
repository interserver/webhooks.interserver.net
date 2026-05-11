<?php
declare(strict_types=1);

/**
 * Producer-side counterpart of MyAdmin\Notifications\Queue.
 *
 * Pushes envelopes onto a Redis list consumed by the teams-chat-bot. Falls
 * back to a direct Power Automate webhook POST if Redis is unavailable, so
 * github events are never silently dropped.
 *
 * Same envelope shape as MyAdmin\Notifications\Queue (v=1).
 */
class NotificationQueue
{
    public const ENVELOPE_VERSION = 1;
    public const TTL_SECONDS = 300;
    // GitHub payloads (push with many commits, deep PR objects) routinely run
    // 30–80 KB. We carry the raw payload in `extra.data` so the bot can
    // reformat downstream, so allow up to 256 KB before falling back to
    // strip-then-truncate.
    public const MAX_BYTES = 262144;

    private static ?\Predis\Client $redis = null;
    private static bool $redisProbed = false;
    /** Disposition of the most recent enqueue call: queued|direct_flag_off|direct_no_redis|direct_redis_exception|direct_oversize|failed */
    private static string $lastStatus = 'none';

    public static function getLastStatus(): string
    {
        return self::$lastStatus;
    }

    public static function enqueueMessage(string $msg, string $room, array $extra = []): bool
    {
        $envelope = self::buildEnvelope('msg', $room, $extra, [
            'message' => str_replace("\n", "\n\n", $msg),
            'card' => null,
        ]);
        return self::enqueue($envelope);
    }

    private static function buildEnvelope(string $type, string $room, array $extra, array $payload): array
    {
        $now = time();
        return [
            'v' => self::ENVELOPE_VERSION,
            'id' => self::uuidV4(),
            'ts' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
            'room' => $room,
            'type' => $type,
            'message' => $payload['message'],
            'card' => $payload['card'],
            'extra' => self::sanitizeExtra($extra),
            'fallback_webhook_url' => self::fallbackUrl($room),
        ];
    }

    private static function sanitizeExtra(array $extra): array
    {
        $allowed = ['dedup_key', 'level', 'source', 'truncated', 'retry_count', 'event_type', 'repo', 'action', 'data'];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $extra) && $extra[$k] !== null) {
                $out[$k] = $extra[$k];
            }
        }
        return $out;
    }

    private static function fallbackUrl(string $room): ?string
    {
        global $chatChannels;
        return $chatChannels['teams'][$room] ?? $chatChannels['teams']['notifications'] ?? null;
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private static function enabled(): bool
    {
        return getenv('NOTIF_QUEUE_ENABLED') === 'true' || getenv('NOTIF_QUEUE_ENABLED') === '1';
    }

    private static function keyPrefix(): string
    {
        $env = getenv('NOTIF_QUEUE_KEY_PREFIX');
        return $env !== false && $env !== '' ? $env : 'notif:';
    }

    private static function redis(): ?\Predis\Client
    {
        if (self::$redisProbed) {
            return self::$redis;
        }
        self::$redisProbed = true;
        if (!class_exists(\Predis\Client::class)) {
            error_log('NotificationQueue: predis/predis not installed; falling back to direct send');
            return null;
        }
        // Prefer constants defined by mystage's config.settings.php (shared with
        // the rest of the InterServer stack) over local env vars, so the
        // queue host lives in one place. The 67.217.60.234 instance accepts
        // unauthenticated connections — REDIS_USER / REDIS_PASS from the
        // settings file are intentionally NOT passed to the client.
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
            error_log('NotificationQueue: redis connect failed (' . $host . ':' . $port . '): ' . $e->getMessage());
            self::$redis = null;
        }
        return self::$redis;
    }

    private static function enqueue(array $envelope): bool
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return self::directSend($envelope, 'json_encode_failed');
        }
        if (strlen($json) > self::MAX_BYTES) {
            $envelope = self::truncate($envelope);
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || strlen($json) > self::MAX_BYTES) {
                return self::directSend($envelope, 'oversize');
            }
        }

        if (!self::enabled()) {
            return self::directSend($envelope, 'flag_off');
        }

        $r = self::redis();
        if ($r === null) {
            return self::directSend($envelope, 'no_redis_client');
        }

        try {
            $prefix = self::keyPrefix();
            $r->lpush($prefix . 'queue', [$json]);
            $r->incr($prefix . 'metrics:enqueued');
            self::$lastStatus = 'queued';
            return true;
        } catch (\Throwable $e) {
            error_log('NotificationQueue lpush failed: ' . $e->getMessage());
            return self::directSend($envelope, 'redis_exception');
        }
    }

    /**
     * SET/GET-based per-event rate limiter. Returns true when a record is
     * "fresh" (i.e. nothing has been seen with this signature in the window)
     * and we should send. Returns false if we should suppress to avoid flood.
     */
    public static function shouldSendOrSuppress(string $signature, int $windowSeconds = 60): bool
    {
        $r = self::redis();
        if ($r === null) {
            return true;
        }
        try {
            $key = self::keyPrefix() . 'rate:' . sha1($signature);
            $ok = $r->set($key, '1', 'EX', $windowSeconds, 'NX');
            return $ok !== null && (string)$ok === 'OK';
        } catch (\Throwable $e) {
            return true;
        }
    }

    private static function truncate(array $envelope): array
    {
        // Strip the raw payload first if present — the prebuilt message
        // is the user-visible part; data is bonus context for downstream
        // reformatting and is the largest field by far.
        if (isset($envelope['extra']['data'])) {
            $envelope['extra']['data'] = ['__stripped' => true, '__reason' => 'oversize'];
            $envelope['extra']['truncated'] = true;
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false && strlen($json) <= self::MAX_BYTES) {
                return $envelope;
            }
        }
        if ($envelope['type'] === 'msg' && is_string($envelope['message'])) {
            $cap = self::MAX_BYTES - 2048;
            if (strlen($envelope['message']) > $cap) {
                $envelope['message'] = substr($envelope['message'], 0, $cap) . "\n[truncated]";
                $envelope['extra']['truncated'] = true;
            }
        }
        return $envelope;
    }

    private static function directSend(array $envelope, string $reason): bool
    {
        $url = $envelope['fallback_webhook_url'];
        if (!is_string($url) || $url === '') {
            self::$lastStatus = 'failed_no_fallback';
            error_log('NotificationQueue direct-send abandoned (' . $reason . '): unknown room "' . $envelope['room'] . '"');
            return false;
        }
        self::$lastStatus = 'direct_' . $reason;
        $payload = json_encode([
            'type' => 'message',
            'message' => $envelope['message'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $c = curl_init();
        curl_setopt_array($c, [
            CURLOPT_USERAGENT => 'webhooks.interserver.net/NotificationQueue',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $code >= 200 && $code < 300;
    }
}
