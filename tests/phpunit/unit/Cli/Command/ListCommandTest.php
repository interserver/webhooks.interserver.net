<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\ListCommand;

class ListCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('list');

        $this->assertSame('list', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasRequiredOptions(): void
    {
        $application = new CliApplication();
        $command = $application->find('list');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('limit'));
        $this->assertTrue($definition->hasOption('repo'));
        $this->assertTrue($definition->hasOption('event-type'));
        $this->assertTrue($definition->hasOption('action'));
        $this->assertTrue($definition->hasOption('json'));
    }

    public function testLimitOptionDefaultsTo50(): void
    {
        $application = new CliApplication();
        $command = $application->find('list');
        $definition = $command->getDefinition();

        $limitOption = $definition->getOption('limit');
        $this->assertSame('50', $limitOption->getDefault());
    }

    public function testReturnsFailureOnRedisConnectionError(): void
    {
        // Set invalid Redis configuration to force connection failure
        putenv('REDIS_HOST=invalid-host-that-does-not-exist');
        putenv('REDIS_PORT=9999');

        $application = new CliApplication();
        $command = $application->find('list');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([], ['capture_stderr_separately' => true]);

        // Restore environment
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
