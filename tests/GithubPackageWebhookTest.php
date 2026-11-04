<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure helpers in web/github.php (dedup keys + payload whitelist
 * filtering) for the registry_package / package events. The web entry point
 * is loaded through the GITHUB_WEBHOOK_HELPERS_ONLY seam so only its function
 * declarations run.
 */
class GithubPackageWebhookTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('GITHUB_WEBHOOK_HELPERS_ONLY')) {
            define('GITHUB_WEBHOOK_HELPERS_ONLY', true);
        }
        require_once __DIR__ . '/../web/github.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $dir = __DIR__ . '/events/' . $name;
        $payload = json_decode((string)file_get_contents($dir . '/payload.json'), true);
        $this->assertIsArray($payload, "Fixture {$name} payload must decode");
        $type = trim((string)file_get_contents($dir . '/type.txt'));
        return ['type' => $type, 'payload' => $payload];
    }

    // ── buildDedupKey ────────────────────────────────────────────────────────

    public function testDedupKeyForPublishedRegistryPackage(): void
    {
        $fixture = $this->loadFixture('registry_package_published');
        $key = buildDedupKey($fixture['type'], 'detain/sugarcraft', $fixture['payload']);
        $this->assertSame('github:package:detain/sugarcraft:@detain/sugarcraft:2.1.0', $key);
    }

    public function testDedupKeyWithoutVersionFallsBackToLatest(): void
    {
        $fixture = $this->loadFixture('registry_package_updated_without_version');
        $key = buildDedupKey($fixture['type'], 'detain/sugarcraft', $fixture['payload']);
        $this->assertSame('github:package:detain/sugarcraft:@detain/sugarcraft:latest', $key);
    }

    public function testDedupKeyForLegacyPackageEvent(): void
    {
        $fixture = $this->loadFixture('package');
        $key = buildDedupKey($fixture['type'], 'Codertocat/hello-world-npm', $fixture['payload']);
        $this->assertSame('github:package:Codertocat/hello-world-npm:hello-world-npm:1.0.0', $key);
    }

    public function testDedupKeyMissingNameFallsBackToEventTypeKey(): void
    {
        $payload = [
            'action' => 'published',
            'registry_package' => ['package_type' => 'Npm'],
        ];
        $key = buildDedupKey('registry_package', 'org/repo', $payload);
        $this->assertSame('github:registry_package:org/repo', $key);
    }

    // ── Whitelist filtering ──────────────────────────────────────────────────

    public function testRegistryPackageSurvivesPayloadWhitelistFiltering(): void
    {
        $fixture = $this->loadFixture('registry_package_published');
        $categories = require __DIR__ . '/../field_analysis_full/field_categories.php';
        $this->assertArrayHasKey('registry_package', $categories['per_event']);

        $whitelist = buildWebhookWhitelist($categories);
        $whitelist = addUniversalRepoFields($whitelist);
        foreach ($categories['per_event'][$fixture['type']] as $field) {
            $whitelist[$field] = true;
        }

        $filtered = filterWebhookPayload($fixture['payload'], $whitelist, $fixture['type']);

        // Kept: the lean package descriptor the Teams bot post-processes.
        $pkg = $filtered['registry_package'] ?? null;
        $this->assertIsArray($pkg, 'registry_package must survive filtering');
        $this->assertSame('@detain/sugarcraft', $pkg['name']);
        $this->assertSame('Npm', $pkg['package_type']);
        $this->assertSame('https://github.com/detain/sugarcraft/packages/8123456', $pkg['html_url']);
        $this->assertSame('Shared sugarcraft UI building blocks', $pkg['description']);
        $this->assertArrayHasKey('id', $pkg);
        $this->assertArrayHasKey('url', $pkg);
        $this->assertArrayHasKey('created_at', $pkg);
        $this->assertSame('2.1.0', $pkg['package_version']['name']);
        $this->assertSame('2.1.0', $pkg['package_version']['version']);
        $this->assertSame('sugarcraft 2.1.0', $pkg['package_version']['summary']);
        $this->assertSame('detain', $pkg['user']['login']);
        $this->assertSame('detain', $pkg['author']['login']);
        $this->assertSame('published', $filtered['action']);
        $this->assertSame('detain/sugarcraft', $filtered['repository']['full_name']);

        // Dropped: the bloat the whitelist is designed to strip.
        $this->assertArrayNotHasKey('package_files', $pkg['package_version']);
        $this->assertArrayNotHasKey('installation_command', $pkg['package_version']);
        $this->assertArrayNotHasKey('registry', $pkg['package_version']);
        $this->assertArrayNotHasKey('owner', $pkg);
        $this->assertArrayNotHasKey('body', $pkg['package_version']);
    }

    public function testLegacyPackageEventSurvivesFiltering(): void
    {
        $fixture = $this->loadFixture('package');
        $categories = require __DIR__ . '/../field_analysis_full/field_categories.php';
        $this->assertArrayHasKey('package', $categories['per_event']);

        $whitelist = buildWebhookWhitelist($categories);
        $whitelist = addUniversalRepoFields($whitelist);
        foreach ($categories['per_event'][$fixture['type']] as $field) {
            $whitelist[$field] = true;
        }

        $filtered = filterWebhookPayload($fixture['payload'], $whitelist, $fixture['type']);

        $pkg = $filtered['package'] ?? null;
        $this->assertIsArray($pkg, 'legacy package object must survive filtering');
        $this->assertSame('hello-world-npm', $pkg['name']);
        $this->assertSame('npm', $pkg['package_type']);
        $this->assertSame('1.0.0', $pkg['package_version']['version']);
        $this->assertArrayNotHasKey('package_files', $pkg['package_version']);
        $this->assertArrayNotHasKey('manifest', $pkg['package_version']);
        $this->assertArrayNotHasKey('body_html', $pkg['package_version']);
    }

    // ── Message builder over the filtered payload (end-to-end shape) ─────────

    public function testBuildTextOnFilteredRegistryPackagePayload(): void
    {
        $fixture = $this->loadFixture('registry_package_published');
        $categories = require __DIR__ . '/../field_analysis_full/field_categories.php';
        $whitelist = addUniversalRepoFields(buildWebhookWhitelist($categories));
        foreach ($categories['per_event'][$fixture['type']] as $field) {
            $whitelist[$field] = true;
        }
        $filtered = filterWebhookPayload($fixture['payload'], $whitelist, $fixture['type']);

        $Builder = new GithubMessageBuilder($fixture['type'], $filtered);
        $expected = trim((string)file_get_contents(__DIR__ . '/events/registry_package_published/expected_text.txt'));
        $this->assertSame($expected, $Builder->build()['text']);
    }

    public function testPackageTextFallsBackToDefaultWithoutName(): void
    {
        $payload = [
            'action' => 'published',
            'registry_package' => ['package_type' => 'Npm'],
            'repository' => ['full_name' => 'org/repo'],
            'sender' => ['login' => 'alice', 'avatar_url' => 'https://example.com/a.png'],
        ];

        $text = (new GithubMessageBuilder('registry_package', $payload))->build()['text'];

        $this->assertStringContainsString('**registry_package**', $text);
        $this->assertStringContainsString('(published)', $text);
    }
}
