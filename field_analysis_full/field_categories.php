<?php
declare(strict_types=1);

/**
 * GitHub Webhook Field Categorization
 *
 * Categorizes fields into USEFUL (for notifications) and SKIP (noise).
 *
 * Key principles:
 * - Keep correlation IDs (link events to their parent/cause)
 * - Keep avatar URLs for the triggering user
 * - Keep counts (stars, forks, etc.) - they're useful
 * - Keep timestamps for ordering
 * - Skip URLs except html_url and direct download links
 *
 * Auto-generated from field_analysis_full data.
 */

return [

    /**
     * =============================================================
     * UNIVERSAL FIELDS - present in ALL 56 event/action groups
     * =============================================================
     *
     * These are repository/sender boilerplate that appears everywhere.
     * Most are SKIP, but some are USEFUL.
     *
     * NOTE ON COUNTS: Repository counts (stargazers_count, forks_count,
     * watchers_count) are listed in 'universal' but should only be
     * included for their specific events:
     *   - stargazers_count → star events only
     *   - watchers_count → watch events only
     *   - forks_count → fork events only
     *   - open_issues_count → issues events only
     *
     * The per_event array correctly scopes these to the right events.
     */
    'universal' => [
        /**
         * USEFUL - Core identity and correlation fields
         */
        'useful' => [
            // Repository identity (for display and grouping)
            'repo',                               // Short form "owner/repo"
            'data.repository.full_name',          // Full name for display

            // Event identity
            'event',                              // Event type name
            'data.action',                        // Action (opened, closed, etc)

            // Sender/Correlation - CRITICAL for grouping and user identification
            // NOTE: Excluded for workflow_run and workflow_job events per user request
            'data.sender.login',                  // Username
            'data.sender.html_url',               // Profile link
            'data.sender.avatar_url',             // Avatar for display

            // Timestamps (at least one needed per notification)
            'data.repository.pushed_at',          // Last push time
        ],

        /**
         * SKIP - Boilerplate URLs, IDs, and metadata
         */
        'skip' => [
            // === URL fields (we have html_url for links) ===
            'data.repository.url',
            'data.repository.html_url',           // Only need full_name, not this
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
            'data.repository.git_url',
            'data.repository.ssh_url',
            'data.repository.clone_url',
            'data.repository.svn_url',

            // === Repository metadata we don't need ===
            'data.repository.name',               // Only need full_name
            'data.repository.description',
            'data.repository.homepage',           // Not needed per user request
            'data.repository.language',
            'data.repository.default_branch',
            'data.repository.open_issues_count',  // Only needed for issues events

            // === Owner sub-fields (we have sender.login) ===
            'data.repository.owner.login',
            'data.repository.owner.id',
            'data.repository.owner.node_id',
            'data.repository.owner.gravatar_id',
            'data.repository.owner.url',
            'data.repository.owner.html_url',
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

            // === Sender sub-fields (we have the key ones in useful) ===
            'data.sender.id',
            'data.sender.node_id',
            'data.sender.gravatar_id',
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

            // === Node IDs (internal GitHub IDs) ===
            'data.repository.node_id',

            // === Counts we don't care about ===
            'data.repository.size',
            'data.repository.forks',
            'data.repository.open_issues',
            'data.repository.watchers',
            'data.repository.stargazers',
            'data.repository.watchers_count',
            'data.repository.stargazers_count',
            'data.repository.forks_count',

            // === Organization (not needed per user request) ===
            'data.organization.login',
            'data.organization.id',
            'data.organization.node_id',
            'data.organization.avatar_url',
            'data.organization.html_url',
            'data.organization.description',

            // === Head commit (not needed per user request) ===
            'data.head_commit',
            'data.head_commit.id',
            'data.head_commit.tree_id',
            'data.head_commit.distinct',
            'data.head_commit.url',
            'data.head_commit.message',
            'data.head_commit.timestamp',
            'data.head_commit.author.name',
            'data.head_commit.author.email',
            'data.head_commit.author.date',
            'data.head_commit.author.username',
            'data.head_commit.committer.name',
            'data.head_commit.committer.email',
            'data.head_commit.committer.date',
            'data.head_commit.committer.username',
            'data.head_commit.added',
            'data.head_commit.removed',
            'data.head_commit.modified',

            // === Boolean flags (not actionable for notifications) ===
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

            // === Other metadata ===
            'data.repository.created_at',
            'data.repository.updated_at',
            'data.repository.license',
            'data.repository.topics',
            'data.repository.visibility',
            'data.repository.pull_request_creation_policy',
            'data.repository.mirror_url',
            'data.repository.custom_properties',
            'data.repository.master_branch',
            'data',
        ],
    ],

    /**
     * =============================================================
     * CORRELATION IDS - CRITICAL for grouping related events
     * =============================================================
     *
     * These fields link events that stem from a single cause.
     * For example: a push triggers workflow_run which triggers workflow_jobs
     *
     * Structure:
     *   push.head_sha
     *     └── workflow_run.head_sha (matches)
     *           └── workflow_job.run_id (links to workflow_run.id)
     *                 └── check_run.check_suite_id (links check_runs together)
     *
     * MUST NOT be excluded - they are essential for grouping!
     */
    'correlation_ids' => [
        // Links workflow_run to its triggered workflow_jobs
        'data.workflow_run.id',                   // Parent workflow run ID
        'data.workflow_job.run_id',               // Links job to parent workflow_run

        // Links check_runs to their check_suite
        'data.check_run.check_suite_id',

        // Commit SHA - links push to CI runs
        'data.workflow_run.head_sha',
        'data.workflow_job.head_sha',
        'data.check_run.head_sha',
        'data.check_suite.head_sha',
        'data.status.sha',
        'data.head_commit.id',                    // Commit that triggered CI

        // Run IDs
        'data.workflow_run.run_number',

        // PR number for correlation
        'data.pull_request.number',
        'data.issues.number',
        'data.issue.number',

        // Review correlation
        'data.pull_request_review.id',
        'data.pull_request_review_comment.pull_request_url',

        // For forked repos
        'data.forkee.id',

        // Deployment correlation
        'data.deployment.id',
        'data.deployment_status.deployment_id',
    ],

    /**
     * =============================================================
     * PER-EVENT USEFUL FIELDS
     * =============================================================
     *
     * Key = event name, Value = array of field paths useful for that event
     */
    'per_event' => [
        'push' => [
            // Branch/reference info
            'data.ref',                           // "refs/heads/branch"
            'data.before',                        // Before commit SHA
            'data.after',                         // After commit SHA

            // Push metadata
            'data.forced',                        // Forced push?
            'data.created',                       // Branch created?
            'data.deleted',                       // Branch deleted?
            'data.base_ref',                      // Base branch (for PRs)
            'data.compare',                       // Comparison URL

            // Commits
            'data.commits',                       // Array of commits
            'data.commits[].id',                  // Commit ID (correlation)
            'data.commits[].message',             // Commit message
            'data.commits[].author.name',         // Author name
            'data.commits[].author.email',        // Author email
            'data.commits[].added',               // Files added
            'data.commits[].removed',             // Files removed
            'data.commits[].modified',            // Files modified
            'data.commits[].distinct',            // Is this a new commit?

            // Pusher
            'data.pusher.name',                   // Username
            'data.pusher.email',                  // Email

            // NOTE: No organization - not needed per user request
            // NOTE: No head_commit - we use commits[] array instead
        ],

        'pull_request' => [
            // PR identity
            'data.action',
            'data.number',                        // PR number

            // PR content
            'data.pull_request.title',
            'data.pull_request.body',
            'data.pull_request.state',            // open/closed

            // PR URLs
            'data.pull_request.html_url',         // Link to PR
            'data.pull_request.diff_url',
            'data.pull_request.patch_url',

            // Author (the triggering user)
            'data.pull_request.user.login',
            'data.pull_request.user.html_url',
            'data.pull_request.user.avatar_url',

            // Branches (CORRELATION - head_sha matches workflow_run)
            'data.pull_request.head.ref',         // Source branch
            'data.pull_request.head.sha',         // Source SHA
            'data.pull_request.base.ref',         // Target branch
            'data.pull_request.base.sha',         // Target SHA

            // Labels
            'data.pull_request.labels',
            'data.pull_request.labels[].name',
            'data.pull_request.labels[].color',

            // Assignees
            'data.pull_request.assignees',
            'data.pull_request.assignees[].login',

            // Reviewers
            'data.pull_request.requested_reviewers',
            'data.pull_request.requested_reviewers[].login',

            // Milestone
            'data.pull_request.milestone.title',

            // State
            'data.pull_request.draft',
            'data.pull_request.merged',
            'data.pull_request.merge_commit_sha',
            'data.pull_request.merged_at',
            'data.pull_request.closed_at',
            'data.pull_request.author_association',

            // For merged_by (who merged)
            'data.pull_request.merged_by.login',
            'data.pull_request.merged_by.avatar_url',

            // For assigned action
            'data.assignee.login',

            // For edited action
            'data.changes.title.from',
            'data.changes.body.from',
        ],

        'issues' => [
            'data.action',
            'data.issue.number',

            // Content
            'data.issue.title',
            'data.issue.body',

            // State
            'data.issue.state',
            'data.issue.locked',
            'data.issue.active_lock_reason',
            'data.issue.author_association',

            // URLs
            'data.issue.html_url',

            // Author
            'data.issue.user.login',
            'data.issue.user.html_url',
            'data.issue.user.avatar_url',

            // Labels
            'data.issue.labels',
            'data.issue.labels[].name',
            'data.issue.labels[].color',

            // Assignees
            'data.issue.assignees',
            'data.issue.assignees[].login',

            // Milestone
            'data.issue.milestone.title',

            // Counts - open_issues_count only for issues events
            'data.issue.comments',
            'data.repository.open_issues_count',

            // Timestamps
            'data.issue.created_at',
            'data.issue.updated_at',
            'data.issue.closed_at',

            // For labeled action
            'data.label.name',
            'data.label.color',

            // For edited action
            'data.changes.title',
            'data.changes.title.from',
            'data.changes.body',
            'data.changes.body.from',

            // For reopened action
            'data.issue.state_reason',
        ],

        'issue_comment' => [
            'data.action',

            // Comment content
            'data.comment.body',
            'data.comment.html_url',

            // Author
            'data.comment.user.login',
            'data.comment.user.html_url',
            'data.comment.user.avatar_url',

            // Timestamps
            'data.comment.created_at',
            'data.comment.updated_at',
            'data.comment.author_association',

            // Context - the issue
            'data.issue.number',
            'data.issue.title',
            'data.issue.html_url',
            'data.issue.user.login',
            'data.issue.labels',
        ],

        'workflow_run' => [
            'data.action',

            // Workflow identity
            'data.workflow_run.id',               // CORRELATION - links to jobs
            'data.workflow_run.name',             // e.g., "CI"
            'data.workflow_run.workflow_id',

            // Branch/commit (CORRELATION - links to push)
            'data.workflow_run.head_branch',
            'data.workflow_run.head_sha',

            // Display
            'data.workflow_run.display_title',
            'data.workflow_run.run_number',

            // Event that triggered it
            'data.workflow_run.event',            // push, pull_request, etc

            // Status
            'data.workflow_run.status',
            'data.workflow_run.conclusion',

            // Timestamps
            'data.workflow_run.created_at',
            'data.workflow_run.updated_at',
            'data.workflow_run.run_started_at',

            // NOTE: No html_url, run_url, jobs_url - we have the IDs
            // NOTE: No actor/triggering_actor info - not needed for workflow notifications
        ],

        'workflow_job' => [
            'data.action',

            // Job identity - we have IDs, don't need URLs
            'data.workflow_job.id',
            'data.workflow_job.name',             // e.g., "Build", "Test"

            // CORRELATION - links to parent workflow_run
            'data.workflow_job.run_id',
            'data.workflow_job.workflow_name',

            // Branch/commit (CORRELATION)
            'data.workflow_job.head_branch',
            'data.workflow_job.head_sha',

            // Status
            'data.workflow_job.status',
            'data.workflow_job.conclusion',

            // Timestamps
            'data.workflow_job.created_at',
            'data.workflow_job.started_at',
            'data.workflow_job.completed_at',

            // NOTE: No html_url, run_url - we have the IDs
            // NOTE: No sender info - not needed for workflow notifications

            // Runner
            'data.workflow_job.runner_name',      // e.g., "ubuntu-latest"
            'data.workflow_job.labels',           // Runner labels

            // Steps (for status updates)
            'data.workflow_job.steps',
            'data.workflow_job.steps[].name',
            'data.workflow_job.steps[].status',
            'data.workflow_job.steps[].conclusion',
            'data.workflow_job.steps[].number',
        ],

        'check_run' => [
            'data.action',

            // Check identity
            'data.check_run.id',
            'data.check_run.name',               // e.g., "ESLint", "Build"
            'data.check_run.head_sha',

            // CORRELATION - links to check_suite
            'data.check_run.check_suite_id',

            // Status
            'data.check_run.status',
            'data.check_run.conclusion',

            // Timestamps
            'data.check_run.started_at',
            'data.check_run.completed_at',

            // URLs
            'data.check_run.html_url',           // Link to check
            'data.check_run.details_url',        // External details

            // Output (CI result summary)
            'data.check_run.output.title',
            'data.check_run.output.summary',
            'data.check_run.output.text',
            'data.check_run.output.annotations_count',

            // App info
            'data.check_run.app.name',
            'data.check_run.app.html_url',

            // check_suite nested fields (used in message builder)
            'data.check_run.check_suite.head_branch',
            'data.check_run.check_suite.head_commit.message',
        ],

        'check_suite' => [
            'data.action',

            // Suite identity
            'data.check_suite.id',

            // CORRELATION - head_sha links to commit/push
            'data.check_suite.head_branch',
            'data.check_suite.head_sha',

            // Status
            'data.check_suite.status',
            'data.check_suite.conclusion',

            // URLs
            'data.check_suite.html_url',

            // App
            'data.check_suite.app.name',

            // Commit message
            'data.check_suite.head_commit.message',
            'data.check_suite.head_commit.author.name',

            // Pull requests
            'data.check_suite.pull_requests',
            'data.check_suite.pull_requests[].number',
            'data.check_suite.pull_requests[].head.ref',

            // check_run fields (used as fallback in message builder)
            'data.check_run.conclusion',
            'data.check_run.name',
            'data.check_run.html_url',
            'data.check_run.check_suite.head_branch',
            'data.check_run.check_suite.head_commit.message',
        ],

        'release' => [
            'data.action',

            // Release identity
            'data.release.tag_name',
            'data.release.name',
            'data.release.body',

            // URLs
            'data.release.html_url',             // Link to release

            // Target
            'data.release.target_commitish',     // branch or tag

            // State
            'data.release.prerelease',
            'data.release.draft',

            // Author
            'data.release.author.login',
            'data.release.author.html_url',
            'data.release.author.avatar_url',

            // Timestamps
            'data.release.created_at',
            'data.release.published_at',

            // Assets
            'data.release.assets',
            'data.release.assets[].name',
            'data.release.assets[].browser_download_url',
            'data.release.assets[].size',
            'data.release.assets[].download_count',
        ],

        'status' => [
            // SHA (CORRELATION)
            'data.sha',

            // State
            'data.state',                        // pending, success, failure, error
            'data.description',
            'data.target_url',

            // Context
            'data.context',                      // e.g., "ci/circleci"

            // Timestamps
            'data.created_at',

            // Branches
            'data.branches',
            'data.branches[].name',
            'data.branches[].protected',

            // Commit info
            'data.commit.commit.message',
            'data.commit.author.login',
            'data.commit.author.avatar_url',

            // Avatar (if provided separately)
            'data.avatar_url',
        ],

        'deployment' => [
            'data.action',

            // Deployment identity
            'data.deployment.id',
            'data.deployment.sha',
            'data.deployment.ref',

            // Environment
            'data.deployment.environment',
            'data.deployment.description',

            // URLs
            'data.deployment.url',
            'data.deployment.repository_url',

            // Creator
            'data.deployment.creator.login',
            'data.deployment.creator.avatar_url',

            // Timestamp
            'data.deployment.created_at',

            // Task
            'data.deployment.task',
        ],

        'deployment_status' => [
            'data.action',

            // Status identity
            'data.deployment_status.id',
            'data.deployment_status.state',      // success, failure, pending

            // Details
            'data.deployment_status.description',
            'data.deployment_status.target_url',
            'data.deployment_status.environment',
            'data.deployment_status.environment_url',

            // CORRELATION - links to deployment
            'data.deployment_status.deployment_url',
            'data.deployment_status.repository_url',

            // Creator
            'data.deployment_status.creator.login',
            'data.deployment_status.creator.avatar_url',

            // Timestamps
            'data.deployment_status.created_at',
            'data.deployment_status.updated_at',

            // Log
            'data.deployment_status.log_url',
        ],

        'watch' => [
            'data.action',
            'data.sender.login',
            'data.sender.avatar_url',
            // watchers_count - ONLY useful for watch events
            'data.repository.watchers_count',
        ],

        'star' => [
            'data.action',
            'data.starred_at',
            'data.sender.login',
            'data.sender.avatar_url',
            // stargazers_count - ONLY useful for star events
            'data.repository.stargazers_count',
        ],

        'create' => [
            'data.ref',                          // Branch/tag name
            'data.ref_type',                     // "branch" or "tag"
            'data.master_branch',
            'data.description',
            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'delete' => [
            'data.ref',
            'data.ref_type',
            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'ping' => [
            'data.zen',
            'data.hook_id',
            'data.hook.name',
            'data.hook.type',
            'data.hook.active',
            'data.hook.events',
            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'gollum' => [
            'data.pages',
            'data.pages[].page_name',
            'data.pages[].title',
            'data.pages[].action',
            'data.pages[].html_url',
            'data.pages[].sha',
            'data.pages[].summary',
            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'fork' => [
            // CORRELATION - forkee ID
            'data.forkee.id',
            'data.forkee.full_name',
            'data.forkee.html_url',
            'data.forkee.description',
            'data.forkee.language',
            'data.sender.login',
            'data.sender.avatar_url',
            // Counts - useful here for the new fork totals
            'data.forkee.stargazers_count',
            'data.forkee.forks_count',
            'data.forkee.open_issues_count',
        ],

        'label' => [
            'data.action',
            'data.label.id',
            'data.label.name',
            'data.label.color',
            'data.label.description',
            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'pull_request_review' => [
            'data.action',

            // Review identity
            'data.review.id',

            // Content
            'data.review.state',                // approved, changes_requested, commented
            'data.review.body',

            // URLs
            'data.review.html_url',

            // Author
            'data.review.user.login',
            'data.review.user.avatar_url',

            // Timestamps
            'data.review.submitted_at',
            'data.review.updated_at',
            'data.review.author_association',

            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'pull_request_review_comment' => [
            'data.action',

            // Comment identity
            'data.comment.id',

            // Content
            'data.comment.body',

            // File location
            'data.comment.path',
            'data.comment.line',
            'data.comment.commit_id',
            'data.comment.original_commit_id',

            // Diff info
            'data.comment.diff_hunk',
            'data.comment.position',
            'data.comment.original_position',

            // CORRELATION
            'data.comment.pull_request_url',

            // URLs
            'data.comment.html_url',

            // Author
            'data.comment.user.login',
            'data.comment.user.avatar_url',

            // Timestamp
            'data.comment.created_at',

            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'pull_request_review_thread' => [
            'data.action',

            // Thread identity
            'data.thread.node_id',

            // Comments
            'data.thread.comments',
            'data.thread.comments[].id',
            'data.thread.comments[].body',
            'data.thread.comments[].author_association',
            'data.thread.comments[].created_at',
            'data.thread.comments[].html_url',

            'data.sender.login',
            'data.sender.avatar_url',
        ],

        'dependabot_alert' => [
            'data.action',

            // Alert identity
            'data.alert.id',
            'data.alert.ghsa_id',
            'data.alert.number',

            // Advisory info
            'data.alert.security_advisory.ghsa_id',
            'data.alert.security_advisory.cve_id',
            'data.alert.security_advisory.summary',
            'data.alert.security_advisory.description',
            'data.alert.security_advisory.severity',

            // Vulnerabilities
            'data.alert.security_advisory.vulnerabilities',
            'data.alert.security_advisory.vulnerabilities[].package.ecosystem',
            'data.alert.security_advisory.vulnerabilities[].package.name',
            'data.alert.security_advisory.vulnerabilities[].vulnerable_version_range',

            // Dependency
            'data.alert.dependency.manifest_path',
            'data.alert.dependency.package.name',
            'data.alert.dependency.package.ecosystem',
            'data.alert.fixed_in',

            // State
            'data.alert.fix_reason',
            'data.alert.dismissed_at',

            // URLs
            'data.alert.html_url',

            // Timestamps
            'data.alert.created_at',
            'data.alert.updated_at',
        ],

        'repository_vulnerability_alert' => [
            'data.action',
            'data.alert.ghsa_id',
            'data.alert.severity',
            'data.alert.number',
            'data.alert.html_url',
            'data.alert.created_at',
        ],

        'repository' => [
            'data.action',
            'data.repository.full_name',
            'data.repository.html_url',
            'data.repository.description',
            // NOTE: homepage removed per user request
            'data.repository.default_branch',

            // For edited action
            'data.changes.description',
            'data.changes.description.from',
            'data.changes.default_branch',
            'data.changes.default_branch.from',
            'data.changes.homepage',
            'data.changes.homepage.from',

            // For renamed action
            'data.changes.repository.name',
            'data.changes.repository.name.from',

            'data.sender.login',
            'data.sender.avatar_url',
        ],
    ],

    /**
     * =============================================================
     * HIGH PRIORITY EVENTS - always announce these
     * =============================================================
     */
    'high_priority_events' => [
        'push',
        'pull_request',
        'issues',
        'release',
        'deployment',
        'deployment_status',
        'fork',
        'star',
    ],

    /**
     * =============================================================
     * HIGH VOLUME EVENTS - often filtered/summarized
     * =============================================================
     */
    'high_volume_events' => [
        'workflow_job' => 139275,
        'check_run' => 78700,
        'workflow_run' => 10206,
    ],

    /**
     * =============================================================
     * COMMONLY IGNORED EVENTS - usually noise
     * =============================================================
     */
    'commonly_ignored' => [
        'ping',
        'check_suite',  // Usually handled via check_run
    ],

    /**
     * =============================================================
     * GROUPING STRATEGY
     * =============================================================
     *
     * How to group notifications by originating event:
     *
     * PUSH-based chain:
     *   1. push(head_sha) → triggers
     *   2. workflow_run(head_sha) → triggers
     *   3. workflow_job(run_id = workflow_run.id)
     *   4. check_run(check_suite_id links to check_suite)
     *
     * PR-based chain:
     *   1. pull_request(number) → triggers
     *   2. workflow_run(event=pull_request, head_sha matches PR head)
     *   3. check_run/pipeline events linked by head_sha
     *
     * GROUPING_ID suggestions:
     *   - For push events: data.after (the commit SHA)
     *   - For PR events: data.pull_request.number + repo
     *   - For workflow_run: data.workflow_run.id
     *   - For workflow_job: data.workflow_job.run_id
     */
    'grouping_strategy' => [
        'push' => 'data.after',                  // Commit SHA
        'pull_request' => 'data.pull_request.number',
        'workflow_run' => 'data.workflow_run.id',
        'workflow_job' => 'data.workflow_job.run_id',
        'issues' => 'data.issue.number',
        'release' => 'data.release.tag_name',
    ],

];
