<?php
declare(strict_types=1);
/**
 * StateStore - Ghi JSON atomic + per-profile lock (State Store V2).
 * Mọi runtime JSON (life/guard/batch/gen/layout) qua day, khong file_put_contents
 * truc tiep file chinh: tmp -> flush -> rename (khong zero-byte/corrupt khi crash).
 * File lock per-profile (khong global lock): Stop All / Auto Activity / UI Save
 * khong ghi de nhau.
 */
require_once __DIR__ . '/MachineContext.php';

class StateStore
{
    public const WRITE_RETRIES = 3;

    private static function lockFile(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_lock_' . $safe . '.lock';
    }

    /**
     * Chay $fn duoi per-profile lock (timeout ms). Tra ve ket qua $fn hoac null khi timeout.
     */
    public static function withLock(string $key, callable $fn, int $timeoutMs = 2000)
    {
        $f = self::lockFile($key);
        $fh = @fopen($f, 'c');
        if (!$fh) {
            try {
                return $fn();
            } catch (Throwable $e) {
                return null;
            }
        }
        $deadline = microtime(true) + max(100, $timeoutMs) / 1000;
        $locked = false;
        try {
            while (microtime(true) < $deadline) {
                if (@flock($fh, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                usleep(50000);
            }
            if (!$locked) return null;
            return $fn();
        } catch (Throwable $e) {
            return null;
        } finally {
            try {
                if ($locked) @flock($fh, LOCK_UN);
            } catch (Throwable $e) {
            }
            @fclose($fh);
        }
    }

    /**
     * Ghi JSON atomic: tmp (pid-unique) -> flush -> rename. Retry khi file lock/AV.
     * @return bool
     */
    public static function writeJson(string $path, $data, int $retries = self::WRITE_RETRIES): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return false;
        $tmp = $path . '.tmp.' . getmypid() . '.' . substr(md5(microtime(true) . mt_rand()), 0, 6);
        for ($i = 0; $i < max(1, $retries); $i++) {
            try {
                $fh = @fopen($tmp, 'wb');
                if (!$fh) {
                    usleep(50000 * ($i + 1));
                    continue;
                }
                if (@flock($fh, LOCK_EX | LOCK_NB)) {
                    @fwrite($fh, $json);
                    @fflush($fh);
                    @flock($fh, LOCK_UN);
                } else {
                    @fwrite($fh, $json);
                    @fflush($fh);
                }
                @fclose($fh);
                if (@rename($tmp, $path)) return true;
                // Windows rename khong overwrite khi dich dang mo doc -> thu copy+unlink
                if (@copy($tmp, $path)) {
                    @unlink($tmp);
                    return true;
                }
            } catch (Throwable $e) {
            }
            usleep(50000 * ($i + 1));
        }
        @unlink($tmp);
        return false;
    }

    /** Doc JSON validated: tra ve array hoac null (corrupt/khong phai array). */
    public static function readJson(string $path): ?array
    {
        try {
            if (!is_file($path)) return null;
            $j = json_decode((string)@file_get_contents($path), true);
            return is_array($j) ? $j : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Xoa file .tmp* mo coi cua path (sau crash truoc rename). */
    public static function cleanupTmp(string $path): void
    {
        try {
            foreach (glob($path . '.tmp.*') ?: [] as $f) {
                if (is_file($f) && (time() - (int)@filemtime($f)) > 300) @unlink($f);
            }
        } catch (Throwable $e) {
        }
    }
}
