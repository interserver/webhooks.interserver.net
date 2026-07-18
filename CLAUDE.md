# GitHub Webhooks RocketChat/Teams Announcer

PHP webhook receiver (`web/github.php`) that validates GitHub event signatures, builds chat-formatted notifications, and enqueues them to Redis (with direct Power Automate POST fallback) for RocketChat + Microsoft Teams delivery.

## Commands

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run
vendor/bin/php-cs-fixer fix
php scripts/github-code-review.php
```

## Architecture

- **Entry**: `web/github.php` (active) · `web/github-old.php` (legacy, RC-only)
- **Core**: `src/GithubWebhook.php` · `src/GithubMessageBuilder.php` · `src/NotificationQueue.php` · `src/CodeReviewQueue.php` · `src/IgnoredEventException.php` · `src/NotImplementedException.php`
- **Config**: `.env` (copied from `.env.dist`) — defines `GITHUB_WEBHOOKS_SECRET`, `NOTIF_QUEUE_ENABLED`, `NOTIF_QUEUE_KEY_PREFIX`, `REDIS_HOST`, `REDIS_PORT`, `LOG_LEVEL`, `RATE_LIMIT_WINDOW`, plus code-review queue keys. RocketChat / Teams webhook URLs live in `src/config.php` (gitignored) under `$chatChannels['rocketchat']` / `$chatChannels['teams']`
- **Logs**: `log/Y/m/d/` — JSON files named `His_eventtype_action_user_repo.json` (verbosity controlled by `LOG_LEVEL`)
- **Code review**: `src/CodeReviewQueue.php` enqueues push events for async review by `scripts/github-code-review.php`; `scripts/github-code-review-list.php` lists queued review jobs
- **Field analysis**: `scripts/analyze_fields.php`, `scripts/filter_webhook.php`, `scripts/filter_webhook_batch.php` produce reports under `field_analysis_full/` (per-group JSON, `frequency_matrix.json`, `FIELD_CATEGORIZATION.md`)
- **Tests**: `tests/` via `phpunit.xml` · fixtures in `tests/events/{event_name}/`
- **Quality**: `phpstan.neon` · `phpstan-bootstrap.php` · `.php-cs-fixer.dist.php` (PSR2 + PHP74Migration)
- **CI**: `.github/` — GitHub Actions workflows (`.github/workflows/ci.yml`)

## GitHub Review CLI

The `github-review` CLI tool manages code reviews of GitHub Pull Requests.

### CLI Commands

```bash
# Build PHAR distribution
php -d phar.readonly=0 bin/build-phar

# Run CLI directly
bin/github-review --version
bin/github-review list --help
bin/github-review metrics
bin/github-review submit --help

