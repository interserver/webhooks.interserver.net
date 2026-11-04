<?php
declare(strict_types=1);

class GithubMessageBuilder
{
    private const BODY_EXCERPT_CHARS = 500;

    /**
     * Dependabot folds these into <details> blocks; they only expand on the
     * "Bumps x from a to b" line, so they are dropped from the excerpt.
     */
    private const DROPPED_DETAILS_SUMMARIES = ['Release notes', 'Changelog', 'Commits', 'Dependabot commands and options'];

    private string $EventType;
    private array $Message;
    private string $RepositoryName;
    private string $User;

    public function __construct(string $EventType, array $Message)
    {
        $this->EventType = $EventType;
        $this->Message = $Message;
        $this->RepositoryName = $this->deriveRepositoryName();
        $this->User = $this->deriveUser();
    }

    public function getRepositoryName(): string
    {
        return $this->RepositoryName;
    }

    public function getUser(): string
    {
        return $this->User;
    }

    /**
     * Builds the chat message array with avatar, optional alias, and text.
     *
     * @return array{avatar: string, alias?: string, text: string}
     */
    public function build(): array
    {
        $Msg = [];
        $Msg['avatar'] = $this->deriveAvatar();
        $Alias = $this->deriveAlias();
        if ($Alias !== null) {
            $Msg['alias'] = $Alias;
        }
        $Msg['text'] = $this->buildText($Alias ?? $this->User);
        return $Msg;
    }

    private function deriveRepositoryName(): string
    {
        if (isset($this->Message['repository']['full_name'])) {
            return $this->Message['repository']['full_name'];
        }
        return sprintf('%s/%s', $this->Message['repository']['owner']['name'], $this->Message['repository']['name']);
    }

    private function deriveUser(): string
    {
        $EventType = $this->EventType;
        $Message = $this->Message;
        if (isset($Message[$EventType]['app']['name'])) {
            return $Message[$EventType]['app']['name'];
        }
        return $Message['sender']['login'];
    }

    private function deriveAvatar(): string
    {
        $EventType = $this->EventType;
        $Message = $this->Message;
        if (isset($Message[$EventType]['app']['owner']['avatar_url'])) {
            return $Message[$EventType]['app']['owner']['avatar_url'];
        }
        if (isset($Message['avatar_url'])) {
            return $Message['avatar_url'];
        }
        return $Message['sender']['avatar_url'];
    }

    private function deriveAlias(): ?string
    {
        $Message = $this->Message;
        if (isset($Message['head_commit']['author']['name'])) {
            return $Message['head_commit']['author']['name'];
        }
        if (isset($Message['commits'][0]['author']['name'])) {
            return $Message['commits'][0]['author']['name'];
        }
        if (isset($Message['commit']['commit']['author']['name'])) {
            return $Message['commit']['commit']['author']['name'];
        }
        return null;
    }

    private function buildText(string $Alias): string
    {
        $EventType = $this->EventType;
        $Message = $this->Message;
        $RepositoryName = $this->RepositoryName;
        $User = $this->User;

        switch ($EventType) {
            case 'issues':
                return $this->buildIssuesText($Message, $RepositoryName, $User);
            case 'pull_request':
                return $this->buildPullRequestText($Message, $RepositoryName, $User);
            case 'push':
                return $this->buildPushText($Message, $RepositoryName, $Alias);
            case 'check_suite':
            case 'check_run':
                return $this->buildCheckText($Message, $RepositoryName);
            case 'workflow_run':
            case 'workflow_job':
                return $this->buildWorkflowText($EventType, $Message, $RepositoryName);
            case 'gollum':
                return $this->buildGollumText($Message, $RepositoryName, $User);
            case 'registry_package':
            case 'package':
                return $this->buildPackageText($Message, $RepositoryName, $Alias);
            default:
                return $this->buildDefaultText($EventType, $Message, $RepositoryName, $Alias);
        }
    }

    private function buildIssuesText(array $Message, string $RepositoryName, string $User): string
    {
        $Issue = $Message['issue'];
        $IssueUrl = $Issue['html_url'];
        $IssueTitle = $Issue['title'];
        $Action = $Message['action'];

        $ChatMsg = "🐛 [{$User}](https://github.com/{$User}) **{$Action}** issue [#{$Issue['number']} {$IssueTitle}]({$IssueUrl}) "
                 . "in [{$RepositoryName}](https://github.com/{$RepositoryName}).";

        if (!empty($Issue['labels'])) {
            $Labels = array_map(fn ($l) => "**{$l['name']}**", $Issue['labels']);
            $ChatMsg .= " " . implode(' ', $Labels);
        }

        if ($Action === 'closed' && !empty($Issue['state_reason'])) {
            $ChatMsg .= " _(reason: {$Issue['state_reason']})_";
        }

        if ($Action === 'edited' && !empty($Message['changes'])) {
            $Changed = array_keys($Message['changes']);
            $ChatMsg .= " _(changed: " . implode(', ', $Changed) . ")_";
        }

        $Excerpt = self::bodyExcerpt($Issue['body'] ?? '');
        if ($Excerpt !== '') {
            $ChatMsg .= "\n\n> " . $Excerpt;
        }

        return $ChatMsg;
    }

