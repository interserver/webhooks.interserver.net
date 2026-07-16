<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Renderer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Webhooks\Cli\Renderer\TableRenderer;

class TableRendererTest extends TestCase
{
    private TableRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TableRenderer();
    }

    public function testRenderQueueListWithEntries(): void
    {
        $output = new BufferedOutput();

        $entries = [
            [
                'id' => 'abc-123-def-456',
                'ts' => time() - 120,
                'repo' => 'owner/repo',
                'pr_number' => 42,
                'action' => 'opened',
                'head_branch' => 'feature/xyz',
                'base_branch' => 'main',
                'author' => 'testuser',
                'sha' => 'abc123def456789',
            ],
        ];

        $this->renderer->renderQueueList($output, $entries, false);

        $content = $output->fetch();
        $this->assertStringContainsString('owner/repo', $content);
        $this->assertStringContainsString('42', $content);
        $this->assertStringContainsString('opened', $content);
    }

    public function testRenderQueueListVerbose(): void
    {
        $output = new BufferedOutput();

        $entries = [
            [
                'id' => 'abc-123-def-456',
                'ts' => time() - 120,
                'repo' => 'owner/repo',
                'pr_number' => 42,
                'action' => 'opened',
                'head_branch' => 'feature/xyz',
                'base_branch' => 'main',
                'author' => 'testuser',
                'sha' => 'abc123def456789',
            ],
        ];

        $this->renderer->renderQueueList($output, $entries, true);

        $content = $output->fetch();
        $this->assertStringContainsString('abc-123', $content);
    }

    public function testRenderQueueListEmpty(): void
    {
        $output = new BufferedOutput();

        $this->renderer->renderQueueList($output, [], false);

        $content = $output->fetch();
        $this->assertStringContainsString('no entries', $content);
    }

    public function testRenderStatusList(): void
    {
        $output = new BufferedOutput();

        $statuses = [
            [
                'id' => 'abc-123-def-456',
                'repo' => 'owner/repo',
                'pr_number' => 42,
                'status' => 'queued',
                'severity' => 'info',
                'issues_count' => 0,
                'created_at' => time() - 120,
            ],
        ];

        $this->renderer->renderStatusList($output, $statuses);

        $content = $output->fetch();
        $this->assertStringContainsString('owner/repo', $content);
        $this->assertStringContainsString('42', $content);
    }

    public function testRenderStatusItem(): void
    {
        $output = new BufferedOutput();

        $status = [
            'id' => 'abc-123-def-456',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'status' => 'completed',
            'severity' => 'warning',
            'issues_count' => 3,
            'created_at' => time() - 120,
            'completed_at' => time() - 60,
        ];

        $this->renderer->renderStatusItem($output, $status);

        $content = $output->fetch();
        $this->assertStringContainsString('Review Status', $content);
        $this->assertStringContainsString('owner/repo', $content);
        $this->assertStringContainsString('42', $content);
        $this->assertStringContainsString('completed', $content);
    }

    public function testRenderStatusItemWithIssues(): void
    {
        $output = new BufferedOutput();

        $status = [
            'id' => 'abc-123-def-456',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'status' => 'completed',
            'severity' => 'warning',
            'issues_count' => 2,
            'created_at' => time() - 120,
            'issues' => [
                [
                    'severity' => 'error',
                    'file' => 'src/Database.php',
                    'line' => 42,
                    'message' => 'SQL injection vulnerability',
                    'rule' => 'security/sql-injection',
                ],
                [
                    'severity' => 'warning',
                    'file' => 'src/Logger.php',
                    'line' => 100,
                    'message' => 'Missing null check',
                    'rule' => 'logic/null-check',
                ],
            ],
        ];

        $this->renderer->renderStatusItem($output, $status);

        $content = $output->fetch();
        $this->assertStringContainsString('SQL injection vulnerability', $content);
        $this->assertStringContainsString('src/Database.php', $content);
    }

    public function testRenderMetrics(): void
    {
        $output = new BufferedOutput();

        $repoBreakdown = [
            'owner/repo1' => 5,
            'owner/repo2' => 3,
        ];

        $actionBreakdown = [
            'opened' => 6,
            'synchronize' => 2,
        ];

        $this->renderer->renderMetrics(
            $output,
            8,
            100,
            $repoBreakdown,
            $actionBreakdown
        );

        $content = $output->fetch();
        $this->assertStringContainsString('Queue Depth', $content);
        $this->assertStringContainsString('8', $content);
        $this->assertStringContainsString('Total Enqueued', $content);
        $this->assertStringContainsString('100', $content);
    }

    public function testRenderCancelList(): void
    {
        $output = new BufferedOutput();

        $jobs = [
            [
                'id' => 'abc-123-def-456',
                'repo' => 'owner/repo',
                'pr_number' => 42,
                'action' => 'opened',
                'head_branch' => 'feature/xyz',
            ],
        ];

        $this->renderer->renderCancelList($output, $jobs, true);

        $content = $output->fetch();
        $this->assertStringContainsString('1 job(s) cancelled', $content);
    }

    public function testSeverityIcons(): void
    {
        $output = new BufferedOutput();

        $status = [
            'id' => 'abc-123',
            'repo' => 'owner/repo',
            'pr_number' => 42,
            'status' => 'completed',
            'severity' => 'error',
            'issues_count' => 1,
            'created_at' => time(),
        ];

        $this->renderer->renderStatusItem($output, $status);

        $content = $output->fetch();
        // Error severity should show red circle emoji
        $this->assertStringContainsString("\xE2\x9D\x94", $content);
    }

    public function testStatusIcons(): void
    {
        $output = new BufferedOutput();

        $statuses = [
            ['id' => '1', 'repo' => 'r', 'pr_number' => 1, 'status' => 'queued', 'severity' => 'info', 'issues_count' => 0, 'created_at' => time()],
            ['id' => '2', 'repo' => 'r', 'pr_number' => 2, 'status' => 'completed', 'severity' => 'info', 'issues_count' => 0, 'created_at' => time()],
            ['id' => '3', 'repo' => 'r', 'pr_number' => 3, 'status' => 'failed', 'severity' => 'info', 'issues_count' => 0, 'created_at' => time()],
        ];

        $this->renderer->renderStatusList($output, $statuses);

        $content = $output->fetch();
        // Should contain status icons
        $this->assertStringContainsString('⏳', $content); // queued
        $this->assertStringContainsString('✅', $content); // completed
        $this->assertStringContainsString('❌', $content); // failed
    }
}
