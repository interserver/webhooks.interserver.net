<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\StatusCommand;

class StatusCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('status');

        $this->assertSame('status', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasRequiredOptions(): void
    {
        $application = new CliApplication();
        $command = $application->find('status');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('id'));
        $this->assertTrue($definition->hasOption('repo'));
        $this->assertTrue($definition->hasOption('pr'));
        $this->assertTrue($definition->hasOption('watch'));
        $this->assertTrue($definition->hasOption('json'));
    }

    public function testWatchModeRequiresId(): void
    {
        $application = new CliApplication();
        $command = $application->find('status');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--watch' => true,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            '--watch requires --id',
            $commandTester->getErrorOutput()
        );
    }

    public function testReturnsFailureOnRedisConnectionError(): void
    {
        putenv('REDIS_HOST=invalid-host-that-does-not-exist');
        putenv('REDIS_PORT=9999');

        $application = new CliApplication();
        $command = $application->find('status');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([], ['capture_stderr_separately' => true]);

        // Restore environment
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
