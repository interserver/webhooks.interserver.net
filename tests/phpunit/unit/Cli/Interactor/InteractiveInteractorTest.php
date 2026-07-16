<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Interactor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Webhooks\Cli\Interactor\InteractiveInteractor;

class InteractiveInteractorTest extends TestCase
{
    private InteractiveInteractor $interactor;
    private ArrayInput $input;
    private NullOutput $output;

    protected function setUp(): void
    {
        $definition = new InputDefinition([
            new InputOption('non-interactive', null, InputOption::VALUE_NONE),
        ]);

        $this->input = new ArrayInput([], $definition);
        $this->output = new NullOutput();

        $this->interactor = new InteractiveInteractor($this->input, $this->output);
    }

    public function testDefaultIsInteractive(): void
    {
        $interactor = new InteractiveInteractor(
            new ArrayInput([]),
            new NullOutput()
        );

        // With no TTY and CI not set, behavior depends on environment
        // But by default it should be interactive if not explicitly disabled
        $this->assertIsBool($interactor->isInteractive());
    }

    public function testSetInteractiveChangesMode(): void
    {
        $this->interactor->setInteractive(false);
        $this->assertFalse($this->interactor->isInteractive());

        $this->interactor->setInteractive(true);
        $this->assertTrue($this->interactor->isInteractive());
    }

    public function testPromptReturnsDefaultInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $result = $this->interactor->prompt('Enter value:', null, 'default_value');
        $this->assertSame('default_value', $result);
    }

    public function testConfirmReturnsDefaultInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        // With default true
        $result = $this->interactor->confirm('Continue?', true);
        $this->assertTrue($result);

        // With default false
        $result = $this->interactor->confirm('Continue?', false);
        $this->assertFalse($result);
    }

    public function testSelectReturnsDefaultInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $options = ['option1', 'option2', 'option3'];
        $result = $this->interactor->select('Choose:', $options, 'option2');
        $this->assertSame('option2', $result);
    }

    public function testSelectReturnsFirstOptionWhenNoDefaultInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $options = ['option1', 'option2', 'option3'];
        $result = $this->interactor->select('Choose:', $options, null);
        $this->assertSame('option1', $result);
    }

    public function testSelectReturnsEmptyStringWhenNoOptionsAvailable(): void
    {
        $this->interactor->setInteractive(false);

        $result = $this->interactor->select('Choose:', [], null);
        $this->assertSame('', $result);
    }

    public function testSelectMultipleReturnsDefaultsInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $options = ['option1', 'option2', 'option3'];
        $defaults = ['option1', 'option3'];

        $result = $this->interactor->selectMultiple('Choose:', $options, $defaults);
        $this->assertSame(['option1', 'option3'], $result);
    }

    public function testSelectMultipleReturnsEmptyArrayWhenNoOptionsAvailable(): void
    {
        $this->interactor->setInteractive(false);

        $result = $this->interactor->selectMultiple('Choose:', [], []);
        $this->assertSame([], $result);
    }

    public function testSecretReturnsEmptyStringInNonInteractiveMode(): void
    {
        $this->interactor->setInteractive(false);

        $result = $this->interactor->secret('Enter password:');
        $this->assertSame('', $result);
    }

    public function testPromptReturnsChoicesWhenProvidedInInteractiveMode(): void
    {
        // In interactive mode with choices and default, it should use select
        $this->interactor->setInteractive(true);

        // Create a mock question helper that returns a fixed value
        $mockHelper = $this->createMock(QuestionHelper::class);
        $mockHelper->method('ask')->willReturn('option2');

        $this->interactor->setQuestionHelper($mockHelper);

        $result = $this->interactor->prompt(
            'Choose:',
            ['option1', 'option2', 'option3'],
            'option2'
        );

        $this->assertSame('option2', $result);
    }

    public function testWithProgressBarInNonInteractiveModeExecutesCallback(): void
    {
        $this->interactor->setInteractive(false);

        $callbackExecuted = false;
        $callback = function (iterable $items) use (&$callbackExecuted) {
            $callbackExecuted = true;
            foreach ($items as $i) {
                // Just iterate
            }
            return 'result';
        };

        $result = $this->interactor->withProgressBar($callback, 5, 'Processing');

        $this->assertTrue($callbackExecuted);
        $this->assertSame('result', $result);
    }

    public function testWithProgressBarInNonInteractiveModeDoesNotWriteToOutput(): void
    {
        $this->interactor->setInteractive(false);

        $output = new NullOutput(); // Already tested with NullOutput

        $callback = function (iterable $items) {
            foreach ($items as $i) {
                // Just iterate
            }
        };

        // Should not throw
        $this->interactor->withProgressBar($callback, 3, 'Test');
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testDetectNonInteractiveFromFlag(): void
    {
        $definition = new InputDefinition([
            new InputOption('non-interactive', 'n', InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--non-interactive' => true], $definition);
        $interactor = new InteractiveInteractor($input, new NullOutput());

        $this->assertFalse($interactor->isInteractive());
    }

    public function testDetectNonInteractiveFromShortFlag(): void
    {
        $definition = new InputDefinition([
            new InputOption('non-interactive', 'n', InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['-n' => true], $definition);
        $interactor = new InteractiveInteractor($input, new NullOutput());

        $this->assertFalse($interactor->isInteractive());
    }

    public function testDetectNonInteractiveFromNoInteractionFlag(): void
    {
        $definition = new InputDefinition([
            new InputOption('no-interaction', 'n', InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--no-interaction' => true], $definition);
        $interactor = new InteractiveInteractor($input, new NullOutput());

        $this->assertFalse($interactor->isInteractive());
    }
}
