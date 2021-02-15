<?php

declare(strict_types=1);

use danog\MadelineProto\Logger;
use danog\MadelineProto\Shutdown;

class BuiltinPlugin extends AbstractPlugin implements Plugin
{
    private BaseEventHandler $eh;
    private int              $totalUpdates;

    function __construct(BaseEventHandler $eh)
    {
        parent::__construct($eh);

        $this->eh = $eh;
        $this->totalUpdates = 0;
    }

    public function onStart(BaseEventHandler $eh): \Generator
    {
        $this->eh = $eh;

        // Send a startup notification and wipe it if configured so 
        $notif      = $this->getNotif();
        $notifState = substr($notif, 0, 2) === 'on';
        $notifAge   = strlen($notif) <= 3 ? 0 : intval(substr($notif, 3));
        $dest       = $eh->getRobotId();
        if ($notifState) {
            $nowstr = $eh->formatTime($eh->getHandlerUnserialized());
            $text = SCRIPT_INFO . ' started at ' . $nowstr . ' on ' . hostName() . ' using ' . $eh->getRobotName() . ' account.';
            $result = yield $eh->messages->sendMessage([
                'peer'    => $dest,
                'message' => $text
            ]);
            yield $eh->logger($text, Logger::ERROR);
            if ($notifAge > 0) {
                $msgid = $result['updates'][1]['message']['id'];
                $eh->callFork((function () use ($eh, $msgid, $notifAge) {
                    try {
                        yield $eh->sleep($notifAge);
                        yield $eh->messages->deleteMessages([
                            'revoke' => true,
                            'id'     => [$msgid]
                        ]);
                        yield $eh->logger('Robot\'s startup message is deleted.', Logger::ERROR);
                    } catch (\Exception $e) {
                        yield $eh->logger($e, Logger::ERROR);
                    }
                })());
            }
        }
    }

