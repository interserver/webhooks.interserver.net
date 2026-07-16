# GitHub Webhooks → RocketChat / Microsoft Teams Announcer

Receives [GitHub webhook events](https://docs.github.com/en/developers/webhooks-and-events/webhooks/webhook-events-and-payloads), validates them, builds formatted notifications, and enqueues them for delivery to RocketChat and/or Microsoft Teams (via Power Automate).

---

## Architecture

```
GitHub → web/github.php → GithubWebhook (validate)
                           → GithubMessageBuilder (format)
                           → NotificationQueue (enqueue to Redis)
                           → (fallback) Power Automate direct POST
```

### Entry Points

| File | Purpose |
|------|---------|
| `web/github.php` | Active webhook receiver endpoint |
| `web/github-old.php` | Legacy version (RocketChat only) |

### Core Classes

| Class | File | Responsibility |
|-------|------|----------------|
| `GithubWebhook` | `src/GithubWebhook.php` | Request validation, payload parsing, signature verification |
| `GithubMessageBuilder` | `src/GithubMessageBuilder.php` | Builds chat-formatted text per event type |
| `NotificationQueue` | `src/NotificationQueue.php` | Redis enqueue with direct fallback |
| `IgnoredEventException` | `src/IgnoredEventException.php` | Thrown for intentionally ignored events |
| `NotImplementedException` | `src/NotImplementedException.php` | Thrown for unsupported event types |

---

## Configuration

Copy `.env.dist` to `.env` and configure:

```bash
# GitHub webhook HMAC secret — must match the secret in your GitHub hook settings
# https://github.com/organizations/interserver/settings/hooks
GITHUB_WEBHOOKS_SECRET=

# Notification queue settings
# When NOTIF_QUEUE_ENABLED=true (default), events go to Redis for teams-chat-bot to consume.
# When false, falls through to direct Power Automate POSTs.
NOTIF_QUEUE_ENABLED=true
NOTIF_QUEUE_KEY_PREFIX=notif:

# Redis connection (canonical values come from /home/sites/mystage/include/config/config.settings.php)
# Override here only when running outside the shared InterServer environment
REDIS_HOST=dragonfly.mailbaby.net
REDIS_PORT=6379

# Disk log verbosity for incoming events:
#   debug → write every event payload
#   info  → write only on send-failure (recommended for production)
#   warn  → don't write at all
LOG_LEVEL=debug

# Producer-side per-event-type rate limiter window (seconds).
# 0 disables (default) — recommended, since teams-chat-bot already coalesces downstream.
# Only set non-zero to suppress same-dedup_key bursts at the webhook itself.
RATE_LIMIT_WINDOW=0
```

### RocketChat / Teams Channel URLs

Channel webhook URLs are stored in `src/config.php` under `$chatChannels`:

```php
$chatChannels = [
    'rocketchat' => [
        'int-dev'       => '...',  // BAckhdSAoMsPieCof... (webhook URL)
        'notifications' => '...',
        'hardware'      => '...',
        'general'       => '...',
        'development'   => '...',
    ],
    'teams' => [
        'notifications'   => 'https://...powerautomate.com/...',  // Power Automate webhook
        'int-dev'         => 'https://...powerautomate.com/...',
        'int-dev-announce'=> 'https://...powerautomate.com/...',
        'development'     => 'https://...powerautomate.com/...',
        'hardware'        => 'https://...powerautomate.com/...',
        'general'         => 'https://...powerautomate.com/...',
    ],
];
```

---

## Event Handling

### Flow in `web/github.php`

1. **Validate signature** — HMAC SHA256 against `GITHUB_WEBHOOKS_SECRET`
2. **Parse request** — Extract event type and JSON payload
3. **Check payload** — Ensure payload is not empty
4. **Pick room** — Route to `int-dev-announce` or `notifications` based on repo
5. **Build dedup key** — Per-event-type deduplication key to prevent duplicates
6. **Rate limit check** — Optional per-key suppression (disabled by default)
7. **Build message** — Use `GithubMessageBuilder` to format chat text
8. **Enqueue** — Push to Redis via `NotificationQueue::enqueueMessage()`
9. **Log result** — Write disposition to error_log

### Room Routing

Repositories starting with `sugarcraft/` or in this list route to `int-dev-announce`:

- `detain/CandyCore`
- `detain/scoop-emulators`
- `detain/detain`
- `detain/sugarcraft`
- `detain/watchable`
- `detain/php-dup-finder`

All others route to `notifications`.

### Supported Events

The `GithubMessageBuilder` handles these event types with rich formatting:

| Event | Emoji | Description |
|-------|-------|-------------|
| `issues` | 🐛 | Issue opened, closed, edited, labeled, etc. Shows issue number, title, labels, body preview (500 chars), state reason |
| `pull_request` | 🔀 | PR opened, closed, merged. Shows PR number, title, branch arrows (`feature` → `main`), draft status, commit/file stats, body preview |
| `push` | 📦 | Push with commit list. Shows branch, commit count, per-commit SHA (7 chars), message, author, file change counts (`+3 ~2 -1 files`) |
| `check_suite` / `check_run` | ✅❌⏳ | CI check results. Shows check name, conclusion, branch, details link, commit message |
| `workflow_run` / `workflow_job` | ✅❌⏳🔄 | GitHub Actions status. Shows workflow name, status, branch, run link, commit message, current step |
| `gollum` | 📝 | Wiki page updates. Lists pages created/edited with titles, URLs, and summaries |
| `*` (default) | ℹ️ | Generic fallback message for unhandled events |

### Message Format Features

- **Markdown links** — `[User](https://github.com/User)`, `[#123 Title](url)`
- **Branch backticks** — `` `feature` → `main` ``
- **Labels** — `` `bug` `enhancement` ``
- **Emoji indicators** — 🐛 issues, 🔀 PRs, 📦 pushes, 📝 wiki, ✅❌⏳🔄 CI
- **File counts** — `+3 ~2 -1 files` (added, modified, removed)
- **Body truncation** — First 500 characters of issue/PR body
- **Multi-author attribution** — "by Author" shown when commits have different authors than pusher

### Events Ignored by Design

| Event | Reason |
|-------|--------|
| `fork` | Too spammy |
| `star` / `watch` | Too spammy |
| `status` | Handled via `check_run` / `check_suite` instead |

---

## NotificationQueue

The `NotificationQueue` class (`src/NotificationQueue.php`) handles message delivery:

### Envelope Format

```json
{
  "v": 1,
  "id": "uuid-v4",
  "ts": 1699999999,
  "expires_at": 1700000299,
  "room": "notifications",
  "type": "msg",
  "message": "📦 Alice pushed 3 commits to org/repo `main`...",
  "card": null,
  "extra": {
    "dedup_key": "github:push:org/repo:main",
    "level": "info",
    "event_type": "push",
    "action": null,
    "repo": "org/repo",
    "data": { /* full GitHub payload */ },
    "source": "webhooks/github.php"
  },
  "fallback_webhook_url": "https://...powerautomate.com/..."
}
```

### Delivery Flow

1. **Redis available?** → `LPUSH` to queue list, increment metrics counter
2. **Redis unavailable** → Fall back to direct Power Automate POST
3. **JSON oversized (>256KB)** → Strip raw payload first, then truncate message

### Deduplication Keys

Each event type has a specific dedup key format:

| Event | Dedup Key Format |
|-------|------------------|
| `push` | `github:push:{repo}:{branch}` |
| `issues` | `github:issue:{repo}:{number}` |
| `pull_request` | `github:pr:{repo}:{number}` |
| `check_run` | `github:checkrun:{repo}:{sha7}` |
| `check_suite` | `github:check:{repo}:{sha7}` |
| `workflow_run` | `github:wf:{repo}:{branch}:{name}` |
| `workflow_job` | `github:wfjob:{repo}:{branch}:{jobName}` |
| `gollum` | `github:wiki:{repo}` |
| `status` | `github:status:{repo}:{sha7}` |
| `star` / `watch` / `fork` | `github:{event}:{repo}` |
| `ping` | `github:ping:{repo}` |

### Dispositions

`NotificationQueue::getLastStatus()` returns:

| Value | Meaning |
|-------|---------|
| `queued` | Successfully pushed to Redis |
| `direct_flag_off` | `NOTIF_QUEUE_ENABLED=false`, sent directly |
| `direct_no_redis` | Redis unavailable, sent directly |
| `direct_redis_exception` | Redis error, sent directly |
| `direct_oversize` | Payload too large, truncated then sent directly |
| `failed_no_fallback` | No fallback URL configured |

---

## Logging

Events are logged to `log/Y/m/d/` directories:

```
log/
└── 2026/
    └── 05/
        └── 11/
            ├── 103045_push__alice_org_repo.json
            ├── 103120_issues_opened__bob_detain_repo.json
            └── ...
```

Log verbosity controlled by `LOG_LEVEL` env var:
- `debug` — writes every event with payload size
- `info` — writes only on failures
- `warn` — no logging

---

## GitHub Review CLI

A command-line tool for managing code reviews of GitHub Pull Requests.

### Installation

#### Option 1: PHAR Distribution (Recommended)

Download the pre-built PHAR archive and install it system-wide:

```bash
# Download the latest PHAR from the releases page
# or build it yourself: php bin/build-phar

# Install globally on Linux/macOS
sudo mv github-review.phar /usr/local/bin/github-review
sudo chmod +x /usr/local/bin/github-review

# Verify installation
github-review --version
```

**System Requirements:**
- PHP 7.4 or higher
- PHP extensions: phar, openssl (for signing), zlib (for compression)

#### Option 2: Composer Installation

```bash
# Clone the repository
git clone https://github.com/interserver/webhooks.interserver.net.git
cd webhooks.interserver.net

# Install dependencies
composer install

# Make the CLI executable
chmod +x bin/github-review

# Run directly
bin/github-review --help

# Or run via composer
composer exec github-review
```

#### Option 3: Build PHAR from Source

```bash
# Clone and install dependencies
git clone https://github.com/interserver/webhooks.interserver.net.git
cd webhooks.interserver.net
composer install

# Build the PHAR
php -d phar.readonly=0 bin/build-phar

# The PHAR will be at build/github-review.phar

# Optional: Sign the PHAR
openssl genpkey -algorithm RSA -out private.key
php -d phar.readonly=0 bin/build-phar --sign

# Install the built PHAR
sudo mv build/github-review.phar /usr/local/bin/github-review
sudo chmod +x /usr/local/bin/github-review
```

### Quick Start

```bash
# List queued review jobs
bin/github-review list

# Show queue statistics
bin/github-review metrics

# Submit a PR for review
bin/github-review submit owner/repo --pr 42

# Check review status
bin/github-review status --repo owner/repo

# View help
bin/github-review --help
```

### Commands

#### `list` - List Queue Jobs

List pending code review jobs in the Redis queue.

```bash
bin/github-review list
bin/github-review list --limit 20
bin/github-review list --repo owner/repo
bin/github-review list --event-type pull_request --action opened
bin/github-review list --json
```

Options:
- `-l, --limit N` — Show at most N entries (default: 50)
- `-r, --repo OWNER/REPO` — Filter by repository
- `-e, --event-type TYPE` — Filter by source type (push, pr, check_run, etc.)
- `-a, --action ACTION` — Filter by action (opened, synchronize, closed)
- `--json` — Output as JSON
- `-d, --detailed` — Include id and PR URL in output

#### `metrics` - Queue Statistics

Show queue statistics and processing metrics.

```bash
bin/github-review metrics
bin/github-review metrics --json
```

#### `submit` - Submit PR for Review

Submit a Pull Request for code review analysis.

```bash
bin/github-review submit owner/repo --pr 42
bin/github-review submit owner/repo 42           # Short form
bin/github-review submit owner/repo --all        # All open PRs
bin/github-review submit owner/repo --pr 42 --audit-types security,performance
bin/github-review submit owner/repo --pr 42 --severity error
```

Options:
- `repo` — Repository in owner/repo format (first argument)
- `pr` — PR number (second argument or `--pr` option)
- `-t, --audit-types TYPES` — Comma-separated audit types (security,performance,documentation,logic,style,full)
- `-s, --severity LEVEL` — Minimum severity (error, warning, info)
- `--no-security`, `--no-performance`, `--no-documentation`, `--no-logic`, `--no-style` — Disable specific audits
- `--post-diffs` — Post inline diff of suggested fixes as PR review comments
- `--post-branch` — Create a branch with suggested fixes (mutually exclusive with --post-diffs)
- `--split-changes` — Split changes into multiple PRs by file/audit-type
- `--split-by STRATEGY` — Split strategy: file, audit, severity, size
- `--dry-run` — Preview without submitting
- `-w, --wait` — Wait for completion

#### `status` - Check Review Status

Check the status of a review job.

```bash
bin/github-review status --id abc-123
bin/github-review status --repo owner/repo --pr 42
bin/github-review status --watch
```

Options:
- `-i, --id UUID` — Check specific job by ID
- `-r, --repo OWNER/REPO` — Filter by repository
- `-p, --pr NUMBER` — Filter by PR number
- `--json` — Output as JSON
- `-w, --watch` — Poll for updates continuously

#### `cancel` - Cancel Jobs

Cancel queued or processing review jobs.

```bash
bin/github-review cancel --id abc-123
bin/github-review cancel --all
```

Options:
- `-i, --id UUID` — Cancel specific job by ID
- `--all` — Clear entire queue

#### `config` - Configuration Management

Manage CLI configuration.

```bash
bin/github-review config --list
bin/github-review config --add-repo owner/repo
bin/github-review config --set-token
```

Options:
- `-l, --list` — List current configuration
- `--add-repo OWNER/REPO` — Add repository to watch list
- `--remove-repo OWNER/REPO` — Remove repository
- `--set-token` — Set/update GitHub token
- `--set-checkout-root PATH` — Set checkout directory
- `--reset` — Reset to defaults

### Global Options

- `-h, --help` — Show help message
- `-V, --version` — Show version
- `-v, --verbose` — Increase verbosity (stackable: `-vvv`)
- `-q, --quiet` — Suppress output
- `--json` — Output as JSON (for scripting)
- `--debug` — Show debug information
- `-n, --non-interactive` — Non-interactive mode (disables prompting)

### Configuration

Configuration is loaded from multiple sources in order of priority (highest last):

1. **System config** — `/etc/github-review/config.php`
2. **User config** — `~/.config/github-review/config.php`
3. **Project config** — `./config/review-cli.php`
4. **Environment variables**
5. **CLI arguments**

#### Configuration File Template

Copy `config/review-cli.php.dist` to `config/review-cli.php`:

```php
<?php
return [
    'github' => [
        'token' => 'ghp_xxxx',  // Or use GITHUB_TOKEN env var
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
    'repositories' => [
        'owner/repo1',
        'owner/repo2',
    ],
    'defaults' => [
        'audit_types' => 'full',
        'severity' => 'warning',
        'post_summary' => true,
    ],
];
```

#### Environment Variables

Any config value can be overridden via environment variables:

| Variable | Description |
|----------|-------------|
| `GITHUB_TOKEN` | GitHub personal access token |
| `REDIS_HOST` | Redis server host |
| `REDIS_PORT` | Redis server port |
| `CHECKOUT_ROOT` | Checkout directory for PRs |
| `OPENCODE_ANALYZE_CMD` | OpenCode analyze command template |
| `OPENCODE_IMPROVE_CMD` | OpenCode improve command template |
| `CLI_NON_INTERACTIVE` | Force non-interactive mode |

### Interactive Mode

When running without a TTY (cron jobs, CI/CD pipelines) or with `--non-interactive` flag, the CLI:

- Requires all parameters via flags/arguments
- Fails on first error
- Uses JSON output for scripting
- Disables progress bars

When running interactively, the CLI:

- Shows colored tables
- Enables progress bars
- Offers interactive prompts for missing arguments
- Provides suggestions and defaults

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error |
| 2 | Invalid arguments |
| 3 | Not found |
| 4 | Validation error |
| 5 | Redis error |
| 6 | GitHub API error |
| 7 | Checkout error |
| 8 | Timeout |

---

## GitHubWebhook Class

```php
class GithubWebhook
{
    // Validates and parses the current HTTP request
    public function ProcessRequest(): bool { ... }

    // Validates X-Hub-Signature-256 header against secret
    public function ValidateHubSignature(string $SecretKey): bool { ... }

    // Returns the event type (e.g. 'push', 'issues')
    public function GetEventType(): string { ... }

    // Returns the decoded JSON payload as array
    public function GetPayload(): array { ... }

    // Returns 'org/repo' format repository name
    public function GetFullRepositoryName(): string { ... }
}
```

---

## GithubMessageBuilder Class

```php
class GithubMessageBuilder
{
    public function __construct(string $EventType, array $Payload) { ... }

    // Builds chat message: ['avatar' => ..., 'alias?' => ..., 'text' => ...]
    public function build(): array { ... }

    public function getRepositoryName(): string { ... }
    public function getUser(): string { ... }
}
```

---

## Testing

```bash
# Run all tests
composer test

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/

# Run a specific test
./vendor/bin/phpunit --filter testPushSameAuthorOmitsByLine
```

### Test Fixtures

Each event type has a directory under `tests/events/{event_name}/` with:

- `payload.json` — Raw GitHub webhook payload
- `type.txt` — Event name (e.g. `push`)
- `expected_text.txt` — Expected output text

Available fixtures:
- `commit_comment`, `delete`, `dependabot_alert_created`
- `discussion_comment_created`, `discussion_created`
- `gollum`, `gollum_created`
- `issue_closed`, `issue_comment`, `issue_comment_many_new_lines`, `issues_closed`, `issues_opened`
- `member`, `milestone`, `package`, `ping`, `ping_org`
- `project`, `public`
- `pull_request_auto_merge_enabled`, `pull_request_closed_merged`, `pull_request_dependabot`
- `pull_request_merged`, `pull_request_opened`, `pull_request_review`, `pull_request_review_comment`
- `push`, `push_branch_with_underscores`, `push_created`, `push_created_plus`, `push_multi_author`
- `push_multi_same_author`, `push_no_author`, `push_tag`
- `release`, `release_different_name`, `release_no_name`
- `repository`, `repository_renamed`, `repository_transferred`
- `repository_vulnerability_alert`, `repository_vulnerability_alert_dismiss`, `repository_vulnerability_alert_resolve`
- `workflow_run_completed`

---

## Quality Tools

```bash
# Static analysis
composer analyse

# Code style check
composer cs-check

# Auto-fix code style
composer cs-fix
```

---

## Dependencies

- **PHP** >=7.4
- **predis/predis** ^2.0 — Redis client
- **vlucas/phpdotenv** ^5.5 — Environment variable loading
- **phpunit/phpunit** ^9.0 — Testing (dev)
- **phpstan/phpstan** ^0.12.53 — Static analysis (dev)

---

## Related Documentation

- [GitHub Webhooks Documentation](https://docs.github.com/en/developers/webhooks-and-events/webhooks/webhook-events-and-payloads)
- [RocketChat Webhook Integration](https://docs.rocket.chat/guides/administration/administration/ integrations)
- [Microsoft Teams Incoming Webhooks](https://docs.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook)

## License

[MIT](LICENSE)
