<?php
declare(strict_types=1);

class GithubMessageBuilder
{
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
            $Labels = array_map(fn($l) => "`{$l['name']}`", $Issue['labels']);
            $ChatMsg .= " " . implode(' ', $Labels);
        }

        if ($Action === 'closed' && !empty($Issue['state_reason'])) {
            $ChatMsg .= " _(reason: {$Issue['state_reason']})_";
        }

        if ($Action === 'edited' && !empty($Message['changes'])) {
            $Changed = array_keys($Message['changes']);
            $ChatMsg .= " _(changed: " . implode(', ', $Changed) . ")_";
        }

        if (!empty($Issue['body'])) {
            $ChatMsg .= "\n\n> " . substr($Issue['body'], 0, 500) . (strlen($Issue['body']) > 500 ? "…" : "");
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
            $ChatMsg .= " (`{$HeadBranch}` → `{$BaseBranch}`)";
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

        if (!empty($PR['body'])) {
            $ChatMsg .= "\n\n> " . substr($PR['body'], 0, 500) . (strlen($PR['body']) > 500 ? "…" : "");
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
                $Commits[] = "• [" . substr($Commit['id'], 0, 7) . "]({$Commit['url']}) _{$CommitMsg}_{$ByAuthor}{$FileInfo}";
            }
        }

        $ChatMsg = "📦 {$Alias} pushed {$CommitCount} "
                 . ($CommitCount === 1 ? "commit" : "commits")
                 . " to [{$RepositoryName}](https://github.com/{$RepositoryName}) "
                 . "[`{$Branch}`](https://github.com/{$RepositoryName}/tree/{$Branch})";

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
        $Status = $Message['check_suite']['conclusion'] ?? $Message['check_run']['conclusion'] ?? 'in_progress';
        $Name = $Message['check_suite']['app']['name'] ?? $Message['check_run']['name'];
        $Url = $Message['check_suite']['url'] ?? $Message['check_run']['html_url'];
        $Branch = $Message['check_suite']['head_branch'] ?? $Message['check_run']['check_suite']['head_branch'] ?? '';
        $CommitMsg = $Message['check_suite']['head_commit']['message'] ?? $Message['check_run']['check_suite']['head_commit']['message'] ?? '';

        $Emoji = $Status === 'success' ? "✅" : ($Status === 'failure' ? "❌" : "⏳");
        $ChatMsg = "{$Emoji} Check **{$Name}** {$Status} "
                 . "for [{$RepositoryName}](https://github.com/{$RepositoryName})";
        if ($Branch) {
            $ChatMsg .= " on `{$Branch}`";
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
            $ChatMsg .= " on `{$Branch}`";
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

    private function buildDefaultText(string $EventType, array $Message, string $RepositoryName, string $Alias): string
    {
        return "ℹ️ {$Alias} triggered a **{$EventType}** event "
             . (isset($Message['action']) ? "({$Message['action']}) " : "")
             . "on [{$RepositoryName}](https://github.com/{$RepositoryName}).";
    }
}
