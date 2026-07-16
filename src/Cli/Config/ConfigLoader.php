<?php
declare(strict_types=1);

namespace Webhooks\Cli\Config;

use Webhooks\Cli\Exception\ExceptionCodes;
use Webhooks\Cli\Exception\ReviewCliException;

/**
 * Loads and merges configuration from multiple sources.
 *
 * Priority (lowest to highest):
 * 1. System config (/etc/github-review/config.php)
 * 2. User config (~/.config/github-review/config.php)
 * 3. Project config (./config/review-cli.php)
 * 4. Environment variables
 * 5. CLI arguments (passed directly)
 */
class ConfigLoader
{
    private const SYSTEM_CONFIG_PATH = '/etc/github-review/config.php';
    private const USER_CONFIG_DIR = '.config/github-review';
    private const USER_CONFIG_FILE = 'config.php';
    private const PROJECT_CONFIG_FILE = 'config/review-cli.php';

    /** @var array<string, mixed> */
    private array $config = [];

    private bool $isInteractive = true;

    /**
     * @param array<string, mixed> $cliArgs Arguments passed from CLI (highest priority)
     */
    public function __construct(array $cliArgs = [])
    {
        $this->load($cliArgs);
    }

    /**
     * Load configuration from all sources and merge.
     *
     * @param array<string, mixed> $cliArgs CLI arguments (highest priority)
     */
    public function load(array $cliArgs = []): void
    {
        // Start with empty config
        $this->config = $this->getDefaults();

        // Merge in order of priority (later merges override earlier)
        $this->config = $this->mergeConfig(
            $this->config,
            $this->loadSystemConfig()
        );
        $this->config = $this->mergeConfig(
            $this->config,
            $this->loadUserConfig()
        );
        $this->config = $this->mergeConfig(
            $this->config,
            $this->loadProjectConfig()
        );
        $this->config = $this->mergeConfig(
            $this->config,
            $this->loadEnvConfig()
        );
        $this->config = $this->mergeConfig(
            $this->config,
            $cliArgs
        );
    }

    /**
     * Get a configuration value by key path.
     *
     * @param string $key Dot-notation key path (e.g., 'github.token', 'redis.host')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get the entire configuration array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Check if a configuration key exists.
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * Set interactive mode for prompting.
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
     * Check if running without a TTY (non-interactive batch mode).
     */
    public function detectNonInteractive(): bool
    {
        // Check for non-interactive flag in config
        if ($this->get('cli.non_interactive', false) === true) {
            return true;
        }

        // Check if stdout is not a TTY
        if (function_exists('posix_isatty')) {
            return !posix_isatty(STDOUT);
        }

        // Fallback: check for common non-interactive indicators
        if (getenv('CI') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get the GitHub token.
     */
    public function getGithubToken(): string
    {
        return (string)$this->get('github.token', '');
    }

    /**
     * Get Redis configuration.
     *
     * @return array{host: string, port: int}
     */
    public function getRedisConfig(): array
    {
        return [
            'host' => (string)$this->get('redis.host', '67.217.60.234'),
            'port' => (int)$this->get('redis.port', 6379),
        ];
    }

    /**
     * Get checkout configuration.
     *
     * @return array{root: string, cleanup_after: int}
     */
    public function getCheckoutConfig(): array
    {
        return [
            'root' => (string)$this->get('checkout.root', '/tmp/pr-checkouts'),
            'cleanup_after' => (int)$this->get('checkout.cleanup_after', 3600),
        ];
    }

    /**
     * Get opencode command templates.
     *
     * @return array{analyze_cmd: string, improve_cmd: string}
     */
    public function getOpencodeConfig(): array
    {
        return [
            'analyze_cmd' => (string)$this->get(
                'opencode.analyze_cmd',
                'opencode analyze --dir {dir} --output json'
            ),
            'improve_cmd' => (string)$this->get(
                'opencode.improve_cmd',
                'opencode improve --dir {dir} --file {file} --line {line} --output json'
            ),
        ];
    }

    /**
     * Get the list of configured repositories.
     *
     * @return string[]
     */
    public function getRepositories(): array
    {
        $repos = $this->get('repositories', []);
        return is_array($repos) ? array_filter($repos, 'is_string') : [];
    }

    /**
     * Get default audit types.
     *
     * @return string[]
     */
    public function getDefaultAuditTypes(): array
    {
        $types = $this->get('defaults.audit_types', 'full');
        if ($types === 'full') {
            return ['security', 'performance', 'documentation', 'logic', 'style'];
        }
        if (is_string($types)) {
            return array_filter(array_map('trim', explode(',', $types)));
        }
        return is_array($types) ? $types : ['full'];
    }

    /**
     * Get default severity level.
     */
    public function getDefaultSeverity(): string
    {
        return (string)$this->get('defaults.severity', 'warning');
    }

    /**
     * Check if post summary is enabled by default.
     */
    public function getDefaultPostSummary(): bool
    {
        return (bool)$this->get('defaults.post_summary', true);
    }

    /**
     * Load system-wide configuration.
     *
     * @return array<string, mixed>
     */
    private function loadSystemConfig(): array
    {
        if (!file_exists(self::SYSTEM_CONFIG_PATH)) {
            return [];
        }

        $config = $this->includeConfig(self::SYSTEM_CONFIG_PATH);
        return is_array($config) ? $config : [];
    }

    /**
     * Load user configuration (~/.config/github-review/config.php).
     *
     * @return array<string, mixed>
     */
    private function loadUserConfig(): array
    {
        $home = $this->getHomeDirectory();
        if ($home === null) {
            return [];
        }

        $configPath = $home . '/' . self::USER_CONFIG_DIR . '/' . self::USER_CONFIG_FILE;
        if (!file_exists($configPath)) {
            return [];
        }

        $config = $this->includeConfig($configPath);
        return is_array($config) ? $config : [];
    }

    /**
     * Load project configuration (./config/review-cli.php).
     *
     * @return array<string, mixed>
     */
    private function loadProjectConfig(): array
    {
        $configPath = $this->getProjectRoot() . '/' . self::PROJECT_CONFIG_FILE;
        if (!file_exists($configPath)) {
            return [];
        }

        $config = $this->includeConfig($configPath);
        return is_array($config) ? $config : [];
    }

    /**
     * Load configuration from environment variables.
     *
     * @return array<string, mixed>
     */
    private function loadEnvConfig(): array
    {
        $config = [];

        // GitHub token
        if (getenv('GITHUB_TOKEN') !== false) {
            $config['github']['token'] = getenv('GITHUB_TOKEN');
        }

        // Redis
        if (getenv('REDIS_HOST') !== false) {
            $config['redis']['host'] = getenv('REDIS_HOST');
        }
        if (getenv('REDIS_PORT') !== false) {
            $config['redis']['port'] = (int)getenv('REDIS_PORT');
        }

        // Checkout
        if (getenv('CHECKOUT_ROOT') !== false) {
            $config['checkout']['root'] = getenv('CHECKOUT_ROOT');
        }

        // OpenCode commands
        if (getenv('OPENCODE_ANALYZE_CMD') !== false) {
            $config['opencode']['analyze_cmd'] = getenv('OPENCODE_ANALYZE_CMD');
        }
        if (getenv('OPENCODE_IMPROVE_CMD') !== false) {
            $config['opencode']['improve_cmd'] = getenv('OPENCODE_IMPROVE_CMD');
        }

        // CLI flags
        if (getenv('CLI_NON_INTERACTIVE') !== false) {
            $config['cli']['non_interactive'] = getenv('CLI_NON_INTERACTIVE') !== 'false';
        }

        return $config;
    }

    /**
     * Get the user's home directory.
     */
    private function getHomeDirectory(): ?string
    {
        // Try environment variables first
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }

        // Try to get from password database
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && array_key_exists('dir', $info)) {
                return $info['dir'];
            }
        }

