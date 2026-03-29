---
name: chat-message-format
description: Formats dual Rocket Chat + MS Teams notification messages following the project's emoji+markdown style. Builds $Msg with avatar/alias/text keys, [User](url) links, repo links, branch backticks, and action emojis (📦 push, 🐛 issues, 🔀 PR, 📝 wiki, ✅/❌ CI). Calls SendToChat('notifications', $Msg, $useRC, $useTeams). Use when user says 'format message', 'notification style', 'chat output', or is writing a new event handler case. Do NOT use for changing SendToChat() itself or src/GithubWebhook.php.
---
# chat-message-format

## Critical

- Never build `$Msg` before signature validation (`ValidateHubSignature`) and logging have already run — message building always happens inside the `switch ($EventType)` block in `web/github.php`.
- `$Msg['text']` must be a single string. Teams receives only `$Payload['text']`; Rocket Chat receives the full `$Msg` array (`avatar`, `alias`, `text`).
- Do NOT add a new `SendToChat` call outside the event case — every case ends with exactly one `SendToChat('notifications', $Msg, $useRC, $useTeams); break;`.

## Instructions

1. **Declare case variables** at the top of your `case 'event_name':` block. Pull from `$Message` (the decoded payload) and the pre-set globals `$User`, `$RepositoryName`, `$EventType`.
   ```php
   case 'my_event':
       $Action = $Message['action'] ?? '';
       $RepoUrl = 'https://github.com/' . $RepositoryName;
   ```
   Verify `$User` and `$RepositoryName` are already set above the `switch` before proceeding.

2. **Build the markdown text** into `$ChatMsg` using these exact link/format patterns:
   - User link: `[{$User}](https://github.com/{$User})`
   - Repo link: `[{$RepositoryName}]({$RepoUrl})`
   - Branch: `` [`{$Branch}`](https://github.com/{$RepositoryName}/tree/{$Branch}) ``
   - PR branches: `` (`{$HeadBranch}` → `{$BaseBranch}`) ``
   - Commit SHA: `[` + `substr($Commit['id'], 0, 7)` + `]({$Commit['url']})`
   - Issue/PR number+title: `[#{$Number} {$Title}]({$Url})`
   - Bold action: `**{$Action}**`
   - Italic commit message: `_{$CommitMsg}_`

3. **Prefix with the correct action emoji** based on event type:
   ```
   📦  push / package / release
   🐛  issues / issue_comment
   🔀  pull_request / pull_request_review
   📝  gollum (wiki)
   ✅  success / merged / completed
   ❌  failure
   ⏳  in_progress / queued
   🔄  other CI statuses (check_run, workflow_run, workflow_job)
   ℹ️  default / unknown
   ```

4. **Assemble `$Msg`** with all three keys:
   ```php
   $Msg = [];
   $Msg['avatar'] = $Message['sender']['avatar_url'];
   // Only set alias when a commit author is available:
   if (isset($Message['head_commit'])) {
       $Msg['alias'] = $Message['head_commit']['author']['name'];
   }
   $Msg['text'] = $ChatMsg;
   ```
   Verify `$Msg['text']` is non-empty before the `SendToChat` call.

5. **Set flags and call SendToChat**:
   ```php
   $useRC    = true;
   $useTeams = true;
   // Suppress Teams for specific repos when appropriate:
   // if (in_array($RepositoryName, ['org/repo'])) { $useTeams = false; }
   SendToChat('notifications', $Msg, $useRC, $useTeams);
   break;
   ```

## Examples

**User says:** "Add a handler for the `discussion` event that posts who opened/closed a discussion."

**Actions taken:**
```php
case 'discussion':
    $Action      = $Message['action'] ?? '';
    $Discussion  = $Message['discussion'] ?? [];
    $Title       = $Discussion['title'] ?? 'unknown';
    $Url         = $Discussion['html_url'] ?? '';
    $Number      = $Discussion['number'] ?? '';
    $RepoUrl     = 'https://github.com/' . $RepositoryName;
    $Emoji       = $Action === 'closed' ? '✅' : 'ℹ️';
    $ChatMsg     = "{$Emoji} [{$User}](https://github.com/{$User}) **{$Action}** discussion "
                 . "[#{$Number} {$Title}]({$Url}) "
                 . "in [{$RepositoryName}]({$RepoUrl})";
    $Msg = [];
    $Msg['avatar'] = $Message['sender']['avatar_url'];
    $Msg['text']   = $ChatMsg;
    $useRC    = true;
    $useTeams = true;
    SendToChat('notifications', $Msg, $useRC, $useTeams);
    break;
```

**Result:** Both Rocket Chat and Teams receive:
> ℹ️ [alice](https://github.com/alice) **opened** discussion [#7 Design feedback](https://github.com/org/repo/discussions/7) in [org/repo](https://github.com/org/repo)

## Common Issues

- **Teams shows raw array / PHP notice**: You set `$Msg['text']` to an array instead of a string. `$Msg['text']` must be a plain string — `SendToChat` passes `$Payload['text']` directly to Teams.
- **Rocket Chat posts with wrong author name**: `$Msg['alias']` was set to `$User` (the GitHub login) instead of the commit author's display name (`$Message['head_commit']['author']['name']`). Only set `alias` when commit author data is present.
- **Branch still shows `refs/heads/` prefix**: Strip it with `str_replace('refs/heads/', '', $Message['ref'])` before using in the message.
- **`$Message['sender']` undefined notice**: Some bot-triggered events nest the actor under `$Message[$EventType]['app']`. Guard with `$Message[$EventType]['app']['owner']['avatar_url'] ?? $Message['sender']['avatar_url']`.
- **`SendToChat` returns `false`**: The `$Where` key (`'notifications'`) is not present in `$chatChannels['rocketchat']` or `$chatChannels['teams']` in `src/config.php`. Verify the key exists in both arrays.