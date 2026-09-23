<?php
declare(strict_types=1);
/**
 * bin/telegram_polling.php - Inbound receiver DAEMON (backend lifecycle §2).
 * Thuoc TelegramInboundSupervisor, KHONG thuoc UI/pairing/chat (§24-§25).
 * Chi dung khi: app mo + connection ENABLED (supervisor dam bao) hoac setup.
 * Dung khi: app shutdown / user Ngat ket noi / AUTH / CONFLICT can xu ly.
 *
 * Loop vinh vien (§6): long poll 25s -> enqueue queue (bounded 1000 §12) ->
 * dispatcher drain (error boundary moi update §37) -> ack offset sau enqueue (§13).
 * Empty [] la binh thuong (§7). Backoff 1/2/5/10/30s (§18). 409/401 rieng (§21-23).
 */
set_time_limit(0);
ini_set('max_execution_time', '0');
require_once __DIR__ . '/../sync/TelegramGateway.php';
require_once __DIR__ . '/../sync/TelegramOffset.php';
require_once __DIR__ . '/../sync/CommandRouter.php';
require_once __DIR__ . '/../sync/ConversationService.php';
require_once __DIR__ . '/../sync/ConfirmationService.php';
require_once __DIR__ . '/../sync/TelegramSetup.php';
require_once __DIR__ . '/../sync/TgBotStore.php';
require_once __DIR__ . '/../sync/TelegramCounters.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.tg_polling.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.tg_polling.pid', (string)getmypid());

const TG_MAX_QUEUE = 1000;
$workerStartedAt = time();
TelegramGateway::heartbeat(null, null, 'STARTING');
TelegramGateway::setWorkerStart($workerStartedAt);

