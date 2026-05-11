# GitHub Webhook Field Categorization

This document categorizes fields from GitHub webhook events into **USEFUL** (for team chat notifications) and **SKIP** (noise/boilerplate).

Generated from analysis of 246,924 webhook events across 29 event types and 56 event/action groups.

---

## IMPORTANT NOTES

### Correlation IDs (MUST NOT BE EXCLUDED!)
These fields link events that stem from a single cause. Essential for grouping:
- `data.workflow_run.id` + `data.workflow_job.run_id` → links jobs to parent run
- `data.check_run.check_suite_id` → links check_runs together
- `data.*.head_sha` / `data.sha` → links push to CI runs

### Counts (Conditional)
Repository counts are **only useful for their specific events**:
- `stargazers_count` → star events only
- `watchers_count` → watch events only
- `forks_count` → fork events only
- `open_issues_count` → issues events only

### Avatar URLs (Required for most events)
Always keep `data.sender.avatar_url` - needed to show who triggered the event.

**Exception**: Workflow events (workflow_run, workflow_job) do NOT need sender info per user request.

### Timestamps
Keep at least one timestamp per notification for ordering.

### Repository Info
Most notifications only need `data.repository.full_name`. HTML URL and other repo metadata are not needed.

**Exception**: Counts (stargazers_count, watchers_count, forks_count) are only useful for their specific events (star, watch, fork).

### Workflow Events
For workflow_run and workflow_job, we have the IDs (run_id, workflow_job.id) so html_url and run_url are not needed.

### Head Commit
`data.head_commit` and all its subfields are NOT needed - we use the commits[] array instead.

### Organization
`data.organization` and all subfields are NOT needed per user request.

---

## GLOBAL UNIVERSAL FIELDS (present in ALL event types)

These 124 fields appear in every single event type. They're mostly boilerplate.

### USEFUL (from universal)
```php
$global_useful = [
    // Repository identity
    'repo',                          // "owner/repo" short form
    'data.repository.full_name',     // "owner/repo"
    'data.repository.name',          // repo name only
    'data.repository.html_url',      // link to repo
    'data.repository.description',   // repo description
    'data.repository.language',      // primary language
    'data.repository.default_branch',// main branch name

    // Sender (who triggered) - KEEP AVATAR URL!
    'data.sender.login',             // username
    'data.sender.html_url',          // link to profile
    'data.sender.avatar_url',        // AVATAR - needed for display
    'data.sender.id',                // user ID (correlation)

    // Event metadata
    'event',                         // event type name
    'data.action',                   // action (opened, closed, etc)
];
```

### SKIP (from universal - URLs, IDs, counts)
```php
$global_skip = [
    // All the URL fields - we already have html_url
    'data.repository.url',
    'data.repository.forks_url',
    'data.repository.keys_url',
    'data.repository.collaborators_url',
    'data.repository.teams_url',
    'data.repository.hooks_url',
    'data.repository.issue_events_url',
    'data.repository.events_url',
    'data.repository.assignees_url',
    'data.repository.branches_url',
    'data.repository.tags_url',
    'data.repository.blobs_url',
    'data.repository.git_tags_url',
    'data.repository.git_refs_url',
    'data.repository.trees_url',
    'data.repository.statuses_url',
    'data.repository.languages_url',
    'data.repository.stargazers_url',
    'data.repository.contributors_url',
    'data.repository.subscribers_url',
    'data.repository.subscription_url',
    'data.repository.commits_url',
    'data.repository.git_commits_url',
    'data.repository.comments_url',
    'data.repository.issue_comment_url',
    'data.repository.contents_url',
    'data.repository.compare_url',
    'data.repository.merges_url',
    'data.repository.archive_url',
    'data.repository.downloads_url',
    'data.repository.issues_url',
    'data.repository.pulls_url',
    'data.repository.milestones_url',
    'data.repository.notifications_url',
    'data.repository.labels_url',
    'data.repository.releases_url',
    'data.repository.deployments_url',

    // Git URLs
    'data.repository.git_url',
    'data.repository.ssh_url',
    'data.repository.clone_url',
    'data.repository.svn_url',

    // IDs and node_ids (internal use only)
    'data.repository.id',
    'data.repository.node_id',
    'data.repository.owner.id',
    'data.repository.owner.node_id',
    'data.repository.owner.gravatar_id',
    'data.sender.node_id',
    'data.sender.gravatar_id',

    // Counts - CONDITIONAL: only useful for specific events
    // DO NOT include in general notifications
    'data.repository.stargazers_count',  // ONLY for star events
    'data.repository.watchers_count',    // ONLY for watch events
    'data.repository.forks_count',       // ONLY for fork events
    'data.repository.open_issues_count', // ONLY for issues events
    'data.repository.size',
    'data.repository.forks',
    'data.repository.open_issues',
    'data.repository.watchers',
    'data.repository.stargazers',

    // Boolean flags (usually not interesting)
    'data.repository.private',
    'data.repository.fork',
    'data.repository.has_issues',
    'data.repository.has_projects',
    'data.repository.has_downloads',
    'data.repository.has_wiki',
    'data.repository.has_pages',
    'data.repository.has_discussions',
    'data.repository.archived',
    'data.repository.disabled',
    'data.repository.allow_forking',
    'data.repository.is_template',
    'data.repository.web_commit_signoff_required',
    'data.repository.has_pull_requests',

    // Owner sub-fields (we just need login/html_url typically)
    'data.repository.owner.login',
    'data.repository.owner.url',
    'data.repository.owner.followers_url',
    'data.repository.owner.following_url',
    'data.repository.owner.gists_url',
    'data.repository.owner.starred_url',
    'data.repository.owner.subscriptions_url',
    'data.repository.owner.organizations_url',
    'data.repository.owner.repos_url',
    'data.repository.owner.events_url',
    'data.repository.owner.received_events_url',
    'data.repository.owner.type',
    'data.repository.owner.user_view_type',
    'data.repository.owner.site_admin',
    'data.repository.owner.name',
    'data.repository.owner.email',
    'data.repository.owner.avatar_url',

    // Sender sub-fields (we just need login/html_url/avatar_url)
    'data.sender.url',
    'data.sender.followers_url',
    'data.sender.following_url',
    'data.sender.gists_url',
    'data.sender.starred_url',
    'data.sender.subscriptions_url',
    'data.sender.organizations_url',
    'data.sender.repos_url',
    'data.sender.events_url',
    'data.sender.received_events_url',
    'data.sender.type',
    'data.sender.user_view_type',
    'data.sender.site_admin',

    // Timestamps (we usually don't display these directly)
    'data.repository.created_at',
    'data.repository.updated_at',

    // License and topics (array - not core to notifications)
    'data.repository.license',
    'data.repository.topics',
    'data.repository.visibility',
    'data.repository.pull_request_creation_policy',

    // Mirror
    'data.repository.mirror_url',

    // Other metadata
    'data.repository.master_branch',
    'data.repository.custom_properties',

    // The raw wrapper
    'data',
];
```

