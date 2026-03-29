---
name: add-event-handler
description: Adds a new GitHub event type case to the switch block in `web/github.php`. Handles extracting fields from `$Message`, building a `$ChatMsg` string with markdown links, setting `$Msg['text']`, and calling `SendToChat('notifications', $Msg, $useRC, $useTeams)`. Use when user says 'add support for X event', 'handle X webhook', 'new event type', or adds a case to `web/github.php`. Do NOT use for modifying existing event handlers.
---
# add-event-handler

## Critical

- **Never** remove the `break` at the end of a case — fall-through will corrupt routing.
- `$Msg['text']` MUST be set before calling `SendToChat`. Teams POSTs only `$Payload['text']`; missing it sends `null`.
- Add new cases **before** `default:` — the default catch-all must remain last.
- `$useRC` and `$useTeams` are already `true` at switch entry. Only override them if the event or repo should skip a target (see `issues` case for the per-repo pattern).
- Do NOT re-declare `$RepositoryName`, `$EventType`, `$Message`, `$User`, or `$Msg` — they are set before the switch.

## Instructions

1. **Look up the GitHub payload schema** for the target event at `https://docs.github.com/en/webhooks/webhook-events-and-payloads#<event_name>`. Note which top-level keys carry the entity (e.g. `pull_request`, `issue`, `release`) and which carry `action`, `html_url`, `title`/`name`.
   Verify: you know the exact key names before writing any `$Message['...']` access.

2. **Add the case block** inside the `switch ($EventType)` block in `web/github.php`, immediately before `default:` (line ~265):

   ```php
   case 'your_event':
       $Entity  = $Message['your_event'];          // top-level entity key
       $Action  = $Message['action'] ?? '';
       $Url     = $Entity['html_url'] ?? '';
       $Title   = $Entity['title']   ?? $Entity['name'] ?? '';

       $ChatMsg = "🔔 [{$User}](https://github.com/{$User}) **{$Action}** "
                . "[{$Title}]({$Url}) "
                . "in [{$RepositoryName}](https://github.com/{$RepositoryName}).";

       // Optional: append body preview (max 500 chars)
       if (!empty($Entity['body'])) {
           $ChatMsg .= "\n\n> " . substr($Entity['body'], 0, 500)
                    . (strlen($Entity['body']) > 500 ? "…" : "");
       }

       $Msg['text'] = $ChatMsg;
       SendToChat('notifications', $Msg, $useRC, $useTeams);
       break;
   ```

   This step uses `$User`, `$RepositoryName`, `$Msg` set before the switch.

3. **Choose an emoji** that fits the event. Reference from existing cases:
   - Push / release: `📦`  
   - Issue / bug: `🐛`  
   - Pull request: `🔀`  
   - Wiki (gollum): `📝`  
   - CI check pass/fail: `✅` / `❌` / `⏳`  
   - Generic/info: `ℹ️`

4. **Suppress noisy CI-style events** by commenting out `SendToChat` and leaving `$Msg['text']` set (matches `check_suite`/`workflow_run` pattern):
   ```php
   $Msg['text'] = $ChatMsg;
   //SendToChat('notifications', $Msg, $useRC, $useTeams);
   break;
   ```

5. **Suppress for specific repos** using the `issues` case pattern:
   ```php
   case 'your_event':
       if (in_array($RepositoryName, ['owner/repo1', 'owner/repo2'])) {
           $useTeams = false;
           break;
       }
       // ... rest of handler
   ```

6. **Add a test fixture** under `tests/events/<event_name>/`:
   - `payload.json` — raw GitHub webhook payload (copy from GitHub delivery logs or docs)
   - `type.txt` — event name only, e.g. `your_event` (no trailing newline needed; `trim()` is applied)
   - Note: `expected.bin` / `discord.json` are consumed by `IrcConverter`/`DiscordConverter` tests, not by `web/github.php` — add them only if those converters also need updating.

7. **Run quality checks** to verify no syntax errors or style violations:
   ```bash
   vendor/bin/phpstan analyse
   vendor/bin/php-cs-fixer fix --dry-run
   ```
   Fix any reported issues before committing.

## Examples

**User says:** "Add support for the `milestone` webhook event"

**Actions taken:**
1. GitHub docs show payload has `$Message['milestone']` with keys `html_url`, `title`, `description`, and `$Message['action']`.
2. Insert before `default:`:
   ```php
   case 'milestone':
       $Milestone = $Message['milestone'];
       $Action    = $Message['action'] ?? '';
       $Url       = $Milestone['html_url'] ?? '';
       $Title     = $Milestone['title'] ?? '';

       $ChatMsg = "🏁 [{$User}](https://github.com/{$User}) **{$Action}** "
                . "milestone [{$Title}]({$Url}) "
                . "in [{$RepositoryName}](https://github.com/{$RepositoryName}).";

       if (!empty($Milestone['description'])) {
           $ChatMsg .= "\n\n> " . substr($Milestone['description'], 0, 500)
                    . (strlen($Milestone['description']) > 500 ? "…" : "");
       }

       $Msg['text'] = $ChatMsg;
       SendToChat('notifications', $Msg, $useRC, $useTeams);
       break;
   ```
3. Created `tests/events/milestone/payload.json` and `tests/events/milestone/type.txt` containing `milestone`.
4. Ran `vendor/bin/phpstan analyse` — no errors.

**Result:** `milestone` events POST to Rocket Chat and Teams with action, linked title, and optional description preview.

## Common Issues

- **`Undefined array key 'your_event'`** at `$Entity = $Message['your_event']`: The payload root key name differs from the event name (e.g. `deployment_status` event uses `$Message['deployment_status']` but `check_run` also appears nested). Dump `$Message` via the log file in `log/` and check the actual keys.

- **Teams receives `null` body**: `$Msg['text']` was not set before `SendToChat`. `SendToChat` sends `$Payload['text']` to Teams — ensure `$Msg['text'] = $ChatMsg` is present.

- **`php-cs-fixer` reports indentation errors**: The switch body uses a **tab** for the `case` line and **8 spaces** for the body (mixed in the file). Match the surrounding case's indentation exactly — do not auto-convert.

- **Event still hits `default:`**: The `$EventType` string comes from the `X-GitHub-Event` header. Verify your `case` string matches exactly (lowercase, underscores) — e.g. `'pull_request'` not `'pullRequest'`.

- **`SendToChat` returns `false` silently**: The channel key `'notifications'` must exist in both `$chatChannels['rocketchat']` and `$chatChannels['teams']` in `src/config.php`. If the URL is missing, curl will fail with HTTP 0.