# Run via PHAR
php -d phar.readonly=0 build/github-review.phar --version
```

### CLI Source Files

- **Entry point**: `bin/github-review`
- **Application**: `src/Cli/Application.php`
- **Commands**: `src/Cli/Command/`
  - `AbstractCommand.php` — Base command
  - `ListCommand.php` — List queued review jobs
  - `MetricsCommand.php` — Show queue statistics
  - `SubmitCommand.php` — Submit PR for review
  - `StatusCommand.php` — Check review status
  - `CancelCommand.php` — Cancel queued jobs
  - `ConfigCommand.php` — Manage configuration
  - `ActivityCommand.php` — GitHub activity view
- **Services**: `src/Cli/Service/`
  - `CodeAnalyzer.php` — OpenCode integration
  - `CheckoutManager.php` — Git checkout management
  - `DiffGenerator.php` — Diff generation
  - `ReviewPoster.php` — GitHub API posting
  - `GithubActivityService.php` — GitHub activity fetching
- **Config**: `src/Cli/Config/ConfigLoader.php`
- **Exceptions**: `src/Cli/Exception/` — `ReviewCliException.php`, `ExceptionCodes.php`
- **Renderers**: `src/Cli/Renderer/`
  - `JsonRenderer.php` — JSON output
  - `TableRenderer.php` — Table output
- **Interactor**: `src/Cli/Interactor/` — `InteractiveInteractor.php`, `ActivityInteractor.php`, `InteractiveTui.php`, `TuiPane.php`, `TuiState.php`

### CLI Tests

```bash
vendor/bin/phpstan analyse src/Cli/ --level=8
vendor/bin/phpunit tests/phpunit/unit/Cli/ --testdox
```

## Event Handling Pattern

All event handling lives in `web/github.php`:

```php
// 1. Validate signature — always first
if (!$Hook->ValidateHubSignature(GITHUB_WEBHOOKS_SECRET)) {
    throw new Exception('Secret validation failed.');
}
// 2. Pick room from $RepositoryName ('int-dev-announce' for sugarcraft/* and a
//    short detain/* allowlist; otherwise 'notifications')
// 3. Build the chat-formatted message
$Builder = new GithubMessageBuilder($EventType, $Message);
$Msg     = $Builder->build();
// 4. Enqueue to Redis; falls back to direct Power Automate POST when disabled
//    or unavailable
$queue = new NotificationQueue();
$queue->enqueueMessage($room, $Msg['text'], $dedupKey, $EventType, $action, $RepositoryName, $Message, $fallbackWebhookUrl);
// 5. For push events, also enqueue to CodeReviewQueue for async review
// 6. Log disposition via NotificationQueue::getLastStatus()
```

## NotificationQueue

`src/NotificationQueue.php` enqueues a JSON envelope to Redis via `LPUSH` when `NOTIF_QUEUE_ENABLED=true` (default); otherwise it POSTs directly to the Power Automate `fallback_webhook_url`.

- **Envelope keys**: `v`, `id` (uuid), `ts`, `expires_at`, `room`, `type`, `message`, `card`, `extra.dedup_key`, `extra.event_type`, `extra.action`, `extra.repo`, `extra.data`, `extra.source`, `fallback_webhook_url`
- **Dedup keys** (per event): `github:push:{repo}:{branch}` · `github:issue:{repo}:{number}` · `github:pr:{repo}:{number}` · `github:checkrun:{repo}:{sha7}` · `github:check:{repo}:{sha7}` · `github:wf:{repo}:{branch}:{name}` · `github:wfjob:{repo}:{branch}:{jobName}` · `github:wiki:{repo}` · `github:status:{repo}:{sha7}` · `github:{event}:{repo}` for `star`/`watch`/`fork`/`ping`
- **Oversize handling**: when the JSON exceeds 256KB, `extra.data` is stripped first, then the message text is truncated
- **`getLastStatus()`** returns one of: `queued`, `direct_flag_off`, `direct_no_redis`, `direct_redis_exception`, `direct_oversize`, `failed_no_fallback`

## Exception Handling

| Exception | HTTP Code | Meaning |
|---|---|---|
| `IgnoredEventException` | 200 | `fork`, `watch`, `status` — ignored by design |
| `NotImplementedException` | 501 | unhandled event type |
| `Exception` | 500 (default) | signature fail, empty payload, etc. |

## Supported vs README

> **Note**: README lists `check_run`, `check_suite`, `workflow_run`, `workflow_job` as unsupported — they ARE handled in `web/github.php`. The README event lists are stale.

## Test Fixtures

Each event type has a directory under `tests/events/{event_name}/` containing:
- `payload.json` — raw GitHub webhook payload
- `type.txt` — event name (e.g. `tests/events/commit_comment/type.txt`)
- `expected_text.txt` — expected chat message text output from `GithubMessageBuilder`

## Code Style

- `declare(strict_types=1)` at top of every PHP file
- PascalCase for variables from `$Hook` (e.g. `$RepositoryName`, `$EventType`, `$Message`)
- `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` on all `json_encode` calls in `web/github.php`
- PHP CS Fixer enforces `@PSR2` + `@PHP74Migration`; run before committing

## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.

## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.