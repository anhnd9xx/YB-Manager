<?php
declare(strict_types=1);
/**
 * AILogContextService - Logs co chon loc: theo module/entity/job/time/error.
 * Khong dump ca file. Dung correlation IDs san co (job_id, DEV-x, update_id).
 */
require_once __DIR__ . '/../config.php';

class AILogContextService
{
    private const MAX_LINES = 120;

    /**
     * @param array{module?, entity?, job?, error?, hours?, limit?} $q
     * @return array[] [{file, line}]
     */
    public static function query(array $q, ?string $root = null): array
    {
        $root = $root ?: BASE_DIR;
        $files = self::logFiles($root);
        // Uu tien file theo module
        $mod = strtolower((string)($q['module'] ?? ''));
        if ($mod !== '') {
            usort($files, fn($a, $b) => ((int)str_contains(strtolower($b), $mod))
                - ((int)str_contains(strtolower($a), $mod)));
        }
        $needles = [];
        foreach (['entity', 'job', 'error'] as $k) {
            if (!empty($q[$k])) $needles[] = mb_strtolower((string)$q[$k]);
        }
        // Dev job code -> ca branch name dang ai/dev-N
        if (!empty($q['job']) && preg_match('/DEV-(\d+)/i', (string)$q['job'], $m)) {
            $needles[] = mb_strtolower($m[0]);
            $needles[] = 'dev-' . $m[1];
        }
        $since = time() - max(1, min(72, (int)($q['hours'] ?? 6))) * 3600;
        $limit = max(5, min(self::MAX_LINES, (int)($q['limit'] ?? 40)));
        $out = [];
        foreach ($files as $f) {
            if (count($out) >= $limit) break;
            $lines = self::tail($f, 400);
            foreach ($lines as $line) {
                // Loc theo thoi gian neu parse duoc [H:i:s] hom nay? Don gian: lay tail
                $l = mb_strtolower($line);
                $hit = !$needles;
                foreach ($needles as $nd) {
                    if ($nd !== '' && str_contains($l, $nd)) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $out[] = ['file' => basename($f), 'line' => self::redactLine($line)];
                    if (count($out) >= $limit) break;
                }
            }
        }
        return $out;
    }

    /** @return string[] duong dan log files */
    public static function logFiles(string $root): array
    {
        $out = [];
        try {
            foreach (glob(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . '*.log') ?: [] as $f) {
                if (is_file($f) && filesize($f) < 5 * 1024 * 1024) $out[] = $f;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** @return string[] N dong cuoi */
    private static function tail(string $file, int $n): array
    {
        try {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) return [];
            return array_slice($lines, -$n);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function redactLine(string $line): string
    {
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            return ProjectContextService::redactSecrets(mb_substr($line, 0, 300));
        } catch (Throwable $e) {
            return mb_substr($line, 0, 300);
        }
    }

    public static function toText(array $rows): string
    {
        if (!$rows) return '(không có log liên quan)';
        $out = [];
        foreach ($rows as $r) $out[] = '[' . $r['file'] . '] ' . $r['line'];
        return implode("\n", $out);
    }
}
