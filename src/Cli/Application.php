<?php
declare(strict_types=1);

namespace Webhooks\Cli;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webhooks\Cli\Command\ListCommand;
use Webhooks\Cli\Command\MetricsCommand;
use Webhooks\Cli\Command\SubmitCommand;
use Webhooks\Cli\Command\StatusCommand;
use Webhooks\Cli\Command\ActivityCommand;
use Webhooks\Cli\Command\CancelCommand;
use Webhooks\Cli\Command\ConfigCommand;

/**
 * GitHub Review CLI Application.
 *
 * Entry point for the github-review command-line tool.
 */
class Application extends BaseApplication
{
    private const NAME = 'github-review';
    private const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
    }

    /**
     * @return Command[]
     */
    protected function getDefaultCommands(): array
    {
        return array_merge(parent::getDefaultCommands(), [
            new ActivityCommand(),
            new ListCommand(),
            new MetricsCommand(),
            new SubmitCommand(),
            new StatusCommand(),
            new CancelCommand(),
            new ConfigCommand(),
        ]);
    }

    /**
     * Override to make --help without a command show application help (list of all commands)
     * instead of the help for the default "list" command.
     *
     * Symfony Console's default behavior runs the HelpCommand with command_name=$this->defaultCommand,
     * which results in showing help for 'list' instead of the application help (list of commands).
     * This override detects the --help/-h case and shows application-level help directly.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // When --help or -h is passed without a command name (no first argument),
        // show application help (list of all commands) instead of running HelpCommand
        // which would show help for the default command ('list').
        if ($input->hasParameterOption(['--help', '-h'], true) && $input->getFirstArgument() === null) {
            $helper = new DescriptorHelper();
            $helper->describe($output, $this, [
                'format' => 'txt',
                'raw_text' => false,
            ]);

            return Command::SUCCESS;
        }

        return parent::doRun($input, $output);
    }
}
