<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\CancelCommand;

class CancelCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('cancel');

        $this->assertSame('cancel', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasRequiredOptions(): void
    {
        $application = new CliApplication();
        $command = $application->find('cancel');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('id'));
        $this->assertTrue($definition->hasOption('repo'));
        $this->assertTrue($definition->hasOption('all'));
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('json'));
    }

    public function testRequiresIdOrRepoOrAll(): void
    {
        $application = new CliApplication();
        $command = $application->find('cancel');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Specify --id, --repo, or --all',
            $commandTester->getErrorOutput()
        );
    }

    public function testAllAndRepoAreMutuallyExclusive(): void
    {
        $application = new CliApplication();
        $command = $application->find('cancel');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--all' => true,
            '--repo' => 'owner/repo',
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'mutually exclusive',
            $commandTester->getErrorOutput()
        );
    }

    public function testReturnsFailureOnRedisConnectionError(): void
    {
        putenv('REDIS_HOST=invalid-host-that-does-not-exist');
        putenv('REDIS_PORT=9999');

        $application = new CliApplication();
        $command = $application->find('cancel');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--all' => true,
        ], ['capture_stderr_separately' => true]);

        // Restore environment
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