    private function buildPullRequestText(array $Message, string $RepositoryName, string $User): string
    {
        $PR = $Message['pull_request'];
        $Action = $Message['action'];
        $PRUrl = $PR['html_url'];
        $PRTitle = $PR['title'];
        $HeadBranch = $PR['head']['ref'] ?? '';
        $BaseBranch = $PR['base']['ref'] ?? '';

        $ChatMsg = "🔀 [{$User}](https://github.com/{$User}) **{$Action}** pull request "
                 . "[#{$PR['number']} {$PRTitle}]({$PRUrl}) "
                 . "in [{$RepositoryName}](https://github.com/{$RepositoryName})";

        if ($HeadBranch && $BaseBranch) {
            $ChatMsg .= " (**{$HeadBranch}** → **{$BaseBranch}**)";
        }
        $ChatMsg .= ".";

        if (!empty($PR['draft'])) {
            $ChatMsg .= " _(draft)_";
        }

        if ($Action === 'closed' && !empty($PR['merged'])) {
            $ChatMsg .= " ✅ Merged.";
        }

        if (in_array($Action, ['opened', 'reopened', 'closed']) && isset($PR['commits'])) {
            $Stats = [];
            if (!empty($PR['commits'])) {
                $Stats[] = "{$PR['commits']} commit" . ($PR['commits'] !== 1 ? "s" : "");
            }
            if (isset($PR['additions'], $PR['deletions'])) {
                $Stats[] = "+{$PR['additions']}/-{$PR['deletions']}";
            }
            if (!empty($PR['changed_files'])) {
                $Stats[] = "{$PR['changed_files']} file" . ($PR['changed_files'] !== 1 ? "s" : "");
            }
            if (!empty($Stats)) {
                $ChatMsg .= " _(" . implode(', ', $Stats) . ")_";
            }
        }

        $Excerpt = self::bodyExcerpt($PR['body'] ?? '');
        if ($Excerpt !== '') {
            $ChatMsg .= "\n\n> " . $Excerpt;
        }

        return $ChatMsg;
    }

    private function buildPushText(array $Message, string $RepositoryName, string $Alias): string
    {
        $Branch = isset($Message['ref']) ? str_replace('refs/heads/', '', $Message['ref']) : '';
        $CommitCount = isset($Message['commits']) ? count($Message['commits']) : 1;
        $Commits = [];

        if (!empty($Message['commits'])) {
            $AllSameAuthor = array_reduce($Message['commits'], function (bool $carry, array $c) use ($Alias): bool {
                return $carry && (($c['author']['name'] ?? '') === $Alias);
            }, true);

            foreach ($Message['commits'] as $Commit) {
                $CommitMsg = strtok($Commit['message'], "\n");
                $FileCounts = [];
                if (!empty($Commit['added'])) {
                    $FileCounts[] = '+' . count($Commit['added']);
                }
                if (!empty($Commit['modified'])) {
                    $FileCounts[] = '~' . count($Commit['modified']);
                }
                if (!empty($Commit['removed'])) {
                    $FileCounts[] = '-' . count($Commit['removed']);
                }
                $FileInfo = !empty($FileCounts) ? ' _(' . implode(' ', $FileCounts) . ' files)_' : '';
                $ByAuthor = $AllSameAuthor ? '' : " by {$Commit['author']['name']}";
                $CommitUrl = "https://github.com/{$RepositoryName}/commit/{$Commit['id']}";
                $Commits[] = "• [" . substr($Commit['id'], 0, 7) . "]({$CommitUrl}) _{$CommitMsg}_{$ByAuthor}{$FileInfo}";
            }
        }

        $ChatMsg = "📦 {$Alias} pushed {$CommitCount} "
                 . ($CommitCount === 1 ? "commit" : "commits")
                 . " to [{$RepositoryName}](https://github.com/{$RepositoryName}) "
                 . "[**{$Branch}**](https://github.com/{$RepositoryName}/tree/{$Branch})";

        if (!empty($Message['compare'])) {
            $ChatMsg .= " ([compare]({$Message['compare']}))";
        }

        if (!empty($Commits)) {
            $ChatMsg .= "\n" . implode("\n", $Commits);
        }

        return $ChatMsg;
    }

