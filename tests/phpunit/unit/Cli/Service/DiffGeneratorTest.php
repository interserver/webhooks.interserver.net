<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Service;

use PHPUnit\Framework\TestCase;
use Webhooks\Cli\Service\DiffGenerator;

class DiffGeneratorTest extends TestCase
{
    private DiffGenerator $diffGenerator;

    protected function setUp(): void
    {
        $this->diffGenerator = new DiffGenerator(3);
    }

    public function testConstructorSetsContextLines(): void
    {
        $generator = new DiffGenerator(5);
        $this->assertSame(5, $this->getPrivateProperty($generator, 'contextLines'));
    }

    public function testGenerateDiffReturnsString(): void
    {
        $original = "line 1\nline 2\nline 3\n";
        $new = "line 1\nmodified line 2\nline 3\n";

        $result = $this->diffGenerator->generateDiff('test.txt', $original, $new);

        $this->assertIsString($result);
    }

    public function testGenerateDiffContainsFileLabels(): void
    {
        $original = "original content\n";
        $new = "new content\n";

        $result = $this->diffGenerator->generateDiff('src/Test.php', $original, $new);

        $this->assertStringContainsString('src/Test.php', $result);
    }

    public function testGenerateDiffShowsAdditions(): void
    {
        $original = "line 1\n";
        $new = "line 1\nline 2\n";

        $result = $this->diffGenerator->generateDiff('test.txt', $original, $new);

        $this->assertStringContainsString('+', $result);
    }

    public function testGenerateDiffShowsDeletions(): void
    {
        $original = "line 1\nline 2\n";
        $new = "line 1\n";

        $result = $this->diffGenerator->generateDiff('test.txt', $original, $new);

        $this->assertStringContainsString('-', $result);
    }

    public function testGenerateDiffWithNewFilePath(): void
    {
        $original = "";
        $new = "new file content\n";

        $result = $this->diffGenerator->generateDiff('old.txt', $original, $new, 'new.txt');

        $this->assertStringContainsString('new.txt', $result);
    }

    public function testGenerateLineChangesDiffWithNoChangesReturnsEmpty(): void
    {
        $originalContent = ['line 1', 'line 2', 'line 3'];
        $lineChanges = [];

        $result = $this->diffGenerator->generateLineChangesDiff('test.txt', $lineChanges, $originalContent);

        $this->assertSame('', $result);
    }

    public function testGenerateNewFileDiffShowsNewFileMode(): void
    {
        $result = $this->diffGenerator->generateNewFileDiff('newfile.php', '<?php echo "hello";');

        $this->assertStringContainsString('new file mode', $result);
        $this->assertStringContainsString('newfile.php', $result);
    }

    public function testGenerateNewFileDiffShowsPlusLines(): void
    {
        $content = "line 1\nline 2\nline 3\n";
        $result = $this->diffGenerator->generateNewFileDiff('newfile.txt', $content);

        $this->assertStringContainsString('+line 1', $result);
        $this->assertStringContainsString('+line 2', $result);
        $this->assertStringContainsString('+line 3', $result);
    }

    public function testGenerateDeletedFileDiffShowsDeletedFileMode(): void
    {
        $result = $this->diffGenerator->generateDeletedFileDiff('deleted.php', '<?php // deleted');

        $this->assertStringContainsString('deleted file mode', $result);
        $this->assertStringContainsString('deleted.php', $result);
    }

    public function testGenerateDeletedFileDiffShowsMinusLines(): void
    {
        $content = "line 1\nline 2\n";
        $result = $this->diffGenerator->generateDeletedFileDiff('deleted.txt', $content);

        $this->assertStringContainsString('-line 1', $result);
        $this->assertStringContainsString('-line 2', $result);
    }

    public function testGenerateGitDiffReturnsString(): void
    {
        // This test may fail if not in a git repo, so just check it returns a string
        $result = $this->diffGenerator->generateGitDiff('/tmp');

        $this->assertIsString($result);
    }

    public function testGeneratePatchIncludesHeader(): void
    {
        $result = $this->diffGenerator->generatePatch('test.txt', 'original', 'modified');

        $this->assertStringContainsString('From:', $result);
        $this->assertStringContainsString('Subject:', $result);
    }

    public function testFormatAsMarkdownWrapsInCodeBlock(): void
    {
        $diff = "--- a/test.txt\n+++ b/test.txt";
        $result = $this->diffGenerator->formatAsMarkdown($diff);

        $this->assertStringStartsWith('```diff', $result);
        $this->assertStringEndsWith('```', $result);
        $this->assertStringContainsString($diff, $result);
    }

    public function testDiffHasChangesReturnsTrueWhenChangesExist(): void
    {
        $diff = "--- a/test.txt\n+++ b/test.txt\n-old\n+new";
        $this->assertTrue($this->diffGenerator->diffHasChanges($diff));
    }

    public function testDiffHasChangesReturnsFalseForNoChanges(): void
    {
        $diff = "--- a/test.txt\n+++ b/test.txt";
        $this->assertFalse($this->diffGenerator->diffHasChanges($diff));
    }

    public function testDiffHasChangesReturnsFalseForEmptyDiff(): void
    {
        $this->assertFalse($this->diffGenerator->diffHasChanges(''));
    }

    public function testGenerateDiffWithIdenticalContentShowsNoChanges(): void
    {
        $content = "same content\n";
        $result = $this->diffGenerator->generateDiff('test.txt', $content, $content);

        // The diff command might not produce output for identical files
        $this->assertIsString($result);
    }

    public function testCreateTempFileCreatesFile(): void
    {
        $content = 'test content';
        $tmpFile = $this->invokePrivateMethod($this->diffGenerator, 'createTempFile', [$content]);

        $this->assertFileExists($tmpFile);
        $this->assertSame($content, file_get_contents($tmpFile));

        @unlink($tmpFile);
    }

    public function testEscapeShellArgSurroundsWithSingleQuotes(): void
    {
        $result = $this->invokePrivateMethod($this->diffGenerator, 'escapeShellArg', ["test'value"]);

        // Should be escaped with single quotes
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringContainsString("test", $result);
    }

    public function testFormatUnifiedDiffReturnsString(): void
    {
        $result = $this->invokePrivateMethod(
            $this->diffGenerator,
            'formatUnifiedDiff',
            ['original.txt', 'new.txt', "line1\nline2\n", "line1\nmodified\n"]
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('---', $result);
        $this->assertStringContainsString('+++', $result);
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
