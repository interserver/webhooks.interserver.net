<?php
declare(strict_types=1);

namespace Webhooks\Tests\Unit\Cli\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webhooks\Cli\Application as CliApplication;
use Webhooks\Cli\Command\SubmitCommand;

class SubmitCommandTest extends TestCase
{
    public function testConfigureSetsNameAndDescription(): void
    {
        $application = new CliApplication();
        $command = $application->find('submit');

        $this->assertSame('submit', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    public function testConfigureHasRequiredOptions(): void
    {
        $application = new CliApplication();
        $command = $application->find('submit');
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('repo'));
        $this->assertTrue($definition->hasOption('pr'));
        $this->assertTrue($definition->hasOption('commit'));
        $this->assertTrue($definition->hasOption('audit-types'));
        $this->assertTrue($definition->hasOption('no-security'));
        $this->assertTrue($definition->hasOption('no-performance'));
        $this->assertTrue($definition->hasOption('no-documentation'));
        $this->assertTrue($definition->hasOption('no-logic'));
        $this->assertTrue($definition->hasOption('no-style'));
        $this->assertTrue($definition->hasOption('issue-for-commits'));
        $this->assertTrue($definition->hasOption('issue-label'));
        $this->assertTrue($definition->hasOption('issue-assignee'));
        $this->assertTrue($definition->hasOption('post-diffs'));
        $this->assertTrue($definition->hasOption('post-branch'));
        $this->assertTrue($definition->hasOption('branch-name'));
        $this->assertTrue($definition->hasOption('split-changes'));
        $this->assertTrue($definition->hasOption('split-batch-size'));
        $this->assertTrue($definition->hasOption('split-by'));
        $this->assertTrue($definition->hasOption('split-label'));
        $this->assertTrue($definition->hasOption('all'));
        $this->assertTrue($definition->hasOption('combine'));
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('non-interactive'));
    }

    public function testPostDiffsAndPostBranchAreMutuallyExclusive(): void
    {
        $application = new CliApplication();
        $command = $application->find('submit');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--repo' => 'owner/repo',
            '--pr' => '42',
            '--post-diffs' => true,
            '--post-branch' => true,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'mutually exclusive',
            $commandTester->getErrorOutput()
        );
    }

    public function testSplitChangesAndCombineAreMutuallyExclusive(): void
    {
        $application = new CliApplication();
        $command = $application->find('submit');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--repo' => 'owner/repo',
            '--pr' => '42',
            '--split-changes' => true,
            '--combine' => true,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'mutually exclusive',
            $commandTester->getErrorOutput()
        );
    }

    public function testRequiresRepository(): void
    {
        $application = new CliApplication();
        $command = $application->find('submit');
        $commandTester = new CommandTester($command);

        // Non-interactive mode without repo should fail
        $exitCode = $commandTester->execute([
            '--non-interactive' => true,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testDryRunModeDoesNotRequireRedis(): void
    {
        putenv('REDIS_HOST=invalid-host-that-does-not-exist');
        putenv('REDIS_PORT=9999');

        $application = new CliApplication();
        $command = $application->find('submit');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--repo' => 'owner/repo',
            '--pr' => '42',
            '--dry-run' => true,
        ], ['capture_stderr_separately' => true]);

        // Restore environment
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testInvalidRepositoryFormat(): void
    {
        $application = new CliApplication();
        $command = $application->find('submit');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--repo' => 'invalid-repo-format',
            '--pr' => '42',
            '--dry-run' => true,
        ], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testReturnsFailureOnRedisConnectionError(): void
    {
        putenv('REDIS_HOST=invalid-host-that-does-not-exist');
        putenv('REDIS_PORT=9999');

        $application = new CliApplication();
        $command = $application->find('submit');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--repo' => 'owner/repo',
            '--pr' => '42',
        ], ['capture_stderr_separately' => true]);

        // Restore environment
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
