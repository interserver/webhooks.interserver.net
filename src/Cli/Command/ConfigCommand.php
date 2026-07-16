<?php
declare(strict_types=1);

namespace Webhooks\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webhooks\Cli\Renderer\JsonRenderer;

/**
 * Manage CLI configuration.
 */
class ConfigCommand extends AbstractCommand
{
    private const CONFIG_FILE = __DIR__ . '/../../../../config/review.php';

    private JsonRenderer $jsonRenderer;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        parent::__construct();
        $this->jsonRenderer = new JsonRenderer();
        $this->config = $this->loadConfig();
    }

    protected static ?string $defaultName = 'config';

    protected function configure(): void
    {
        $this->setName('config');
        $this->setDescription('Manage configuration');
        $this->setHelp(<<<'HELP'
The <info>config</info> command manages the CLI configuration.

  <info>%command.full_name% --list</info>
  <info>%command.full_name% --set-key VALUE</info>
  <info>%command.full_name% --reset KEY</info>
  <info>%command.full_name% --reset-all</info>
  <info>%command.full_name% --json</info>

Options:
  <info>-l, --list</info>            List current configuration
  <info>--set-key VALUE</info>       Set a configuration key
  <info>--reset KEY</info>           Reset a specific key
  <info>--reset-all</info>           Reset all configuration
  <info>--json</info>                Output as JSON

Configuration Keys:
  github.token              GitHub personal access token
  github.api_url            GitHub API URL (for enterprise)
  redis.host                Redis host
  redis.port                Redis port
  checkout.root             Checkout directory
  checkout.cleanup_after    Cleanup timeout (seconds)
  opencode.analyze_cmd      OpenCode analyze command template
  opencode.improve_cmd      OpenCode improve command template
  repositories              Array of watched repositories
  defaults.audit_types      Default audit types
  defaults.severity         Default severity level
  defaults.post_summary     Post summary comment (boolean)
HELP
        );

        $this->addOption(
            'list',
            'l',
            InputOption::VALUE_NONE,
            'List current configuration'
        );

        $this->addOption(
            'set-key',
            null,
            InputOption::VALUE_REQUIRED,
            'Set a configuration key (format: key=value)'
        );

        $this->addOption(
            'reset',
            null,
            InputOption::VALUE_REQUIRED,
            'Reset a specific configuration key'
        );

        $this->addOption(
            'reset-all',
            null,
            InputOption::VALUE_NONE,
            'Reset all configuration to defaults'
        );

        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output as JSON'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $list = (bool)$input->getOption('list');
        $setKey = $input->getOption('set-key');
        $reset = $input->getOption('reset');
        $resetAll = (bool)$input->getOption('reset-all');
        $asJson = (bool)$input->getOption('json');

        // List mode (default if no action specified)
        if ($list || (!$setKey && !$reset && !$resetAll)) {
            return $this->listConfig($output, $asJson);
        }

        // Set key
        if ($setKey !== null) {
            return $this->setKey($output, $io, $setKey, $asJson);
        }

        // Reset key
        if ($reset !== null) {
            return $this->resetKey($output, $io, $reset, $asJson);
        }

        // Reset all
        if ($resetAll) {
            return $this->resetAll($output, $io, $asJson);
        }

        return Command::SUCCESS;
    }

    private function listConfig(OutputInterface $output, bool $asJson): int
    {
        if ($asJson) {
            $this->jsonRenderer->renderConfig($output, $this->config);
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $output
        );

        $io->section('GitHub Configuration');
        $this->printKeyValue($output, '  Token', $this->maskToken($this->config['github']['token'] ?? ''));
        $this->printKeyValue($output, '  API URL', $this->config['github']['api_url'] ?? 'https://api.github.com');

        $io->section('Redis Configuration');
        $this->printKeyValue($output, '  Host', $this->config['redis']['host'] ?? '67.217.60.234');
        $this->printKeyValue($output, '  Port', (string)($this->config['redis']['port'] ?? 6379));

        $io->section('Checkout Configuration');
        $this->printKeyValue($output, '  Root', $this->config['checkout']['root'] ?? '/tmp/pr-checkouts');
        $this->printKeyValue($output, '  Cleanup After', (string)($this->config['checkout']['cleanup_after'] ?? 3600) . 's');

        $io->section('OpenCode Configuration');
        $this->printKeyValue($output, '  Analyze Cmd', $this->config['opencode']['analyze_cmd'] ?? '');
        $this->printKeyValue($output, '  Improve Cmd', $this->config['opencode']['improve_cmd'] ?? '');

        $io->section('Repositories');
        $repos = $this->config['repositories'] ?? [];
        if ($repos === []) {
            $output->writeln('  (none configured)');
        } else {
            foreach ($repos as $repo) {
                $output->writeln('  - ' . $repo);
            }
        }

        $io->section('Defaults');
        $this->printKeyValue($output, '  Audit Types', $this->config['defaults']['audit_types'] ?? 'full');
        $this->printKeyValue($output, '  Severity', $this->config['defaults']['severity'] ?? 'warning');
        $this->printKeyValue($output, '  Post Summary', $this->config['defaults']['post_summary'] ?? 'true' ? 'true' : 'false');

        return Command::SUCCESS;
    }

    private function setKey(OutputInterface $output, SymfonyStyle $io, string $setKey, bool $asJson): int
    {
        $parts = explode('=', $setKey, 2);
        if (count($parts) !== 2) {
            $io->error('Invalid format. Use: key=value');
            return Command::FAILURE;
        }

        [$key, $value] = $parts;
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            $io->error('Key cannot be empty');
            return Command::FAILURE;
        }

        // Parse nested keys (e.g., github.token)
        $segments = explode('.', $key);
        if (count($segments) > 3) {
            $io->error('Invalid key format. Use: section.key or section.subsection.key');
            return Command::FAILURE;
        }

        // Validate specific keys
        if ($segments[0] === 'redis' && count($segments) === 2) {
            if ($segments[1] === 'port') {
                if (!is_numeric($value)) {
                    $io->error('Redis port must be numeric');
                    return Command::FAILURE;
                }
                $value = (int)$value;
            }
        }

        if ($segments[0] === 'defaults' && $segments[1] === 'post_summary') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
        }

        // Update config
        $this->setNestedValue($this->config, $segments, $value);

        if ($this->saveConfig()) {
            if ($asJson) {
                $this->jsonRenderer->renderSuccess($output, "Set {$key} = " . (is_string($value) ? $this->maskToken($value) : json_encode($value)), [
                    'key' => $key,
                    'value' => $value,
                ]);
                return Command::SUCCESS;
            }

            $io->success("Configuration updated: {$key}");
            return Command::SUCCESS;
        }

        $io->error('Failed to save configuration');
        return Command::FAILURE;
    }

    private function resetKey(OutputInterface $output, SymfonyStyle $io, string $key, bool $asJson): int
    {
        $segments = explode('.', $key);
        $defaults = $this->getDefaults();

        // Navigate to the value in defaults
        $defaultValue = $defaults;
        foreach ($segments as $segment) {
            if (!is_array($defaultValue) || !array_key_exists($segment, $defaultValue)) {
                $io->error("Unknown configuration key: {$key}");
                return Command::FAILURE;
            }
            $defaultValue = $defaultValue[$segment];
        }

        // Set to default value
        $this->setNestedValue($this->config, $segments, $defaultValue);

        if ($this->saveConfig()) {
            if ($asJson) {
                $this->jsonRenderer->renderSuccess($output, "Reset {$key} to default", [
                    'key' => $key,
                    'value' => $defaultValue,
                ]);
                return Command::SUCCESS;
            }

            $io->success("Reset {$key} to default");
            return Command::SUCCESS;
        }

        $io->error('Failed to save configuration');
        return Command::FAILURE;
    }

    private function resetAll(OutputInterface $output, SymfonyStyle $io, bool $asJson): int
    {
        $this->config = $this->getDefaults();

        if ($this->saveConfig()) {
            if ($asJson) {
                $this->jsonRenderer->renderSuccess($output, 'Reset all configuration to defaults');
                return Command::SUCCESS;
            }

            $io->success('Reset all configuration to defaults');
            return Command::SUCCESS;
        }

        $io->error('Failed to save configuration');
        return Command::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if (!file_exists(self::CONFIG_FILE)) {
            return $this->getDefaults();
        }

        $config = include self::CONFIG_FILE;
        if (!is_array($config)) {
            return $this->getDefaults();
        }

        return array_replace_recursive($this->getDefaults(), $config);
    }

    private function saveConfig(): bool
    {
        $dir = dirname(self::CONFIG_FILE);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($this->config, true) . ";\n";

        return file_put_contents(self::CONFIG_FILE, $content, LOCK_EX) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaults(): array
    {
        return [
            'github' => [
                'token' => getenv('GITHUB_TOKEN') ?: '',
                'api_url' => 'https://api.github.com',
            ],
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: '67.217.60.234',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            ],
            'checkout' => [
                'root' => getenv('CHECKOUT_ROOT') ?: '/tmp/pr-checkouts',
                'cleanup_after' => 3600,
            ],
            'opencode' => [
                'analyze_cmd' => getenv('OPENCODE_ANALYZE_CMD') ?: 'opencode analyze --dir {dir} --output json',
                'improve_cmd' => getenv('OPENCODE_IMPROVE_CMD') ?: 'opencode improve --dir {dir} --file {file} --line {line} --output json',
            ],
            'repositories' => [],
            'defaults' => [
                'audit_types' => 'full',
                'severity' => 'warning',
                'post_summary' => true,
            ],
        ];
    }

    /**
     * @param array<string> $segments
     * @param mixed $value
     */
    private function setNestedValue(array &$array, array $segments, $value): void
    {
        $current = &$array;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if ($i === $lastIndex) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    private function printKeyValue(OutputInterface $output, string $label, string $value): void
    {
        $output->writeln(sprintf('%s: <info>%s</info>', $label, $value));
    }

    private function maskToken(string $token): string
    {
        if ($token === '' || strlen($token) < 8) {
            return $token !== '' ? '********' : '(not set)';
        }
        return substr($token, 0, 4) . '********' . substr($token, -4);
    }
}
