<?php
declare(strict_types=1);
/**
 * SchedulerService - Lich dinh ky TAO JOB (khong chay module truc tiep §29).
 * Schedule types: ONCE | DAILY | WEEKLY | INTERVAL.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class SchedulerService
{
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL DEFAULT '',
                job_type VARCHAR(40) NOT NULL DEFAULT '',
                module VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
                schedule_type VARCHAR(15) NOT NULL DEFAULT 'DAILY',
                schedule_config TEXT NULL,
                target_config TEXT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_run_at DATETIME NULL,
                next_run_at DATETIME NULL,
                last_job_id VARCHAR(32) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sched_next (enabled, next_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** @return array[] */
    public static function list(bool $enabledOnly = false): array
    {
        self::ensureTable();
        try {
            $w = $enabledOnly ? 'WHERE enabled=1' : '';
            return db()->query("SELECT * FROM scheduled_jobs $w ORDER BY next_run_at IS NULL, next_run_at ASC")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function countEnabled(): int
    {
        self::ensureTable();
        try {
            return (int)db()->query('SELECT COUNT(*) FROM scheduled_jobs WHERE enabled=1')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array[] N luot chay tiep theo (ke ca legacy bao cao ngay/tuan) */
    public static function upcoming(int $limit = 5): array
    {
        self::ensureTable();
        $out = [];
        try {
            $rows = db()->query('SELECT * FROM scheduled_jobs WHERE enabled=1 AND next_run_at IS NOT NULL
                ORDER BY next_run_at ASC LIMIT ' . max(1, min(20, $limit)))->fetchAll();
            foreach ($rows as $r) {
                $out[] = ['at' => $r['next_run_at'], 'name' => $r['name'],
                    'kind' => 'schedule', 'id' => (int)$r['id']];
            }
        } catch (Throwable $e) {
        }
        // Legacy: bao cao ngay/tuan cua notify (read-only preview)
        try {
            if (get_setting('notify_daily_enabled', '0') === '1') {
                $t = get_setting('notify_daily_time', '23:00');
                $at = date('Y-m-d') . ' ' . $t . ':00';
                if (strtotime($at) <= time()) $at = date('Y-m-d H:i:s', strtotime($at) + 86400);
                $out[] = ['at' => $at, 'name' => 'Báo cáo cuối ngày', 'kind' => 'legacy', 'id' => 0];
            }
        } catch (Throwable $e) {
        }
        usort($out, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));
        return array_slice($out, 0, max(1, min(20, $limit)));
    }

    public static function save(?int $id, array $in): ?array
    {
        self::ensureTable();
        $type = strtoupper((string)($in['schedule_type'] ?? 'DAILY'));
        if (!in_array($type, ['ONCE', 'DAILY', 'WEEKLY', 'INTERVAL'], true)) $type = 'DAILY';
        $cfg = $in['schedule_config'] ?? [];
        if (is_string($cfg)) $cfg = json_decode($cfg, true) ?: [];
        $tgt = $in['target_config'] ?? [];
        if (is_string($tgt)) $tgt = json_decode($tgt, true) ?: [];
        $row = ['name' => mb_substr((string)($in['name'] ?? 'Lịch'), 0, 190),
            'job_type' => mb_substr(strtoupper((string)($in['job_type'] ?? 'PROXY_CHECK')), 0, 40),
            'module' => mb_substr(strtoupper((string)($in['module'] ?? self::moduleFor((string)($in['job_type'] ?? '')))), 0, 30),
            'schedule_type' => $type,
            'schedule_config' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            'target_config' => json_encode($tgt, JSON_UNESCAPED_UNICODE),
            'enabled' => empty($in['enabled']) && array_key_exists('enabled', $in) ? 0 : 1];
        try {
            if ($id > 0) {
                db()->prepare('UPDATE scheduled_jobs SET name=?, job_type=?, module=?, schedule_type=?,
                        schedule_config=?, target_config=?, enabled=? WHERE id=?')
                    ->execute([$row['name'], $row['job_type'], $row['module'], $row['schedule_type'],
                        $row['schedule_config'], $row['target_config'], $row['enabled'], $id]);
            } else {
                db()->prepare('INSERT INTO scheduled_jobs (name, job_type, module, schedule_type,
                        schedule_config, target_config, enabled) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$row['name'], $row['job_type'], $row['module'], $row['schedule_type'],
                        $row['schedule_config'], $row['target_config'], $row['enabled']]);
                $id = (int)db()->lastInsertId();
            }
            self::refreshNext($id);
            $st = db()->prepare('SELECT * FROM scheduled_jobs WHERE id=?');
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function delete(int $id): bool
    {
        self::ensureTable();
        try {
            $st = db()->prepare('DELETE FROM scheduled_jobs WHERE id=?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function toggle(int $id, bool $on): bool
    {
        self::ensureTable();
        try {
            db()->prepare('UPDATE scheduled_jobs SET enabled=? WHERE id=?')->execute([$on ? 1 : 0, $id]);
            if ($on) self::refreshNext($id);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function moduleFor(string $jobType): string
    {
        return match (strtoupper($jobType)) {
            'EVALUATION' => 'EVALUATION',
            'BROWSER_START', 'BROWSER_STOP' => 'BROWSER',
            'AUTO_ACTIVITY' => 'AUTO_ACTIVITY',
            'PROXY_CHECK' => 'PROXY',
            default => 'SYSTEM',
        };
    }

    /** Tinh next_run_at tu config. */
    public static function computeNext(string $type, array $cfg, ?string $from = null): ?string
    {
        $base = $from ? strtotime($from) : time();
        if ($base === false) $base = time();
        try {
            if ($type === 'ONCE') {
                $at = strtotime((string)($cfg['at'] ?? ''));
                return ($at && $at > time()) ? date('Y-m-d H:i:s', $at) : null;
            }
            if ($type === 'INTERVAL') {
                $min = max(5, (int)($cfg['minutes'] ?? 60));
                return date('Y-m-d H:i:s', $base + $min * 60);
            }
            $time = (string)($cfg['time'] ?? '08:00');
            if (!preg_match('/^\d{2}:\d{2}/', $time)) $time = '08:00';
            if ($type === 'WEEKLY') {
                $wd = max(1, min(7, (int)($cfg['weekday'] ?? 1)));
                $d = strtotime('next Monday +' . ($wd - 1) . ' days', $base);
                $at = strtotime(date('Y-m-d', $d) . ' ' . substr($time, 0, 5));
                if ($at <= $base) $at += 7 * 86400;
                return date('Y-m-d H:i:s', $at);
            }
            // DAILY
            $at = strtotime(date('Y-m-d', $base) . ' ' . substr($time, 0, 5));
            if ($at <= $base) $at += 86400;
            return date('Y-m-d H:i:s', $at);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function refreshNext(int $id): void
    {
        try {
            $st = db()->prepare('SELECT * FROM scheduled_jobs WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) return;
            $next = self::computeNext((string)$r['schedule_type'],
                json_decode((string)($r['schedule_config'] ?? ''), true) ?: [],
                (string)($r['last_run_at'] ?? ''));
            db()->prepare('UPDATE scheduled_jobs SET next_run_at=? WHERE id=?')->execute([$next, $id]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Tick (throttled): lich den gio -> JobManager::create (source SCHEDULER).
     * @return array job_ids da tao
     */
    public static function tick(): array
    {
        static $last = 0;
        if ((time() - $last) < 60) return [];
        $last = time();
        self::ensureTable();
        $created = [];
        try {
            require_once __DIR__ . '/JobManager.php';
            $rows = db()->query("SELECT * FROM scheduled_jobs WHERE enabled=1
                AND next_run_at IS NOT NULL AND next_run_at<=NOW() ORDER BY next_run_at ASC LIMIT 5")->fetchAll();
            foreach ($rows as $r) {
                $targets = self::resolveTargets(json_decode((string)($r['target_config'] ?? ''), true) ?: [],
                    (string)$r['job_type']);
                $job = JobManager::create((string)$r['module'], (string)$r['name'], $targets, 'SCHEDULER',
                    ['job_type' => (string)$r['job_type'], 'created_by' => 'scheduler',
                        'notify_on_complete' => 1, 'notify_on_failure' => 1]);
                db()->prepare('UPDATE scheduled_jobs SET last_run_at=NOW(), last_job_id=? WHERE id=?')
                    ->execute([$job['job_id'], (int)$r['id']]);
                self::refreshNext((int)$r['id']);
                $created[] = $job['job_id'];
                try {
                    SyncLogger::info('scheduler', '[SCHED] fired schedule=' . $r['id'] . ' job=' . $job['job_id']);
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
        return $created;
    }

    /** target_config -> ids that: {profile_ids|all|due, proxy_ids|all}. */
    public static function resolveTargets(array $tgt, string $jobType): array
    {
        try {
            $jt = strtoupper($jobType);
            if ($jt === 'PROXY_CHECK') {
                if (($tgt['proxy_ids'] ?? '') === 'all' || empty($tgt['proxy_ids'])) {
                    return array_map(fn($r) => (int)$r['id'],
                        db()->query('SELECT id FROM proxies ORDER BY id')->fetchAll());
                }
                return array_values(array_filter(array_map('intval', (array)$tgt['proxy_ids'])));
            }
            $sel = $tgt['profile_ids'] ?? 'all';
            if ($sel === 'all' || $sel === '' || $sel === []) {
                return array_map(fn($r) => (int)$r['id'],
                    db()->query('SELECT id FROM profiles ORDER BY id')->fetchAll());
            }
            return array_values(array_filter(array_map('intval', (array)$sel)));
        } catch (Throwable $e) {
            return [];
        }
    }
}