    private function buildCheckText(array $Message, string $RepositoryName): string
    {
        $HasCheckRun = isset($Message['check_run']);
        $Status = $Message['check_suite']['conclusion'] ?? ($HasCheckRun ? $Message['check_run']['conclusion'] : null) ?? 'in_progress';
        $Name = $Message['check_suite']['app']['name'] ?? ($HasCheckRun ? $Message['check_run']['name'] : null) ?? 'Check';
        $Url = $Message['check_suite']['url'] ?? ($HasCheckRun ? $Message['check_run']['html_url'] : null) ?? '#';
        $Branch = $Message['check_suite']['head_branch'] ?? ($HasCheckRun ? ($Message['check_run']['check_suite']['head_branch'] ?? '') : '') ?: '';
        $CommitMsg = $Message['check_suite']['head_commit']['message'] ?? ($HasCheckRun ? ($Message['check_run']['check_suite']['head_commit']['message'] ?? '') : '') ?: '';

        $Emoji = $Status === 'success' ? "✅" : ($Status === 'failure' ? "❌" : "⏳");
        $ChatMsg = "{$Emoji} Check **{$Name}** {$Status} "
                 . "for [{$RepositoryName}](https://github.com/{$RepositoryName})";
        if ($Branch) {
            $ChatMsg .= " on **{$Branch}**";
        }
        $ChatMsg .= " ([details]({$Url}))";
        if ($CommitMsg) {
            $CommitMsg = strtok($CommitMsg, "\n");
            $ChatMsg .= "\n> _{$CommitMsg}_";
        }

        return $ChatMsg;
    }

    private function buildWorkflowText(string $EventType, array $Message, string $RepositoryName): string
    {
        $WorkflowName = $Message['workflow_run']['name'] ?? $Message['workflow']['name'] ?? $Message['workflow_job']['workflow_name'] ?? $Message['workflow_job']['name'] ?? "Workflow";
        $Status = $Message['workflow_run']['conclusion'] ?? $Message['workflow_job']['conclusion'] ?? $Message['workflow_run']['status'] ?? $Message['workflow_job']['status'] ?? "in_progress";
        $Url = $Message['workflow_run']['html_url'] ?? $Message['workflow_job']['html_url'] ?? "#";
        $Branch = $Message['workflow_run']['head_branch'] ?? $Message['workflow_job']['head_branch'] ?? '';
        $Emoji = $Status === 'success' ? "✅" : ($Status === 'failure' ? "❌" : ($Status === 'in_progress' ? "⏳" : "🔄"));

        $ChatMsg = "{$Emoji} Workflow **{$WorkflowName}** {$Status} "
                 . "for [{$RepositoryName}](https://github.com/{$RepositoryName})";
        if ($Branch) {
            $ChatMsg .= " on **{$Branch}**";
        }
        $ChatMsg .= " ([view run]({$Url}))";

        if ($EventType === 'workflow_run' && !empty($Message['workflow_run']['head_commit']['message'])) {
            $HeadCommitMsg = strtok($Message['workflow_run']['head_commit']['message'], "\n");
            $ChatMsg .= "\n> _{$HeadCommitMsg}_";
        }

        if ($EventType === 'workflow_job' && !empty($Message['workflow_job']['steps'])) {
            foreach ($Message['workflow_job']['steps'] as $Step) {
                if ($Step['status'] === 'in_progress') {
                    $ChatMsg .= "\n> Step: _{$Step['name']}_";
                    break;
                }
            }
        }

        return $ChatMsg;
    }

    private function buildGollumText(array $Message, string $RepositoryName, string $User): string
    {
        $Pages = $Message['pages'] ?? [];
        if (!empty($Pages)) {
            $PageLines = [];
            foreach ($Pages as $Page) {
                $PageAction = $Page['action'] ?? 'updated';
                $PageTitle = $Page['title'] ?? 'Unknown';
                $PageUrl = $Page['html_url'] ?? '';
                $Summary = !empty($Page['summary']) ? " — {$Page['summary']}" : '';
                $PageLines[] = "• **{$PageAction}** [{$PageTitle}]({$PageUrl}){$Summary}";
            }
            $PageCount = count($Pages);
            return "📝 [{$User}](https://github.com/{$User}) updated {$PageCount} wiki "
                 . ($PageCount === 1 ? "page" : "pages")
                 . " on [{$RepositoryName}](https://github.com/{$RepositoryName}):\n"
                 . implode("\n", $PageLines);
        }
        return "📝 [{$User}](https://github.com/{$User}) updated the wiki "
             . "on [{$RepositoryName}](https://github.com/{$RepositoryName}).";
    }

