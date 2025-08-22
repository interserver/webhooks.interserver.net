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
    file_put_contents(__DIR__.'/../log/'.date('Ymd_His').'_'.$EventType.(isset($Message['action']) ? '_'.$Message['action'] : '').'_'.$User.'_'.str_replace(['/', '-', ' '], ['_', '_', '_'], $RepositoryName).'.json', json_encode($log, JSON_PRETTY_PRINT));
    if (empty($Message)) {
        throw new Exception('Empty message, not sending.');
    }


    switch ($EventType) {
        case 'issues':
            $Issue = $Message['issue'];
            $IssueUrl = $Issue['html_url'];
            $IssueTitle = $Issue['title'];
            $Action = $Message['action'];

            $ChatMsg = "🐛 [{$User}](https://github.com/{$User}) **{$Action}** issue [#{$Issue['number']} {$IssueTitle}]({$IssueUrl}) "
                     . "in [{$RepositoryName}](https://github.com/{$RepositoryName}).";

            if (!empty($Issue['body'])) {
                $ChatMsg .= "\n\n> " . substr($Issue['body'], 0, 200) . (strlen($Issue['body']) > 200 ? "…" : "");
            }

            $Msg['text'] = $ChatMsg;
            SendToChat('int-dev', $Msg);
            break;

        case 'pull_request':
            $PR = $Message['pull_request'];
            $Action = $Message['action'];
            $PRUrl = $PR['html_url'];
            $PRTitle = $PR['title'];

            $ChatMsg = "🔀 [{$User}](https://github.com/{$User}) **{$Action}** pull request "
                     . "[#{$PR['number']} {$PRTitle}]({$PRUrl}) "
                     . "in [{$RepositoryName}](https://github.com/{$RepositoryName}).";

            if (!empty($PR['body'])) {
                $ChatMsg .= "\n\n> " . substr($PR['body'], 0, 200) . (strlen($PR['body']) > 200 ? "…" : "");
            }

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg);
            break;

        case 'push':
            $Branch = isset($Message['ref']) ? str_replace('refs/heads/', '', $Message['ref']) : '';
            $CommitCount = isset($Message['commits']) ? count($Message['commits']) : 1;
            $Commits = [];

            if (!empty($Message['commits'])) {
                foreach ($Message['commits'] as $Commit) {
                    $Commits[] = "• [".substr($Commit['id'], 0, 7)."]({$Commit['url']}) _{$Commit['message']}_ "
                               . "by {$Commit['author']['name']}";
                }
            }

            $ChatMsg = "📦 {$Msg['alias']} pushed {$CommitCount} "
                     . ($CommitCount === 1 ? "commit" : "commits")
                     . " to [{$RepositoryName}](https://github.com/{$RepositoryName}) "
                     . "[`{$Branch}`](https://github.com/{$RepositoryName}/tree/{$Branch})";

            if (!empty($Commits)) {
                $ChatMsg .= "\n" . implode("\n", $Commits);
            }

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg);
            break;

        case 'check_suite':
        case 'check_run':
            $Status = $Message['check_suite']['conclusion'] ?? $Message['check_run']['conclusion'] ?? 'in_progress';
            $Name = $Message['check_suite']['app']['name'] ?? $Message['check_run']['name'];
            $Url = $Message['check_suite']['url'] ?? $Message['check_run']['html_url'];

            $Emoji = $Status === 'success' ? "✅" : ($Status === 'failure' ? "❌" : "⏳");
            $ChatMsg = "{$Emoji} Check **{$Name}** {$Status} "
                     . "for [{$RepositoryName}](https://github.com/{$RepositoryName}) "
                     . "([details]({$Url}))";

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg);
            break;

        case 'workflow_run':
        case 'workflow_job':
            $Workflow = $Message['workflow']['name'] ?? $Message['workflow_job']['name'] ?? "Workflow";
            $Status = $Message['workflow_run']['conclusion'] ?? $Message['workflow_job']['conclusion'] ?? "in_progress";
            $Url = $Message['workflow_run']['html_url'] ?? "#";
            $Emoji = $Status === 'success' ? "✅" : ($Status === 'failure' ? "❌" : "⏳");

            $ChatMsg = "{$Emoji} Workflow **{$Workflow}** {$Status} "
                     . "for [{$RepositoryName}](https://github.com/{$RepositoryName}) "
                     . "([view run]({$Url}))";

            $Msg['text'] = $ChatMsg;
            //SendToChat('notifications', $Msg);
            break;

        default:
            $ChatMsg = "ℹ️ {$Msg['alias']} triggered a **{$EventType}** event "
                     . (isset($Message['action']) ? "({$Message['action']}) " : "")
                     . "on [{$RepositoryName}](https://github.com/{$RepositoryName}).";

            $Msg['text'] = $ChatMsg;
            SendToChat('notifications', $Msg);
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

function SendToChat(string $Where, array $Payload) : bool
{
    global $chatChannels;
    $Url = $chatChannels['rocketchat'][$Where];
    $c = curl_init();
    curl_setopt_array($c, [
        CURLOPT_USERAGENT      => 'https://github.com/xPaw/GitHub-WebHook',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_URL            => $Url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($Payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($c);
    $Code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    //error_log('Rocket Chat HTTP ' . $Code . PHP_EOL);
    $c = curl_init();
    $Url = $chatChannels['teams'][$Where];
    curl_setopt_array($c, [
        CURLOPT_USERAGENT      => 'https://github.com/xPaw/GitHub-WebHook',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_URL            => $Url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'type' => 'message',
            'message' => $Payload['text']
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($c);
    $Code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);

    return $Code >= 200 && $Code < 300;
}
