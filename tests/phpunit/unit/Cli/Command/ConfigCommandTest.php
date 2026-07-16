<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\ConfigCommand;

class ConfigCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');

        $this->assertSame('config', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasRequiredOptions(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('list'));
        $this->assertTrue($definition->hasOption('set-key'));
        $this->assertTrue($definition->hasOption('reset'));
        $this->assertTrue($definition->hasOption('reset-all'));
        $this->assertTrue($definition->hasOption('json'));
    }

    public function testListShowsConfiguration(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--list' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testInvalidSetKeyFormat(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--set-key' => 'invalid-format-without-equals',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Invalid format',
            $commandTester->getDisplay()
        );
    }

    public function testEmptyKeyInSetKey(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--set-key' => '=somevalue',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Key cannot be empty',
            $commandTester->getDisplay()
        );
    }

    public function testInvalidKeyFormat(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--set-key' => 'too.many.segments.invalid',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testResetUnknownKey(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--reset' => 'nonexistent.key',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Unknown configuration key',
            $commandTester->getDisplay()
        );
    }

    public function testJsonOutput(): void
    {
        $application = new CliApplication();
        $command = $application->find('config');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $output = $commandTester->getDisplay();
        $data = json_decode($output, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
    }
}
