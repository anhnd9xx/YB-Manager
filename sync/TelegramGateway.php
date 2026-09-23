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
            // 409: 1 bot chi 1 getUpdates consumer (§4, §38)
            if (str_contains($d, 'conflict') || str_contains($d, 'terminated by other')) {
                throw new RuntimeException('poll_conflict');
            }
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
        // Runtime token duy nhat tu ConfigService (§17)
        require_once __DIR__ . '/TelegramConfigService.php';
        $token = TelegramConfigService::get_bot_token();
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

    /**
     * Worker co nen poll? inbound ON hoac setup session active (pairing).
     * Voi bot model: chi poll khi primary CONNECTED (disconnect = dung).
     * Legacy (chua migrate): fallback hanh vi cu.
     */
    public static function shouldPoll(): bool
    {
        try {
            require_once __DIR__ . '/TgBotStore.php';
            $conns = TgBotStore::list();
            if ($conns) {
                $prim = null;
                foreach ($conns as $c) {
                    if (!empty($c['is_primary'])) {
                        $prim = $c;
                        break;
                    }
                }
                $prim = $prim ?? $conns[0];
                if (($prim['status'] ?? '') !== TgBotStore::ST_CONNECTED) return false;
                // Credential INVALID (401 that) -> khong poll (§20, §48)
                if (isset($prim['credential_status']) && $prim['credential_status'] === 'INVALID') return false;
                if (self::inboundEnabled()) return true;
                require_once __DIR__ . '/TelegramSetup.php';
                return TelegramSetup::active() !== null;
            }
            if (trim((string)get_setting('notify_bot_token', '')) === '') return false;
            if (self::inboundEnabled()) return true;
            require_once __DIR__ . '/TelegramSetup.php';
            return TelegramSetup::active() !== null;
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
        $st = ['listening' => false, 'last_update_at' => null, 'last_error' => null,
            'state' => 'STOPPED', 'last_poll_started_at' => null, 'last_poll_completed_at' => null,
            'poll_error_count' => 0];
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j)) {
                $st['listening'] = (microtime(true) - (float)($j['heartbeat'] ?? 0)) < 90
                    && in_array(($j['state'] ?? ''), ['LISTENING', 'RECONNECTING'], true);
                $st['last_update_at'] = $j['last_update_at'] ?? null;
                $st['last_error'] = $j['last_error'] ?? null;
                $st['state'] = $j['state'] ?? 'STOPPED';
                $st['last_poll_started_at'] = $j['last_poll_started_at'] ?? null;
                $st['last_poll_completed_at'] = $j['last_poll_completed_at'] ?? null;
                $st['poll_error_count'] = (int)($j['poll_error_count'] ?? 0);
                // Watchdog §28: LISTENING nhung khong poll cycle >60s -> UNHEALTHY
                if ($st['state'] === 'LISTENING' && ($j['last_poll_completed_at'] ?? 0) > 0
                    && (microtime(true) - (float)$j['last_poll_completed_at']) > 60) {
                    $st['state'] = 'UNHEALTHY';
                    $st['listening'] = false;
                }
            }
        }
        return $st;
    }

    public static function heartbeat(?string $error = null, ?string $lastUpdateAt = null,
        ?string $state = null, bool $pollCompleted = false): void
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_poll.json';
        $prev = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        if ($lastUpdateAt !== null) $prev['last_update_at'] = $lastUpdateAt;
        $prev['heartbeat'] = microtime(true);
        if ($error !== null) {
            $prev['last_error'] = $error;
            $prev['poll_error_count'] = (int)($prev['poll_error_count'] ?? 0) + 1;
        }
        if ($state !== null) $prev['state'] = $state;
        if ($pollCompleted) {
            $prev['last_poll_completed_at'] = microtime(true);
            $prev['poll_error_count'] = 0;
        }
        @file_put_contents($f, json_encode($prev, JSON_UNESCAPED_UNICODE));
    }

    public static function pollStarted(): void
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_poll.json';
        $prev = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        $prev['heartbeat'] = microtime(true);
        $prev['last_poll_started_at'] = microtime(true);
        if (($prev['state'] ?? '') !== 'LISTENING') $prev['state'] = 'LISTENING';
        @file_put_contents($f, json_encode($prev, JSON_UNESCAPED_UNICODE));
    }

    public static function setWorkerStart(int $ts): void
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_poll.json';
        $prev = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        $prev['worker_started_at'] = $ts;
        @file_put_contents($f, json_encode($prev, JSON_UNESCAPED_UNICODE));
    }
}
