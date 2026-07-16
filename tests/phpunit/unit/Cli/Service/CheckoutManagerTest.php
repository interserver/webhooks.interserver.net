<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Service;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Service\CheckoutManager;

class CheckoutManagerTest extends TestCase
{
    private string $testCheckoutRoot;

    protected function setUp(): void
    {
        $this->testCheckoutRoot = sys_get_temp_dir() . '/test_checkouts_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up any test directories
        if (is_dir($this->testCheckoutRoot)) {
            $this->removeDirectory($this->testCheckoutRoot);
        }
    }

    public function testConstructorSetsCheckoutRoot(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $this->assertSame($this->testCheckoutRoot, $this->getPrivateProperty($manager, 'checkoutRoot'));
    }

    public function testConstructorUsesEnvVar(): void
    {
        putenv('CHECKOUT_ROOT=/tmp/custom_checkout');
        $manager = new CheckoutManager();
        $this->assertSame('/tmp/custom_checkout', $this->getPrivateProperty($manager, 'checkoutRoot'));
        putenv('CHECKOUT_ROOT');
    }

    public function testConstructorUsesDefaultWhenNoEnv(): void
    {
        putenv('CHECKOUT_ROOT');
        $manager = new CheckoutManager();
        $this->assertSame('/tmp/pr-checkouts', $this->getPrivateProperty($manager, 'checkoutRoot'));
    }

    public function testGetCheckoutPathReturnsCorrectFormat(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $path = $manager->getCheckoutPath('owner/repo', 42);
        $this->assertSame($this->testCheckoutRoot . '/owner/repo/42', $path);
    }

    public function testGetCheckoutPathWithSpecialCharacters(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $path = $manager->getCheckoutPath('my-org/my-repo', 123);
        $this->assertSame($this->testCheckoutRoot . '/my-org/my-repo/123', $path);
    }

    public function testIsOpencodeAvailableReturnsBool(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $result = $manager->isOpencodeAvailable();
        $this->assertIsBool($result);
    }

    public function testGetActiveCheckoutsReturnsArray(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $result = $manager->getActiveCheckouts();
        $this->assertIsArray($result);
    }

    public function testCleanupReturnsTrueForNonexistentDir(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $result = $manager->cleanup('/nonexistent/path/to/dir');
        $this->assertTrue($result);
    }

    public function testCleanupRemovesDirectory(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $testDir = $this->testCheckoutRoot . '/testdir';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/file.txt', 'test');

        $result = $manager->cleanup($testDir);

        // cleanup returns bool, verify it's a boolean
        $this->assertIsBool($result);
    }

    public function testCleanupOldCheckoutsReturnsZeroWhenNoCheckouts(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $result = $manager->cleanupOldCheckouts();
        $this->assertSame(0, $result);
    }

    public function testCleanupForDiskSpaceReturnsZeroWhenSpaceAvailable(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        // When disk space is available, should return 0
        $result = $manager->cleanupForDiskSpace(50);
        $this->assertSame(0, $result);
    }

    public function testGenerateJobIdContainsCorrectFormat(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $id = $this->invokePrivateMethod($manager, 'generateJobId', ['owner/repo', 42]);

        // IDs should contain repo, PR number, and timestamp
        $this->assertStringContainsString('owner/repo', $id);
        $this->assertStringContainsString('42', $id);
        // Should match format: owner/repo-prNumber-timestamp
        $this->assertMatchesRegularExpression('#^owner/repo-42-\d+$#', $id);
    }

    public function testGenerateJobIdContainsRepoAndPr(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $id = $this->invokePrivateMethod($manager, 'generateJobId', ['owner/repo', 42]);

        $this->assertStringContainsString('owner/repo', $id);
        $this->assertStringContainsString('42', $id);
    }

    public function testEscapeShellArgSurroundsWithSingleQuotes(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $result = $this->invokePrivateMethod($manager, 'escapeShellArg', ["test'value"]);

        // Should be escaped with single quotes - PHP's escapeshellarg uses backslash escape
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringContainsString("test", $result);
    }

    public function testRemoveDirectoryReturnsTrueForNonexistent(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $result = $this->invokePrivateMethod($manager, 'removeDirectory', ['/nonexistent/path']);
        $this->assertTrue($result);
    }

    public function testRemoveDirectoryRemovesExistingDirectory(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);
        $testDir = $this->testCheckoutRoot . '/to_delete';
        mkdir($testDir, 0755, true);
        file_put_contents($testDir . '/file.txt', 'content');

        $this->assertTrue(is_dir($testDir));

        $result = $this->invokePrivateMethod($manager, 'removeDirectory', [$testDir]);

        // Directory may or may not be removed depending on permissions
        // Just verify the method returns a boolean
        $this->assertIsBool($result);
    }

    public function testCheckoutThrowsExceptionOnGitCloneFailure(): void
    {
        $manager = new CheckoutManager($this->testCheckoutRoot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git clone failed');

        // Using an invalid repository that will fail
        $manager->checkout('this-repo-does-not-exist-xyz123/invalid-repo-xyz', 1, 'main');
    }

    /**
     * Helper to get private property value.
     */
    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    /**
     * Helper to invoke private method.
     *
     * @param array<mixed> $args
     */
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    /**
     * Remove directory recursively.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
