<?php
declare(strict_types=1);

namespace Webhooks\Cli\Interactor;

use Symfony\Component\Console\Exception\RuntimeException as SymfonyRuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputStream;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Handles interactive prompting for user input.
 *
 * Auto-detects TTY vs non-TTY mode and provides appropriate fallback
 * when running in batch/non-interactive mode.
 */
class InteractiveInteractor
{
    private ?QuestionHelper $questionHelper = null;
    private InputInterface $input;
    private OutputInterface $output;
    private bool $isInteractive = true;



    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->detectInteractiveMode();
    }

    /**
     * Set whether to run in interactive mode.
     */
    public function setInteractive(bool $interactive): void
    {
        $this->isInteractive = $interactive;
    }

    /**
     * Check if running in interactive mode.
     */
    public function isInteractive(): bool
    {
        return $this->isInteractive;
    }

    /**
     * Set the question helper (for testing).
     */
    public function setQuestionHelper(QuestionHelper $helper): void
    {
        $this->questionHelper = $helper;
    }

    /**
     * Prompt the user with a question and return their answer.
     *
     * @param string $question The question to ask
     * @param array<string>|null $choices Optional list of valid choices
     * @param string|null $default Default value if no input provided
     * @return string The user's answer
     */
    public function prompt(string $question, ?array $choices = null, ?string $default = null): string
    {
        if (!$this->isInteractive) {
            return $default ?? '';
        }

        $questionText = $this->formatQuestion($question, $choices, $default);

        if ($choices !== null && $default !== null) {
            return $this->select($question, $choices, $default);
        }

        $q = new Question($questionText, $default);

        return $this->askQuestion($q);
    }

    /**
     * Ask a yes/no confirmation question.
     *
     * @param string $question The question to ask
     * @param bool $default Default value (true or false)
     * @return bool The user's answer
     */
    public function confirm(string $question, bool $default = true): bool
    {
        if (!$this->isInteractive) {
            return $default;
        }

        $questionText = $this->formatQuestion($question, ['yes', 'no'], $default ? 'yes' : 'no');

        $q = new ConfirmationQuestion($questionText, $default);

        /** @var bool $answer */
        $answer = $this->askQuestion($q);

        return $answer;
    }

    /**
     * Select one option from a list of choices.
     *
     * @param string $question The question to ask
     * @param array<string> $options List of available options
     * @param string|null $default Default option (index or value)
     * @return string The selected option
     */
    public function select(string $question, array $options, ?string $default = null): string
    {
        if (!$this->isInteractive) {
            return $default ?? $options[0] ?? '';
        }

        if ($options === []) {
            return '';
        }

        $defaultIndex = 0;
        if ($default !== null) {
            $defaultIndex = array_search($default, $options, true);
            if ($defaultIndex === false) {
                $defaultIndex = 0;
            }
        }

        $choiceQuestion = new ChoiceQuestion(
            $this->formatQuestion($question, $options, $default),
            $options,
            $defaultIndex
        );
        $choiceQuestion->setErrorMessage('Invalid choice. Please try again.');

        /** @var string $answer */
        $answer = $this->askQuestion($choiceQuestion);

        return $answer;
    }

    /**
     * Select multiple options from a list of choices.
     *
     * @param string $question The question to ask
     * @param array<string> $options List of available options
     * @param array<string> $defaults Default selections
     * @return array<string> Selected options
     */
    public function selectMultiple(string $question, array $options, array $defaults = []): array
    {
        if (!$this->isInteractive) {
            return $defaults;
        }

        if ($options === []) {
            return [];
        }

        $questionText = $question;
        if ($defaults !== []) {
            $questionText .= ' (default: ' . implode(', ', $defaults) . ')';
        }
        $questionText .= ': ';

        $choiceQuestion = new ChoiceQuestion($questionText, $options);
        $choiceQuestion->setMultiselect(true);
        $choiceQuestion->setErrorMessage('Invalid choice. Please try again.');

        /** @var array<string> $answer */
        $answer = $this->askQuestion($choiceQuestion);

        return $answer;
    }

    /**
     * Ask for a secret (password) input.
     *
     * @param string $question The question to ask
     * @param bool $confirm Whether to ask for confirmation
     * @return string The secret value
     */
    public function secret(string $question, bool $confirm = false): string
    {
        if (!$this->isInteractive) {
            return '';
        }

        $q = new Question($question . ': ');
        $q->setHidden(true);
        $q->setHiddenFallback(true);

        $value = $this->askQuestion($q);

        if ($confirm) {
            $q2 = new Question($question . ' (confirm): ');
            $q2->setHidden(true);
            $q2->setHiddenFallback(true);

            $confirmValue = $this->askQuestion($q2);

            if ($value !== $confirmValue) {
                throw new \RuntimeException('Values do not match.');
            }
        }

        return $value;
    }

    /**
     * Show a progress bar for an operation.
     *
     * @param callable(iterable<mixed>): mixed $callback The operation to track
     * @param int $total Total number of steps
     * @param string $message Description of the operation
     * @return mixed The result of the callback
     */
    public function withProgressBar(
        callable $callback,
        int $total,
        string $message = 'Processing...'
    ): mixed {
        if (!$this->isInteractive) {
            // In non-interactive mode, just run without progress display
            return $callback($this->createProgressIterable($total));
        }

        $progressBar = $this->createProgressBar($total);
        $progressBar->setMessage($message);
        $progressBar->start();

        $result = $callback($this->createProgressIterable($total));

        $progressBar->finish();
        if ($this->output->isDecorated()) {
            $this->output->writeln('');
        }

        return $result;
    }

    /**
     * Ask a question and get the answer.
     *
     * @return mixed
     */
    private function askQuestion(Question $question): mixed
    {
        $helper = $this->getQuestionHelper();

        try {
            return $helper->ask($this->input, $this->output, $question);
        } catch (SymfonyRuntimeException $e) {
            // If we can't ask (e.g., non-interactive), return default
            if ($question instanceof ChoiceQuestion) {
                return $question->getDefault();
            }
            return $question->getDefault();
        }
    }

    /**
     * Get the question helper.
     */
    private function getQuestionHelper(): QuestionHelper
    {
        if ($this->questionHelper === null) {
            // Get from application if available
            $showHelp = $this->input->getOption('help') !== null && $this->input->getOption('help') !== false;
            $application = $showHelp ? null : $this->getApplication();
            if ($application !== null) {
                /** @var QuestionHelper $helper */
                $helper = $application->getHelperSet()->get('question');
                $this->questionHelper = $helper;
            } else {
                $this->questionHelper = new QuestionHelper();
            }
        }

        return $this->questionHelper;
    }

    /**
     * Detect if we're running in interactive mode.
     */
    private function detectInteractiveMode(): void
    {
        // Check for explicit non-interactive flag
        if ($this->input->hasOption('non-interactive')) {
            $nonInteractive = $this->input->getOption('non-interactive');
            if ($nonInteractive !== null && $nonInteractive !== false) {
                $this->isInteractive = false;
                return;
            }
        }

        // Check for --no-interaction flag (Symfony convention)
        if ($this->input->hasParameterOption(['--no-interaction', '-n'])) {
            $this->isInteractive = false;
            return;
        }

        // Check for CI environment
        if (getenv('CI') !== false) {
            $this->isInteractive = false;
            return;
        }

        // Check if we have a TTY
        if (function_exists('posix_isatty')) {
            $this->isInteractive = posix_isatty(STDOUT) && posix_isatty(STDIN);
        }
    }

    /**
     * Format a question for display.
     *
     * @param string $question
     * @param array<string>|null $choices
     * @param string|null $default
     */
    private function formatQuestion(string $question, ?array $choices, ?string $default): string
    {
        $text = $question;

        if ($choices !== null) {
            $choicesStr = implode(', ', $choices);
            $text .= " ({$choicesStr})";
        }

        if ($default !== null) {
            $text .= " [{$default}]";
        }

        $text .= ': ';

        return $text;
    }

    /**
     * Create a simple progress iterable for non-interactive mode.
     *
     * @return \Generator<int>
     */
    private function createProgressIterable(int $total): \Generator
    {
        for ($i = 0; $i < $total; $i++) {
            yield $i;
        }
    }

    /**
     * Create a Symfony ProgressBar instance.
     */
    private function createProgressBar(int $total): \Symfony\Component\Console\Helper\ProgressBar
    {
        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar(
            $this->output,
            $total
        );

        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setBarCharacter('<info>=</info>');
        $progressBar->setEmptyBarCharacter('<comment>-</comment>');
        $progressBar->setProgressCharacter('>');

        return $progressBar;
    }

    /**
     * Get the application from the command's helper set.
     */
    private function getApplication(): mixed
    {
        // In CLI context, we can't reliably get the application from input.
        // Return null since the application isn't needed for our use case.
        return null;
    }
}