### SKIP (from universal - URLs, IDs, counts)
```php
$global_skip = [
    // All the URL fields - we already have html_url
    'data.repository.url',
    'data.repository.forks_url',
    'data.repository.keys_url',
    'data.repository.collaborators_url',
    'data.repository.teams_url',
    'data.repository.hooks_url',
    'data.repository.issue_events_url',
    'data.repository.events_url',
    'data.repository.assignees_url',
    'data.repository.branches_url',
    'data.repository.tags_url',
    'data.repository.blobs_url',
    'data.repository.git_tags_url',
    'data.repository.git_refs_url',
    'data.repository.trees_url',
    'data.repository.statuses_url',
    'data.repository.languages_url',
    'data.repository.stargazers_url',
    'data.repository.contributors_url',
    'data.repository.subscribers_url',
    'data.repository.subscription_url',
    'data.repository.commits_url',
    'data.repository.git_commits_url',
    'data.repository.comments_url',
    'data.repository.issue_comment_url',
    'data.repository.contents_url',
    'data.repository.compare_url',
    'data.repository.merges_url',
    'data.repository.archive_url',
    'data.repository.downloads_url',
    'data.repository.issues_url',
    'data.repository.pulls_url',
    'data.repository.milestones_url',
    'data.repository.notifications_url',
    'data.repository.labels_url',
    'data.repository.releases_url',
    'data.repository.deployments_url',

    // Git URLs
    'data.repository.git_url',
    'data.repository.ssh_url',
    'data.repository.clone_url',
    'data.repository.svn_url',

    // IDs and node_ids (internal use only)
    'data.repository.id',
    'data.repository.node_id',
    'data.repository.owner.id',
    'data.repository.owner.node_id',
    'data.repository.owner.gravatar_id',
    'data.sender.id',
    'data.sender.node_id',
    'data.sender.gravatar_id',

    // Counts (not actionable for notifications)
    'data.repository.stargazers_count',
    'data.repository.watchers_count',
    'data.repository.forks_count',
    'data.repository.size',
    'data.repository.open_issues_count',

    // Boolean flags (usually not interesting)
    'data.repository.private',
    'data.repository.fork',
    'data.repository.has_issues',
    'data.repository.has_projects',
    'data.repository.has_downloads',
    'data.repository.has_wiki',
    'data.repository.has_pages',
    'data.repository.has_discussions',
    'data.repository.archived',
    'data.repository.disabled',
    'data.repository.allow_forking',
    'data.repository.is_template',
    'data.repository.web_commit_signoff_required',
    'data.repository.has_pull_requests',

    // Owner sub-fields (we just need login/html_url typically)
    'data.repository.owner.url',
    'data.repository.owner.followers_url',
    'data.repository.owner.following_url',
    'data.repository.owner.gists_url',
    'data.repository.owner.starred_url',
    'data.repository.owner.subscriptions_url',
    'data.repository.owner.organizations_url',
    'data.repository.owner.repos_url',
    'data.repository.owner.events_url',
    'data.repository.owner.received_events_url',
    'data.repository.owner.type',
    'data.repository.owner.user_view_type',
    'data.repository.owner.site_admin',

    // Sender sub-fields (we just need login/html_url)
    'data.sender.url',
    'data.sender.followers_url',
    'data.sender.following_url',
    'data.sender.gists_url',
    'data.sender.starred_url',
    'data.sender.subscriptions_url',
    'data.sender.organizations_url',
    'data.sender.repos_url',
    'data.sender.events_url',
    'data.sender.received_events_url',
    'data.sender.type',
    'data.sender.user_view_type',
    'data.sender.site_admin',

    // Timestamps (we usually don't display these directly)
    'data.repository.created_at',
    'data.repository.updated_at',
    'data.repository.pushed_at',

    // License and topics (array - not core to notifications)
    'data.repository.license',
    'data.repository.topics',
    'data.repository.visibility',
    'data.repository.pull_request_creation_policy',

    // Mirror
    'data.repository.mirror_url',

    // Computed counts
    'data.repository.forks',
    'data.repository.open_issues',
    'data.repository.watchers',
    'data.repository.stargazers',
    'data.repository.master_branch',
    'data.repository.custom_properties',

    // Avatar URLs (we don't display these in text)
    'data.repository.owner.avatar_url',
    'data.sender.avatar_url',

    // The raw wrapper
    'data',
];
```