        return null;
    }

    /**
     * Get the project root directory.
     */
    private function getProjectRoot(): string
    {
        // Use a environment variable if set
        if (getenv('PROJECT_ROOT') !== false) {
            return (string)getenv('PROJECT_ROOT');
        }

        // Default to current working directory
        return getcwd() ?: __DIR__ . '/../../../..';
    }

    /**
     * Safely include a config file.
     *
     * @return array<string, mixed>|null
     */
    private function includeConfig(string $path): ?array
    {
        try {
            /** @var mixed $config */
            $config = include $path;
            return is_array($config) ? $config : null;
        } catch (\Throwable $e) {
            // Silently ignore config file errors
            return null;
        }
    }

    /**
     * Deep merge two configuration arrays.
     *
     * @param array<string, mixed> $base Base config
     * @param array<string, mixed> $override Override config
     * @return array<string, mixed> Merged config
     */
    private function mergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !$this->isSequentialArray($value)
                && !$this->isSequentialArray($base[$key])
            ) {
                // Recursively merge nested associative arrays
                $base[$key] = $this->mergeConfig($base[$key], $value);
            } else {
                // Override the value
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Check if an array is sequential (0-based integer keys).
     *
     * @param array<mixed> $arr
     */
    private function isSequentialArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Get default configuration values.
     *
     * @return array<string, mixed>
     */
    private function getDefaults(): array
    {
        return [
            'github' => [
                'token' => '',
            ],
            'redis' => [
                'host' => '67.217.60.234',
                'port' => 6379,
            ],
            'checkout' => [
                'root' => '/tmp/pr-checkouts',
                'cleanup_after' => 3600,
            ],
            'opencode' => [
                'analyze_cmd' => 'opencode analyze --dir {dir} --output json',
                'improve_cmd' => 'opencode improve --dir {dir} --file {file} --line {line} --output json',
            ],
            'repositories' => [],
            'defaults' => [
                'audit_types' => 'full',
                'severity' => 'warning',
                'post_summary' => true,
            ],
            'cli' => [
                'non_interactive' => false,
            ],
        ];
    }
}
