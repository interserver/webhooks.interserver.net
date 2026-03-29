---
name: add-test-fixture
description: Creates a new test event fixture directory under tests/events/{event_name}/ with payload.json, type.txt, and expected_text.txt. Use when user says 'add test for X event', 'create fixture', 'test event payload', or needs to add files to tests/events/. Do NOT use for modifying existing fixtures or writing unit tests that don't involve GitHub webhook payloads.
---
# Add Test Fixture

## Critical

- Every fixture directory requires **three files** — `GithubMessageBuilderTest.php` reads all three; missing any will cause a fatal test error:
  ```
  tests/events/deployment/type.txt
  tests/events/deployment/payload.json
  tests/events/deployment/expected_text.txt
  ```
- `expected_text.txt` contains plain-text chat message output — **generate it by running the test harness**, not by hand.
- The value in `tests/events/deployment/type.txt` must match the `X-GitHub-Event` header value exactly (e.g. `push`, `pull_request`, `ping`) — no trailing newline issues; the test trims it.

## Instructions

1. **Create the fixture directory.**
   Use the GitHub event name as the directory name (e.g. `deployment`, `workflow_dispatch`). For action variants, append `_{action}` (e.g. `pull_request_merged`, `issue_closed`). Verify no existing directory with that name exists under `tests/events/`.

2. **Write the event type file.**
   Content is the bare event type only — no action suffix, no newline required (the test trims it). Matches the value GitHub sends in the `X-GitHub-Event` HTTP header.
   Write `deployment` to `tests/events/deployment/type.txt`.

3. **Write the payload file.**
   Save a real GitHub webhook payload to `tests/events/deployment/payload.json`. Mirror the formatting of existing fixtures — tabs for indentation, `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` style:
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

4. **Generate `expected_text.txt` using the test suite.**
   Run the tests — the new fixture will fail and print the actual output. Use that output as the content of `expected_text.txt`, then re-run to confirm it passes:
   ```bash
   vendor/bin/phpunit tests/GithubMessageBuilderTest.php
   ```

5. **Verify the fixture passes.**
   ```bash
   vendor/bin/phpunit tests/GithubMessageBuilderTest.php
   ```
   All tests must pass.

## Examples

**User says:** "Add a test fixture for the `deployment` event"

**Actions taken:**
1. Create `tests/events/deployment/`
2. Write `tests/events/deployment/type.txt` → `deployment`
3. Write `tests/events/deployment/payload.json` with a real GitHub deployment payload
4. Run `vendor/bin/phpunit tests/GithubMessageBuilderTest.php`, capture actual output, write to `tests/events/deployment/expected_text.txt`
5. Re-run tests to verify they pass

**Result:** `tests/events/deployment/` contains all three files; `vendor/bin/phpunit tests/GithubMessageBuilderTest.php` exits green.

## Common Issues

- **`file_get_contents(...): Failed to open stream`** — one of the three required files is missing. Check `tests/events/deployment/` contains `type.txt`, `payload.json`, `expected_text.txt`.
- **`assertEquals failed`** — `expected_text.txt` content doesn't match actual output. Re-run the test, capture actual output, update the file.
- **New fixture not picked up by test runner** — confirm the directory is directly under `tests/events/` (not nested). The test uses a single-level `DirectoryIterator`.
- **`GetEventType()` assertion fails** — `tests/events/deployment/type.txt` contains extra whitespace or wrong casing. The value must exactly match the GitHub `X-GitHub-Event` header (all lowercase, underscores, e.g. `pull_request` not `Pull-Request`).
