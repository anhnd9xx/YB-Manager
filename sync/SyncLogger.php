<?php
declare(strict_types=1);
/**
 * SyncLogger - Component Logger cua module Synchronize (PHASE 1).
 * Ghi file sync/sync.log (dong JSON) + su kien quan trong (warn/error) vao activity_logs
 * de hien thi o tab Logs san co. Khong bao gio nem exception ra ngoai.
 */
require_once __DIR__ . '/../config.php';

class SyncLogger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARN = 'WARN';
    public const ERROR = 'ERROR';

    public static function log(string $level, string $event, ?int $profileId = null, string $message = '', ?Throwable $ex = null): void
    {
        try {
            $row = [
                'ts' => date('Y-m-d H:i:s'),
                'level' => $level,
                'event' => $event,
                'profileId' => $profileId,
                'message' => $message,
            ];
            if ($ex !== null) $row['exception'] = mb_substr($ex->getMessage(), 0, 500);
            @file_put_contents(__DIR__ . '/sync.log',
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX);
            if ($level === self::WARN || $level === self::ERROR) {
                log_action($profileId, 'sync_' . strtolower($event), mb_substr($message, 0, 500));
            }
        } catch (Throwable $e) {
            // logger khong duoc lam hong luong chinh
        }
    }

    public static function debug(string $event, string $message = '', ?int $profileId = null): void
    {
        self::log(self::DEBUG, $event, $profileId, $message);
    }

    public static function info(string $event, string $message = '', ?int $profileId = null): void
    {
        self::log(self::INFO, $event, $profileId, $message);
    }

    public static function warn(string $event, string $message = '', ?int $profileId = null, ?Throwable $ex = null): void
    {
        self::log(self::WARN, $event, $profileId, $message, $ex);
    }

    public static function error(string $event, string $message = '', ?int $profileId = null, ?Throwable $ex = null): void
    {
        self::log(self::ERROR, $event, $profileId, $message, $ex);
    }
}
