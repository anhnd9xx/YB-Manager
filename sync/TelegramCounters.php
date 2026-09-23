<?php
declare(strict_types=1);
/**
 * TelegramCounters - Dem dev cho diagnostics (§50-§51).
 * in_recv / in_dedup / out_req / out_sent.
 */
class TelegramCounters
{
    private static function file(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_counters.json';
    }
    public static function bump(string $key): void
    {
        try {
            $f = self::file();
            $j = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
            $j[$key] = (int)($j[$key] ?? 0) + 1;
            @file_put_contents($f, json_encode($j));
        } catch (Throwable $e) {
        }
    }
    public static function all(): array
    {
        try {
            $f = self::file();
            $j = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
            return ['in_recv' => (int)($j['in_recv'] ?? 0), 'in_dedup' => (int)($j['in_dedup'] ?? 0),
                'out_req' => (int)($j['out_req'] ?? 0), 'out_sent' => (int)($j['out_sent'] ?? 0),
                'ui_dedup' => (int)($j['ui_dedup'] ?? 0)];
        } catch (Throwable $e) {
            return ['in_recv' => 0, 'in_dedup' => 0, 'out_req' => 0, 'out_sent' => 0, 'ui_dedup' => 0];
        }
    }
}
