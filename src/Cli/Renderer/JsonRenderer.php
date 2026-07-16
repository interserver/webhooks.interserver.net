<?php
declare(strict_types=1);

namespace Webhooks\Cli\Renderer;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * JSON output renderer for CLI commands.
 */
final class JsonRenderer
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Render queue list entries as JSON.
     *
     * @param array<array<string, mixed>> $entries
     */
    public function renderQueueList(
        OutputInterface $output,
        string $queueKey,
        int $queueDepth,
        int $totalEnqueued,
        array $entries,
        int $limit
    ): void {
        $data = [
            'queue_key' => $queueKey,
            'queue_depth' => $queueDepth,
            'total_enqueued' => $totalEnqueued,
            'shown' => count($entries),
            'limit' => $limit,
            'entries' => $entries,
        ];

        $this->writeJson($output, $data);
    }

    /**
     * Render metrics as JSON.
     *
     * @param array<string, int> $repoBreakdown
     * @param array<string, int> $actionBreakdown
     */
    public function renderMetrics(
        OutputInterface $output,
        string $queueKey,
        int $queueDepth,
        int $totalEnqueued,
        string $redisHost,
        int $redisPort,
        array $repoBreakdown,
        array $actionBreakdown,
        int $totalRetries
    ): void {
        $data = [
            'queue_key' => $queueKey,
            'queue_depth' => $queueDepth,
            'total_enqueued' => $totalEnqueued,
            'redis_host' => $redisHost,
            'redis_port' => $redisPort,
            'by_repository' => $repoBreakdown,
            'by_action' => $actionBreakdown,
            'total_retries' => $totalRetries,
        ];

        $this->writeJson($output, $data);
    }

    /**
     * Render status list as JSON.
     *
     * @param array<array<string, mixed>> $statuses
     */
    public function renderStatusList(
        OutputInterface $output,
        array $statuses
    ): void {
        $this->writeJson($output, [
            'statuses' => $statuses,
            'count' => count($statuses),
        ]);
    }

    /**
     * Render a single status item as JSON.
     *
     * @param array<string, mixed> $status
     */
    public function renderStatusItem(
        OutputInterface $output,
        array $status
    ): void {
        $this->writeJson($output, $status);
    }

    /**
     * Render cancel result as JSON.
     *
     * @param array<array<string, mixed>> $cancelledJobs
     */
    public function renderCancelResult(
        OutputInterface $output,
        array $cancelledJobs,
        bool $all = false
    ): void {
        $this->writeJson($output, [
            'cancelled' => count($cancelledJobs),
            'all' => $all,
            'jobs' => $cancelledJobs,
        ]);
    }

    /**
     * Render submit result as JSON.
     *
     * @param array<string, mixed> $result
     */
    public function renderSubmitResult(
        OutputInterface $output,
        array $result
    ): void {
        $this->writeJson($output, $result);
    }

    /**
     * Render configuration as JSON.
     *
     * @param array<string, mixed> $config
     */
    public function renderConfig(
        OutputInterface $output,
        array $config
    ): void {
        $this->writeJson($output, [
            'config' => $config,
        ]);
    }

    /**
     * Render error as JSON.
     */
    public function renderError(
        OutputInterface $output,
        string $message,
        int $code = 1,
        ?array $details = null
    ): void {
        $error = [
            'error' => true,
            'message' => $message,
            'code' => $code,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        $this->writeJson($output, $error);
    }

    /**
     * Render success message as JSON.
     *
     * @param array<string, mixed> $data
     */
    public function renderSuccess(
        OutputInterface $output,
        string $message,
        ?array $data = null
    ): void {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->writeJson($output, $response);
    }

    /**
     * Render dry-run preview as JSON.
     *
     * @param array<string, mixed> $preview
     */
    public function renderDryRun(
        OutputInterface $output,
        array $preview
    ): void {
        // Avoid double-wrapping if $preview already contains 'would_submit'
        $wouldSubmit = isset($preview['would_submit'])
            ? $preview['would_submit']
            : $preview;

        $this->writeJson($output, [
            'dry_run' => true,
            'would_submit' => $wouldSubmit,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(OutputInterface $output, array $data): void
    {
        $json = json_encode($data, self::JSON_FLAGS | JSON_PRETTY_PRINT);
        if ($json === false) {
            $output->writeln('{}');
            return;
        }
        $output->writeln($json);
    }
}
