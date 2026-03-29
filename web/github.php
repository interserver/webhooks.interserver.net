<?php
declare(strict_types=1);

/**
* @link https://docs.github.com/en/developers/webhooks-and-events/webhooks/webhook-events-and-payloads
* @link https://github.com/github/docs/blob/main/content/developers/webhooks-and-events/webhooks/webhook-events-and-payloads.md
* @link https://github.com/organizations/interserver/settings/hooks/359889086?tab=deliveries
*
*/

header('Content-Type: text/plain; charset=utf-8');
// set default response code
http_response_code(500);

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/GithubWebhook.php';
require __DIR__ . '/../src/IgnoredEventException.php';
require __DIR__ . '/../src/NotImplementedException.php';

$Hook = new GitHubWebHook();
try {
    if (!$Hook->ValidateHubSignature(GITHUB_WEBHOOKS_SECRET)) {
        throw new Exception('Secret validation failed.');
    }
    $Hook->ProcessRequest();
    $RepositoryName = $Hook->GetFullRepositoryName();
    $EventType = $Hook->GetEventType();
    // format message
    //$Converter = new Converter($Hook->GetEventType(), $Hook->GetPayload());
    //$Message = $Converter->GetEmbed();
    $Message = $Hook->GetPayload();
    $log = ['repo' => $RepositoryName, 'event' => $EventType, 'data' => $Message];
    $Msg = [];
    if (isset($Message[$EventType]['app']['name'])) {
        $User = $Message[$EventType]['app']['name'];
    } else {
        $User = $Message['sender']['login'];
    }
    if (isset($Message[$EventType]['app']['owner']['avatar_url'])) {
        $Msg['avatar'] = $Message[$EventType]['app']['owner']['avatar_url'];
    } elseif (isset($Message['avatar_url'])) {
        $Msg['avatar'] = $Message['avatar_url'];
    } else {
        $Msg['avatar'] = $Message['sender']['avatar_url'];
    }
    if (isset($Message['head_commit'])) {
        $Msg['alias'] = $Message['head_commit']['author']['name'];
    } elseif (isset($Message['commits']) && isset($Message['commits'][0]['author']['name'])) {
        $Msg['alias'] = $Message['commits'][0]['author']['name'];
    } elseif (isset($Message['commit']) && isset($Message['commit']['commit']['author']['name'])) {
        $Msg['alias'] = $Message['commit']['commit']['author']['name'];
    }
    file_put_contents(__DIR__.'/../log/'.date('Ymd_His').'_'.$EventType.(isset($Message['action']) ? '_'.$Message['action'] : '').'_'.$User.'_'.str_replace(['/', '-', ' '], ['_', '_', '_'], $RepositoryName).'.json', json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |  JSON_UNESCAPED_SLASHES));
    if (empty($Message)) {
        throw new Exception('Empty message, not sending.');
    }

    //http_response_code(200);
    //exit;



    $useRC = true;
    $useTeams = true;
    switch ($EventType) {
        case 'issues':
		if (in_array($RepositoryName, ['interserver/mailbaby-api-samples', 'detain/interserver-api-samples'])) {
			$useTeams = false;
			break;
		}
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

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;

        case 'pull_request':
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

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;

        case 'push':
            $Branch = isset($Message['ref']) ? str_replace('refs/heads/', '', $Message['ref']) : '';
            $CommitCount = isset($Message['commits']) ? count($Message['commits']) : 1;
            $Commits = [];

            if (!empty($Message['commits'])) {
                foreach ($Message['commits'] as $Commit) {
                    $CommitMsg = strtok($Commit['message'], "\n");
                    $FileCounts = [];
                    if (!empty($Commit['added'])) $FileCounts[] = '+' . count($Commit['added']);
                    if (!empty($Commit['modified'])) $FileCounts[] = '~' . count($Commit['modified']);
                    if (!empty($Commit['removed'])) $FileCounts[] = '-' . count($Commit['removed']);
                    $FileInfo = !empty($FileCounts) ? ' _(' . implode(' ', $FileCounts) . ' files)_' : '';
                    $Commits[] = "• [" . substr($Commit['id'], 0, 7) . "]({$Commit['url']}) _{$CommitMsg}_ "
                               . "by {$Commit['author']['name']}{$FileInfo}";
                }
            }

            $ChatMsg = "📦 {$Msg['alias']} pushed {$CommitCount} "
                     . ($CommitCount === 1 ? "commit" : "commits")
                     . " to [{$RepositoryName}](https://github.com/{$RepositoryName}) "
                     . "[`{$Branch}`](https://github.com/{$RepositoryName}/tree/{$Branch})";

            if (!empty($Message['compare'])) {
                $ChatMsg .= " ([compare]({$Message['compare']}))";
            }

            if (!empty($Commits)) {
                $ChatMsg .= "\n" . implode("\n", $Commits);
            }

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;

        case 'check_suite':
        case 'check_run':
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

            $Msg['text'] = $ChatMsg;
            //SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;

        case 'workflow_run':
        case 'workflow_job':
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

            $Msg['text'] = $ChatMsg;
            //SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;
	case 'gollum':
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
                $ChatMsg = "📝 [{$User}](https://github.com/{$User}) updated {$PageCount} wiki "
                         . ($PageCount === 1 ? "page" : "pages")
                         . " on [{$RepositoryName}](https://github.com/{$RepositoryName}):\n"
                         . implode("\n", $PageLines);
            } else {
                $ChatMsg = "📝 [{$User}](https://github.com/{$User}) updated the wiki "
                         . "on [{$RepositoryName}](https://github.com/{$RepositoryName}).";
            }
            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;

        default:
            $ChatMsg = "ℹ️ {$Msg['alias']} triggered a **{$EventType}** event "
                     . (isset($Message['action']) ? "({$Message['action']}) " : "")
                     . "on [{$RepositoryName}](https://github.com/{$RepositoryName}).";

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg, $useRC, $useTeams);
            break;
    }
    /*
    foreach($githubWebhooks as $Event => $Repos) {
        if (!WildMatch($EventType, $Event))
        foreach ($Repos as $Repo => $SendTargets) {
            if (!WildMatch($RepositoryName, $Repo))
                continue;
            foreach($SendTargets as $ChannelName) {
                SendToChat($ChannelName, $Message);
            }
        }
    }
    */
    //error_log('GitHub Hook '.$EventType.' on '.$RepositoryName.' called');
    http_response_code(202);
} catch (IgnoredEventException $e) {
    http_response_code(200);
    error_log('This GitHub event is ignored.');
} catch (NotImplementedException $e) {
    http_response_code(501);
    error_log('Unsupported GitHub event: ' . $e->EventName);
} catch (Exception $e) {
    error_log('Exception: ' . $e->getMessage() . PHP_EOL);
}