---

## PUSH EVENT (push/none)

Volume: 4,784 events

### USEFUL
```php
$push_useful = [
    'data.ref',                      // "refs/heads/branch-name"
    'data.before',                   // before commit SHA
    'data.after',                    // after commit SHA (CORRELATION - links to CI)
    'data.forced',                   // forced push?
    'data.created',                  // branch created?
    'data.deleted',                  // branch deleted?
    'data.base_ref',                 // base branch (if PR)
    'data.compare',                  // comparison URL
    'data.commits',                  // array of commits
    'data.commits[].id',             // commit SHA (CORRELATION)
    'data.commits[].message',        // commit message
    'data.commits[].author.name',    // author name
    'data.commits[].author.email',   // author email
    'data.commits[].added',          // files added
    'data.commits[].removed',        // files removed
    'data.commits[].modified',       // files modified
    'data.commits[].distinct',       // is this a new commit?
    'data.pusher.name',              // pusher username
    'data.pusher.email',             // pusher email
];
```

**Note**: Commit URLs are constructed from `https://github.com/{repo}/commit/{commits[].id}`

### SKIP
```php
$push_skip = [
    // Commits - IDs and metadata (URL is constructed, not stored)
    'data.commits[].tree_id',
    'data.commits[].timestamp',
    'data.commits[].url',
    'data.commits[].author.date',
    'data.commits[].author.username',
    'data.commits[].committer.date',
    'data.commits[].committer.username',

    // head_commit - entirely skipped (use commits[] array instead)
    'data.head_commit',

    // Array markers
    'data.commits.added[]',
    'data.commits.removed[]',
    'data.commits.modified[]',

    // Organization detail
    'data.organization.login',
    'data.organization.id',
    'data.organization.node_id',
    'data.organization.avatar_url',
    'data.organization.html_url',
    'data.organization.description',
];
```

---

## PULL REQUEST EVENT (pull_request/*)

Volume: 1,014 events total

### USEFUL (across all PR actions)
```php
$pr_useful = [
    'data.action',
    'data.number',                   // PR number
    'data.pull_request.title',       // PR title
    'data.pull_request.body',        // PR description
    'data.pull_request.state',       // open/closed
    'data.pull_request.html_url',    // PR link
    'data.pull_request.user.login',  // author
    'data.pull_request.user.html_url',
    'data.pull_request.head.ref',    // source branch
    'data.pull_request.head.sha',    // source SHA
    'data.pull_request.base.ref',    // target branch
    'data.pull_request.base.sha',    // target SHA
    'data.pull_request.commits_url',
    'data.pull_request.diff_url',
    'data.pull_request.patch_url',
    'data.pull_request.labels',      // array of labels
    'data.pull_request.labels[].name',
    'data.pull_request.assignees',   // array of assignees
    'data.pull_request.assignees[].login',
    'data.pull_request.requested_reviewers',  // array
    'data.pull_request.requested_reviewers[].login',
    'data.pull_request.milestone',   // milestone object
    'data.pull_request.milestone.title',
    'data.pull_request.draft',       // boolean
    'data.pull_request.merged',      // boolean
    'data.pull_request.merge_commit_sha',
    'data.pull_request.closed_at',
    'data.pull_request.author_association',
];
```

