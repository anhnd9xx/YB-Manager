<?php
declare(strict_types=1);
/**
 * bin/telegram_polling.php - Inbound worker (§42): long polling.
 * getUpdates(offset, timeout=30) -> idempotency update_id -> route/callback ->
 * reply. Backoff exponential khi loi. Persist offset (§43). Khong chay tren UI.
 */
require_once __DIR__ . '/../sync/TelegramGateway.php';
require_once __DIR__ . '/../sync/TelegramOffset.php';
require_once __DIR__ . '/../sync/CommandRouter.php';
require_once __DIR__ . '/../sync/ConversationService.php';
require_once __DIR__ . '/../sync/ConfirmationService.php';
require_once __DIR__ . '/../sync/TelegramSetup.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.tg_polling.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.tg_polling.pid', (string)getmypid());

function tg_offset_file(): string
{
    // Legacy shim: offset that su dung TelegramOffset (persist, restart-safe)
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_offset.json';
}
function tg_offset_get(): int
{
    return TelegramOffset::get();
}
function tg_offset_set(int $offset): void
{
    TelegramOffset::set($offset);
}

try {
    SyncLogger::info('telegram', '[Polling] started pid=' . getmypid());
} catch (Throwable $e) {
}
$backoff = 5;
TelegramGateway::heartbeat(null, null, 'STARTING');
while (true) {
    try {
        // Poll khi inbound ON hoac setup session active (pairing) — khong doi confirm
        if (!TelegramGateway::shouldPoll()) {
            TelegramGateway::heartbeat(null, null, 'STOPPED');
            sleep(10);
            continue;
        }
        $transport = TelegramGateway::transport();
        $offset = tg_offset_get();
        TelegramGateway::pollStarted();
        try {
            $updates = $transport->receive($offset, 30);
            $backoff = 5;
            TelegramGateway::heartbeat(null, null, 'LISTENING', true);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === 'poll_conflict') {
                // 409: bot dang bi tien trinh khac poll (§4)
                TelegramGateway::heartbeat('TELEGRAM_POLLING_CONFLICT', null, 'ERROR');
                echo date('H:i:s') . " 409 conflict, sleep 60s\n";
                sleep(60);
                continue;
            }
            TelegramGateway::heartbeat($msg, null, 'RECONNECTING');
            if ($msg === 'poll_unauthorized') {
                echo date('H:i:s') . " unauthorized, sleep 60s\n";
                sleep(60);
                continue;
            }
            echo date('H:i:s') . " poll err ($msg), backoff {$backoff}s\n";
            sleep($backoff);
            $backoff = min(30, $backoff * 2); // 1/2/5/10/30 max (§29)
            continue;
        }
        foreach ($updates as $u) {
            $uid = (int)($u['update_id'] ?? 0);
            try {
                handle_update($u);
            } catch (Throwable $e) {
                try {
                    SyncLogger::warn('telegram', 'handle update loi: ' . mb_substr($e->getMessage(), 0, 150));
                } catch (Throwable $e2) {
                }
            }
            // Ack SAU khi xu ly (crash giua chung khong mat update §10)
            if ($uid > 0) tg_offset_set($uid + 1);
            TelegramGateway::heartbeat(null, date('Y-m-d H:i:s'));
        }
        ConfirmationService::sweep();
    } catch (Throwable $e) {
        TelegramGateway::heartbeat('worker_error', null, 'ERROR');
        sleep(5);
    }
}

function handle_update(array $u): void
{
    $uid = (int)($u['update_id'] ?? 0);
    if ($uid > 0 && CommandRouter::seenUpdate($uid)) return; // idempotency (§23, §54)
    // Dev log (§14): khong sensitive (mask chat/user, chi command text)
    try {
        $kind = isset($u['callback_query']) ? 'callback' : (isset($u['message']) ? 'message' : 'other');
        $m = $u['message'] ?? $u['callback_query']['message'] ?? [];
        $ct = (string)(($m['chat'] ?? [])['type'] ?? '?');
        $cid = '***' . substr((string)(($m['chat'] ?? [])['id'] ?? ''), -4);
        $fr = $u['message']['from'] ?? $u['callback_query']['from'] ?? [];
        $fuid = '***' . substr((string)($fr['id'] ?? ''), -4);
        $txt = trim((string)(($u['message'] ?? [])['text'] ?? ''));
        $cmd = $txt !== '' ? (explode(' ', $txt)[0] ?? '') : (string)($u['callback_query']['data'] ?? '');
        SyncLogger::debug('telegram', '[TELEGRAM UPDATE] update_id=' . $uid . ' type=' . $kind
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
    $isCmd = str_starts_with($text, '/');
    // Test mode (§13): hien nhu message thuong, KHONG parse command/job/eval/chrome.
    // Mac dinh ON khi connected. /pair luon route (pairing pre-connection).
    $testMode = get_setting('tg_chat_test_mode', '1') !== '0';
    $msgType = $isCmd ? ConversationService::T_COMMAND : ConversationService::T_TEXT;
    // Command Mode (§Q): OFF -> hien nhu tin nhan thuong, khong parse (tru /pair).
    $cmdMode = get_setting('tg_chat_command_mode', '1') === '1';
    if (($testMode || !$cmdMode) && $isCmd && stripos($text, '/pair') !== 0) {
        $msgType = ConversationService::T_TEXT;
    }
    ConversationService::log(ConversationService::IN, $chatId, $text,
        ['user_id' => $userId, 'message_id' => (string)($msg['message_id'] ?? ''),
            'telegram_message_id' => (string)($msg['message_id'] ?? ''),
            'telegram_update_id' => $uid, 'type' => $msgType]);
    // Setup session uu tien cho sender chua authorized (one-field pairing §5-§9).
    // Sender da authorized van di router binh thuong.
    require_once __DIR__ . '/../sync/PermissionService.php';
    $chk = PermissionService::check($chatId, $userId);
    if (empty($chk['ok']) && ($msg['chat']['type'] ?? '') === 'private') {
        $setup = TelegramSetup::onPrivateMessage($msg, $uid);
        if (!empty($setup['asked_confirm']) || TelegramSetup::active() !== null) {
            return; // dang trong setup flow, khong route command
        }
    }
    if ($msgType === ConversationService::T_TEXT && $isCmd) {
        return; // test mode / command mode OFF: chi luu/hien, khong execute
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
    // answer truoc de Telegram khong spinning (§44)
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
        // Quick-action buttons di qua Router nhu text (permission/role day du) (§28-§29)
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