    public function __invoke(array $update, array $vars, BaseEventHandler $eh): \Generator
    {
        $this->totalUpdates += 1;

        if (!($vars['fromRobot'] && $vars['toRobot']) && !($vars['fromAdmin'] && $vars['toOffice'])) {
            return false;
        }
        if (!oneOf($update, 'NewMessage|EditMessage') || !hasText($update)) {
            return false;
        }

        if ($eh->newMessage($update)) {

            //Function: Finnish executing the Stop command.
            if ($vars['msgText'] === 'Robot is stopping ...') {
                if (Shutdown::removeCallback('restarter')) {
                    yield $eh->logger('Self-Restarter disabled.', Logger::ERROR);
                }
                yield $eh->logger('Robot stopped at ' . date('d H:i:s!'), Logger::ERROR);
                yield $eh->stop();
                return true;
            }

            //Function: Finnish executing the Restart command.
            if ($vars['msgText'] === 'Robot is restarting ...') {
                yield $eh->logger('Robot restarted at ' . date('d H:i:s!'), Logger::ERROR);
                yield $eh->restart();
                return true;
            }

            //Function: Finnish executing the Logout command.
            if ($vars['msgText'] === 'Robot is logging out ...') {
                yield $eh->logger('Robot logged out at ' . date('d H:i:s!'), Logger::ERROR);
                yield $eh->logout();
                return true;
            }
        }

        if (!hasText($update) || $update['_'] !== 'updateNewMessage') {
            return false;
        }
        if (!isset($vars['msgText'][0]) || strpos($vars['prefixes'], $vars['msgText'][0]) === false) {
            return false;
        }

        extract($vars);

        $msgFront = substr(\str_replace(array("\r", "\n"), '<br>', $msgText), 0, 60);
        yield $eh->logger(($vars['execute'] ? 'new: ' : 'old: ') . $msgFront, Logger::ERROR);

        $executed = false;
        switch ($fromRobot ? $verb : '') {
            case '':
                // Not a verb and/or not sent by an admin.
                break;
            case 'ping':
                yield $eh->messages->sendMessage([
                    'peer'            => $peer,
                    'reply_to_msg_id' => $msgId,
                    'message'         => 'Pong'
                ]);
                yield $eh->logger("Command '/ping' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                $executed = true;
                break;
        }

        switch ($executed ? '' : $verb) {
            case '':
                break;
            case 'help':
                $text = getHelpText($eh->getPrefixes());
                yield respond($eh, $peer, $msgId, $text);
                yield $eh->logger("Command '/help' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                break;
            case 'status':
                $peakMemUsage    = formatBytes(\getPeakMemory(), 3);
                $currentMemUsage = formatBytes(\getCurrentMemory(), 3);
                $memoryLimit     = ini_get('memory_limit');
                $memoryLimit     = $memoryLimit === '-1' || $memoryLimit === '0' ? 'MAX' : $memoryLimit;
                $sessionSize     = formatBytes(getFileSize($eh->getSessionName()), 3);
                $launch          = false; // yield \getPreviousLaunch($eh, LAUNCHES_FILE, SCRIPT_START_TIME);
                if ($launch) {
                    $lastStartTime      = strval($launch['time_start']);
                    $lastEndTime        = strval($launch['time_end']);
                    $lastLaunchMethod   = $launch['launch_method'];
                    $durationNano       = $lastEndTime - $lastStartTime;
                    $duration           = $lastEndTime ? \computeDuration($durationNano) : 'UNAVAILABLE';
                    $lastLaunchDuration = strval($duration);
                    $lastPeakMemory     = formatBytes($launch['memory_end']);
                } else {
                    $lastEndTime        = 'UNAVAILABLE';
                    $lastLaunchMethod   = 'UNAVAILABLE';
                    $lastLaunchDuration = 'UNAVAILABLE';
                    $lastPeakMemory     = 'UNAVAILABLE';
                }
                $notif = $this->getNotif();
                $notifState = substr($notif, 0, 2) === 'on' ? 'ON' : 'OFF';
                $notifAge   = $notifState === 'OFF' ? '' : (strlen($notif) <= 3 ? ' / Never wipe' : (' / Wipe after ' . substr($notif, 3) . ' secs'));
                $notifStr = "$notifState$notifAge";

                $status  = '<b>STATUS:</b>  (Script: ' . SCRIPT_INFO . ')<br>';
                $status .= "Host: " . hostname() . "<br>";
                $status .= "Robot's Account: " . $eh->getRobotName() . "<br>";
                $status .= "Robot's User-Id: $robotId<br>";
                $status .= "Session Age: "      . computeDuration($eh->getSessionCreated()) .      "<br>";
                $status .= "Script Age: "       . computeDuration($eh->getScriptStarted()) .       "<br>";
                $status .= "API Instance Age: " . computeDuration($eh->getHandlerUnserialized()) . "<br>";
                $status .= "Peak Memory: $peakMemUsage<br>";
                $status .= "Current Memory: $currentMemUsage<br>";
                $status .= "Allowed Memory: $memoryLimit<br>";
                $status .= 'CPU: '         . getCpuUsage() . '<br>';
                $status .= "Session Size: $sessionSize<br>";
                $status .= 'Time: ' . $eh->getZone() . ' ' . $eh->formatTime() . '<br>';
                $status .= 'Updates Processed: ' . $this->totalUpdates . '<br>';
                //$status .= 'Loop State: ' . ($eh->getLoopState() ? 'ON' : 'OFF') . '<br>';
                $status .= 'Notification: ' . $notifStr . PHP_EOL;
                //$status .= 'Launch Method: ' . getLaunchMethod() . '<br>';
                //$status .= 'Previous Stop Time: '       . $lastEndTime . '<br>';
                //$status .= 'Previous Launch Method: '   . $lastLaunchMethod . '<br>';
                //$status .= 'Previous Launch Duration: ' . $lastLaunchDuration . '<br>';
                //$status .= 'Previous Peak Memory: '     . $lastPeakMemory . '<br>';
                yield respond($eh, $peer, $msgId, $status);
                yield $eh->logger("Command '/status' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                break;
            case 'stats':
                $text   = "Preparing statistics ....";
                $result = yield respond($eh, $peer, $msgId, $text);
                $resMsgId = $eh->getEditMessage() ? $result[0]['message']['id'] : $result['updates'][0]['id'];
                unset($result);
                $response = yield $eh->contacts->getContacts([]);
                $totalCount  = count($response['users']);
                $mutualCount = 0;
                foreach ($response['users'] as $user) {
                    $mutualCount += ($user['mutual_contact'] ?? false) ? 1 : 0;
                }
                unset($response);
                $totalDialogsOut = 0;
                $peerCounts   = [
                    'user' => 0, 'bot' => 0, 'basicgroup' => 0, 'supergroup' => 0, 'channel' => 0,
                    'chatForbidden' => 0, 'channelForbidden' => 0
                ];
                $params = [];
                yield visitAllDialogs(
                    $eh,
                    $params,
                    function (
                        $mp,
                        int    $totalDialogs,
                        int    $index,
                        int    $botapiId,
                        string $subtype,
                        string $name,
                        ?array $userOrChat,
                        array  $message
                    )
                    use (&$totalDialogsOut, &$peerCounts): void {
                        $totalDialogsOut = $totalDialogs;
                        $peerCounts[$subtype] += 1;
                    }
                );
                $stats  = '<b>STATISTICS</b>  (Script: ' . SCRIPT_INFO . ')<br>';
                $stats .= "Robot's Account: " . $eh->getRobotName() . "<br>";
                $stats .= "Total Dialogs: $totalDialogsOut<br>";
                $stats .= "Users: {$peerCounts['user']}<br>";
                $stats .= "Bots: {$peerCounts['bot']}<br>";
                $stats .= "Basic groups: {$peerCounts['basicgroup']}<br>";
                $stats .= "Forbidden Basic groups: {$peerCounts['chatForbidden']}<br>";
                $stats .= "Supergroups: {$peerCounts['supergroup']}<br>";
                $stats .= "Channels: {$peerCounts['channel']}<br>";
                $stats .= "Forbidden Supergroups or Channels: {$peerCounts['channelForbidden']}<br>";
                $stats .= "Total Contacts: $totalCount<br>";
                $stats .= "Mutual Contacts: $mutualCount";
                yield respond($eh, $peer, $resMsgId, $stats, true);
                break;
            case 'crash':
                yield $eh->logger("Purposefully crashing the script....", Logger::ERROR);
                $e = new \ErrorException('Artificial exception generated for testing the robot.');
                //yield $this->echo($e->getTraceAsString($e) . PHP_EOL);
                throw $e;
            case 'maxmem':
                $arr = array();
                try {
                    for ($i = 1;; $i++) {
                        $arr[] = md5(strvAL($i));
                    }
                } catch (\Exception $e) {
                    unset($arr);
                    $text = $e->getMessage();
                    yield $eh->logger($text, Logger::ERROR);
                }
                break;
            case 'notif':
                $params = $command['params'];
                $param1 = strtolower($params[0] ?? '');
                $paramsCount = count($params);
                if (
                    ($param1  !== 'on'  && $param1 !== 'off' && $param1 !== 'state') ||
                    ($param1  === 'on'  && $paramsCount !== 1 && $paramsCount !== 2) ||
                    ($param1  === 'on'  && $paramsCount === 2 && !ctype_digit($params['1'])) ||
                    (($param1 === 'off' || $param1 === 'state') && $paramsCount !== 1)
                ) {
                    $text = "The notif argument must be 'off', 'on', 'on 123', or 'state'.";
                    yield respond($eh, $peer, $msgId, $text);
                    break;
                }
                if ($param1 === 'on') {
                    $notification = 'on' . (!isset($params[1]) ? '' : (' ' . $params[1]));
                    yield $eh->echo("Notification: '$notification'" . PHP_EOL);
                    $this->setNotif($notification);
                } elseif ($param1 === 'off') {
                    $this->setNotif('off');
                }
                $notif = $this->getNotif();
                $notifState = substr($notif, 0, 2) === 'on' ? 'ON' : 'OFF';
                $notifAge   = strlen($notif) <= 3 ? '' : (' / ' . substr($notif, 3) . ' secs');
                $text = "The notif is $notifState$notifAge";
                yield respond($eh, $peer, $msgId, $text);
                break;
            case 'restart':
                if (PHP_SAPI === 'cli') {
                    $text = "Command '/restart' is only avaiable under webservers. Ignored!";
                    yield respond($eh, $peer, $msgId, $text);
                    yield $eh->logger("Command '/restart' is only avaiable under webservers. Ignored!  " . date('d H:i:s!'), Logger::ERROR);
                    break;
                }
                $text = 'Robot is restarting ...';
                yield $eh->logger($text, Logger::ERROR);
                yield respond($eh, $peer, $msgId, $text);
                $eh->setStopReason('restart');
                //$eh->restart();
                break;
            case 'logout':
                $text = 'Robot is logging out ...';
                yield $eh->logger($text, Logger::ERROR);
                yield respond($eh, $peer, $msgId, $text);
                $eh->setStopReason('logout');
                //$eh->logout();
                break;
            case 'stop':
                $text = 'Robot is stopping ...';
                yield respond($eh, $peer, $msgId, $text);
                yield $eh->logger($text, Logger::ERROR);
                $eh->setStopReason($verb);
                break;
            default:
                $text = "Invalid command: '$msgText'";
                yield respond($eh, $peer, $msgId, $text);
                break;
        } // enf of the command switch
    }

    public function getNotif(): string
    {
        $saved = $this->eh->__get('notification');
        $saved = $saved ?? 'off';
        return $saved;
    }
    public function setNotif(string $notification): void
    {
        if ($notification === '') throw new ErrorException("Invalid parameters: '$notification'");
        $this->eh->__set('notification', $notification);
    }
}