    /**
     * registry_package is the current event name; package is the legacy one.
     * Both carry the package object under their own key.
     */
    private function buildPackageText(array $Message, string $RepositoryName, string $Alias): string
    {
        $Package = $Message['registry_package'] ?? $Message['package'] ?? [];
        $PackageName = (string)($Package['name'] ?? '');
        if ($PackageName === '') {
            return $this->buildDefaultText($this->EventType, $Message, $RepositoryName, $Alias);
        }

        $Action = (string)($Message['action'] ?? 'published');
        $Type = strtolower((string)($Package['package_type'] ?? 'package'));
        $Label = self::packageLabel($Package, $PackageName, $Type);

        $Subject = "{$Type} package **{$Label}**";
        $PackageUrl = self::packageUrl($Package, $RepositoryName);
        if ($PackageUrl !== '') {
            $Subject = "[{$Subject}]({$PackageUrl})";
        }

        return "📦 {$Alias} {$Action} {$Subject} "
             . "on [**{$RepositoryName}**](https://github.com/{$RepositoryName}).";
    }

    /**
     * Human label for a package version:
     *   container with a tag  → "phlix-hub:latest" (docker image reference)
     *   digest-only version   → "phlix-hub@sha256:00fdf23e8ee8" (12-hex short id)
     *   anything else         → "@detain/sugarcraft@2.1.0", or the bare name
     * Kept in step with packageLabel() in the Teams bot's notificationConsumer.js.
     *
     * @param array<string, mixed> $Package
     */
    private static function packageLabel(array $Package, string $PackageName, string $Type): string
    {
        $Version = is_array($Package['package_version'] ?? null) ? $Package['package_version'] : [];
        $TagMeta = $Version['container_metadata']['tag'] ?? null;
        $IsContainer = in_array($Type, ['container', 'docker', 'container_registry'], true) || is_array($TagMeta);
        $Tag = is_array($TagMeta) ? trim((string)($TagMeta['name'] ?? '')) : '';
        if ($IsContainer && $Tag !== '') {
            return "{$PackageName}:{$Tag}";
        }

        $RawVersion = trim((string)($Version['name'] ?? $Version['version'] ?? ''));
        if (preg_match('/^sha256:([0-9a-f]{12})[0-9a-f]*$/i', $RawVersion, $Match)) {
            $RawVersion = 'sha256:' . strtolower($Match[1]);
        }
        return $RawVersion !== '' ? "{$PackageName}@{$RawVersion}" : $PackageName;
    }

    /**
     * Best working link: the per-version page, then the package page — unless
     * it is GitHub's https://github.com/{owner}/packages/{id} shape, which
     * 404s for user-owned container packages — then the repo's packages tab.
     *
     * @param array<string, mixed> $Package
     */
    private static function packageUrl(array $Package, string $RepositoryName): string
    {
        $Safe = static function ($Url): string {
            $Url = trim((string)$Url);
            return preg_match('#^https://[^()\s]+$#i', $Url) ? $Url : '';
        };
        $VersionUrl = $Safe($Package['package_version']['html_url'] ?? '');
        if ($VersionUrl !== '') {
            return $VersionUrl;
        }
        $PackageUrl = $Safe($Package['html_url'] ?? '');
        if ($PackageUrl !== '' && !preg_match('#^https://github\.com/[^/]+/packages/\d+/?$#i', $PackageUrl)) {
            return $PackageUrl;
        }
        return $RepositoryName !== '' ? "https://github.com/{$RepositoryName}/packages" : '';
    }

    private function buildDefaultText(string $EventType, array $Message, string $RepositoryName, string $Alias): string
    {
        return "ℹ️ {$Alias} triggered a **{$EventType}** event "
             . (isset($Message['action']) ? "({$Message['action']}) " : "")
             . "on [{$RepositoryName}](https://github.com/{$RepositoryName}).";
    }

    /**
     * Compact Markdown excerpt of an issue/PR body for the chat message.
     * Returns '' when nothing readable is left after cleaning.
     */
    private static function bodyExcerpt(string $Body): string
    {
        $Text = self::cleanBody($Body);
        if (mb_strlen($Text) <= self::BODY_EXCERPT_CHARS) {
            return $Text;
        }
        $Text = mb_substr($Text, 0, self::BODY_EXCERPT_CHARS);
        // Don't leave a half-written link, code span or bold run at the cut —
        // Teams shows the raw markers and can mis-render whatever follows.
        $Text = (string)preg_replace('/!?\[[^\]]*(?:\]\([^)]*)?$/u', '', $Text);
        foreach (['```', '`', '**'] as $Marker) {
            if (substr_count($Text, $Marker) % 2 === 1) {
                $Text = substr($Text, 0, (int)strrpos($Text, $Marker));
            }
        }
        return rtrim($Text) . '…';
    }

