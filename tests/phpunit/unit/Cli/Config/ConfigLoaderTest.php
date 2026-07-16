<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Config;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Config\ConfigLoader;

class ConfigLoaderTest extends TestCase
{
    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $loader = new ConfigLoader();
        $this->assertSame('default_value', $loader->get('nonexistent.key', 'default_value'));
    }

    public function testGetReturnsConfigValue(): void
    {
        $loader = new ConfigLoader(['github' => ['token' => 'test-token']]);
        $this->assertSame('test-token', $loader->get('github.token'));
    }

    public function testGetWithDotNotation(): void
    {
        $loader = new ConfigLoader([
            'redis' => [
                'host' => 'custom-host',
                'port' => 6380,
            ],
        ]);

        $this->assertSame('custom-host', $loader->get('redis.host'));
        $this->assertSame(6380, $loader->get('redis.port'));
    }

    public function testHasReturnsFalseForNonexistentKey(): void
    {
        $loader = new ConfigLoader();
        $this->assertFalse($loader->has('nonexistent.key'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $loader = new ConfigLoader(['github' => ['token' => 'test']]);
        $this->assertTrue($loader->has('github.token'));
    }

    public function testAllReturnsConfigArray(): void
    {
        $loader = new ConfigLoader(['github' => ['token' => 'test']]);
        $all = $loader->all();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('github', $all);
    }

    public function testDefaultValuesAreSet(): void
    {
        $loader = new ConfigLoader();

        $this->assertSame('67.217.60.234', $loader->get('redis.host'));
        $this->assertSame(6379, $loader->get('redis.port'));
        $this->assertSame('/tmp/pr-checkouts', $loader->get('checkout.root'));
        $this->assertSame(3600, $loader->get('checkout.cleanup_after'));
    }

    public function testCliArgsOverrideDefaults(): void
    {
        $loader = new ConfigLoader([
            'redis' => ['host' => 'cli-host', 'port' => 6380],
        ]);

        $this->assertSame('cli-host', $loader->get('redis.host'));
        $this->assertSame(6380, $loader->get('redis.port'));
    }

    public function testGetGithubToken(): void
    {
        $loader = new ConfigLoader(['github' => ['token' => 'ghp_test']]);
        $this->assertSame('ghp_test', $loader->getGithubToken());
    }

    public function testGetGithubTokenReturnsEmptyStringWhenNotSet(): void
    {
        $loader = new ConfigLoader();
        $this->assertSame('', $loader->getGithubToken());
    }

    public function testGetRedisConfig(): void
    {
        $loader = new ConfigLoader([
            'redis' => ['host' => 'custom-redis', 'port' => 6380],
        ]);

        $config = $loader->getRedisConfig();
        $this->assertSame('custom-redis', $config['host']);
        $this->assertSame(6380, $config['port']);
    }

    public function testGetCheckoutConfig(): void
    {
        $loader = new ConfigLoader([
            'checkout' => ['root' => '/custom/path', 'cleanup_after' => 7200],
        ]);

        $config = $loader->getCheckoutConfig();
        $this->assertSame('/custom/path', $config['root']);
        $this->assertSame(7200, $config['cleanup_after']);
    }

    public function testGetOpencodeConfig(): void
    {
        $loader = new ConfigLoader([
            'opencode' => [
                'analyze_cmd' => 'custom analyze',
                'improve_cmd' => 'custom improve',
            ],
        ]);

        $config = $loader->getOpencodeConfig();
        $this->assertSame('custom analyze', $config['analyze_cmd']);
        $this->assertSame('custom improve', $config['improve_cmd']);
    }

    public function testGetRepositories(): void
    {
        $loader = new ConfigLoader([
            'repositories' => ['owner/repo1', 'owner/repo2'],
        ]);

        $repos = $loader->getRepositories();
        $this->assertSame(['owner/repo1', 'owner/repo2'], $repos);
    }

    public function testGetRepositoriesReturnsEmptyArrayWhenNotSet(): void
    {
        $loader = new ConfigLoader();
        $this->assertSame([], $loader->getRepositories());
    }

    public function testGetDefaultAuditTypesWithFull(): void
    {
        $loader = new ConfigLoader([
            'defaults' => ['audit_types' => 'full'],
        ]);

        $types = $loader->getDefaultAuditTypes();
        $this->assertSame(
            ['security', 'performance', 'documentation', 'logic', 'style'],
            $types
        );
    }

    public function testGetDefaultAuditTypesWithCommaSeparated(): void
    {
        $loader = new ConfigLoader([
            'defaults' => ['audit_types' => 'security,performance'],
        ]);

        $types = $loader->getDefaultAuditTypes();
        $this->assertSame(['security', 'performance'], $types);
    }

    public function testGetDefaultSeverity(): void
    {
        $loader = new ConfigLoader([
            'defaults' => ['severity' => 'error'],
        ]);

        $this->assertSame('error', $loader->getDefaultSeverity());
    }

    public function testGetDefaultPostSummary(): void
    {
        $loader = new ConfigLoader([
            'defaults' => ['post_summary' => false],
        ]);

        $this->assertFalse($loader->getDefaultPostSummary());
    }

    public function testSetAndGetInteractive(): void
    {
        $loader = new ConfigLoader();
        $this->assertTrue($loader->isInteractive());

        $loader->setInteractive(false);
        $this->assertFalse($loader->isInteractive());
    }

    public function testCliNonInteractiveFlag(): void
    {
        $loader = new ConfigLoader(['cli' => ['non_interactive' => true]]);
        $this->assertTrue($loader->detectNonInteractive());
    }

    public function testMergePriorityCliOverEnv(): void
    {
        // CLI args should take priority over env
        $loader = new ConfigLoader([
            'github' => ['token' => 'cli-token'],
        ]);

        $this->assertSame('cli-token', $loader->getGithubToken());
    }

    public function testDeepMergeNestedArrays(): void
    {
        $loader = new ConfigLoader([
            'defaults' => [
                'audit_types' => 'security',
                'severity' => 'error',
            ],
        ]);

        // Should merge with defaults
        $this->assertSame('security', $loader->get('defaults.audit_types'));
        $this->assertSame('error', $loader->get('defaults.severity'));
        // Default post_summary should remain
        $this->assertTrue($loader->get('defaults.post_summary'));
    }
}