### SKIP (across all PR actions)
```php
$pr_skip = [
    // Internal IDs
    'data.pull_request.id',
    'data.pull_request.node_id',
    'data.pull_request.url',
    'data.pull_request.issue_url',
    'data.pull_request.statuses_url',
    'data.pull_request.review_comments_url',
    'data.pull_request.review_comment_url',
    'data.pull_request.comments_url',

    // User sub-fields we don't need
    'data.pull_request.user.id',
    'data.pull_request.user.node_id',
    'data.pull_request.user.gravatar_id',
    'data.pull_request.user.url',
    'data.pull_request.user.followers_url',
    'data.pull_request.user.following_url',
    'data.pull_request.user.gists_url',
    'data.pull_request.user.starred_url',
    'data.pull_request.user.subscriptions_url',
    'data.pull_request.user.organizations_url',
    'data.pull_request.user.repos_url',
    'data.pull_request.user.events_url',
    'data.pull_request.user.received_events_url',
    'data.pull_request.user.type',
    'data.pull_request.user.user_view_type',
    'data.pull_request.user.site_admin',

    // Head/base repo sub-fields
    'data.pull_request.head.repo.id',
    'data.pull_request.head.repo.node_id',
    'data.pull_request.head.repo.private',
    'data.pull_request.head.repo.url',
    'data.pull_request.head.repo.owner.id',
    // ... etc, lots of nested URL/ID fields

    // Labels/assignees arrays contain lots of subfields we skip
];
```

### PR CLOSED exclusive (merged info)
```php
$pr_closed_exclusive = [
    'data.pull_request.merged_by',       // who merged
    'data.pull_request.merged_by.login',
    'data.pull_request.merged_at',
];
```

---

## ISSUES EVENT (issues/*)

Volume: 2,792 events

### USEFUL
```php
$issues_useful = [
    'data.action',
    'data.issue.number',             // issue number
    'data.issue.title',              // issue title
    'data.issue.body',               // issue description
    'data.issue.state',              // open/closed
    'data.issue.html_url',           // link to issue
    'data.issue.user.login',         // author
    'data.issue.user.html_url',
    'data.issue.user.avatar_url',
    'data.issue.labels',             // array of labels
    'data.issue.labels[].name',
    'data.issue.labels[].color',
    'data.issue.assignees',          // array
    'data.issue.assignees[].login',
    'data.issue.milestone',          // milestone object
    'data.issue.milestone.title',
    'data.issue.comments',           // comment count
    'data.repository.open_issues_count', // total open issues
    'data.issue.created_at',
    'data.issue.updated_at',
    'data.issue.closed_at',
    'data.issue.author_association',
    'data.issue.locked',
    'data.issue.active_lock_reason',

    // Labeled action exclusive
    'data.label.name',               // the label added/removed
    'data.label.color',

    // Changes (for edited action)
    'data.changes.title',
    'data.changes.title.from',
    'data.changes.body',
    'data.changes.body.from',
];
```

### SKIP
```php
$issues_skip = [
    'data.issue.id',
    'data.issue.node_id',
    'data.issue.url',
    'data.issue.repository_url',
    'data.issue.labels_url',
    'data.issue.comments_url',
    'data.issue.events_url',
    'data.issue.timeline_url',

    // User sub-fields
    'data.issue.user.id',
    'data.issue.user.node_id',
    'data.issue.user.gravatar_id',
    'data.issue.user.url',
    'data.issue.user.followers_url',
    // ... etc

    // Assignee details
    'data.issue.assignee.login',
    'data.issue.assignee.id',
    'data.issue.assignee.node_id',
    // etc

    // Reactions (not core to notification)
    'data.issue.reactions',
    'data.issue.reactions.url',
    'data.issue.reactions.total_count',
    // ... all reaction emoji counts

    // Sub-issues summary
    'data.issue.sub_issues_summary',
    'data.issue.issue_dependencies_summary',

    // Github app
    'data.issue.performed_via_github_app',

    // State reason
    'data.issue.state_reason',
];
```

---

## ISSUE COMMENT EVENT (issue_comment/*)

Volume: 432 events

### USEFUL
```php
$issue_comment_useful = [
    'data.action',
    'data.comment.body',             // comment text
    'data.comment.html_url',         // link to comment
    'data.comment.user.login',       // author
    'data.comment.created_at',
    'data.comment.updated_at',
    'data.comment.author_association',

    // Associated issue (for context)
    'data.issue.number',
    'data.issue.title',
    'data.issue.html_url',
    'data.issue.user.login',
    'data.issue.labels',
];
```

### SKIP
```php
$issue_comment_skip = [
    'data.comment.id',
    'data.comment.node_id',
    'data.comment.url',
    'data.comment.issue_url',
    'data.comment.performed_via_github_app',
    'data.comment.pin',

    // User sub-fields
    'data.comment.user.id',
    'data.comment.user.node_id',
    'data.comment.user.gravatar_id',
    // ... etc
];
```

