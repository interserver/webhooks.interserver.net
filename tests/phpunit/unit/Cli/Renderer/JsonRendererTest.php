<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Renderer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Webhooks\Cli\Renderer\JsonRenderer;

class JsonRendererTest extends TestCase
{
    private JsonRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new JsonRenderer();
    }

    public function testRenderQueueList(): void
    {
        $output = new BufferedOutput();

        $entries = [
            [
                'id' => 'abc-123',
                'repo' => 'owner/repo',
                'pr_number' => 42,
            ],
        ];

        $this->renderer->renderQueueList($output, 'test:queue', 1, 100, $entries, 50);

        $data = $this->decodeJson($output->fetch());

        $this->assertSame('test:queue', $data['queue_key']);
        $this->assertSame(1, $data['queue_depth']);
        $this->assertSame(100, $data['total_enqueued']);
        $this->assertSame(1, $data['shown']);
        $this->assertSame(50, $data['limit']);
        $this->assertCount(1, $data['entries']);
    }

    public function testRenderMetrics(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderMetrics(
            $output,
            'test:queue',
            5,
            200,
            '127.0.0.1',
            6379,
            ['owner/repo' => 5],
            ['opened' => 5],
            2
        );

        $data = $this->decodeJson($output->fetch());

        $this->assertSame('test:queue', $data['queue_key']);
        $this->assertSame(5, $data['queue_depth']);
        $this->assertSame(200, $data['total_enqueued']);
        $this->assertSame('127.0.0.1', $data['redis_host']);
        $this->assertSame(6379, $data['redis_port']);
        $this->assertSame(['owner/repo' => 5], $data['by_repository']);
        $this->assertSame(['opened' => 5], $data['by_action']);
        $this->assertSame(2, $data['total_retries']);
    }

    public function testRenderStatusList(): void
    {
        $output = new BufferedOutput();

        $statuses = [
            [
                'id' => 'abc-123',
                'repo' => 'owner/repo',
                'pr_number' => 42,
            ],
        ];

        $this->renderer->renderStatusList($output, $statuses);

        $data = $this->decodeJson($output->fetch());

        $this->assertSame(1, $data['count']);
        $this->assertCount(1, $data['statuses']);
        $this->assertSame('abc-123', $data['statuses'][0]['id']);
    }

    public function testRenderStatusItem(): void
    {
        $output = new BufferedOutput();

        $status = [
            'id' => 'abc-123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'status' => 'completed',
        ];

        $this->renderer->renderStatusItem($output, $status);

        $data = $this->decodeJson($output->fetch());

        $this->assertSame('abc-123', $data['id']);
        $this->assertSame('completed', $data['status']);
    }

    public function testRenderCancelResult(): void
    {
        $output = new BufferedOutput();

        $cancelled = [
            ['id' => 'abc-123', 'repo' => 'owner/repo'],
            ['id' => 'def-456', 'repo' => 'owner/repo'],
        ];

        $this->renderer->renderCancelResult($output, $cancelled, false);

        $data = $this->decodeJson($output->fetch());

        $this->assertSame(2, $data['cancelled']);
        $this->assertFalse($data['all']);
        $this->assertCount(2, $data['jobs']);
    }

    public function testRenderCancelResultAll(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderCancelResult($output, [], true);

        $data = $this->decodeJson($output->fetch());

        $this->assertSame(0, $data['cancelled']);
        $this->assertTrue($data['all']);
    }

    public function testRenderSubmitResult(): void
    {
        $output = new BufferedOutput();

        $result = [
            'submitted' => 1,
            'failed' => 0,
            'repository' => 'owner/repo',
        ];

        $this->renderer->renderSubmitResult($output, $result);

        $data = $this->decodeJson($output->fetch());

        $this->assertSame(1, $data['submitted']);
        $this->assertSame('owner/repo', $data['repository']);
    }

    public function testRenderConfig(): void
    {
        $output = new BufferedOutput();

        $config = [
            'github' => ['token' => 'secret'],
            'redis' => ['host' => 'localhost'],
        ];

        $this->renderer->renderConfig($output, $config);

        $data = $this->decodeJson($output->fetch());

        $this->assertArrayHasKey('config', $data);
        $this->assertSame('secret', $data['config']['github']['token']);
    }

    public function testRenderError(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderError($output, 'Something went wrong', 42);

        $data = $this->decodeJson($output->fetch());

        $this->assertTrue($data['error']);
        $this->assertSame('Something went wrong', $data['message']);
        $this->assertSame(42, $data['code']);
    }

    public function testRenderErrorWithDetails(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderError($output, 'Failed', 1, ['field' => 'repo']);

        $data = $this->decodeJson($output->fetch());

        $this->assertTrue($data['error']);
        $this->assertSame(['field' => 'repo'], $data['details']);
    }

    public function testRenderSuccess(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderSuccess($output, 'Operation completed');

        $data = $this->decodeJson($output->fetch());

        $this->assertTrue($data['success']);
        $this->assertSame('Operation completed', $data['message']);
    }

    public function testRenderSuccessWithData(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderSuccess($output, 'Done', ['id' => 123]);

        $data = $this->decodeJson($output->fetch());

        $this->assertTrue($data['success']);
        $this->assertSame(123, $data['data']['id']);
    }

    public function testRenderDryRun(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderDryRun($output, [
            'would_submit' => ['repo' => 'owner/repo', 'pr' => 42],
        ]);

        $data = $this->decodeJson($output->fetch());

        $this->assertTrue($data['dry_run']);
        $this->assertSame('owner/repo', $data['would_submit']['repo']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);
        $this->assertNotNull($data, 'Failed to decode JSON: ' . $json);
        $this->assertIsArray($data);
        return $data;
    }
}
