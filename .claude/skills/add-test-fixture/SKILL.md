---
name: add-test-fixture
description: Creates a new test event fixture directory under tests/events/{event_name}/ with payload.json, type.txt, and expected_text.txt. Use when user says 'add test for X event', 'create fixture', 'test event payload', or needs to add files to tests/events/. Do NOT use for modifying existing fixtures or writing unit tests that don't involve GitHub webhook payloads.
---
# Add Test Fixture

## Critical

- Every fixture directory requires **exactly three files** — `GithubMessageBuilderTest.php` skips any directory missing `expected_text.txt`. The required files are:
  ```
  tests/events/deployment/type.txt
  tests/events/deployment/payload.json
  tests/events/deployment/expected_text.txt
  ```
- `expected_text.txt` contains **plain text** (no IRC color codes). Write the expected `$Msg['text']` output from `GithubMessageBuilder::build()` directly.
- Do NOT create `expected.bin` or `discord.json` — those are for the removed `IrcConverter`/`DiscordConverter` and will be silently ignored.
- The value in `type.txt` must match the `X-GitHub-Event` header value exactly (e.g. `push`, `pull_request`) — `GithubMessageBuilderTest.php` trims it.

## Instructions

1. **Create the fixture directory.**
   ```bash
   mkdir tests/events/deployment
   ```
   Use the GitHub event name as the directory name. For action variants, append `_{action}` (e.g. `pull_request_merged`, `issues_closed`).

2. **Write the event type file.**
   ```bash
   echo -n 'deployment' > tests/events/deployment/type.txt
   ```
   Bare event type only — no action suffix, no trailing newline required.

3. **Write the payload file.**
   Copy a real payload from `log/` (structure is `{"repo":"...","event":"...","data":{...}}`; use the `data` value) or from GitHub docs. Save as `tests/events/deployment/payload.json`. Validate:
   ```bash
   php -r "json_decode(file_get_contents('tests/events/deployment/payload.json')); echo json_last_error();"
   ```
   Must print `0`.

4. **Determine the expected text.**
   Instantiate `GithubMessageBuilder` with the event type and payload, call `build()['text']`, and save the result:
   ```php
   $builder = new GithubMessageBuilder('deployment', json_decode(file_get_contents('tests/events/deployment/payload.json'), true));
   echo $builder->build()['text'];
   ```
   Or generate it via a quick CLI script and write to `tests/events/deployment/expected_text.txt`.

5. **Verify the fixture passes.**
   ```bash
   vendor/bin/phpunit
   ```
   All tests must pass. The fixture is picked up automatically via `DirectoryIterator` on `tests/events/`.

## Common Issues

- **Fixture not tested** — `expected_text.txt` is missing. The test provider skips directories without this file.
- **`assertSame` fails with whitespace difference** — `expected_text.txt` has a trailing newline. The test `trim()`s the file content, so trailing newlines are fine, but mid-string differences (e.g. extra space) will fail.
- **`json_decode` returns null** — `payload.json` has invalid JSON. Validate with `php -r "var_dump(json_decode(file_get_contents('...')));"` — must not print `NULL`.
- **New fixture not picked up** — confirm the directory is directly under `tests/events/` (not nested two levels deep).