---

## WORKFLOW RUN EVENT (workflow_run/*)

Volume: 10,206 events

### USEFUL
```php
$workflow_run_useful = [
    'data.action',
    'data.workflow_run.id',              // CORRELATION - links to jobs
    'data.workflow_run.name',            // workflow name (e.g., "CI")
    'data.workflow_run.workflow_id',
    'data.workflow_run.head_branch',     // branch name
    'data.workflow_run.head_sha',        // commit SHA (CORRELATION)
    'data.workflow_run.display_title',
    'data.workflow_run.run_number',
    'data.workflow_run.event',           // push, pull_request, etc
    'data.workflow_run.status',          // queued, in_progress, completed
    'data.workflow_run.conclusion',      // success, failure, cancelled
    'data.workflow_run.run_attempt',
    'data.workflow_run.created_at',
    'data.workflow_run.updated_at',
    'data.workflow_run.run_started_at',
];
```

**Note**: No html_url, jobs_url, or actor info - IDs are sufficient for correlation.

### SKIP
```php
$workflow_run_skip = [
    // URLs - we have IDs for correlation
    'data.workflow_run.html_url',
    'data.workflow_run.jobs_url',
    'data.workflow_run.url',
    'data.workflow_run.pull_requests',
    'data.workflow_run.referenced_workflows',
    'data.workflow_run.previous_attempt_url',
    'data.workflow_run.workflow_url',
    'data.workflow_run.logs_url',
    'data.workflow_run.artifacts_url',
    'data.workflow_run.cancel_url',
    'data.workflow_run.rerun_url',

    // IDs and node_ids
    'data.workflow_run.node_id',
    'data.workflow_run.path',
    'data.workflow_run.check_suite_id',
    'data.workflow_run.check_suite_node_id',

    // Actor info - not needed for workflow notifications
    'data.workflow_run.actor',
    'data.workflow_run.triggering_actor',

    // head_commit - skipped entirely
    'data.workflow_run.head_commit',
];
```

---

## WORKFLOW JOB EVENT (workflow_job/*)

Volume: 139,275 events (HIGHEST VOLUME)

### USEFUL
```php
$workflow_job_useful = [
    'data.action',                      // queued, in_progress, completed, waiting
    'data.workflow_job.id',             // CORRELATION
    'data.workflow_job.run_id',         // CORRELATION - links to parent workflow_run
    'data.workflow_job.name',           // job name (e.g., "Build", "Test")
    'data.workflow_job.workflow_name',  // workflow name
    'data.workflow_job.head_branch',    // branch
    'data.workflow_job.head_sha',       // commit SHA (CORRELATION)
    'data.workflow_job.status',         // queued, in_progress, completed
    'data.workflow_job.conclusion',     // success, failure, skipped, cancelled
    'data.workflow_job.created_at',
    'data.workflow_job.started_at',
    'data.workflow_job.completed_at',
    'data.workflow_job.runner_name',    // e.g., "ubuntu-latest"
    'data.workflow_job.labels',         // runner labels array
    'data.workflow_job.steps',          // array of steps
    'data.workflow_job.steps[].name',
    'data.workflow_job.steps[].status',
    'data.workflow_job.steps[].conclusion',
    'data.workflow_job.steps[].number',
];
```

**Note**: No html_url, run_url - IDs are sufficient for correlation.

### SKIP
```php
$workflow_job_skip = [
    // URLs - we have IDs for correlation
    'data.workflow_job.html_url',
    'data.workflow_job.run_url',
    'data.workflow_job.url',
    'data.workflow_job.check_run_url',

    // IDs
    'data.workflow_job.node_id',
    'data.workflow_job.run_attempt',
    'data.workflow_job.runner_id',
    'data.workflow_job.runner_group_id',
    'data.workflow_job.runner_group_name',

    // Steps - only name/status/conclusion needed (no timestamps)
    'data.workflow_job.steps[].started_at',
    'data.workflow_job.steps[].completed_at',
];
```

---

## CHECK RUN EVENT (check_run/*)

Volume: 78,700 events

### USEFUL
```php
$check_run_useful = [
    'data.action',
    'data.check_run.id',
    'data.check_run.name',           // e.g., "ESLint", "Build"
    'data.check_run.head_sha',       // CORRELATION
    'data.check_run.check_suite_id', // CORRELATION - links to suite
    'data.check_run.status',         // completed
    'data.check_run.conclusion',     // success, failure, neutral
    'data.check_run.started_at',
    'data.check_run.completed_at',
    'data.check_run.html_url',       // link to check
    'data.check_run.details_url',    // external details URL
    'data.check_run.output.title',   // check output title
    'data.check_run.output.summary', // check output summary
    'data.check_run.output.text',
    'data.check_run.output.annotations_count',
    'data.check_run.app.name',       // GitHub App name
    'data.check_run.app.html_url',

    // check_suite nested fields (needed for message builder fallback)
    'data.check_run.check_suite.head_branch',
    'data.check_run.check_suite.head_commit.message',
];
```

