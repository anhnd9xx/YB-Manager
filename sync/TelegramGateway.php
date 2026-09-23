<?php
declare(strict_types=1);
/**
 * TelegramGateway - Giao tiep 2 chieu Telegram, KHONG business logic (§3).
 * receive_updates / send_message / send_buttons / handle_callback_query /
 * ack_update / connection_state. Transports thay the duoc (§5):
 * LongPollingTransport (local, uu tien §4) | WebhookTransport (stub tuong lai).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';
require_once __DIR__ . '/TelegramProvider.php';

interface TelegramTransport
{
    /** @return array[] updates[] */
    public function receive(int $offset, int $timeout): array;
    public function ack(int $updateId): void;
}

class LongPollingTransport implements TelegramTransport
{
    private string $token;
    public function __construct(string $token)
    {
        $this->token = $token;
    }
    public function receive(int $offset, int $timeout): array
    {
        $timeout = max(5, min(50, $timeout));
        $url = TelegramProvider::API . $this->token . '/getUpdates';
        $ch = curl_init($url . '?' . http_build_query(['offset' => $offset, 'timeout' => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query'])]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout + 10,
            CURLOPT_CONNECTTIMEOUT => 8]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body)) {
            throw new RuntimeException('poll_network');
        }
        $j = json_decode($body, true);
        if (!is_array($j) || empty($j['ok'])) {
            $d = strtolower((string)($j['description'] ?? ''));
            if (str_contains($d, 'unauthorized')) throw new RuntimeException('poll_unauthorized');
            throw new RuntimeException('poll_bad_response');
        }
        return is_array($j['result'] ?? null) ? $j['result'] : [];
    }
    public function ack(int $updateId): void
    {
        // Long polling ack = offset (persist o worker, §43)
    }
}

class WebhookTransport implements TelegramTransport
{
    /** Tuong lai: Telegram POST updates -> webhook endpoint -> hang doi. */
    public function receive(int $offset, int $timeout): array
    {
        return [];
    }
    public function ack(int $updateId): void
    {
    }
}

class TelegramGateway
{
    public static function transport(): TelegramTransport
    {
        $token = trim((string)get_setting('notify_bot_token', ''));
        $mode = get_setting('notify_transport', 'polling');
        if ($mode === 'webhook') return new WebhookTransport();
        return new LongPollingTransport($token);
    }

    public static function inboundEnabled(): bool
    {
        try {
            return get_setting('notify_inbound_enabled', '0') === '1'
                && trim((string)get_setting('notify_bot_token', '')) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array{ok, error?} */
    public static function sendMessage(string $chatId, string $text): array
    {
        return TelegramProvider::sendMessage($text, $chatId);
    }

    /**
     * @param array[] $buttons [[text, callback_data], ...] rows
     * @return array{ok, error?, message_id?}
     */
    public static function sendButtons(string $chatId, string $text, array $buttons): array
    {
        $cfg = TelegramProvider::configured();
        if (!$cfg['ok']) return ['ok' => false, 'error' => 'not_configured'];
        $kb = ['inline_keyboard' => []];
        foreach ($buttons as $row) {
            $r = [];
            foreach ((array)$row as $b) {
                if (is_array($b) && isset($b[0], $b[1])) {
                    $r[] = ['text' => mb_substr((string)$b[0], 0, 64),
                        'callback_data' => mb_substr((string)$b[1], 0, 64)];
                }
            }
            if ($r) $kb['inline_keyboard'][] = $r;
        }
        $ch = curl_init(TelegramProvider::API . $cfg['token'] . '/sendMessage');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML',
                'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE)],
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body)) return ['ok' => false, 'error' => 'network'];
        $j = json_decode($body, true);
        if (!empty($j['ok'])) return ['ok' => true, 'message_id' => (int)($j['result']['message_id'] ?? 0)];
        return ['ok' => false, 'error' => TelegramProvider::friendly(['ok' => false,
            'error' => 'api', 'description' => $j['description'] ?? ''])];
    }

    /** @return array{ok, error?} */
    public static function answerCallback(string $callbackId, string $text = ''): array
    {
        $cfg = TelegramProvider::configured();
        if (!$cfg['ok']) return ['ok' => false, 'error' => 'not_configured'];
        $ch = curl_init(TelegramProvider::API . $cfg['token'] . '/answerCallbackQuery');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 200)],
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        curl_close($ch);
        $j = is_string($body) ? json_decode($body, true) : null;
        return !empty($j['ok']) ? ['ok' => true] : ['ok' => false, 'error' => 'callback_failed'];
    }

    /** @return array{listening, last_update_at, last_error} */
    public static function connectionState(): array
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_poll.json';
        $st = ['listening' => false, 'last_update_at' => null, 'last_error' => null];
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j)) {
                $st['listening'] = (microtime(true) - (float)($j['heartbeat'] ?? 0)) < 90;
                $st['last_update_at'] = $j['last_update_at'] ?? null;
                $st['last_error'] = $j['last_error'] ?? null;
            }
        }
        return $st;
    }

    public static function heartbeat(?string $error = null, ?string $lastUpdateAt = null): void
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_poll.json';
        $prev = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        if ($lastUpdateAt !== null) $prev['last_update_at'] = $lastUpdateAt;
        $prev['heartbeat'] = microtime(true);
        if ($error !== null) $prev['last_error'] = $error;
        @file_put_contents($f, json_encode($prev, JSON_UNESCAPED_UNICODE));
    }
}
