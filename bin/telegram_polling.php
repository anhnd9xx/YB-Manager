<?php
declare(strict_types=1);
/**
 * bin/telegram_polling.php - Inbound worker (§42): long polling.
 * getUpdates(offset, timeout=30) -> idempotency update_id -> route/callback ->
 * reply. Backoff exponential khi loi. Persist offset (§43). Khong chay tren UI.
 */
require_once __DIR__ . '/../sync/TelegramGateway.php';
require_once __DIR__ . '/../sync/CommandRouter.php';
require_once __DIR__ . '/../sync/ConversationService.php';
require_once __DIR__ . '/../sync/ConfirmationService.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$lock = @fopen(__DIR__ . '/.tg_polling.lock', 'c');
if (!$lock) exit(0);
if (!@flock($lock, LOCK_EX | LOCK_NB)) exit(0);
@fwrite($lock, (string)getmypid());
@fflush($lock);
@file_put_contents(__DIR__ . '/.tg_polling.pid', (string)getmypid());

function tg_offset_file(): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_offset.json';
}
function tg_offset_get(): int
{
    $f = tg_offset_file();
    if (!is_file($f)) return 0;
    $j = json_decode((string)@file_get_contents($f), true);
    return (int)(is_array($j) ? ($j['offset'] ?? 0) : 0);
}
function tg_offset_set(int $offset): void
{
    @file_put_contents(tg_offset_file(), json_encode(['offset' => $offset]));
}

try {
    SyncLogger::info('telegram', '[Polling] started pid=' . getmypid());
} catch (Throwable $e) {
}
$backoff = 5;
while (true) {
    try {
        if (!TelegramGateway::inboundEnabled()) {
            TelegramGateway::heartbeat('inbound_disabled');
            sleep(10);
            continue;
        }
        $transport = TelegramGateway::transport();
        $offset = tg_offset_get();
        try {
            $updates = $transport->receive($offset, 30);
            $backoff = 5;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            TelegramGateway::heartbeat($msg);
            if ($msg === 'poll_unauthorized') {
                echo date('H:i:s') . " unauthorized, sleep 60s\n";
                sleep(60);
                continue;
            }
            echo date('H:i:s') . " poll err ($msg), backoff {$backoff}s\n";
            sleep($backoff);
            $backoff = min(120, $backoff * 2); // exponential backoff
            continue;
        }
        foreach ($updates as $u) {
            $uid = (int)($u['update_id'] ?? 0);
            if ($uid > 0) tg_offset_set($uid + 1); // ack truoc de khong replay (§43)
            try {
                handle_update($u);
            } catch (Throwable $e) {
                try {
                    SyncLogger::warn('telegram', 'handle update loi: ' . mb_substr($e->getMessage(), 0, 150));
                } catch (Throwable $e2) {
                }
            }
            TelegramGateway::heartbeat(null, date('Y-m-d H:i:s'));
        }
        ConfirmationService::sweep();
    } catch (Throwable $e) {
        sleep(5);
    }
}

function handle_update(array $u): void
{
    $uid = (int)($u['update_id'] ?? 0);
    if ($uid > 0 && CommandRouter::seenUpdate($uid)) return; // idempotency (§23, §54)
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
    ConversationService::log(ConversationService::IN, $chatId, $text,
        ['user_id' => $userId, 'message_id' => (string)($msg['message_id'] ?? '')]);
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
    ConversationService::log(ConversationService::OUT, $chatId, $text,
        ['command_id' => $reply['command_id'] ?? null, 'job_id' => $reply['job_id'] ?? null,
            'status' => !empty($r['ok']) ? 'SENT' : 'FAILED']);
}
