<?php
declare(strict_types=1);
/**
 * TelegramProvider - Gui Telegram, KHONG business logic (§8, §36).
 * send_message / test_connection / retry(backoff o worker) / split_long_message.
 * Khong log token, khong gui credentials (§10, §47).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class TelegramProvider
{
    public const API = 'https://api.telegram.org/bot';
    public const MAX_LEN = 4000;

    public static function configured(): array
    {
        $token = trim((string)get_setting('notify_bot_token', ''));
        $chat = trim((string)get_setting('notify_chat_id', ''));
        return ['token' => $token, 'chat_id' => $chat,
            'ok' => $token !== '' && $chat !== ''];
    }

    /** @return array{ok, error?} */
    public static function sendMessage(string $text, ?string $chatId = null): array
    {
        $cfg = self::configured();
        if (!$cfg['ok']) return ['ok' => false, 'error' => 'not_configured'];
        $chatId = $chatId ?? $cfg['chat_id'];
        foreach (self::split($text) as $i => $part) {
            $r = self::post($cfg['token'], 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $part,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
            if (empty($r['ok'])) {
                return ['ok' => false, 'error' => self::friendly($r)];
            }
            if ($i > 0) usleep(400000); // throttle giua cac part
        }
        return ['ok' => true];
    }

    /** @return array{ok, error?, bot?} */
    public static function testConnection(?string $token = null): array
    {
        $token = $token ?? trim((string)get_setting('notify_bot_token', ''));
        if ($token === '') return ['ok' => false, 'error' => 'empty_token'];
        $r = self::post($token, 'getMe', []);
        if (!empty($r['ok'])) {
            $u = $r['result'] ?? [];
            return ['ok' => true, 'bot' => '@' . ($u['username'] ?? '?')];
        }
        return ['ok' => false, 'error' => self::friendly($r)];
    }

    /** @return array{ok, error?} */
    public static function sendTest(): array
    {
        return self::sendMessage("✅ YT Manager kết nối Telegram thành công.");
    }

    /** @return array{ok, error?} */
    public static function sendReport(array $doc): array
    {
        return self::sendMessage(self::formatDocument($doc));
    }

    /** Render ReportDocument thanh message (provider khac tai su dung). */
    public static function formatDocument(array $doc): string
    {
        $lines = [];
        $lines[] = '<b>' . self::esc((string)($doc['title'] ?? 'Báo cáo')) . '</b>';
        if (!empty($doc['summary'])) $lines[] = self::esc((string)$doc['summary']);
        foreach ((array)($doc['sections'] ?? []) as $s) {
            $lines[] = '';
            if (!empty($s['heading'])) $lines[] = '<b>' . self::esc((string)$s['heading']) . '</b>';
            foreach ((array)($s['lines'] ?? []) as $l) $lines[] = self::esc((string)$l);
        }
        if (!empty($doc['warnings'])) {
            $lines[] = '';
            foreach ((array)$doc['warnings'] as $w) $lines[] = '⚠️ ' . self::esc((string)$w);
        }
        if (!empty($doc['generated_at'])) $lines[] = '';
        if (!empty($doc['generated_at'])) $lines[] = '<i>' . self::esc((string)$doc['generated_at']) . '</i>';
        return implode("\n", $lines);
    }

    /** Cat message dai thanh nhieu part (giu dong nguyen). */
    public static function split(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_LEN) return [$text];
        $parts = [];
        $cur = '';
        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($cur) + mb_strlen($line) + 1 > self::MAX_LEN) {
                $parts[] = $cur;
                $cur = '';
            }
            $cur .= ($cur === '' ? '' : "\n") . $line;
        }
        if ($cur !== '') $parts[] = $cur;
        return $parts;
    }

    private static function post(string $token, string $method, array $params): array
    {
        $ch = curl_init(self::API . $token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body) || $body === '') {
            return ['ok' => false, 'error' => 'network', 'curl_errno' => $errno];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) return ['ok' => false, 'error' => 'bad_response'];
        if (!empty($j['ok'])) return ['ok' => true, 'result' => $j['result'] ?? []];
        return ['ok' => false, 'error' => 'api_' . ($j['error_code'] ?? '?'),
            'description' => $j['description'] ?? ''];
    }

    /** Friendly error, KHONG kem token (§11). */
    public static function friendly(array $r): string
    {
        $e = (string)($r['error'] ?? 'unknown');
        $d = strtolower((string)($r['description'] ?? ''));
        if ($e === 'not_configured') return 'Chưa cấu hình Bot Token / Chat ID';
        if ($e === 'empty_token') return 'Chưa nhập Bot Token';
        if ($e === 'network') return 'Không kết nối được Telegram (mạng/proxy)';
        if (str_contains($d, 'unauthorized') || $e === 'api_401') return 'Bot Token sai (unauthorized)';
        if (str_contains($d, 'chat not found') || $e === 'api_400') return 'Chat ID sai hoặc bot chưa được thêm vào chat';
        if (str_contains($d, 'bot was blocked') || $e === 'api_403') return 'Bot bị chặn bởi chat đích';
        return 'Telegram lỗi: ' . ($r['description'] ?? $e);
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
