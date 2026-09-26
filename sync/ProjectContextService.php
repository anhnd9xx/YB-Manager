<?php
declare(strict_types=1);
/**
 * ProjectContextService - Context co cau truc cho OpenCode (khong raw chat).
 * Gom: root, architecture summary, module registry, DB schema refs, logs,
 * health, dev rules. Tat ca qua redactSecrets (khong token/password/cookie).
 */
require_once __DIR__ . '/../config.php';

class ProjectContextService
{
    /** @return array{ok, root?, context?, error?} */
    public static function build(?int $projectId = null, int $maxChars = 6000): array
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = $projectId ? DevProjectRegistry::get($projectId) : DevProjectRegistry::primary();
            if (!$p) return ['ok' => false, 'error' => 'Chưa có project'];
            $root = (string)$p['root_path'];
            $parts = [];
            $parts[] = 'PROJECT: ' . $p['name'] . ' | root: ' . $root
                . ' | default branch: ' . ($p['default_branch'] ?? 'master');
            $parts[] = 'ARCHITECTURE: ' . self::architectureSummary();
            $parts[] = 'DEV RULES: ' . self::devRulesSummary($root);
            $parts[] = 'MODULES: ' . self::moduleRegistry($root);
            $parts[] = 'DATABASE: ' . self::schemaRefs();
            $parts[] = 'HEALTH: ' . self::healthSnapshot();
            $parts[] = 'RECENT LOGS: ' . self::recentLogs($root);
            $ctx = self::redactSecrets(implode("\n", $parts));
            if (mb_strlen($ctx) > $maxChars) $ctx = mb_substr($ctx, 0, $maxChars) . "\n…(rút gọn)";
            return ['ok' => true, 'root' => $root, 'project' => $p, 'context' => $ctx];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public static function architectureSummary(): string
    {
        return 'PHP 8 + MySQL (XAMPP Windows), web UI vanilla JS, background workers PHP-CLI. '
            . 'index.php routes views; api/*.php endpoints; sync/*.php services; bin/* workers. '
            . 'EventBus -> NotificationManager -> Telegram. JobManager + job_worker cho batch. '
            . 'Telegram: 1 long-poll consumer (bin/telegram_polling.php) + queue/dispatcher + supervisor. '
            . 'Chrome channels: profiles/ + ChromeBatchManager; proxy via proxy_relay.php. '
            . 'Git worktree cho AI Dev Jobs (khong sua truc tiep main).';
    }

    /** Tom tat DEV_RULES.md (neu co). */
    public static function devRulesSummary(string $root): string
    {
        $f = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'DEV_RULES.md';
        if (!is_file($f)) return '(chua co DEV_RULES.md)';
        $t = (string)@file_get_contents($f);
        $t = preg_replace('/\s+/', ' ', $t);
        return mb_substr(trim($t), 0, 1200);
    }

    /** Quet sync/*.php lay class + dong mo ta dau. */
    public static function moduleRegistry(string $root): string
    {
        $out = [];
        try {
            foreach (glob(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'sync' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $f) {
                $src = (string)@file_get_contents($f);
                if ($src === '' || strlen($src) > 300000) continue;
                if (!preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $m)) continue;
                $desc = '';
                if (preg_match('/\/\*\*\s*(.*?)\s*\*\//s', $src, $d)) {
                    $desc = trim(preg_replace('/\s*\*\s?/', ' ', (string)$d[1]));
                    $desc = mb_substr(preg_replace('/\s+/', ' ', $desc), 0, 120);
                }
                $out[] = basename($f) . ' [' . implode(',', $m[1]) . ']' . ($desc !== '' ? ': ' . $desc : '');
                if (count($out) >= 80) break;
            }
        } catch (Throwable $e) {
        }
        return implode(' | ', $out);
    }

    public static function schemaRefs(): string
    {
        try {
            $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
            $names = array_map(fn($r) => (string)$r[0], $tables);
            return 'tables(' . count($names) . '): ' . implode(',', array_slice($names, 0, 60));
        } catch (Throwable $e) {
            return '(khong doc duoc schema)';
        }
    }

    public static function healthSnapshot(): string
    {
        try {
            require_once __DIR__ . '/SystemHealthService.php';
            $o = SystemHealthService::overall();
            return ($o['label'] ?? '?') . ' (unhealthy=' . ($o['unhealthy'] ?? 0)
                . ' degraded=' . ($o['degraded'] ?? 0) . ')';
        } catch (Throwable $e) {
            return '(unknown)';
        }
    }

    public static function recentLogs(string $root, int $lines = 15): string
    {
        $out = [];
        try {
            foreach (['telegram_polling.log', 'job_worker.log', 'notify_worker.log'] as $lf) {
                $f = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $lf;
                if (!is_file($f)) continue;
                $all = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $tail = array_slice($all, -$lines);
                if ($tail) $out[] = $lf . ': ' . implode(' / ', array_slice($tail, -5));
            }
        } catch (Throwable $e) {
        }
        return $out ? implode(' | ', $out) : '(no logs)';
    }

    /** Redact secrets truoc khi dua cho AI (§35). */
    public static function redactSecrets(string $text): string
    {
        // Telegram bot token
        $text = preg_replace('/\d{5,}:[A-Za-z0-9_-]{20,}/', '[REDACTED_BOT_TOKEN]', $text);
        // proxy/userinfo passwords: :pass@ / password=xxx
        $text = preg_replace('/(password["\']?\s*[:=]\s*["\']?)[^"\'&\s,}]+/i', '$1[REDACTED]', $text);
        $text = preg_replace('/(mongodb(\+srv)?|mysql|http|https|ftp):\/\/([^:\/\s]+:)([^@\/\s]+)@/i', '$1://$3[REDACTED]@', $text);
        // userinfo dang user:pass@host (khong scheme); ca pass chua @
        $text = preg_replace('/([A-Za-z0-9_.%-]+):([^:@\s\'"]{4,})@/', '$1:[REDACTED]@', $text);
        $text = preg_replace('/([A-Za-z0-9_.%-]+):([^\s\'"]*@[^@\s\'"]+)/', '$1:[REDACTED]', $text);
        // api keys
        $text = preg_replace('/\b(sk-[A-Za-z0-9_-]{10,}|AIza[A-Za-z0-9_-]{10,}|xox[bpas]-[A-Za-z0-9-]+)\b/', '[REDACTED_API_KEY]', $text);
        // OPENCODE server password setting (khong bao gio gui)
        $text = preg_replace('/ai_oc_password["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i', 'ai_oc_password=[REDACTED]', $text);
        return $text;
    }
}