    /**
     * Reduce a GitHub issue/PR body to plain Markdown that Teams renders cleanly.
     *
     * Bot-authored bodies (Dependabot, Mend) are mostly HTML scaffolding.
     * Teams shows an <img> as an image, and an HTML block left open by the
     * excerpt cut swallows every following line as raw text — including the
     * bullets the bot later appends under the message. So drop images,
     * comments and boilerplate footers, turn the HTML that carries meaning
     * into Markdown, and strip the rest.
     */
    private static function cleanBody(string $Text): string
    {
        $Text = str_replace(["\r\n", "\r"], "\n", $Text);
        $Text = (string)preg_replace('/<!--.*?-->/s', '', $Text);

        // Bot bodies put their boilerplate (Dependabot's command list, Mend's
        // promo) after the first horizontal rule.
        $Parts = preg_split('/\n[ \t]*\n[ \t]*(?:-{3,}|\*{3,}|_{3,})[ \t]*(?:\n|$)/', $Text, 2);
        if ($Parts !== false && trim($Parts[0]) !== '') {
            $Text = $Parts[0];
        }

        $Folds = implode('|', array_map(fn ($s) => preg_quote($s, '/'), self::DROPPED_DETAILS_SUMMARIES));
        $Text = (string)preg_replace('/<details>\s*<summary>\s*(?:' . $Folds . ')\s*<\/summary>.*?<\/details>/is', '', $Text);

        // Images — linked badges first so no empty [](…) link is left behind.
        $Text = (string)preg_replace('/\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)/', '', $Text);
        $Text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $Text);
        $Text = (string)preg_replace('/<img\b[^>]*>(?:\s*<\/img>)?/i', '', $Text);

        // Markdown comments ([//]: # (dependabot-automerge-start)) and boilerplate.
        $Text = (string)preg_replace('/^\[\/\/\]: #.*$/m', '', $Text);
        $Text = (string)preg_replace('/^Dependabot will resolve any conflicts with this PR.*$/m', '', $Text);

        // HTML that carries meaning → Markdown.
        $Wrap = fn (string $Marker) => function (array $m) use ($Marker): string {
            $Inner = trim($m[2]);
            return $Inner === '' ? '' : "{$Marker}{$Inner}{$Marker}";
        };
        $Text = (string)preg_replace_callback('/<(b|strong)\b[^>]*>(.*?)<\/\1>/is', $Wrap('**'), $Text);
        $Text = (string)preg_replace_callback('/<(i|em)\b[^>]*>(.*?)<\/\1>/is', $Wrap('_'), $Text);
        $Text = (string)preg_replace_callback('/<(code)\b[^>]*>(.*?)<\/\1>/is', $Wrap('`'), $Text);
        $Text = (string)preg_replace_callback('/<a\b[^>]*?\bhref\s*=\s*["\']?([^"\'\s>]+)["\']?[^>]*>(.*?)<\/a>/is', function (array $m): string {
            $Label = trim(strip_tags($m[2]));
            return $Label === '' ? '' : "[{$Label}]({$m[1]})";
        }, $Text);
        $Text = (string)preg_replace('/<li\b[^>]*>/i', "\n- ", $Text);
        $Text = (string)preg_replace('/<br\s*\/?>/i', "\n", $Text);
        // Block tags become paragraph breaks; other known tags are dropped.
        // Only named tags are touched so prose like "<dependency name>" survives.
        $Text = (string)preg_replace('/<\/?(?:p|div|details|summary|blockquote|h[1-6]|ul|ol|table|thead|tbody|tr|pre|hr)\b[^>]*>/i', "\n\n", $Text);
        $Text = (string)preg_replace('/<\/?(?:li|span|td|th|sup|sub|kbd|picture|source|a|b|strong|i|em|code)\b[^>]*>/i', '', $Text);
        $Text = (string)preg_replace('/\[\s*\]\([^)]*\)/', '', $Text);

        // Indentation only matters for nested list items.
        $Lines = array_map(function (string $Line): string {
            $Line = rtrim($Line);
            return preg_match('/^\s*(?:[-*+]|\d+\.)\s/', $Line) ? $Line : ltrim($Line);
        }, explode("\n", $Text));
        $Text = (string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $Lines));
        return trim($Text);
    }
}