function WildMatch(string $string, string $expression) : bool
{
    if (strpos($expression, '*') === false) {
        return strcmp($expression, $string) === 0;
    }
    $expression = preg_quote($expression, '/');
    $expression = str_replace('\*', '.*', $expression);
    return preg_match('/^' . $expression . '$/', $string) === 1;
}

function SendToChat(string $Where, array $Payload, bool $useRC = true, bool $useTeams = true) : bool
{
    error_log("Sending Payload ".json_encode($Payload)." to {$Where}");
    global $chatChannels;
    if ($useRC === true) {
        $Url = $chatChannels['rocketchat'][$Where];
        $c = curl_init();
        curl_setopt_array($c, [
        CURLOPT_USERAGENT      => 'https://github.com/xPaw/GitHub-WebHook',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_URL            => $Url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($Payload, JSON_UNESCAPED_UNICODE |  JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
        ]);
        $out = curl_exec($c);
	error_log("{$Url} Out: {$out}");
        $Code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        error_log('Rocket Chat HTTP ' . $Code . PHP_EOL);
    }
    if ($useTeams === true) {
        $c = curl_init();
        $Url = $chatChannels['teams'][$Where];
        curl_setopt_array($c, [
        CURLOPT_USERAGENT      => 'https://github.com/xPaw/GitHub-WebHook',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_URL            => $Url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'type' => 'message',
            'message' => $Payload['text']
        ], JSON_UNESCAPED_UNICODE |  JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
        ]);
       curl_exec($c);
        $Code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
    }

    return $Code >= 200 && $Code < 300;
}
