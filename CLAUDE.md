# GitHub Webhooks RocketChat/Teams Announcer

PHP webhook receiver (`web/github.php`) that validates GitHub event signatures, routes by event type, logs to `log/`, and POSTs to Rocket Chat + Microsoft Teams.

## Commands

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run
vendor/bin/php-cs-fixer fix
```

## Architecture

- **Entry**: `web/github.php` (active) · `web/github-old.php` (legacy, RC-only)
- **Core**: `src/GithubWebhook.php` · `src/IgnoredEventException.php` · `src/NotImplementedException.php`
- **Config**: `src/config.php` (gitignored) — defines `GITHUB_WEBHOOKS_SECRET` and `$chatChannels['rocketchat']` / `$chatChannels['teams']`
- **Logs**: `log/` — JSON files named `Ymd_His_eventtype_action_user_repo.json`
- **Tests**: `tests/` via `phpunit.xml` · fixtures in `tests/events/{event_name}/`
- **Quality**: `phpstan.neon` · `.php-cs-fixer.dist.php` (PSR2 + PHP74Migration)
- **CI**: `.github/` — GitHub Actions workflows (`.github/workflows/ci.yml`)

## Event Handling Pattern

All event handling lives in `web/github.php` inside a `switch ($EventType)` block:

```php
// 1. Validate signature — always first
if (!$Hook->ValidateHubSignature(GITHUB_WEBHOOKS_SECRET)) {
    throw new Exception('Secret validation failed.');
}
// 2. Log every event to log/ before processing
file_put_contents(__DIR__.'/../log/'.date('Ymd_His').'_'.$EventType
    .(isset($Message['action']) ? '_'.$Message['action'] : '')
    .'_'.$User.'_'.str_replace(['/', '-', ' '], '_', $RepositoryName).'.json',
    json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// 3. Route by event type
switch ($EventType) {
    case 'push': /* ... */ SendToChat('notifications', $Msg, $useRC, $useTeams); break;
    default: SendToChat('notifications', $Msg, $useRC, $useTeams); break;
}
```

## SendToChat Signature

```php
SendToChat(string $Where, array $Payload, bool $useRC = true, bool $useTeams = true): bool
```
- `$Where` maps to `$chatChannels['rocketchat'][$Where]` and `$chatChannels['teams'][$Where]`
- Teams payload wraps `$Payload['text']` in `['type' => 'message', 'message' => ...]`
- Always set `$useRC`/`$useTeams` flags per-case; some repos skip Teams (see `issues` case)

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
- A raw GitHub webhook payload JSON file
- `type.txt` with the event name (see `tests/events/commit_comment/type.txt`)

## Code Style

- `declare(strict_types=1)` at top of every PHP file
- PascalCase for variables from `$Hook` (e.g. `$RepositoryName`, `$EventType`, `$Message`)
- `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` on all `json_encode` calls in `web/github.php`
- PHP CS Fixer enforces `@PSR2` + `@PHP74Migration`; run before committing

<!-- caliber:managed:pre-commit -->
## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