### SKIP
```php
$check_run_skip = [
    'data.check_run.node_id',
    'data.check_run.external_id',
    'data.check_run.url',
    'data.check_run.output.annotations_url',

    // check_suite nested - most fields skipped
    'data.check_run.check_suite.id',
    'data.check_run.check_suite.node_id',
    'data.check_run.check_suite.head_sha',
    'data.check_run.check_suite.status',
    'data.check_run.check_suite.conclusion',
    'data.check_run.check_suite.app',

    // App sub-fields
    'data.check_run.app.id',
    'data.check_run.app.client_id',
    'data.check_run.app.slug',
    'data.check_run.app.node_id',
    'data.check_run.app.created_at',
    'data.check_run.app.updated_at',
    'data.check_run.app.permissions',
    'data.check_run.app.events',
];
```

---

## CHECK SUITE EVENT (check_suite/*)

Volume: 5,694 events

### USEFUL
```php
$check_suite_useful = [
    'data.action',
    'data.check_suite.id',               // CORRELATION
    'data.check_suite.head_branch',
    'data.check_suite.head_sha',         // CORRELATION
    'data.check_suite.status',
    'data.check_suite.conclusion',
    'data.check_suite.html_url',
    'data.check_suite.app.name',         // GitHub App
    'data.check_suite.head_commit.message',
    'data.check_suite.head_commit.author.name',

    // Pull requests
    'data.check_suite.pull_requests',
    'data.check_suite.pull_requests[].number',
    'data.check_suite.pull_requests[].head.ref',

    // check_run fields (needed for message builder fallback)
    'data.check_run.conclusion',
    'data.check_run.name',
    'data.check_run.html_url',
    'data.check_run.check_suite.head_branch',
    'data.check_run.check_suite.head_commit.message',
];
```

### SKIP
```php
$check_suite_skip = [
    // Most check_suite fields are URLs, IDs, permissions
    'data.check_suite.node_id',
    'data.check_suite.url',
    'data.check_suite.before',
    'data.check_suite.after',
    'data.check_suite.pull_requests',
    'data.check_suite.rerequestable',
    'data.check_suite.runs_rerequestable',
    'data.check_suite.latest_check_runs_count',
    'data.check_suite.check_runs_url',
    'data.check_suite.created_at',
    'data.check_suite.updated_at',

    // Head commit details
    'data.check_suite.head_commit.id',
    'data.check_suite.head_commit.tree_id',
    'data.check_suite.head_commit.timestamp',
    'data.check_suite.head_commit.author.email',
    'data.check_suite.head_commit.committer.email',

    // App details (permissions, events arrays)
    'data.check_suite.app.*', // all app subfields are IDs/URLs
];
```

---

## RELEASE EVENT (release/*)

Volume: 95 events

### USEFUL
```php
$release_useful = [
    'data.action',                   // created, edited, published, deleted, released
    'data.release.tag_name',         // tag version
    'data.release.name',             // release title
    'data.release.body',             // release notes
    'data.release.html_url',         // link to release
    'data.release.target_commitish', // branch/tag
    'data.release.prerelease',       // boolean
    'data.release.draft',            // boolean
    'data.release.created_at',
    'data.release.published_at',
    'data.release.author.login',
    'data.release.assets',           // download assets
    'data.release.assets[].name',    // asset filename
    'data.release.assets[].browser_download_url',
];
```

### SKIP
```php
$release_skip = [
    'data.release.id',
    'data.release.node_id',
    'data.release.url',
    'data.release.upload_url',
    'data.release.html_url',
    'data.release.assets_url',
    'data.release.tarball_url',
    'data.release.zipball_url',
    'data.release.author.id',
    'data.release.author.node_id',
    'data.release.author.gravatar_id',
    'data.release.author.url',
    // ... etc

    // Assets
    'data.release.assets[].id',
    'data.release.assets[].node_id',
    'data.release.assets[].created_at',
    'data.release.assets[].updated_at',
    'data.release.assets[].size',
    'data.release.assets[].download_count',
    'data.release.assets[].content_type',
    'data.release.assets[].state',
];
```

---

## STATUS EVENT (status/none)

Volume: 800 events

### USEFUL
```php
$status_useful = [
    'data.sha',                     // commit SHA
    'data.state',                   // pending, success, failure, error
    'data.description',             // status description
    'data.target_url',              // link to details
    'data.context',                 // e.g., "default", "ci/circleci"
    'data.branches',                // array of branches
    'data.branches[].name',
    'data.branches[].protected',
    'data.commit.commit.message',   // commit message
    'data.commit.author.login',     // author
];
```

