<?php
declare(strict_types=1);
/**
 * MonitoringService - aggregation cho module "Thong ke & Theo doi".
 * Moi so lieu tu DB that (profiles/account_states/proxies/alerts/history/snapshots).
 * Khong fake chart: trend thieu snapshot -> series rong.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AlertManager.php';
require_once __DIR__ . '/StateHistory.php';

class MonitoringService
{
    public static function hasMonCols(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $r = db()->query("SHOW COLUMNS FROM profiles LIKE 'monitor_enabled'")->fetch();
            $has = (bool)$r;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    public static function hasEvalCols(): bool
    {
        try {
            return (bool)db()->query("SHOW COLUMNS FROM account_states LIKE 'eval_status'")->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Tong hop KPI. $filter: watchlist-only? */
    public static function get_summary(bool $watchlistOnly = false): array
    {
        $w = $watchlistOnly && self::hasMonCols() ? 'WHERE p.monitor_enabled=1 AND p.watchlist=1' : '';
        try {
            $rows = db()->query(
                'SELECT p.id, p.status AS runtime, p.proxy_id, pr.status AS proxy_status,'
                . ' s.eval_status, s.last_known_status, s.last_attempt_status, s.stage'
                . ' FROM profiles p LEFT JOIN proxies pr ON pr.id=p.proxy_id'
                . ' LEFT JOIN account_states s ON s.profile_id=p.id'
                . " $w"
            )->fetchAll();
        } catch (Throwable $e) {
            try {
                $rows = db()->query(
                    'SELECT p.id, p.status AS runtime, p.proxy_id, pr.status AS proxy_status,'
                    . " NULL AS eval_status, NULL AS last_known_status, NULL AS last_attempt_status FROM profiles p LEFT JOIN proxies pr ON pr.id=p.proxy_id $w"
                )->fetchAll();
            } catch (Throwable $e2) {
                $rows = [];
            }
        }
        $total = count($rows);
        $active = 0;
        $issues = 0;
        $unchecked = 0;
        $checking = 0;
        $running = 0;
        $withProxy = 0;
        $proxyDead = 0;
        $evalErrors = 0; // attempt moi nhat loi, rieng voi channel status
        foreach ($rows as $r) {
            // Dashboard dung last_known_channel_status (khong dung attempt status)
            $ev = (string)($r['last_known_status'] ?? '');
            if ($ev === '' || $ev === null) $ev = (string)($r['eval_status'] ?? 'UNCHECKED');
            if ($ev === '' || $ev === null) $ev = 'UNCHECKED';
            if (($r['runtime'] ?? '') === 'running') $running++;
            if (!empty($r['proxy_id'])) $withProxy++;
            if (!empty($r['proxy_id']) && ($r['proxy_status'] ?? '') === 'dead') $proxyDead++;
            if (in_array(($r['last_attempt_status'] ?? ''), ['FAILED', 'TIMEOUT'], true)) $evalErrors++;
            if ($ev === 'ACTIVE') $active++;
            elseif ($ev === 'UNCHECKED') $unchecked++;
            elseif ($ev === 'CHECKING') $checking++;
            else $issues++; // LOGIN_REQUIRED/VERIFICATION_REQUIRED/UNAVAILABLE/RESTRICTED/ERROR
        }
        $alerts = AlertManager::counts();
        self::maybeSnapshot($total, $active, $issues, $unchecked, $running, $proxyDead);
        // Monitoring summary: coverage / check gan day / qua han / watchlist.
        // Stale threshold lay tu config monitor interval (acc_max_data_age_h), khong hard-code.
        $coverage = $total;
        $watchlist = 0;
        $checkedRecent = 0;
        $stale = 0;
        $staleH = 72;
        try {
            $staleH = max(1, (int)get_setting('acc_max_data_age_h', '72'));
        } catch (Throwable $e) {
        }
        try {
            if (self::hasMonCols()) {
                $coverage = (int)db()->query('SELECT COUNT(*) FROM profiles WHERE monitor_enabled=1' . ($watchlistOnly ? ' AND watchlist=1' : ''))->fetchColumn();
                $watchlist = (int)db()->query('SELECT COUNT(*) FROM profiles WHERE watchlist=1')->fetchColumn();
            }
            if (self::hasEvalCols()) {
                $w = $watchlistOnly && self::hasMonCols() ? 'AND p.watchlist=1' : '';
                $checkedRecent = (int)db()->query(
                    "SELECT COUNT(*) FROM account_states s JOIN profiles p ON p.id=s.profile_id WHERE s.last_attempt_at >= DATE_SUB(NOW(), INTERVAL $staleH HOUR) $w")->fetchColumn();
                $stale = max(0, $total - $checkedRecent);
            }
        } catch (Throwable $e) {
        }
        return ['total' => $total, 'active' => $active, 'issues' => $issues,
            'unchecked' => $unchecked, 'checking' => $checking, 'running' => $running,
            'withProxy' => $withProxy, 'proxyDead' => $proxyDead, 'evalErrors' => $evalErrors,
            'proxyOk' => max(0, $withProxy - $proxyDead),
            'alerts' => $alerts['total'], 'alertsCritical' => $alerts['CRITICAL'],
            'coverage' => $coverage, 'watchlist' => $watchlist,
            'checkedRecent' => $checkedRecent, 'stale' => $stale, 'staleHours' => $staleH];
    }

    /** Phan bo eval status (count + percent). */
    public static function get_status_distribution(bool $watchlistOnly = false): array
    {
        $s = self::get_summary($watchlistOnly);
        $total = max(1, $s['total']);
        $keys = ['ACTIVE' => $s['active'], 'ISSUES' => $s['issues'], 'UNCHECKED' => $s['unchecked'], 'CHECKING' => $s['checking']];
        // Chi tiet issues theo eval_status that
        $detail = [];
        try {
            $w = $watchlistOnly && self::hasMonCols() ? 'WHERE p.watchlist=1' : '';
            foreach (db()->query("SELECT s.eval_status, COUNT(*) c FROM profiles p LEFT JOIN account_states s ON s.profile_id=p.id $w GROUP BY s.eval_status") as $r) {
                $k = (string)($r['eval_status'] ?? 'UNCHECKED');
                if ($k === '') $k = 'UNCHECKED';
                $detail[$k] = (int)$r['c'];
            }
        } catch (Throwable $e) {
        }
        $out = [];
        foreach (['ACTIVE', 'LOGIN_REQUIRED', 'VERIFICATION_REQUIRED', 'CHANNEL_UNAVAILABLE', 'ERROR', 'CHECKING', 'UNCHECKED'] as $k) {
            $c = $k === 'ACTIVE' ? $s['active'] : ($detail[$k] ?? 0);
            if ($k === 'UNCHECKED') $c = $s['unchecked'];
            if ($k === 'CHECKING') $c = $s['checking'];
            $out[] = ['status' => $k, 'count' => $c, 'pct' => round(100 * $c / $total, 1)];
        }
        return $out;
    }

    /** Phan bo theo stage/platform/proxy. */
    public static function get_distribution(string $by = 'stage'): array
    {
        $by = strtolower($by);
        try {
            if ($by === 'platform') {
                return db()->query('SELECT platform AS k, COUNT(*) c FROM profiles GROUP BY platform ORDER BY c DESC')->fetchAll();
            }
            if ($by === 'proxy') {
                $with = (int)db()->query('SELECT COUNT(*) FROM profiles WHERE proxy_id IS NOT NULL')->fetchColumn();
                $total = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
                $dead = (int)db()->query("SELECT COUNT(*) FROM profiles p JOIN proxies pr ON pr.id=p.proxy_id WHERE pr.status='dead'")->fetchColumn();
                return [['k' => 'Co proxy', 'c' => $with], ['k' => 'Proxy loi', 'c' => $dead], ['k' => 'Khong proxy', 'c' => $total - $with]];
            }
            if ($by === 'monitoring' && self::hasMonCols()) {
                return db()->query('SELECT IF(monitor_enabled=1,\'Dang theo doi\',\'Tat\') AS k, COUNT(*) c FROM profiles GROUP BY monitor_enabled')->fetchAll();
            }
            // stage (account lifecycle, khong tron eval)
            return db()->query('SELECT COALESCE(s.stage,\'NEW\') AS k, COUNT(*) c FROM profiles p LEFT JOIN account_states s ON s.profile_id=p.id GROUP BY k ORDER BY c DESC')->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Rows kenh cho tab Kenh: filter server-side + pagination (100-1000+ channels).
     * $f: search, eval, chrome, stage, platform, proxy(none|dead|ok), monitoring(on|off), alert(yes), watch(1)
     */
    public static function get_channel_rows(array $f, int $page = 1, int $per = 20): array
    {
        $per = max(5, min(100, $per));
        $page = max(1, $page);
        $w = [];
        $p = [];
        $search = trim((string)($f['search'] ?? ''));
        if ($search !== '') {
            $w[] = '(p.name LIKE ? OR p.channel_handle LIKE ?)';
            $p[] = "%$search%";
            $p[] = "%$search%";
        }
        if (!empty($f['platform'])) {
            $w[] = 'p.platform=?';
            $p[] = $f['platform'];
        }
        if (!empty($f['chrome'])) {
            $w[] = 'p.status=?';
            $p[] = $f['chrome'];
        }
        $hasEval = self::hasEvalCols();
        if (!empty($f['eval']) && $hasEval) {
            if ($f['eval'] === 'ISSUES') {
                $w[] = "COALESCE(s.eval_status,'UNCHECKED') IN ('LOGIN_REQUIRED','VERIFICATION_REQUIRED','CHANNEL_UNAVAILABLE','ERROR')";
            } else {
                $w[] = "COALESCE(s.eval_status,'UNCHECKED')=?";
                $p[] = $f['eval'];
            }
        }
        if (!empty($f['stage'])) {
            $w[] = "COALESCE(s.stage,'NEW')=?";
            $p[] = $f['stage'];
        }
        if (isset($f['proxy']) && $f['proxy'] !== '') {
            if ($f['proxy'] === 'none') $w[] = 'p.proxy_id IS NULL';
            elseif ($f['proxy'] === 'dead') $w[] = "pr.status='dead'";
            elseif ($f['proxy'] === 'ok') $w[] = "pr.status='alive'";
        }
        $hasMon = self::hasMonCols();
        if (!empty($f['monitoring']) && $hasMon) {
            $w[] = $f['monitoring'] === 'on' ? 'p.monitor_enabled=1' : 'p.monitor_enabled=0';
        }
        if (!empty($f['watch']) && $hasMon) {
            $w[] = 'p.watchlist=1';
        }
        if (!empty($f['alert'])) {
            $w[] = 'EXISTS (SELECT 1 FROM channel_alerts a WHERE a.profile_id=p.id AND a.status=\'OPEN\')';
        }
        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        try {
            $cnt = db()->prepare("SELECT COUNT(*) FROM profiles p LEFT JOIN proxies pr ON pr.id=p.proxy_id LEFT JOIN account_states s ON s.profile_id=p.id $where");
            $cnt->execute($p);
            $total = (int)$cnt->fetchColumn();
            $off = ($page - 1) * $per;
            $monSel = $hasMon ? ', p.monitor_enabled, p.watchlist' : ', 1 AS monitor_enabled, 0 AS watchlist';
            $evalSel = $hasEval ? ', s.eval_status, s.last_attempt_at AS eval_attempt, s.stability, s.confidence, s.stage, s.success_count, s.fail_count' : ', \'UNCHECKED\' AS eval_status, NULL AS eval_attempt, NULL AS stability, NULL AS confidence, NULL AS stage, NULL AS success_count, NULL AS fail_count';
            $st = db()->prepare(
                "SELECT p.id, p.name, p.platform, p.status AS runtime, p.proxy_id, p.channel_handle,"
                . ' pr.host AS proxy_host, pr.status AS proxy_status,'
                . " (SELECT COUNT(*) FROM tab_sessions t WHERE t.profile_id=p.id AND t.kind='current') AS has_tabs,"
                . ' (SELECT COUNT(*) FROM channel_alerts a WHERE a.profile_id=p.id AND a.status=\'OPEN\') AS open_alerts'
                . $monSel . $evalSel
                . " FROM profiles p LEFT JOIN proxies pr ON pr.id=p.proxy_id LEFT JOIN account_states s ON s.profile_id=p.id"
                . " $where ORDER BY p.id DESC LIMIT $per OFFSET $off"
            );
            $st->execute($p);
            $rows = $st->fetchAll();
            // tab count that (restorable count)
            return ['total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows];
        } catch (Throwable $e) {
            return ['total' => 0, 'page' => $page, 'per' => $per, 'rows' => []];
        }
    }

    /** Thay doi gan nhat (state_history + eval history). */
    public static function get_recent_changes(int $limit = 20): array
    {
        return StateHistory::timeline(null, null, null, max(1, min(50, $limit)), 0);
    }

    /**
     * Trend that theo range (7/30/90 ngay) + metric.
     * Metric map sang cot snapshot; truoc snapshot dau tien -> series rong (khong fake).
     */
    public static function get_trends(int $days = 7, string $metric = 'active'): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 7;
        $colMap = ['active' => 'active', 'issues' => 'issues', 'login' => 'login_required',
            'verify' => 'verification_required', 'proxy' => 'proxy_dead', 'evalfail' => 'eval_failed'];
        $col = $colMap[$metric] ?? 'active';
        try {
            $st = db()->prepare("SELECT taken_at, `$col` AS v FROM monitor_snapshots WHERE taken_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY taken_at ASC");
            $st->execute([$days]);
            $pts = [];
            foreach ($st->fetchAll() as $r) {
                $pts[] = ['t' => $r['taken_at'], 'v' => (int)$r['v']];
            }
            return ['metric' => $metric, 'days' => $days, 'points' => $pts];
        } catch (Throwable $e) {
            return ['metric' => $metric, 'days' => $days, 'points' => []];
        }
    }

    /** Ghi snapshot neu cu >30 phut (goi tu summary; khong query moi render). */
    public static function maybeSnapshot(int $total, int $active, int $issues, int $unchecked, int $running, int $proxyDead): void
    {
        try {
            $last = db()->query('SELECT taken_at FROM monitor_snapshots ORDER BY id DESC LIMIT 1')->fetchColumn();
            if ($last && (time() - strtotime((string)$last)) < 1800) return;
            $lr = 0;
            $vr = 0;
            $ef = 0;
            if (self::hasEvalCols()) {
                $lr = (int)db()->query("SELECT COUNT(*) FROM account_states WHERE eval_status='LOGIN_REQUIRED'")->fetchColumn();
                $vr = (int)db()->query("SELECT COUNT(*) FROM account_states WHERE eval_status='VERIFICATION_REQUIRED'")->fetchColumn();
                $ef = (int)db()->query("SELECT COUNT(*) FROM account_states WHERE eval_status='ERROR'")->fetchColumn();
            }
            db()->prepare('INSERT INTO monitor_snapshots (taken_at, total, active, issues, unchecked, running, proxy_dead, eval_failed, login_required, verification_required) VALUES (NOW(),?,?,?,?,?,?,?,?,?)')
                ->execute([$total, $active, $issues, $unchecked, $running, $proxyDead, $ef, $lr, $vr]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Scheduler tick (goi tu Task Scheduler moi N phut hoac tu account_monitor).
     * Khong thread: chon toi da 4 profile theo priority (watchlist/critical
     * truoc, channel co van de, cuoi la on dinh), interval configurable.
     * Tra ve ids da check.
     */
    public static function tick(int $intervalMin = 60, int $concurrency = 4): array
    {
        $concurrency = max(1, min(8, $concurrency));
        try {
            require_once __DIR__ . '/ChannelEvaluationManager.php';
            ChannelEvaluationManager::watchdog();
        } catch (Throwable $e) {
        }
        $due = [];
        try {
            $hasMon = self::hasMonCols();
            $hasEval = self::hasEvalCols();
            // HIGH: watchlist den han; MEDIUM: co van de; NORMAL: on dinh den han
            $q = "SELECT p.id FROM profiles p LEFT JOIN account_states s ON s.profile_id=p.id"
                . " WHERE p.status='running'"
                . ($hasMon ? ' AND p.monitor_enabled=1' : '')
                . " AND (s.last_attempt_at IS NULL OR s.last_attempt_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))"
                . ($hasEval ? " AND (s.eval_status IS NULL OR s.eval_status<>'CHECKING')" : '')
                . ' ORDER BY '
                . ($hasMon ? 'p.watchlist DESC,' : '')
                . ($hasEval ? " FIELD(COALESCE(s.eval_status,'UNCHECKED'),'LOGIN_REQUIRED','VERIFICATION_REQUIRED','CHANNEL_UNAVAILABLE','ERROR','UNCHECKED','ACTIVE') ASC," : '')
                . ' s.last_attempt_at ASC LIMIT ' . $concurrency;
            $st = db()->prepare($q);
            $st->execute([$intervalMin]);
            $due = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
        $done = [];
        foreach ($due as $pid) {
            try {
                require_once __DIR__ . '/ChannelEvaluationManager.php';
                ChannelEvaluationManager::evaluate_one($pid);
                $done[] = $pid;
            } catch (Throwable $e) {
            }
        }
        return $done;
    }

    /** Monitoring settings 1 profile (backward-compatible defaults). */
    public static function getSettings(int $profileId): array
    {
        $def = ['monitor_enabled' => 1, 'watchlist' => 0, 'alert_on_status_change' => 1,
                'alert_on_login_required' => 1, 'alert_on_proxy_error' => 1];
        if (!self::hasMonCols()) return $def;
        try {
            $st = db()->prepare('SELECT monitor_enabled, watchlist, alert_on_status_change, alert_on_login_required, alert_on_proxy_error FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            return $r ? array_map('intval', $r) : $def;
        } catch (Throwable $e) {
            return $def;
        }
    }

    public static function saveSettings(int $profileId, array $in): bool
    {
        if (!self::hasMonCols() || $profileId <= 0) return false;
        $cur = self::getSettings($profileId);
        $vals = [];
        foreach (array_keys($cur) as $k) {
            $vals[$k] = array_key_exists($k, $in) ? (!empty($in[$k]) ? 1 : 0) : (int)$cur[$k];
        }
        try {
            $old = ((int)$cur['monitor_enabled']) ? 'ON' : 'OFF';
            $new = ($vals['monitor_enabled']) ? 'ON' : 'OFF';
            db()->prepare('UPDATE profiles SET monitor_enabled=?, watchlist=?, alert_on_status_change=?, alert_on_login_required=?, alert_on_proxy_error=? WHERE id=?')
                ->execute([$vals['monitor_enabled'], $vals['watchlist'], $vals['alert_on_status_change'],
                    $vals['alert_on_login_required'], $vals['alert_on_proxy_error'], $profileId]);
            if ($old !== $new) StateHistory::record($profileId, 'monitoring', $old, $new, 'user toggle');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
