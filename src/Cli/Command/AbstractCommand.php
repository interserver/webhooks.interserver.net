<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Predis\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webhooks\CodeReviewQueue;

/**
 * Base class for all CLI commands.
 *
 * Provides common configuration, Redis connection, and helpers.
 */
abstract class AbstractCommand extends Command
{
    protected ?Client $redis = null;

    protected function getRedisHost(): string
    {
        return defined('REDIS_HOST') && REDIS_HOST !== '' ? REDIS_HOST : (getenv('REDIS_HOST') ?: '67.217.60.234');
    }

    protected function getRedisPort(): int
    {
        return defined('REDIS_PORT') && REDIS_PORT !== '' ? (int)REDIS_PORT : (int)(getenv('REDIS_PORT') ?: 6379);
    }

    /**
     * Get the Redis client, connecting if necessary.
     */
    protected function getRedis(): ?Client
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if (!class_exists(Client::class)) {
            return null;
        }

        try {
            $this->redis = new Client([
                'scheme' => 'tcp',
                'host' => $this->getRedisHost(),
                'port' => $this->getRedisPort(),
                'timeout' => 2.0,
            ]);
            $this->redis->connect();
            return $this->redis;
        } catch (\Throwable $e) {
            $this->redis = null;
            return null;
        }
    }

    /**
     * Get the queue key value.
     */
    protected function getQueueKey(): string
    {
        return \CodeReviewQueue::QUEUE_KEY;
    }

    /**
     * Get the metrics key value.
     */
    protected function getMetricsKey(): string
    {
        return \CodeReviewQueue::METRICS_KEY;
    }

    /**
     * Format a timestamp as a human-readable age string.
     */
    protected function formatAge(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '?';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . 's';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . 'm';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . 'h';
        }
        return intdiv($diff, 86400) . 'd';
    }
}