function tg_conn_id(): int
{
    try {
        $conn = TgBotStore::primary();
        return $conn ? (int)$conn['id'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function tg_conn_touch(array $patch): void
{
    try {
        $cid = tg_conn_id();
        if ($cid <= 0) return;
        $sets = [];
        $params = [];
        foreach ($patch as $k => $v) {
            $sets[] = "$k=?";
            $params[] = $v;
        }
        if (!$sets) return;
        $params[] = $cid;
        db()->prepare('UPDATE telegram_bot_connections SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
    } catch (Throwable $e) {
    }
}

try {
    SyncLogger::info('telegram', '[TG POLL START] offset=' . TelegramOffset::get());
} catch (Throwable $e) {
}
$backoff = 1;
$backoffSteps = [1, 2, 5, 10, 30];
$bi = 0;
// Self-test mode: --selftest xu ly 1 update gia qua queue+dispatcher roi exit
if (in_array('--selftest', $argv ?? [], true)) {
    $fake = ['update_id' => 999001, 'message' => [
        'message_id' => 777001, 'date' => time(),
        'chat' => ['id' => 'testchat', 'type' => 'private'],
        'from' => ['id' => 'testuser', 'username' => 'tester'],
        'text' => 'hello selftest']];
    tg_enqueue($fake);
    tg_drain();
    $left = 0;
    try {
        $left = (int)db()->query("SELECT COUNT(*) FROM tg_update_queue WHERE status='QUEUED'")->fetchColumn();
    } catch (Throwable $e) {
    }
    echo "SELFTEST queue_left=$left\n";
    $row = null;
    try {
        $st = db()->prepare("SELECT direction, text FROM tg_messages WHERE chat_id='testchat' ORDER BY id DESC LIMIT 1");
        $st->execute();
        $row = $st->fetch();
    } catch (Throwable $e) {
    }
    echo 'SELFTEST stored=' . json_encode($row) . "\n";
    $nBefore = 0;
    try {
        $nBefore = (int)db()->query("SELECT COUNT(*) FROM tg_messages WHERE chat_id='testchat'")->fetchColumn();
    } catch (Throwable $e) {
    }
    // Replay -> dedup, khong bubble moi
    tg_enqueue($fake);
    tg_drain();
    $n = 0;
    try {
        $n = (int)db()->query("SELECT COUNT(*) FROM tg_messages WHERE chat_id='testchat'")->fetchColumn();
    } catch (Throwable $e) {
    }
    echo "SELFTEST rows_before_replay=$nBefore rows_after_replay=$n\n";
    db()->exec("DELETE FROM tg_update_queue WHERE update_id=999001");
    db()->exec("DELETE FROM tg_messages WHERE chat_id='testchat'");
    db()->exec("DELETE FROM tg_updates WHERE update_id=999001");
    db()->exec("DELETE FROM app_commands WHERE chat_id='testchat'");
    exit($n === $nBefore && $nBefore > 0 ? 0 : 1);
}
while (true) {
    try {
        // Chi dung khi enabled/setup; nguoc lai idle (supervisor se spawn khi can)
        if (!TelegramGateway::shouldPoll()) {
            TelegramGateway::heartbeat('', null, 'STOPPED');
            sleep(10);
            continue;
        }
        $transport = TelegramGateway::transport();
        $offset = TelegramOffset::get();
        TelegramGateway::pollStarted();
        tg_conn_touch(['runtime_state' => 'LISTENING', 'last_poll_at' => date('Y-m-d H:i:s')]);
        try {
            $t0 = microtime(true);
            $updates = $transport->receive($offset, 25);
            $dur = round(microtime(true) - $t0, 1);
            $bi = 0;
            $backoff = 1;
            TelegramGateway::heartbeat(null, null, 'LISTENING', true);
            tg_conn_touch(['runtime_state' => 'LISTENING',
                'last_poll_at' => date('Y-m-d H:i:s'),
                'last_poll_success_at' => date('Y-m-d H:i:s'),
                'last_error_code' => null]);
            try {
                SyncLogger::debug('telegram', '[TG POLL OK] updates=' . count($updates) . ' duration=' . $dur . 's');
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'poll_conflict') {
                // 409: khong retry storm; giu state, cho 60s (§21)
                TelegramGateway::heartbeat('TELEGRAM_POLLING_CONFLICT', null, 'CONFLICT');
                tg_conn_touch(['runtime_state' => 'CONFLICT', 'last_error_code' => 'CONFLICT',
                    'last_error_at' => date('Y-m-d H:i:s')]);
                try {
                    SyncLogger::warn('telegram', '[TG POLL] 409 CONFLICT (tien trinh khac)');
                } catch (Throwable $e2) {
                }
                sleep(60);
                continue;
            }
            if ($msg === 'poll_unauthorized') {
                TelegramGateway::heartbeat('poll_unauthorized', null, 'AUTH_ERROR');
                tg_conn_touch(['runtime_state' => 'AUTH_ERROR', 'last_error_code' => 'AUTH_ERROR',
                    'last_error_at' => date('Y-m-d H:i:s')]);
                try {
                    SyncLogger::error('telegram', '[TG POLL] 401 AUTH_ERROR, stop polling');
                } catch (Throwable $e2) {
                }
                sleep(60);
                continue;
            }
            // Temporary network: backoff 1/2/5/10/30 (§18)
            $delay = $backoffSteps[min($bi, count($backoffSteps) - 1)];
            $bi++;
            TelegramGateway::heartbeat($msg, null, 'RECONNECTING');
            tg_conn_touch(['runtime_state' => 'RECONNECTING', 'last_error_code' => 'NETWORK',
                'last_error_at' => date('Y-m-d H:i:s')]);
            try {
                SyncLogger::warn('telegram', '[TG RECONNECT] reason=NETWORK attempt=' . $bi . ' delay=' . $delay . 's');
            } catch (Throwable $e2) {
            }
            sleep($delay);
            continue;
        }
        // Enqueue + dispatch (error boundary moi update §37)
        foreach ($updates as $u) {
            $uid = (int)($u['update_id'] ?? 0);
            try {
                tg_enqueue($u);
            } catch (Throwable $e) {
                try {
                    SyncLogger::warn('telegram', '[TG UPDATE] enqueue loi update_id=' . $uid);
                } catch (Throwable $e2) {
                }
            }
            // Ack SAU enqueue thanh cong (§13)
            if ($uid > 0) {
                TelegramOffset::set($uid + 1);
                tg_conn_touch(['last_update_id' => $uid]);
            }
        }
        tg_drain();
        TelegramGateway::heartbeat(null, date('Y-m-d H:i:s'));
        ConfirmationService::sweep();
    } catch (Throwable $e) {
        TelegramGateway::heartbeat('worker_error', null, 'ERROR');
        try {
            SyncLogger::error('telegram', '[TG POLL] unexpected: ' . mb_substr($e->getMessage(), 0, 150));
        } catch (Throwable $e2) {
        }
        sleep(5);
    }
}

/** Enqueue raw update (bounded 1000, unique) (§10-§12, §15). */
function tg_enqueue(array $u): void
{
    $uid = (int)($u['update_id'] ?? 0);
    if ($uid <= 0) return;
    $cid = tg_conn_id();
    try {
        $st = db()->prepare('INSERT IGNORE INTO tg_update_queue (connection_id, update_id, update_json, status, created_at)
            VALUES (?,?,?, "QUEUED", NOW())');
        $st->execute([$cid, $uid, json_encode($u, JSON_UNESCAPED_UNICODE)]);
        if ($st->rowCount() === 0) {
            TelegramCounters::bump('in_dedup'); // update nhan lai (§15)
            return;
        }
        // Bounded: qua 1000 -> xoa cu nhat + CRITICAL (§12)
        $n = (int)db()->query('SELECT COUNT(*) FROM tg_update_queue WHERE status=\'QUEUED\'')->fetchColumn();
        if ($n > TG_MAX_QUEUE) {
            db()->exec('DELETE FROM tg_update_queue WHERE status=\'QUEUED\' ORDER BY id ASC LIMIT ' . ($n - TG_MAX_QUEUE));
            try {
                SyncLogger::error('telegram', '[TG QUEUE] overflow, dropped oldest (depth was ' . $n . ')');
            } catch (Throwable $e2) {
            }
        }
    } catch (Throwable $e) {
        throw $e;
    }
}

/** Dispatcher: drain queue theo thu tu, loi 1 update khong giet loop (§11, §37, §39). */
function tg_drain(): void
{
    try {
        $rows = db()->query("SELECT * FROM tg_update_queue WHERE status='QUEUED' ORDER BY id ASC LIMIT 50")->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    foreach ($rows as $row) {
        $qid = (int)$row['id'];
        try {
            db()->prepare("UPDATE tg_update_queue SET status='PROCESSING' WHERE id=? AND status='QUEUED'")->execute([$qid]);
            $u = json_decode((string)($row['update_json'] ?? ''), true);
            if (is_array($u)) handle_update($u);
            db()->prepare("UPDATE tg_update_queue SET status='DONE' WHERE id=?")->execute([$qid]);
        } catch (Throwable $e) {
            try {
                SyncLogger::warn('telegram', '[TG UPDATE] processing failure id=' . $qid);
                db()->prepare("UPDATE tg_update_queue SET status='FAILED' WHERE id=?")->execute([$qid]);
            } catch (Throwable $e2) {
            }
        }
    }
    // Don DONE cu (giu 200 ban ghi gan nhat de audit)
    try {
        db()->exec("DELETE FROM tg_update_queue WHERE status IN ('DONE','FAILED') AND id NOT IN
            (SELECT id FROM (SELECT id FROM tg_update_queue ORDER BY id DESC LIMIT 200) t)");
    } catch (Throwable $e) {
    }
}

function handle_update(array $u): void
{
    $uid = (int)($u['update_id'] ?? 0);
    if ($uid > 0 && CommandRouter::seenUpdate($uid)) {
        TelegramCounters::bump('in_dedup');
        return;
    }
    // Dev log (§14): khong sensitive
    try {
        $kind = isset($u['callback_query']) ? 'callback' : (isset($u['message']) ? 'message' : 'other');
        $m = $u['message'] ?? $u['callback_query']['message'] ?? [];
        $ct = (string)(($m['chat'] ?? [])['type'] ?? '?');
        $cid = '***' . substr((string)(($m['chat'] ?? [])['id'] ?? ''), -4);
        $fr = $u['message']['from'] ?? $u['callback_query']['from'] ?? [];
        $fuid = '***' . substr((string)($fr['id'] ?? ''), -4);
        $txt = trim((string)(($u['message'] ?? [])['text'] ?? ''));
        $cmd = $txt !== '' ? (explode(' ', $txt)[0] ?? '') : (string)($u['callback_query']['data'] ?? '');
        SyncLogger::debug('telegram', '[TG UPDATE] update_id=' . $uid . ' type=' . $kind
            . ' chat_type=' . $ct . ' chat_id=' . $cid . ' user_id=' . $fuid
            . ' text_command=' . mb_substr($cmd, 0, 40));
    } catch (Throwable $e) {
    }
    if (isset($u['callback_query']) && is_array($u['callback_query'])) {
        handle_callback($u['callback_query']);
        return;
    }
    $msg = $u['message'] ?? null;
    if (!is_array($msg)) return;
    $chatId = (string)($msg['chat']['id'] ?? '');
    $userId = (string)($msg['from']['id'] ?? '');
    $text = trim((string)($msg['text'] ?? ''));
    if ($chatId === '' || $text === '') return;
    $msgDate = (int)($msg['date'] ?? 0);
    $isCmd = str_starts_with($text, '/');
    $testMode = get_setting('tg_chat_test_mode', '1') !== '0';
    $cmdMode = get_setting('tg_chat_command_mode', '1') === '1';
    $msgType = $isCmd ? ConversationService::T_COMMAND : ConversationService::T_TEXT;
    if (($testMode || !$cmdMode) && $isCmd && stripos($text, '/pair') !== 0) {
        $msgType = ConversationService::T_TEXT;
    }
    ConversationService::log(ConversationService::IN, $chatId, $text,
        ['user_id' => $userId, 'message_id' => (string)($msg['message_id'] ?? ''),
            'telegram_message_id' => (string)($msg['message_id'] ?? ''),
            'telegram_update_id' => $uid, 'type' => $msgType]);
    tg_conn_touch(['last_inbound_at' => date('Y-m-d H:i:s')]);
    require_once __DIR__ . '/../sync/PermissionService.php';
    $chk = PermissionService::check($chatId, $userId);
    if (empty($chk['ok']) && ($msg['chat']['type'] ?? '') === 'private') {
        $setup = TelegramSetup::onPrivateMessage($msg, $uid);
        if (!empty($setup['asked_confirm']) || TelegramSetup::active() !== null) {
            return;
        }
    }
    if ($msgType === ConversationService::T_TEXT && $isCmd) {
        return;
    }
    // Stale command safety (§57): command cu hon max_age -> EXPIRED, khong execute
    if ($isCmd && $msgType === ConversationService::T_COMMAND && $msgDate > 0) {
        $maxAge = max(60, (int)get_setting('tg_command_max_age', '180'));
        if ((time() - $msgDate) > $maxAge) {
            ConversationService::log(ConversationService::OUT, $chatId, '⌛ Lệnh đã hết hạn, vui lòng gửi lại.',
                ['type' => ConversationService::T_COMMAND]);
            try {
                require_once __DIR__ . '/../sync/TelegramGateway.php';
                TelegramGateway::sendMessage($chatId, '⌛ Lệnh đã hết hạn, vui lòng gửi lại.');
            } catch (Throwable $e) {
            }
            try {
                SyncLogger::warn('telegram', '[TG UPDATE] stale command expired update_id=' . $uid);
            } catch (Throwable $e) {
            }
            return;
        }
    }
    $reply = CommandRouter::route($text, 'TELEGRAM', $chatId, $userId);
    send_reply($chatId, $reply, $text);
}

function handle_callback(array $cb): void
{
    $cbId = (string)($cb['id'] ?? '');
    $data = (string)($cb['data'] ?? '');
    $chatId = (string)($cb['message']['chat']['id'] ?? '');
    $userId = (string)($cb['from']['id'] ?? '');
    if ($data === '' || $chatId === '') {
        if ($cbId !== '') TelegramGateway::answerCallback($cbId);
        return;
    }
    TelegramGateway::answerCallback($cbId);
    if (str_starts_with($data, 'setup:confirm:')) {
        $sid = substr($data, 15);
        $r = TelegramSetup::confirm($sid, $chatId, $userId);
        ConversationService::log(ConversationService::OUT, $chatId, (string)($r['text'] ?? ''));
        TelegramGateway::sendMessage($chatId, (string)($r['text'] ?? ''));
        return;
    }
    if (str_starts_with($data, 'setup:reject:')) {
        $sid = substr($data, 14);
        $r = TelegramSetup::reject($sid, $chatId, $userId);
        ConversationService::log(ConversationService::OUT, $chatId, (string)($r['text'] ?? ''));
        TelegramGateway::sendMessage($chatId, (string)($r['text'] ?? ''));
        return;
    }
    if (str_starts_with($data, 'confirm:')) {
        $id = substr($data, 8);
        $r = CommandRouter::confirm($id, $chatId, $userId);
        send_reply($chatId, $r, '[confirm]');
    } elseif (str_starts_with($data, 'cancel:')) {
        $id = substr($data, 7);
        $r = CommandRouter::cancelConfirm($id, $chatId);
        send_reply($chatId, $r, '[cancel]');
    } elseif (str_starts_with($data, 'cmd:')) {
        $raw = '/' . substr($data, 4);
        ConversationService::log(ConversationService::IN, $chatId, $raw, ['user_id' => $userId]);
        $reply = CommandRouter::route($raw, 'TELEGRAM', $chatId, $userId);
        send_reply($chatId, $reply, $raw);
    }
}

function send_reply(string $chatId, array $reply, string $inboundText): void
{
    $text = (string)($reply['text'] ?? '');
    if ($text === '') return;
    if (!empty($reply['buttons'])) {
        $r = TelegramGateway::sendButtons($chatId, $text, $reply['buttons']);
    } else {
        $r = TelegramGateway::sendMessage($chatId, $text);
    }
    if (!empty($r['ok'])) {
        require_once __DIR__ . '/../sync/TelegramCounters.php';
        TelegramCounters::bump('out_sent');
    }
    ConversationService::log(ConversationService::OUT, $chatId, $text,
        ['command_id' => $reply['command_id'] ?? null, 'job_id' => $reply['job_id'] ?? null,
            'status' => !empty($r['ok']) ? 'SENT' : 'FAILED',
            'type' => ConversationService::T_COMMAND]);
}
