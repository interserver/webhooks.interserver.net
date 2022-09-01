# Github Webhooks RocketChat Announcer

Takes [GitHub](https://github.com/) events and announces the events based on your configuration options over Rocket Chat.

Here is the [Github Events](https://docs.github.com/en/developers/webhooks-and-events/webhooks/webhook-events-and-payloads) documentation.


## Github Action / Workflows Setup stuff to check out

* https://github.com/marketplace/actions/php-insights
* https://github.com/marketplace/actions/php_codesniffer
* https://github.com/marketplace/actions/php-lint
* https://github.com/marketplace/actions/composer-php
* https://github.com/marketplace/actions/php-runner
* https://github.com/marketplace/actions/php-codesniffer
* https://github.com/marketplace/actions/phpstan-php-actions
* https://github.com/daniL16/action-notify-rocketChat
* https://github.com/apampurin/action-notify-rocketChat
* https://github.com/jadolg/rocketchat-notification-action
* https://github.com/RocketChat/Rocket.Chat.GitHub.Action.Notification


## GitHubWebHook
`GitHubWebHook.php` accepts, processes and validates an event,
it also can make sure that the event came from a GitHub server.

Functions in this class are:

#### ProcessRequest()
Accepts an event, throws `Exception` on error.

#### GetEventType()
Returns event type.
See https://developer.github.com/webhooks/#events for a list of events.

#### GetPayload()
Returns decoded JSON payload as an object.

#### GetFullRepositoryName()
Returns full name of the repository for which an event was sent for.

#### ValidateHubSignature( $SecretKey )
Retuns true if HMAC hex digest of the payload matches GitHub's, false otherwise.

#### ~~ValidateIPAddress()~~
Returns true if a request came from GitHub's IP range, false otherwise.
⚠ Use `ValidateHubSignature` instead.

### Errors

hrows `NotImplementedException` when you pass an event that
is not parsed anyhow, and throws `IgnoredEventException` for
`fork`, `watch` and `status` events which are ignored by design.

## Events [\[ref\]](https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads)

Track changes to GitHub webhook payloads documentation here: https://github.com/github/docs/commits/main/data/reusables/webhooks

### Supported events

- commit_comment
- delete
- discussion
- discussion_comment
- gollum
- issue_comment
- issues
- member
- milestone
- package
- ping
- project
- public
- pull_request
- pull_request_review
- pull_request_review_comment
- push
- release
- repository
- repository_vulnerability_alert

### Not yet supported events

- check_run
- check_suite
- code_scanning_alert
- deploy_key
- deployment
- deployment_status
- label
- membership
- meta
- org_block
- organization
- page_build
- project_card
- project_column
- repository_import
- sponsorship
- team
- team_add

### Events ignored by design

- create - Formatted from push event instead
- fork
- star
- status
- watch

Additionally, events like labelling or assigning an issue are also ignored.
Push event ignores branch deletions (use delete event instead).

### Events that can not be supported

- content_reference
- github_app_authorization
- installation
- installation_repositories
- marketplace_purchase
- repository_dispatch
- security_advisory
- workflow_dispatch
- workflow_run

## License
[MIT](LICENSE)
