---
name: add-test-fixture
description: Creates a new test event fixture directory under tests/events/{event_name}/ with payload.json, type.txt, expected.bin, and discord.json. Use when user says 'add test for X event', 'create fixture', 'test event payload', or needs to add files to tests/events/. Do NOT use for modifying existing fixtures or writing unit tests that don't involve GitHub webhook payloads.
---
# Add Test Fixture

## Critical

- Every fixture directory requires **all four files** — `EventTest.php:64` calls `file_get_contents` on all four unconditionally; missing any will cause a fatal test error:
  ```
  tests/events/deployment/type.txt
  tests/events/deployment/payload.json
  tests/events/deployment/expected.bin
  tests/events/deployment/discord.json
  ```
- `expected.bin` contains IRC color codes (non-printable bytes) — **never hand-write it**. Use the generation workflow in Step 4.
- The value in `tests/events/deployment/type.txt` must match the `X-GitHub-Event` header value exactly (e.g. `push`, `pull_request`, `ping`) — no trailing newline issues; `EventTest.php:61` trims it.

## Instructions

1. **Create the fixture directory.**
   ```bash
   mkdir tests/events/deployment
   ```
   Use the GitHub event name as the directory name (e.g. `deployment`, `workflow_dispatch`). For action variants, append `_{action}` (e.g. `pull_request_merged`, `issue_closed`). Verify no existing directory with that name exists under `tests/events/`.

2. **Write the event type file.**
   ```bash
   echo -n 'deployment' > tests/events/deployment/type.txt
   ```
   Content is the bare event type only — no action suffix, no newline required (the test trims it). Matches the value GitHub sends in the `X-GitHub-Event` HTTP header.

3. **Write the payload file.**
   Save a real GitHub webhook payload to `tests/events/deployment/payload.json`. Mirror the formatting of existing fixtures — tabs for indentation, escaped forward-slashes (`\/`), `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` style:
   ```json
   {
   	"action": "created",
   	"sender": {
   		"login": "octocat",
   		"id": 1,
   		"avatar_url": "https:\/\/avatars.githubusercontent.com\/u\/1?v=3"
   	},
   	"repository": {
   		"id": 1296269,
   		"name": "Hello-World",
   		"full_name": "octocat\/Hello-World",
   		"html_url": "https:\/\/github.com\/octocat\/Hello-World",
   		"private": false
   	}
   }
   ```
   Verify `tests/events/deployment/payload.json` is valid JSON:
   ```bash
   php -r "json_decode(file_get_contents('tests/events/deployment/payload.json')); echo json_last_error();"
   ```
   Must print `0`.

4. **Generate the binary and Discord files using the test harness.**
   In `tests/EventTest.php`, uncomment the two generation lines (lines 27 and 38):
   ```php
   file_put_contents( $Path . '/expected.bin', $Message . "\n" );
   // and
   file_put_contents( $Path . '/discord.json', json_encode( $Discord, JSON_PRETTY_PRINT ) . "\n" );
   ```
   Run only the new fixture:
   ```bash
   vendor/bin/phpunit --filter testEvent --group default tests/EventTest.php
   ```
   This writes the real IRC-formatted output (with color codes) to `tests/events/deployment/expected.bin` and the Discord embed to `tests/events/deployment/discord.json`. **Re-comment both lines immediately after**; leaving them uncommented will overwrite all fixtures on every test run.

5. **Verify the fixture passes.**
   ```bash
   vendor/bin/phpunit tests/EventTest.php
   ```
   All tests must pass. If the new fixture is listed as a failure, inspect the generated `tests/events/deployment/expected.bin` content and compare against `web/github.php`'s handler for that event type.

## Examples

**User says:** "Add a test fixture for the `deployment` event"

**Actions taken:**
1. Create `tests/events/deployment/`
2. Write `tests/events/deployment/type.txt` → `deployment`
3. Write `tests/events/deployment/payload.json` with a real GitHub deployment payload
4. Uncomment lines 27 & 38 in `tests/EventTest.php`, run `vendor/bin/phpunit tests/EventTest.php`, re-comment
5. Verify `tests/events/deployment/expected.bin` and `tests/events/deployment/discord.json` were written and tests pass

**Result:** `tests/events/deployment/` contains all four files; `vendor/bin/phpunit tests/EventTest.php` exits green.

## Common Issues

- **`file_get_contents(...): Failed to open stream`** — one of the four required files is missing. Check `tests/events/deployment/` contains `tests/events/deployment/type.txt`, `tests/events/deployment/payload.json`, `tests/events/deployment/expected.bin`, `tests/events/deployment/discord.json`.
- **`assertEquals failed: expected '' got '[10GitHub-WebHook]...'`** — `tests/events/deployment/expected.bin` is empty or was hand-written without IRC codes. Re-run Step 4 generation.
- **`json_decode` returns null for `discord.json`** — `tests/events/deployment/discord.json` was hand-written with invalid JSON. Re-run Step 4 or validate:
  ```bash
  php -r "var_dump(json_decode(file_get_contents('tests/events/deployment/discord.json')));"  
  ```
  Must not print `NULL`.
- **New fixture not picked up by test runner** — confirm the directory is directly under `tests/events/` (not nested). `EventTest.php:51` uses a single-level `DirectoryIterator`.
- **`GetEventType()` assertion fails** — `tests/events/deployment/type.txt` contains extra whitespace or wrong casing. The value must exactly match the GitHub `X-GitHub-Event` header (all lowercase, underscores, e.g. `pull_request` not `Pull-Request`).
