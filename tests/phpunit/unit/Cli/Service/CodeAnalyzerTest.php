<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Service;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Service\CodeAnalyzer;

class CodeAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset any environment variables that might affect tests
        putenv('OPENCODE_ANALYZE_CMD');
        putenv('OPENCODE_IMPROVE_CMD');
    }

    protected function tearDown(): void
    {
        putenv('OPENCODE_ANALYZE_CMD');
        putenv('OPENCODE_IMPROVE_CMD');
    }

    public function testConstructorSetsAnalyzeCommand(): void
    {
        $customCmd = 'opencode analyze --dir {dir} --custom-flag --output json';
        $analyzer = new CodeAnalyzer($customCmd, null);

        $this->assertSame(
            $customCmd,
            $this->getPrivateProperty($analyzer, 'analyzeCommandTemplate')
        );
    }

    public function testConstructorSetsImproveCommand(): void
    {
        $customCmd = 'opencode improve --dir {dir} --file {file} --line {line} --custom --output json';
        $analyzer = new CodeAnalyzer(null, $customCmd);

        $this->assertSame(
            $customCmd,
            $this->getPrivateProperty($analyzer, 'improveCommandTemplate')
        );
    }

    public function testConstructorUsesEnvVarForAnalyzeCmd(): void
    {
        putenv('OPENCODE_ANALYZE_CMD=custom-analyze-cmd');
        $analyzer = new CodeAnalyzer();
        $this->assertSame(
            'custom-analyze-cmd',
            $this->getPrivateProperty($analyzer, 'analyzeCommandTemplate')
        );
    }

    public function testConstructorUsesEnvVarForImproveCmd(): void
    {
        putenv('OPENCODE_IMPROVE_CMD=custom-improve-cmd');
        $analyzer = new CodeAnalyzer();
        $this->assertSame(
            'custom-improve-cmd',
            $this->getPrivateProperty($analyzer, 'improveCommandTemplate')
        );
    }

    public function testConstructorUsesDefaultWhenNoEnv(): void
    {
        $analyzer = new CodeAnalyzer();
        $this->assertSame(
            'opencode analyze --dir {dir} --output json',
            $this->getPrivateProperty($analyzer, 'analyzeCommandTemplate')
        );
    }

    public function testIsAvailableReturnsBool(): void
    {
        $analyzer = new CodeAnalyzer();
        $result = $analyzer->isAvailable();
        $this->assertIsBool($result);
    }

    public function testClearCacheSetsAnalyzeCacheToNull(): void
    {
        $analyzer = new CodeAnalyzer();

        // Use reflection to set cache
        $reflection = new \ReflectionClass($analyzer);
        $prop = $reflection->getProperty('analyzeCache');
        $prop->setAccessible(true);
        $prop->setValue($analyzer, ['cached' => 'data']);

        $analyzer->clearCache();

        $this->assertNull($prop->getValue($analyzer));
    }

    public function testAnalyzeReturnsUnavailableWhenNotAvailable(): void
    {
        // Test that analyze returns error result when opencode is not available
        // This is tested by calling analyze on a directory when opencode is not installed
        $analyzer = new CodeAnalyzer();

        // If opencode is not available, the result should indicate that
        if (!$analyzer->isAvailable()) {
            $result = $analyzer->analyze('/some/dir');
            $this->assertFalse($result['available']);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('not available', $result['message']);
        } else {
            // If opencode is available, skip this test
            $this->markTestSkipped('opencode is available on this system');
        }
    }

    public function testImproveReturnsUnavailableWhenNotAvailable(): void
    {
        $analyzer = new CodeAnalyzer();

        if (!$analyzer->isAvailable()) {
            $result = $analyzer->improve('/some/dir', 'file.php', 42);
            $this->assertFalse($result['available']);
            $this->assertFalse($result['success']);
        } else {
            $this->markTestSkipped('opencode is available on this system');
        }
    }

    public function testFormatForCommentReturnsCorrectStructure(): void
    {
        $analyzer = new CodeAnalyzer();

        $analysis = [
            'issues' => [
                [
                    'severity' => 'error',
                    'type' => 'security',
                    'file' => 'src/Database.php',
                    'line' => 42,
                    'message' => 'SQL injection risk',
                ],
                [
                    'severity' => 'warning',
                    'type' => 'style',
                    'file' => 'src/Database.php',
                    'line' => 100,
                    'message' => 'Missing docblock',
                ],
                [
                    'severity' => 'info',
                    'type' => 'documentation',
                    'file' => 'README.md',
                    'line' => 1,
                    'message' => 'Consider adding examples',
                ],
            ],
        ];

        $result = $analyzer->formatForComment($analysis);

        $this->assertSame(3, $result['total_issues']);
        $this->assertSame(1, $result['issues_by_severity']['error']);
        $this->assertSame(1, $result['issues_by_severity']['warning']);
        $this->assertSame(1, $result['issues_by_severity']['info']);
        $this->assertSame(1, $result['issues_by_type']['security']);
        $this->assertSame(1, $result['issues_by_type']['style']);
        $this->assertSame(1, $result['issues_by_type']['documentation']);
        $this->assertContains('src/Database.php', $result['files_changed']);
        $this->assertContains('README.md', $result['files_changed']);
    }

    public function testFormatForCommentHandlesEmptyIssues(): void
    {
        $analyzer = new CodeAnalyzer();

        $analysis = [
            'issues' => [],
        ];

        $result = $analyzer->formatForComment($analysis);

        $this->assertSame(0, $result['total_issues']);
        $this->assertSame(0, $result['issues_by_severity']['error']);
        $this->assertSame([], $result['files_changed']);
    }

    public function testFormatForCommentHandlesMissingIssuesKey(): void
    {
        $analyzer = new CodeAnalyzer();

        $analysis = [];

        $result = $analyzer->formatForComment($analysis);

        $this->assertSame(0, $result['total_issues']);
        $this->assertSame([], $result['issues_by_file']);
    }

    public function testFormatForCommentHandlesNonArrayIssues(): void
    {
        $analyzer = new CodeAnalyzer();

        $analysis = [
            'issues' => 'not an array',
        ];

        $result = $analyzer->formatForComment($analysis);

        $this->assertSame(0, $result['total_issues']);
    }

    public function testParseJsonOutputReturnsNullForInvalidJson(): void
    {
        $analyzer = new CodeAnalyzer();

        $result = $this->invokePrivateMethod($analyzer, 'parseJsonOutput', ['not valid json {']);
        $this->assertNull($result);
    }

    public function testParseJsonOutputExtractsJsonFromText(): void
    {
        $analyzer = new CodeAnalyzer();

        $json = '{"test": "value", "number": 42}';
        $result = $this->invokePrivateMethod($analyzer, 'parseJsonOutput', ["some text before\n{$json}\nsome text after"]);
        $this->assertSame(['test' => 'value', 'number' => 42], $result);
    }

    public function testCreateUnavailableResultHasCorrectStructure(): void
    {
        $analyzer = new CodeAnalyzer();

        $result = $this->invokePrivateMethod($analyzer, 'createUnavailableResult', ['test message']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['error']);
        $this->assertFalse($result['available']);
        $this->assertSame('test message', $result['message']);
    }

    public function testCreateErrorResultHasCorrectStructure(): void
    {
        $analyzer = new CodeAnalyzer();

        $result = $this->invokePrivateMethod($analyzer, 'createErrorResult', ['error occurred']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['error']);
        $this->assertTrue($result['available']);
        $this->assertSame('error occurred', $result['message']);
    }

    public function testEscapeShellArgSurroundsWithSingleQuotes(): void
    {
        $analyzer = new CodeAnalyzer();
        $result = $this->invokePrivateMethod($analyzer, 'escapeShellArg', ["test'value"]);

        // Should be escaped with single quotes
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringContainsString("test", $result);
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
}
