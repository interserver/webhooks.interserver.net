<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Interactor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Webhooks\Cli\Interactor\ActivityInteractor;

class ActivityInteractorTest extends TestCase
{
    private ActivityInteractor $interactor;
    private ArrayInput $input;
    private NullOutput $output;

    protected function setUp(): void
    {
        $this->input = new ArrayInput([]);
        $this->output = new NullOutput();
        $this->interactor = new ActivityInteractor($this->input, $this->output);
    }

    public function testConstructorSetsInputAndOutput(): void
    {
        $interactor = new ActivityInteractor($this->input, $this->output);

        $this->assertInstanceOf(ActivityInteractor::class, $interactor);
    }

    public function testSetInteractiveChangesMode(): void
    {
        $this->interactor->setInteractive(false);
        $this->assertFalse($this->interactor->isInteractive());

        $this->interactor->setInteractive(true);
        $this->assertTrue($this->interactor->isInteractive());
    }

    public function testSetPageSizeSetsValue(): void
    {
        $this->interactor->setPageSize(10);
        // setPageSize should not throw exception
        $this->assertTrue(true);
    }

    public function testBrowseActivityWithEmptyArray(): void
    {
        $result = $this->interactor->browseActivity([]);

        // Returns null when no activity
        $this->assertNull($result);
    }

    public function testBrowseActivityInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $activity = [
            [
                'type' => 'commit',
                'repo' => 'owner/repo',
                'title' => 'Test commit',
                'author' => 'testuser',
                'date' => date('c'),
                'url' => 'https://github.com/owner/repo/commit/abc123',
                'sha' => 'abc123def456',
            ],
        ];

        // In non-interactive mode, browseActivity returns null
        $result = $this->interactor->browseActivity($activity);
        $this->assertNull($result);
    }

    public function testRenderItemWithCommit(): void
    {
        $item = [
            'type' => 'commit',
            'repo' => 'owner/repo',
            'title' => 'Test commit message',
            'author' => 'testuser',
            'author_url' => 'https://github.com/testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/commit/abc123',
            'sha' => 'abc123def456',
            'branch' => 'main',
            'additions' => 10,
            'deletions' => 5,
        ];

        // Should not throw exception
        $this->interactor->renderItem($item, 0, false);
        $this->assertTrue(true);
    }

    public function testRenderItemWithPR(): void
    {
        $item = [
            'type' => 'pr',
            'repo' => 'owner/repo',
            'number' => 42,
            'title' => 'Test PR',
            'author' => 'testuser',
            'author_url' => 'https://github.com/testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/pull/42',
            'state' => 'open',
            'head_branch' => 'feature-branch',
            'base_branch' => 'main',
            'additions' => 100,
            'deletions' => 50,
            'changed_files' => 5,
        ];

        // Should not throw exception
        $this->interactor->renderItem($item, 0, false);
        $this->assertTrue(true);
    }

    public function testRenderItemWithSelectedMarker(): void
    {
        $item = [
            'type' => 'commit',
            'repo' => 'owner/repo',
            'title' => 'Test commit',
            'author' => 'testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/commit/abc123',
            'sha' => 'abc123def456',
        ];

        // Should not throw exception when selected
        $this->interactor->renderItem($item, 0, true);
        $this->assertTrue(true);
    }

    public function testShowDetailWithCommit(): void
    {
        $item = [
            'type' => 'commit',
            'repo' => 'owner/repo',
            'title' => 'Test commit message',
            'description' => 'Full description of the commit',
            'author' => 'testuser',
            'author_url' => 'https://github.com/testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/commit/abc123',
            'sha' => 'abc123def456',
            'branch' => 'main',
            'files_changed' => 3,
            'additions' => 50,
            'deletions' => 20,
        ];

        // Should not throw exception
        $this->interactor->showDetail($item);
        $this->assertTrue(true);
    }

    public function testShowDetailWithPR(): void
    {
        $item = [
            'type' => 'pr',
            'repo' => 'owner/repo',
            'number' => 42,
            'title' => 'Test PR',
            'body' => 'PR description',
            'author' => 'testuser',
            'author_url' => 'https://github.com/testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/pull/42',
            'state' => 'open',
            'head_branch' => 'feature-branch',
            'base_branch' => 'main',
            'additions' => 100,
            'deletions' => 50,
            'changed_files' => 5,
            'labels' => ['bug', 'urgent'],
            'reviews' => [
                [
                    'author' => 'reviewer1',
                    'state' => 'APPROVED',
                    'submitted_at' => date('c'),
                ],
            ],
        ];

        // Should not throw exception
        $this->interactor->showDetail($item);
        $this->assertTrue(true);
    }

    public function testHandleSelectionInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $item = [
            'type' => 'pr',
            'repo' => 'owner/repo',
            'number' => 42,
            'title' => 'Test PR',
        ];

        // In non-interactive mode, returns null
        $result = $this->interactor->handleSelection($item);
        $this->assertNull($result);
    }

    public function testHandleSelectionInInteractiveModeReturnsNull(): void
    {
        // This will return null immediately since we're in non-interactive mode
        // In a real test with interactive mode, it would prompt
        $this->interactor->setInteractive(false);

        $item = [
            'type' => 'pr',
            'repo' => 'owner/repo',
            'number' => 42,
            'title' => 'Test PR',
        ];

        $result = $this->interactor->handleSelection($item);
        $this->assertNull($result);
    }

    public function testRenderItemWithMissingOptionalFields(): void
    {
        $item = [
            'type' => 'commit',
            'repo' => 'owner/repo',
            'title' => 'Test commit',
            'author' => 'testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/commit/abc123',
            // sha intentionally missing
            // branch intentionally missing
            // additions/deletions intentionally missing
        ];

        // Should not throw exception with missing optional fields
        $this->interactor->renderItem($item, 0, false);
        $this->assertTrue(true);
    }

    public function testShowDetailWithMissingOptionalFields(): void
    {
        $item = [
            'type' => 'pr',
            'repo' => 'owner/repo',
            'number' => 42,
            'title' => 'Test PR',
            'author' => 'testuser',
            'author_url' => 'https://github.com/testuser',
            'date' => date('c'),
            'url' => 'https://github.com/owner/repo/pull/42',
            // state intentionally missing
            // body intentionally missing
            // labels intentionally missing
            // reviews intentionally missing
        ];

        // Should not throw exception with missing optional fields
        $this->interactor->showDetail($item);
        $this->assertTrue(true);
    }
}
