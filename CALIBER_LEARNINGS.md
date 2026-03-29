# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[gotcha:project]** The existing test files (`tests/EventTest.php`, `tests/IgnoredActionsThrowTest.php`, `tests/UnknownActionTest.php`, `tests/IgnoredEventTest.php`) all reference `IrcConverter` and `DiscordConverter` classes that no longer exist in `src/` — running `vendor/bin/phpunit` produces 61 errors about missing classes. These tests are stale and need to be rewritten against `GithubWebhook` directly.
- **[fix:project]** `vendor/bin/phpunit` fails with "No such file or directory" if `vendor/` is not present — always run `composer install` first. The vendor directory is gitignored and absent after a fresh clone.
- **[gotcha:project]** `composer.json` has no `autoload`, `autoload-dev`, or `scripts` sections — there is no PSR-4 autoloader and no `composer test` shortcut. Classes (`GithubWebhook`, `IgnoredEventException`, etc.) are loaded via manual `require` statements. Tests must bootstrap these manually or via a PHPUnit bootstrap file.
- **[pattern:project]** Real GitHub webhook payloads live in `log/` as JSON files with structure `{"repo": "...", "event": "...", "data": {...payload...}}`. The `data` key holds the raw webhook payload — use these as authoritative source material when creating new fixtures under `tests/events/`.
- **[gotcha:project]** In the `push` event handler, `$Msg['alias']` is set from `$Message['head_commit']['author']['name']` (commit author name), **not** `$Message['pusher']['name']`. These differ when CI or bots push commits authored by a human. Code that compares commit authors against the pusher must use `$Msg['alias']`, not `$Message['pusher']['name']`.
