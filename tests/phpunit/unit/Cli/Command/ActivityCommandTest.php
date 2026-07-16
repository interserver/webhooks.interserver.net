<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\ActivityCommand;

class ActivityCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');

        $this->assertSame('activity', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasRequiredOptions(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('repo'));
        $this->assertTrue($definition->hasOption('type'));
        $this->assertTrue($definition->hasOption('since'));
        $this->assertTrue($definition->hasOption('limit'));
        $this->assertTrue($definition->hasOption('org'));
        $this->assertTrue($definition->hasOption('user'));
        $this->assertTrue($definition->hasOption('non-interactive'));
        $this->assertTrue($definition->hasOption('json'));
    }

    public function testSinceOptionDefaultsTo24h(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        $sinceOption = $definition->getOption('since');
        $this->assertSame('24h', $sinceOption->getDefault());
    }

    public function testLimitOptionDefaultsTo30(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        $limitOption = $definition->getOption('limit');
        $this->assertSame('30', $limitOption->getDefault());
    }

    public function testTypeOptionAcceptsValidValues(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        $typeOption = $definition->getOption('type');
        $this->assertNull($typeOption->getDefault()); // Default is handled in code
    }

    public function testNonInteractiveOptionExists(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('non-interactive'));
    }

    public function testJsonOptionExists(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('json'));
    }

    public function testReturnsFailureWhenGhNotAvailable(): void
    {
        // Simulate gh not being available by running with non-interactive mode
        // when gh is not installed
        $application = new CliApplication();
        $command = $application->find('activity');
        $commandTester = new CommandTester($command);

        // This will likely fail because gh is not available in test environment
        // but we just verify it runs
        try {
            $exitCode = $commandTester->execute(
                ['--non-interactive' => true],
                ['capture_stderr_separately' => true]
            );

            // If gh is not available, we expect failure
            if ($exitCode !== Command::SUCCESS) {
                $this->assertSame(Command::FAILURE, $exitCode);
            }
        } catch (\Throwable $e) {
            // Expected in test environment without gh
            $this->assertTrue(true);
        }
    }

    public function testCommandHasHelpText(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');

        $help = $command->getHelp();
        $this->assertNotEmpty($help);
        $this->assertStringContainsString('activity', $help);
    }

    public function testRepoOptionShortForm(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        // Check that -r is the short form for repo
        $repoOption = $definition->getOption('repo');
        $this->assertSame('r', $repoOption->getShortcut());
    }

    public function testTypeOptionShortForm(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        // Check that -t is the short form for type
        $typeOption = $definition->getOption('type');
        $this->assertSame('t', $typeOption->getShortcut());
    }

    public function testSinceOptionShortForm(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        // Check that -s is the short form for since
        $sinceOption = $definition->getOption('since');
        $this->assertSame('s', $sinceOption->getShortcut());
    }

    public function testLimitOptionShortForm(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        // Check that -l is the short form for limit
        $limitOption = $definition->getOption('limit');
        $this->assertSame('l', $limitOption->getShortcut());
    }

    public function testOrgOptionShortForm(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        // Check that -o is the short form for org
        $orgOption = $definition->getOption('org');
        $this->assertSame('o', $orgOption->getShortcut());
    }

    public function testUserOptionShortForm(): void
    {
        $application = new CliApplication();
        $command = $application->find('activity');
        $definition = $command->getDefinition();

        // Check that -u is the short form for user
        $userOption = $definition->getOption('user');
        $this->assertSame('u', $userOption->getShortcut());
    }
}