### SKIP
```php
$status_skip = [
    'data.avatar_url',
    'data.name',                    // redundant with context

    // Commit details (lots of nested info)
    'data.commit.sha',
    'data.commit.node_id',
    'data.commit.commit.author.date',
    'data.commit.commit.committer.date',
    'data.commit.commit.tree.sha',
    'data.commit.commit.url',
    'data.commit.commit.comment_count',
    'data.commit.commit.verification',
    'data.commit.url',
    'data.commit.html_url',
    'data.commit.comments_url',

    // Author/committer subfields
    'data.commit.author.id',
    'data.commit.author.node_id',
    'data.commit.author.gravatar_id',
    // etc

    'data.commit.committer.*', // all subfields

    'data.commit.parents',     // array of parent commits
    'data.created_at',
];
```

---

## DEPLOYMENT / DEPLOYMENT STATUS EVENTS

### deployment/created (63 events)
```php
$deployment_useful = [
    'data.action',
    'data.deployment.sha',
    'data.deployment.ref',          // branch/tag
    'data.deployment.environment',
    'data.deployment.description',
    'data.deployment.created_at',
    'data.deployment.creator.login',
    'data.deployment.url',
];
```

### deployment_status/created (247 events)
```php
$deployment_status_useful = [
    'data.action',
    'data.deployment_status.state', // success, failure, pending
    'data.deployment_status.description',
    'data.deployment_status.target_url', // deployment URL
    'data.deployment_status.environment',
    'data.deployment_status.created_at',
    'data.deployment_status.creator.login',
];
```

### SKIP (for both)
```php
$deployment_skip = [
    'data.deployment.id',
    'data.deployment.node_id',
    'data.deployment.url',
    'data.deployment.repository_url',
    'data.deployment.task',
    'data.deployment.payload',
    'data.deployment.creator.id',
    'data.deployment.creator.node_id',
    'data.deployment.creator.gravatar_id',
    // etc

    'data.deployment_status.id',
    'data.deployment_status.node_id',
    'data.deployment_status.url',
    'data.deployment_status.deployment_url',
    'data.deployment_status.repository_url',
    'data.deployment_status.environment_url',
    'data.deployment_status.log_url',
    'data.deployment_status.updated_at',
    'data.deployment_status.performed_via_github_app',
];
```

---

## WATCH/STAR EVENTS

### watch/started (135 events)
```php
$watch_useful = [
    'data.action',
    'data.sender.login',            // who starred
    'data.sender.avatar_url',       // their avatar
    'data.repository.watchers_count', // NEW total watchers
];
```

### star/created (135 events)
```php
$star_useful = [
    'data.action',
    'data.starred_at',              // when starred
    'data.sender.login',
    'data.sender.avatar_url',
    'data.repository.stargazers_count', // NEW total stars
];
```

---

## CREATE/DELETE EVENTS (branch/tag refs)

### create/none (546 events)
```php
$create_useful = [
    'data.ref',                     // branch/tag name
    'data.ref_type',                // "branch" or "tag"
    'data.master_branch',           // default branch
    'data.description',
    'data.sender.login',
];
```

### delete/none (507 events)
```php
$delete_useful = [
    'data.ref',
    'data.ref_type',
    'data.sender.login',
];
```

---

## PING EVENT (ping/none)

### USEFUL
```php
$ping_useful = [
    'data.zen',                     // random zen message
    'data.hook_id',
    'data.hook.name',               // webhook name
    'data.hook.type',               // "Repository" or "Organization"
    'data.hook.active',             // boolean
    'data.hook.events',             // array of subscribed events
];
```

### SKIP
```php
$ping_skip = [
    'data.hook.id',
    'data.hook.url',
    'data.hook.test_url',
    'data.hook.ping_url',
    'data.hook.deliveries_url',
    'data.hook.config',             // secret, url, content_type
    'data.hook.updated_at',
    'data.hook.created_at',
    'data.hook.last_response',
];
```

---

## GOLLUM EVENT (wiki pages)

### useful
```php
$gollum_useful = [
    'data.pages',                   // array of page changes
    'data.pages[].page_name',
    'data.pages[].title',
    'data.pages[].action',          // created, edited, deleted
    'data.pages[].html_url',
    'data.pages[].sha',
    'data.sender.login',
];
```

---

## FORK EVENT (fork/none)

### USEFUL
```php
$fork_useful = [
    'data.forkee.full_name',        // new fork full name
    'data.forkee.html_url',         // link to fork
    'data.forkee.description',
    'data.forkee.language',
    'data.sender.login',            // who forked
    'data.sender.avatar_url',
    'data.forkee.stargazers_count', // fork's star count
    'data.forkee.forks_count',      // fork's fork count
    'data.forkee.open_issues_count',// fork's open issues
];
```

---

## LABEL EVENT (label/created)

