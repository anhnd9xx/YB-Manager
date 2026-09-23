<?php
declare(strict_types=1);
/**
 * TelegramOffset - Persist last_update_id/offset (restart khong replay cu §19).
 */
class TelegramOffset
{
    private static function file(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_tg_offset.json';
    }
    public static function get(): int
    {
        $f = self::file();
        if (!is_file($f)) return 0;
        $j = json_decode((string)@file_get_contents($f), true);
        return (int)(is_array($j) ? ($j['offset'] ?? 0) : 0);
    }
    public static function set(int $offset): void
    {
        @file_put_contents(self::file(), json_encode(['offset' => $offset]));
    }
}
