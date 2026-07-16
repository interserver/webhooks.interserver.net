<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\MetricsCommand;

class MetricsCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('metrics');

        $this->assertSame('metrics', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasJsonOption(): void
    {
        $application = new CliApplication();
        $command = $application->find('metrics');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('json'));
    }

    public function testReturnsFailureOnRedisConnectionError(): void
    {
        // Set invalid Redis configuration to force connection failure
        putenv('REDIS_HOST=invalid-host-that-does-not-exist');
        putenv('REDIS_PORT=9999');

        $application = new CliApplication();
        $command = $application->find('metrics');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([], ['capture_stderr_separately' => true]);

        // Restore environment
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
