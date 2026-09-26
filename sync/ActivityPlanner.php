<?php
declare(strict_types=1);
/**
 * ActivityPlanner - Daily plan theo profile (§12-19, §21, §55-57).
 * Moi profile co Today's Plan: N sessions cach nhau gap ngau nhien, tasks
 * tuan thu limits ngay. Scheduler chay session due; khong timer/profile.
 * Session key unique -> idempotent; restart an toan (RUNNING cu -> PLANNED).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ActivityManager.php';
require_once __DIR__ . '/SyncLogger.php';

class ActivityPlanner
{
    public const ST_PLANNED = 'PLANNED';
    public const ST_RUNNING = 'RUNNING';
    public const ST_DONE = 'DONE';
    public const ST_SKIPPED = 'SKIPPED';
    public const ST_FAILED = 'FAILED';

    public const RUN_LATE_SEC = 1800; // tre <=30min van chay (§55)
    public const STALE_RUN_SEC = 1800; // RUNNING qua 30min -> PLANNED lai (§56)

    /** Task kinds trong session -> mapping chay o runSessionTask(). */
    public const KINDS = ['GMAIL', 'SEARCH', 'WEBSITE', 'DRIVE', 'CALENDAR', 'YOUTUBE', 'CUSTOM'];

    // ================= Plan =================

    /**
     * Dam bao plan hom nay (idempotent). @return array[] sessions hom nay
     * @return array{ok, sessions?, error?}
     */
    public static function ensureTodayPlan(int $profileId): array
    {
        ActivityManager::ensureTables();
        ActivityManager::seedPoolsFromConfigs();
        $today = date('Y-m-d');
        try {
            $ex = db()->prepare('SELECT COUNT(*) FROM activity_sessions WHERE profile_id=? AND plan_date=?');
            $ex->execute([$profileId, $today]);
            if ((int)$ex->fetchColumn() > 0) return ['ok' => true, 'sessions' => self::todayPlan($profileId)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        $cfg = ActivityManager::getConfig($profileId);
        if (empty($cfg['enabled'])) return ['ok' => false, 'error' => 'disabled'];
        $t0 = microtime(true);
        $sessions = self::generate($profileId, $cfg, $today);
        try {
            $st = db()->prepare('INSERT IGNORE INTO activity_sessions
                (session_key, profile_id, plan_date, run_at, tasks_json, status)
                VALUES (?,?,?,?,?,?)');
            foreach ($sessions as $s) {
                $st->execute([$s['key'], $profileId, $today, $s['run_at'],
                    json_encode($s['tasks'], JSON_UNESCAPED_UNICODE), self::ST_PLANNED]);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        try {
            SyncLogger::info('activity', '[PLANNER] profile=' . $profileId
                . ' sessions=' . count($sessions) . ' ms=' . (int)round((microtime(true) - $t0) * 1000), $profileId);
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'sessions' => self::todayPlan($profileId)];
    }

    /**
     * Sinh sessions: N trong [start+15p, end-15p], gap random, jitter theo
     * profile (spread 32 profile §21).
     * @return array[{key, run_at, tasks[]}]
     */
    private static function generate(int $profileId, array $cfg, string $today): array
    {
        // Ngay nghi? Van tao plan rong (UI hien "nghi") — scheduler bo qua
        $dow = (int)date('N');
        $days = array_map('intval', explode(',', (string)($cfg['active_days'] ?? '1,2,3,4,5,6,7')));
        if (!in_array($dow, $days, true)) return [];
        $n = mt_rand((int)$cfg['sessions_min'], (int)$cfg['sessions_max']);
        $ss = (string)($cfg['schedule_start'] ?? '08:00');
        $se = (string)($cfg['schedule_end'] ?? '22:00');
        $winStart = strtotime("$today $ss") + 900;
        $winEnd = strtotime("$today $se") - 900;
        if ($winEnd <= $winStart) $winEnd = $winStart + 3600;
        $gapMin = max(5, (int)$cfg['gap_min']) * 60;
        $gapMax = max($gapMin, (int)$cfg['gap_max'] * 60);
        // Jitter rieng profile de khong dong loat (§21)
        $t = $winStart + (($profileId * 37) % max(1, (int)$cfg['gap_min'])) * 60;
        $t = min($t, $winEnd);
        $limits = ActivityManager::limitsFor($cfg);
        $planned = ['SEARCH' => 0, 'WEBSITE' => 0, 'GMAIL' => 0, 'DRIVE' => 0, 'CALENDAR' => 0];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($t > $winEnd && $i > 0) break;
            $runAt = date('Y-m-d H:i:s', min($t, $winEnd));
            $tasks = self::pickTasks($cfg, $limits, $planned);
            foreach ($tasks as $tk) {
                $kk = strtoupper((string)($tk['kind'] ?? ''));
                if (isset($planned[$kk])) $planned[$kk]++;
            }
            $out[] = ['key' => "act-$today-p$profileId-s$i", 'run_at' => $runAt, 'tasks' => $tasks];
            $t += mt_rand($gapMin, $gapMax);
        }
        return $out;
    }

    /**
     * Chon 1-3 task kinds tuan thu limits ngay (planned + hom nay da chay).
     * @return array[{kind, ref?}]
     */
    private static function pickTasks(array $cfg, array $limits, array $planned): array
    {
        $mode = (string)($cfg['activity_mode'] ?? 'maintain');
        $pool = $mode === 'maintain'
            ? ['GMAIL', 'GMAIL', 'WEBSITE', 'DRIVE', 'CALENDAR']
            : ($mode === 'search'
                ? ['GMAIL', 'SEARCH', 'SEARCH', 'WEBSITE', 'DRIVE', 'CALENDAR']
                : ['GMAIL', 'SEARCH', 'WEBSITE', 'WEBSITE', 'DRIVE', 'CALENDAR', 'YOUTUBE', 'CUSTOM']);
        // Custom URLs rieng: them CUSTOM neu co
        $customs = [];
        foreach ((array)($cfg['required_pages'] ?? []) as $item) {
            if (is_array($item) && !empty($item['url'])) $customs[] = $item;
        }
        $n = mt_rand((int)$cfg['tasks_min'], (int)$cfg['tasks_max']);
        $doneToday = [];
        try {
            $doneToday = ActivityManager::countToday((int)($cfg['profile_id'] ?? 0));
        } catch (Throwable $e) {
        }
        $tasks = [];
        $usedKinds = [];
        $tries = 0;
        while (count($tasks) < $n && $tries < 20) {
            $tries++;
            $kind = $pool[array_rand($pool)];
            if ($kind === 'CUSTOM' && !$customs) continue;
            // Khong lap kind trong cung session (tru WEBSITE/SEARCH cho phep 2?)
            if (in_array($kind, $usedKinds, true) && $kind !== 'SEARCH') continue;
            $limKey = in_array($kind, ['YOUTUBE', 'CUSTOM'], true) ? 'WEBSITE' : $kind;
            $max = (int)($limits[$limKey][1] ?? 99);
            $used = (int)($doneToday[$limKey] ?? 0) + (int)($planned[$limKey] ?? 0);
            if ($used >= $max) continue;
            $task = ['kind' => $kind];
            if ($kind === 'CUSTOM') {
                $c = $customs[array_rand($customs)];
                $task['url'] = (string)$c['url'];
                $task['label'] = (string)($c['label'] ?? '');
            }
            $tasks[] = $task;
            $usedKinds[] = $kind;
        }
        if (!$tasks) $tasks[] = ['kind' => 'GMAIL'];
        return $tasks;
    }

    /** @return array[] sessions hom nay (som nhat truoc) */
    public static function todayPlan(int $profileId): array
    {
        ActivityManager::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM activity_sessions WHERE profile_id=? AND plan_date=CURDATE() ORDER BY run_at ASC');
            $st->execute([$profileId]);
            $rows = $st->fetchAll();
            foreach ($rows as &$r) {
                $t = json_decode((string)($r['tasks_json'] ?? ''), true);
                $r['tasks'] = is_array($t) ? $t : [];
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Tao lai lich CON LAI hom nay (§19): xoa PLANNED future, generate lai.
     * Khong dong completed/history.
     */
    public static function regenerate(int $profileId): array
    {
        ActivityManager::ensureTables();
        try {
            db()->prepare("DELETE FROM activity_sessions WHERE profile_id=? AND plan_date=CURDATE()
                AND status='PLANNED' AND run_at>NOW()")->execute([$profileId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        $cfg = ActivityManager::getConfig($profileId);
        $today = date('Y-m-d');
        // Chi generate future: dich window start ve hien tai + 15p
        $nowPlus = date('H:i', time() + 900);
        if ($nowPlus > (string)$cfg['schedule_start']) $cfg['schedule_start'] = $nowPlus;
        $sessions = self::generate($profileId, $cfg, $today);
        // Loai past (chi future)
        $sessions = array_values(array_filter($sessions, fn($s) => strtotime($s['run_at']) > time()));
        try {
            $st = db()->prepare('INSERT IGNORE INTO activity_sessions
                (session_key, profile_id, plan_date, run_at, tasks_json, status)
                VALUES (?,?,?,?,?,?)');
            foreach ($sessions as $i => $s) {
                $st->execute(["act-$today-p$profileId-r$i-" . time(), $profileId, $today, $s['run_at'],
                    json_encode($s['tasks'], JSON_UNESCAPED_UNICODE), self::ST_PLANNED]);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        return ['ok' => true, 'sessions' => self::todayPlan($profileId)];
    }

    // ================= Execution =================

    /**
     * Chay sessions due (toi da $maxSessions). Tra ve so session da xu ly.
     * Missed >30min -> SKIPPED (§55); trong han -> chay ngay (RUN_LATE).
     */
    public static function runDueSessions(int $profileId, int $maxSessions = 2): int
    {
        ActivityManager::ensureTables();
        self::bootRecover();
        $cfg = ActivityManager::getConfig($profileId);
        if (empty($cfg['enabled']) || empty($cfg['planner_enabled'])) return 0;
        self::ensureTodayPlan($profileId);
        $done = 0;
        try {
            $st = db()->prepare("SELECT * FROM activity_sessions WHERE profile_id=?
                AND plan_date=CURDATE() AND status='PLANNED' AND run_at<=NOW() ORDER BY run_at ASC LIMIT 10");
            $st->execute([$profileId]);
            $rows = $st->fetchAll();
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($rows as $row) {
            if ($done >= $maxSessions) break;
            $late = time() - strtotime((string)$row['run_at']);
            if ($late > self::RUN_LATE_SEC) {
                self::setStatus((int)$row['id'], self::ST_SKIPPED, 'MISSED_WINDOW');
                continue;
            }
            self::runSession($profileId, $row, $cfg);
            $done++;
        }
        self::updateNextRun($profileId);
        return $done;
    }

    private static function setStatus(int $id, string $status, ?string $error = null): void
    {
        try {
            db()->prepare('UPDATE activity_sessions SET status=?, error_code=?,
                    started_at=CASE WHEN ? IN (\'RUNNING\',\'DONE\',\'FAILED\') THEN COALESCE(started_at, NOW()) ELSE started_at END,
                    completed_at=CASE WHEN ? IN (\'DONE\',\'FAILED\',\'SKIPPED\') THEN NOW() ELSE completed_at END
                WHERE id=?')
                ->execute([$status, $error, $status, $status, $id]);
        } catch (Throwable $e) {
        }
    }

    /** Chay 1 session: guard giong scheduler + tasks tuan tu. */
    private static function runSession(int $profileId, array $row, array $cfg): void
    {
        $sid = (int)$row['id'];
        // Guard tai thoi diem chay (profile co the tat giua chung)
        $fresh = ActivityManager::getConfig($profileId);
        if (empty($fresh['enabled'])) {
            self::setStatus($sid, self::ST_SKIPPED, 'DISABLED');
            return;
        }
        try {
            require_once __DIR__ . '/ActivityScheduler.php';
            if (!ActivityScheduler::inHours($fresh)) {
                self::setStatus($sid, self::ST_SKIPPED, 'OFF_HOURS');
                return;
            }
            if (ActivityScheduler::paused($fresh)) {
                self::setStatus($sid, self::ST_SKIPPED, 'PAUSED');
                return;
            }
            require_once __DIR__ . '/ChromeBatchManager.php';
            if (ChromeBatchManager::isBusy($profileId)
                || ActivityScheduler::evalRunning($profileId)
                || ActivityScheduler::restoring($profileId)) {
                // Hoan lai 5 phut (khong SKIP) — tranh mat session khi eval vua xong
                try {
                    db()->prepare('UPDATE activity_sessions SET run_at=DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id=?')
                        ->execute([$sid]);
                } catch (Throwable $e) {
                }
                return;
            }
            // Chrome tat: SKIP (hoac auto-start nhu scheduler cu §30)
            $running = false;
            try {
                $cst = db()->prepare('SELECT status FROM profiles WHERE id=?');
                $cst->execute([$profileId]);
                $running = (($cst->fetchColumn() ?? '') === 'running');
            } catch (Throwable $e) {
            }
            if (!$running) {
                if (!empty($fresh['auto_start_profile'])) {
                    if (!ActivityScheduler::autoStart($profileId)) {
                        self::setStatus($sid, self::ST_SKIPPED, 'AUTOSTART_FAILED');
                        return;
                    }
                } else {
                    self::setStatus($sid, self::ST_SKIPPED, 'CHROME_STOPPED');
                    return;
                }
            }
        } catch (Throwable $e) {
        }
        self::setStatus($sid, self::ST_RUNNING);
        $t0 = microtime(true);
        $tasks = json_decode((string)($row['tasks_json'] ?? ''), true);
        if (!is_array($tasks)) $tasks = [];
        $ok = 0;
        $fail = 0;
        $excludeDomains = [];
        foreach ($tasks as $tk) {
            $kind = strtoupper((string)($tk['kind'] ?? ''));
            $mapped = self::mapTask($profileId, $tk, $excludeDomains);
            if ($mapped === null) continue;
            try {
                $r = ActivityManager::runTask($profileId, $mapped);
            } catch (Throwable $e) {
                $r = ['ok' => false, 'result' => 'EXCEPTION'];
            }
            if (!empty($r['ok']) || ($r['result'] ?? '') === ActivityManager::R_REUSED) $ok++;
            else $fail++;
            // Domain vua dung -> exclude trong session (§7)
            if (!empty($tk['url'])) $excludeDomains[] = ActivityManager::domainOf((string)$tk['url']);
            if ($kind === 'WEBSITE' && !empty($r['tab'])) {
                // tab vua mo: lay domain thuc te? dung exclude rong — pool tu tranh lap gan
            }
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        try {
            db()->prepare('UPDATE activity_sessions SET status=?, success_count=?, failed_count=?,
                    completed_at=NOW() WHERE id=?')
                ->execute([$fail > 0 && $ok === 0 ? self::ST_FAILED : self::ST_DONE, $ok, $fail, $sid]);
            db()->prepare('UPDATE activity_configs SET last_run_at=NOW() WHERE profile_id=?')
                ->execute([$profileId]);
        } catch (Throwable $e) {
        }
        try {
            SyncLogger::info('activity', '[SESSION] profile=' . $profileId . ' session=' . $sid
                . " ok=$ok fail=$fail duration=" . $ms . 'ms', $profileId);
        } catch (Throwable $e) {
        }
        self::bumpDayJob($profileId);
        self::updateNextRun($profileId);
    }

    /**
     * Map session task -> runTask. SEARCH/WEBSITE pick tai luc chay
     * (pool moi nhat, tranh lap).
     * @return array|null task cho runTask (null = bo qua)
     */
    private static function mapTask(int $profileId, array $tk, array $excludeDomains): ?array
    {
        $kind = strtoupper((string)($tk['kind'] ?? ''));
        switch ($kind) {
            case 'GMAIL':
                return ['type' => ActivityManager::T_ENSURE, 'page' => 'gmail'];
            case 'DRIVE':
                return ['type' => ActivityManager::T_ENSURE, 'page' => 'drive'];
            case 'CALENDAR':
                return ['type' => ActivityManager::T_ENSURE, 'page' => 'calendar'];
            case 'YOUTUBE':
                return ['type' => ActivityManager::T_ENSURE, 'page' => 'youtube'];
            case 'CUSTOM':
                $url = trim((string)($tk['url'] ?? ''));
                if (!ActivityManager::validHttpUrl($url)) return null;
                return ['type' => ActivityManager::T_OPEN, 'url' => $url];
            case 'SEARCH': {
                $cfg = ActivityManager::getConfig($profileId);
                $limits = ActivityManager::limitsFor($cfg);
                $pick = ActivityManager::pickSearch($profileId, (int)($limits['SEARCH'][1] ?? 0));
                if (!$pick) return null;
                return ['type' => ActivityManager::T_SEARCH, 'query' => (string)$pick['query']];
            }
            case 'WEBSITE': {
                $cfg = ActivityManager::getConfig($profileId);
                $limits = ActivityManager::limitsFor($cfg);
                $done = ActivityManager::countToday($profileId);
                if ($done['WEBSITE'] >= (int)($limits['WEBSITE'][1] ?? 99)) return null;
                return ['type' => ActivityManager::T_WEBSITE, 'exclude' => $excludeDomains];
            }
            default:
                return null;
        }
    }

    /** next_run_at = PLANNED future som nhat (cho card "Tiep theo"). */
    public static function updateNextRun(int $profileId): void
    {
        try {
            $st = db()->prepare("SELECT MIN(run_at) FROM activity_sessions WHERE profile_id=?
                AND plan_date=CURDATE() AND status='PLANNED' AND run_at>NOW()");
            $st->execute([$profileId]);
            $next = $st->fetchColumn();
            db()->prepare('UPDATE activity_configs SET next_run_at=? WHERE profile_id=?')
                ->execute([$next ?: null, $profileId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Job ngay cho Job Center (§22): 1 job/profile/ngay, progress = sessions.
     * Khong spam: reuse job hom nay neu co.
     */
    public static function bumpDayJob(int $profileId): void
    {
        try {
            require_once __DIR__ . '/JobManager.php';
            JobManager::ensureSchema();
            $today = date('Y-m-d');
            $targets = json_encode([$profileId]);
            $st = db()->prepare("SELECT * FROM app_jobs WHERE module='AUTO_ACTIVITY'
                AND DATE(created_at)=? AND targets=? ORDER BY created_at DESC LIMIT 1");
            $st->execute([$today, $targets]);
            $job = $st->fetch();
            $cnt = db()->prepare('SELECT
                    SUM(status IN (\'DONE\',\'FAILED\')) AS done,
                    COUNT(*) AS total,
                    SUM(status=\'DONE\') AS ok,
                    SUM(status=\'FAILED\') AS fail
                FROM activity_sessions WHERE profile_id=? AND plan_date=?');
            $cnt->execute([$profileId, $today]);
            $c = $cnt->fetch() ?: ['done' => 0, 'total' => 0, 'ok' => 0, 'fail' => 0];
            if (!$job) {
                $j = JobManager::create('AUTO_ACTIVITY', "Auto Activity #$profileId ($today)",
                    [$profileId], 'SYSTEM', ['job_type' => 'SESSION_DAY', 'created_by' => 'planner',
                        'notify_on_complete' => 0, 'notify_on_failure' => 1, 'resumable' => 1]);
                $job = JobManager::get($j['job_id']);
            }
            if ($job && in_array($job['status'], ['QUEUED', 'RUNNING'], true)) {
                JobManager::update((string)$job['job_id'], [
                    'status' => 'RUNNING',
                    'progress_done' => (int)($c['done'] ?? 0),
                    'progress_total' => max(1, (int)($c['total'] ?? 0)),
                    'success_count' => (int)($c['ok'] ?? 0),
                    'failed_count' => (int)($c['fail'] ?? 0)]);
            }
        } catch (Throwable $e) {
        }
    }

    /** Boot recovery (§56): RUNNING cu -> PLANNED (throttle 5 phut). */
    public static function bootRecover(): void
    {
        static $last = 0;
        if ((time() - $last) < 300) return;
        $last = time();
        try {
            ActivityManager::ensureTables();
            db()->prepare("UPDATE activity_sessions SET status='PLANNED', started_at=NULL
                WHERE status='RUNNING' AND (started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))")
                ->execute();
        } catch (Throwable $e) {
        }
    }

    /** Tom tat hom nay cho UI (§41). */
    public static function summary(int $profileId): array
    {
        ActivityManager::ensureTables();
        $out = ['sessions_done' => 0, 'sessions_total' => 0, 'tasks_done' => 0,
            'tasks_total' => 0, 'success' => 0, 'failed' => 0, 'next_in' => null, 'next_at' => null];
        try {
            $st = db()->prepare("SELECT status, COUNT(*) c, SUM(success_count) ok, SUM(failed_count) fail
                FROM activity_sessions WHERE profile_id=? AND plan_date=CURDATE() GROUP BY status");
            $st->execute([$profileId]);
            foreach ($st->fetchAll() as $r) {
                $c = (int)$r['c'];
                $out['sessions_total'] += $c;
                // Done = DONE/FAILED (SKIPPED khong tinh hoan tat §41)
                if (in_array($r['status'], ['DONE', 'FAILED'], true)) $out['sessions_done'] += $c;
                $out['success'] += (int)($r['ok'] ?? 0);
                $out['failed'] += (int)($r['fail'] ?? 0);
            }
            $h = db()->prepare('SELECT COUNT(*) FROM activity_history WHERE profile_id=? AND created_at>=CURDATE()');
            $h->execute([$profileId]);
            $out['tasks_done'] = (int)$h->fetchColumn();
            $nx = db()->prepare("SELECT MIN(run_at) FROM activity_sessions WHERE profile_id=?
                AND plan_date=CURDATE() AND status='PLANNED' AND run_at>NOW()");
            $nx->execute([$profileId]);
            $next = $nx->fetchColumn();
            if ($next) {
                $out['next_at'] = $next;
                $sec = max(0, strtotime((string)$next) - time());
                $out['next_in'] = $sec < 3600 ? ((int)ceil($sec / 60) . 'm') : (round($sec / 3600, 1) . 'h');
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** Tong hop ngay cho Telegram daily report (§59). */
    public static function dailySummary(): array
    {
        ActivityManager::ensureTables();
        $out = ['profiles' => 0, 'sessions' => 0, 'sessions_done' => 0, 'success' => 0,
            'failed' => 0, 'search' => 0, 'web' => 0, 'gmail' => 0, 'need_attention' => 0];
        try {
            $out['profiles'] = (int)db()->query('SELECT COUNT(*) FROM activity_configs WHERE enabled=1')->fetchColumn();
            $r = db()->query("SELECT COUNT(*) t, SUM(status IN ('DONE','FAILED','SKIPPED')) d,
                    SUM(success_count) ok, SUM(failed_count) fail, COUNT(DISTINCT profile_id) p
                FROM activity_sessions WHERE plan_date=CURDATE()")->fetch();
            if ($r) {
                $out['sessions'] = (int)($r['t'] ?? 0);
                $out['sessions_done'] = (int)($r['d'] ?? 0);
                $out['success'] = (int)($r['ok'] ?? 0);
                $out['failed'] = (int)($r['fail'] ?? 0);
            }
            $h = db()->query("SELECT task_type, domain, COUNT(*) c FROM activity_history
                WHERE created_at>=CURDATE() GROUP BY task_type, domain")->fetchAll();
            foreach ($h as $row) {
                $c = (int)$row['c'];
                if (($row['task_type'] ?? '') === 'OPEN_SEARCH') $out['search'] += $c;
                elseif (($row['task_type'] ?? '') === 'OPEN_RANDOM_WEBSITE') $out['web'] += $c;
                elseif (str_contains(strtolower((string)($row['domain'] ?? '')), 'mail.google')) $out['gmail'] += $c;
            }
            $out['need_attention'] = (int)db()->query("SELECT COUNT(DISTINCT profile_id) FROM activity_history
                WHERE created_at>=CURDATE() AND (error_code IS NOT NULL OR result IN ('BLOCKED','MISSING'))")->fetchColumn();
        } catch (Throwable $e) {
        }
        return $out;
    }
}