### USEFUL
```php
$label_useful = [
    'data.action',
    'data.label.name',
    'data.label.color',
    'data.label.description',
    'data.label.id',
    'data.sender.login',
];
```

---

## PULL REQUEST REVIEW EVENT (pull_request_review/*)

### USEFUL
```php
$pr_review_useful = [
    'data.action',
    'data.review.state',            // approved, changes_requested, commented
    'data.review.body',             // review comment
    'data.review.html_url',
    'data.review.submitted_at',
    'data.review.author_association',
    'data.sender.login',
    // PR details from universal fields
];
```

---

## PULL REQUEST REVIEW COMMENT EVENT

### USEFUL
```php
$pr_review_comment_useful = [
    'data.action',
    'data.comment.body',
    'data.comment.html_url',
    'data.comment.path',            // file path
    'data.comment.line',            // line number
    'data.comment.commit_id',
    'data.comment.created_at',
    'data.sender.login',
];
```

---

## PULL REQUEST REVIEW THREAD EVENT

### USEFUL
```php
$pr_review_thread_useful = [
    'data.action',
    'data.thread.comments[].body',
    'data.thread.comments[].author_association',
    'data.sender.login',
];
```

---

## DEPENDABOT ALERT EVENT

### USEFUL
```php
$dependabot_useful = [
    'data.action',                  // created, fixed, reintroduced, auto_dismissed, auto_reopened
    'data.alert.security_advisory.ghsa_id',
    'data.alert.security_advisory.cve_id',
    'data.alert.security_advisory.summary',
    'data.alert.security_advisory.description',
    'data.alert.security_advisory.severity', // CVSS score
    'data.alert.security_advisory.vulnerabilities[].package.ecosystem',
    'data.alert.security_advisory.vulnerabilities[].package.name',
    'data.alert.security_advisory.vulnerabilities[].vulnerable_version_range',
    'data.alert.dependency.manifest_path',
    'data.alert.dependency.package.name',
    'data.alert.dependency.package.ecosystem',
    'data.alert.fixed_in',          // version fixed in
    'data.alert.html_url',
];
```

### SKIP
```php
$dependabot_skip = [
    'data.alert.id',
    'data.alert.node_id',
    'data.alert.created_at',
    'data.alert.updated_at',
    'data.alert.dismissed_at',
    'data.alert.auto_dismissed_at',
    'data.alert.fix_reason',
    'data.alert.dismissal_request',
    'data.alert.dependency.scope',
    'data.alert.dependency.relationship',
    // etc
];
```

---

## REPOSITORY VULNERABILITY ALERT EVENT

### USEFUL
```php
$repo_vuln_useful = [
    'data.action',                  // create, resolve
    'data.alert.ghsa_id',
    'data.alert.severity',
    'data.alert.number',            // advisory number
    'data.alert.html_url',
    'data.alert.created_at',
];
```

---

## REPOSITORY EVENT (repository/*)

### USEFUL
```php
$repository_useful = [
    'data.action',                  // created, edited, renamed, transferred, public, privatized, archived, unarchived, disabled, enabled
    'data.repository.full_name',
    'data.repository.html_url',
    'data.repository.description',
    // NOTE: homepage removed per user request
    'data.repository.default_branch',

    // For edited action
    'data.changes.description.from',
    'data.changes.default_branch.from',
    'data.changes.homepage.from',

    // For renamed action
    'data.changes.repository.name.from',
    'data.changes.repository.name',
];
```

**Note**: Only `full_name` is typically needed for repo identity, html_url is kept for editing events.

---

## SUMMARY: Priority Field Categories

### HIGH VALUE (almost always useful for notifications)
```
- repo / data.repository.full_name
- event / data.action
- actor/user login and html_url
- titles, names, descriptions
- status, state, conclusion
- branches (ref, head_branch, base_branch)
- SHA (head_sha, sha)
- numbers (issue number, PR number)
- labels[], assignees[], reviewers[]
- message (commit message, review body)
```

### LOW VALUE (usually skip)
```
- All *_url fields except html_url
- All *_count fields
- All id, node_id fields
- All timestamps except key ones
- All avatar_url fields
- Permission arrays
- Most Git metadata (tree_id, distinct, etc)
- Nested user sub-fields beyond login
```

---

## Notes

1. **Volume is misleading**: workflow_job events are 56% of all webhooks but most are `in_progress`/`completed` which we likely want to filter/summarize rather than announce individually.

2. **CI noise**: check_run and check_suite events are very repetitive. Consider announcing only failures, or summarizing at workflow_run level.

3. **Actionable vs Informational**: Some fields (like `data.forced` for push) might matter for security audits but not for general team notifications.

4. **Update frequency**: This document should be updated as the team identifies more or fewer useful fields.

5. **Event filtering**: Many low-value events (workflow_job in_progress, check_run completed with success) can be filtered at the webhook level before they reach the notification logic.
