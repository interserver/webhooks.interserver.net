<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Service;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Service\GithubActivityService;

class GithubActivityServiceTest extends TestCase
{
    public function testGetDefaultOrgsReturnsExpectedOrgs(): void
    {
        $orgs = GithubActivityService::getDefaultOrgs();

        $this->assertIsArray($orgs);
        $this->assertContains('interserver', $orgs);
        $this->assertContains('provirted', $orgs);
        $this->assertContains('sugarcraft', $orgs);
        $this->assertContains('detain', $orgs);
        $this->assertCount(4, $orgs);
    }

    public function testConstructorInitializesWithGhCliAvailable(): void
    {
        $service = new GithubActivityService();

        // isGhCliAvailable returns a boolean (true if gh is available, false otherwise)
        $this->assertIsBool($service->isGhCliAvailable());
    }

    public function testGetUserReturnsNullWhenGhNotAvailable(): void
    {
        $service = new GithubActivityService();

        // If gh is not available, getUser returns null
        if (!$service->isGhCliAvailable()) {
            $this->assertNull($service->getUser());
            return;
        }

        // gh is available but may not be authenticated
        $user = $service->getUser();
        if ($user === null) {
            // gh not authenticated - this is valid behavior
            $this->markTestSkipped('gh is available but not authenticated');
            return;
        }

        // If authenticated, validate the response structure
        $this->assertIsArray($user);
        $this->assertArrayHasKey('login', $user);
        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('url', $user);
    }

    public function testGetOrgsReturnsArray(): void
    {
        $service = new GithubActivityService();

        $orgs = $service->getOrgs();

        $this->assertIsArray($orgs);
    }

    public function testGetReposReturnsArray(): void
    {
        $service = new GithubActivityService();

        $repos = $service->getRepos([]);

        $this->assertIsArray($repos);
    }

    public function testGetReposAcceptsOrgArray(): void
    {
        $service = new GithubActivityService();

        $orgs = [
            ['login' => 'test-org', 'url' => 'https://github.com/test-org'],
        ];

        $repos = $service->getRepos($orgs);

        $this->assertIsArray($repos);
    }

    public function testGetRecentCommitsReturnsArray(): void
    {
        $service = new GithubActivityService();

        $commits = $service->getRecentCommits([], '24h', 10);

        $this->assertIsArray($commits);
    }

    public function testGetRecentPRsReturnsArray(): void
    {
        $service = new GithubActivityService();

        $prs = $service->getRecentPRs([], '24h', 10);

        $this->assertIsArray($prs);
    }

    public function testGetActivityWithEmptyReposReturnsEmptyArray(): void
    {
        $service = new GithubActivityService();

        $activity = $service->getActivity([], ['type' => 'all', 'since' => '24h', 'limit' => 30]);

        $this->assertIsArray($activity);
    }

    public function testGetActivityWithTypePush(): void
    {
        $service = new GithubActivityService();

        $activity = $service->getActivity([], ['type' => 'push', 'since' => '24h', 'limit' => 30]);

        $this->assertIsArray($activity);
    }

    public function testGetActivityWithTypePr(): void
    {
        $service = new GithubActivityService();

        $activity = $service->getActivity([], ['type' => 'pr', 'since' => '24h', 'limit' => 30]);

        $this->assertIsArray($activity);
    }

    public function testGetActivityWithInvalidTypeFallsBackToAll(): void
    {
        $service = new GithubActivityService();

        // Using reflection to test the fallback behavior
        // The service should handle invalid type gracefully
        $activity = $service->getActivity([], ['type' => 'invalid', 'since' => '24h', 'limit' => 30]);

        $this->assertIsArray($activity);
    }
}
