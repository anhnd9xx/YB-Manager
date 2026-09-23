<?php
declare(strict_types=1);
/**
 * TgSecret - Ma hoa Bot Token at rest bang Windows DPAPI LocalMachine (§22).
 * Token doc duoc (can goi API) nen ENCRYPT, khong hash. Key ngoai DB/code.
 * Thuc thi qua sync/dpapi.ps1 (file IO, khong quoting secret tren command-line).
 */
class TgSecret
{
    private static function run(string $mode, string $input): ?string
    {
        $input = trim($input);
        if ($input === '') return null;
        try {
            $script = __DIR__ . '/dpapi.ps1';
            if (!is_file($script)) return null;
            $tmp = rtrim(sys_get_temp_dir(), '/\\');
            $in = $tmp . DIRECTORY_SEPARATOR . 'ytm_dpapi_' . getmypid() . '.in';
            $out = $tmp . DIRECTORY_SEPARATOR . 'ytm_dpapi_' . getmypid() . '.out';
            @file_put_contents($in, $input);
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"'
                . ' -Mode ' . $mode . ' -InFile "' . $in . '" -OutFile "' . $out . '"';
            @shell_exec($cmd);
            @unlink($in);
            $res = is_file($out) ? trim((string)@file_get_contents($out)) : '';
            @unlink($out);
            return $res !== '' ? $res : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return string|null base64 ciphertext (null = that bai) */
    public static function protect(string $raw): ?string
    {
        return self::run('protect', $raw);
    }

    /** @return string|null raw token (null = that bai) */
    public static function unprotect(string $cipher): ?string
    {
        return self::run('unprotect', $cipher);
    }

    public static function available(): bool
    {
        $t = self::protect('__probe__');
        if ($t === null) return false;
        return self::unprotect($t) === '__probe__';
    }
}
