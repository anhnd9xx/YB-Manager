<?php
declare(strict_types=1);
/**
 * ActivityManager - Quan ly AUTO ACTIVITY cua 1 profile (tabs/tasks, khong phai hanh vi nguoi dung).
 *
 * Khong click quang cao, khong CAPTCHA bypass, khong fake mouse/keyboard.
 * Chi mo/navigate tab AUTOMATION background (khong activate, khong focus).
 * KHONG co quyen: move/resize/arrange/monitor window (§37).
 *
 * Tab ownership (§4): runtime set trong state file (targetId -> {url,label,kind}).
 * Chi dieu khien tab AUTOMATION; tab USER/SESSION khong bao gio dong/navigate.
 * Required check: tab khop bat ky owner nao -> REUSED (bind, khong duplicate) (§26).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';
require_once __DIR__ . '/AccountDataCollector.php';

class ActivityManager
{
    // Task types (§3)
    public const T_ENSURE = 'ENSURE_TAB';
    public const T_OPEN = 'OPEN_PAGE';
    public const T_SEARCH = 'OPEN_SEARCH';
    public const T_CHECK = 'CHECK_TAB';
    public const T_CLOSE_AUTO = 'CLOSE_AUTOMATION_TAB';
    public const T_WEBSITE = 'OPEN_RANDOM_WEBSITE';
    public const T_SEARCH_VISIT = 'SEARCH_VISIT';

    // Results / errors (§32)
    public const R_OPENED = 'OPENED';
    public const R_REUSED = 'REUSED';
    public const R_SUCCESS = 'SUCCESS';
    public const R_CHECKED = 'CHECKED';
    public const R_MISSING = 'MISSING';
    public const R_CLOSED = 'CLOSED';
    public const R_SKIPPED = 'SKIPPED';
    public const R_WAIT = 'WAIT';
    public const R_BLOCKED = 'BLOCKED';
    public const E_NETWORK = 'NETWORK_ERROR';
    public const E_PROXY = 'PROXY_ERROR';
    public const E_CDP = 'CDP_ERROR';
    public const E_PAGE_TIMEOUT = 'PAGE_TIMEOUT';
    public const E_BLOCKED = 'BLOCKED';
    public const E_INVALID_URL = 'INVALID_URL';
    public const E_TAB_LIMIT = 'TAB_LIMIT';

    public const INTERVALS = [15, 30, 60, 120];
    public const MAX_TABS_OPTS = [3, 5, 10];

    /** Required presets (§5). */
    public const PRESETS = [
        'gmail' => 'https://mail.google.com/',
        'google' => 'https://www.google.com/',
        'youtube' => 'https://www.youtube.com/',
        'drive' => 'https://drive.google.com/',
        'calendar' => 'https://calendar.google.com/',
    ];

    // ================= Tables =================

    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS activity_configs (
                profile_id INT PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                schedule_start TIME NOT NULL DEFAULT '08:00:00',
                schedule_end TIME NOT NULL DEFAULT '22:00:00',
                interval_minutes INT NOT NULL DEFAULT 30,
                max_tabs INT NOT NULL DEFAULT 5,
                keep_required_tabs TINYINT(1) NOT NULL DEFAULT 1,
                required_pages TEXT NULL,
                search_queries TEXT NULL,
                activity_mode VARCHAR(20) NOT NULL DEFAULT 'maintain',
                auto_start_profile TINYINT(1) NOT NULL DEFAULT 0,
                maintain_always TINYINT(1) NOT NULL DEFAULT 0,
                store_queries TINYINT(1) NOT NULL DEFAULT 0,
                pause_until DATETIME NULL,
                last_run_at DATETIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS activity_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                task_type VARCHAR(30) NOT NULL,
                domain VARCHAR(190) NOT NULL DEFAULT '',
                result VARCHAR(30) NOT NULL DEFAULT '',
                duration_ms INT NOT NULL DEFAULT 0,
                error_code VARCHAR(40) NULL,
                detail TEXT NULL,
                KEY idx_hist_profile (profile_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Planner columns (migration giu config cu)
            $cols = [];
            foreach (db()->query('SHOW COLUMNS FROM activity_configs')->fetchAll() as $r) {
                $cols[(string)$r['Field']] = true;
            }
            $add = [
                'active_days' => "VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7'",
                'sessions_min' => 'INT NOT NULL DEFAULT 6',
                'sessions_max' => 'INT NOT NULL DEFAULT 10',
                'tasks_min' => 'INT NOT NULL DEFAULT 1',
                'tasks_max' => 'INT NOT NULL DEFAULT 3',
                'gap_min' => 'INT NOT NULL DEFAULT 30',
                'gap_max' => 'INT NOT NULL DEFAULT 120',
                'limits_json' => 'TEXT NULL',
                'template' => "VARCHAR(20) NOT NULL DEFAULT 'NORMAL'",
                'planner_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'next_run_at' => 'DATETIME NULL',
                'search_behavior' => "VARCHAR(15) NOT NULL DEFAULT 'SEARCH_VISIT'",
                'max_result_depth' => 'INT NOT NULL DEFAULT 10',
            ];
            foreach ($add as $col => $def) {
                if (empty($cols[$col])) {
                    try {
                        db()->exec("ALTER TABLE activity_configs ADD COLUMN $col $def");
                    } catch (Throwable $e) {
                    }
                }
            }
            db()->exec("CREATE TABLE IF NOT EXISTS activity_websites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL DEFAULT '',
                url VARCHAR(500) NOT NULL DEFAULT '',
                domain VARCHAR(190) NOT NULL DEFAULT '',
                category VARCHAR(20) NOT NULL DEFAULT 'CUSTOM',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                weight INT NOT NULL DEFAULT 1,
                last_used_at DATETIME NULL,
                use_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_act_web_url (url(255)),
                KEY idx_act_web (enabled, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS activity_search_pool (
                id INT AUTO_INCREMENT PRIMARY KEY,
                query VARCHAR(200) NOT NULL DEFAULT '',
                category VARCHAR(20) NOT NULL DEFAULT 'CUSTOM',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                weight INT NOT NULL DEFAULT 1,
                last_used_at DATETIME NULL,
                use_count INT NOT NULL DEFAULT 0,
                use_today DATE NULL,
                use_today_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_act_search_query (query(191)),
                KEY idx_act_search (enabled, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS activity_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_key VARCHAR(80) NOT NULL DEFAULT '',
                profile_id INT NOT NULL,
                plan_date DATE NOT NULL,
                run_at DATETIME NOT NULL,
                tasks_json TEXT NOT NULL,
                status VARCHAR(15) NOT NULL DEFAULT 'PLANNED',
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                success_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                error_code VARCHAR(40) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_act_session (session_key),
                KEY idx_act_sess_profile (profile_id, plan_date, status, run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    // ================= Config =================

    public static function defaultConfig(int $profileId): array
    {
        return ['profile_id' => $profileId, 'enabled' => 0,
            'schedule_start' => '08:00', 'schedule_end' => '22:00',
            'interval_minutes' => 30, 'max_tabs' => 5, 'keep_required_tabs' => 1,
            'required_pages' => ['gmail', 'google'], 'search_queries' => [],
            'activity_mode' => 'maintain', 'auto_start_profile' => 0,
            'maintain_always' => 0, 'store_queries' => 0,
            'pause_until' => null, 'last_run_at' => null,
            'active_days' => '1,2,3,4,5,6,7', 'sessions_min' => 6, 'sessions_max' => 10,
            'tasks_min' => 1, 'tasks_max' => 3, 'gap_min' => 30, 'gap_max' => 120,
            'limits_json' => null, 'template' => 'NORMAL', 'planner_enabled' => 1,
            'next_run_at' => null, 'search_behavior' => 'SEARCH_VISIT', 'max_result_depth' => 10];
    }

    public static function getConfig(int $profileId): array
    {
        self::ensureTables();
        try {
            $st = db()->prepare('SELECT * FROM activity_configs WHERE profile_id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            if (!$r) return self::defaultConfig($profileId);
            $r['enabled'] = (int)$r['enabled'];
            $r['interval_minutes'] = (int)$r['interval_minutes'];
            $r['max_tabs'] = (int)$r['max_tabs'];
            $r['keep_required_tabs'] = (int)$r['keep_required_tabs'];
            $r['auto_start_profile'] = (int)$r['auto_start_profile'];
            $r['maintain_always'] = (int)$r['maintain_always'];
            $r['store_queries'] = (int)$r['store_queries'];
            $r['schedule_start'] = substr((string)($r['schedule_start'] ?? '08:00:00'), 0, 5);
            $r['schedule_end'] = substr((string)($r['schedule_end'] ?? '22:00:00'), 0, 5);
            $rp = json_decode((string)($r['required_pages'] ?? ''), true);
            $r['required_pages'] = is_array($rp) ? array_values($rp) : [];
            $sq = json_decode((string)($r['search_queries'] ?? ''), true);
            $r['search_queries'] = is_array($sq) ? array_values($sq) : [];
            $r['active_days'] = (string)($r['active_days'] ?? '1,2,3,4,5,6,7');
            $r['sessions_min'] = max(1, (int)($r['sessions_min'] ?? 6));
            $r['sessions_max'] = max($r['sessions_min'], (int)($r['sessions_max'] ?? 10));
            $r['tasks_min'] = max(1, (int)($r['tasks_min'] ?? 1));
            $r['tasks_max'] = max($r['tasks_min'], (int)($r['tasks_max'] ?? 3));
            $r['gap_min'] = max(5, (int)($r['gap_min'] ?? 30));
            $r['gap_max'] = max($r['gap_min'], (int)($r['gap_max'] ?? 120));
            $r['template'] = in_array(($r['template'] ?? 'NORMAL'), ['LIGHT', 'NORMAL', 'HIGH', 'CUSTOM'], true)
                ? (string)$r['template'] : 'NORMAL';
            $r['planner_enabled'] = (int)($r['planner_enabled'] ?? 1);
            $bh = strtoupper((string)($r['search_behavior'] ?? 'SEARCH_VISIT'));
            $r['search_behavior'] = in_array($bh, ['SEARCH_ONLY', 'SEARCH_VISIT', 'DIRECT'], true) ? $bh : 'SEARCH_VISIT';
            $r['max_result_depth'] = max(1, min(30, (int)($r['max_result_depth'] ?? 10)));
            return $r;
        } catch (Throwable $e) {
            return self::defaultConfig($profileId);
        }
    }

    /** @return array{ok, errors[]} */
    public static function saveConfig(int $profileId, array $in): array
    {
        self::ensureTables();
        $errors = [];
        $cur = self::getConfig($profileId);
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $ss = self::cleanTime((string)($in['schedule_start'] ?? $cur['schedule_start']), '08:00');
        $se = self::cleanTime((string)($in['schedule_end'] ?? $cur['schedule_end']), '22:00');
        $iv = (int)($in['interval_minutes'] ?? $cur['interval_minutes']);
        if ($iv < 15) {
            // Custom < 15 phut khong cho (§14); lam tron len muc gan nhat
            $iv = 15;
            $errors[] = 'interval_min_15';
        }
        $mt = (int)($in['max_tabs'] ?? $cur['max_tabs']);
        if (!in_array($mt, self::MAX_TABS_OPTS, true)) {
            $mt = 5;
            $errors[] = 'max_tabs_invalid';
        }
        // required_pages: preset keys + custom {label,url}
        $rp = [];
        foreach ((array)($in['required_pages'] ?? $cur['required_pages']) as $item) {
            if (is_string($item) && isset(self::PRESETS[$item])) {
                $rp[] = $item;
            } elseif (is_array($item) && !empty($item['url']) && self::validHttpUrl((string)$item['url'])) {
                $rp[] = ['label' => mb_substr(trim((string)($item['label'] ?? '')), 0, 60) ?: self::hostOf((string)$item['url']),
                    'url' => trim((string)$item['url'])];
            }
        }
        $sq = [];
        foreach ((array)($in['search_queries'] ?? $cur['search_queries']) as $q) {
            $q = trim((string)$q);
            if ($q !== '' && mb_strlen($q) <= 200) $sq[] = $q;
        }
        $sq = array_values(array_unique(array_slice($sq, 0, 100)));
        // pause_until: giu nguyen neu caller khong gui (tranh save config xoa pause)
        $pauseUntil = array_key_exists('pause_until', $in) ? $in['pause_until'] : ($cur['pause_until'] ?? null);
        // Planner fields (validate, giu cu neu khong gui)
        $days = self::cleanDays((string)($in['active_days'] ?? $cur['active_days']));
        $sMin = max(1, min(24, (int)($in['sessions_min'] ?? $cur['sessions_min'])));
        $sMax = max($sMin, min(24, (int)($in['sessions_max'] ?? $cur['sessions_max'])));
        $tMin = max(1, min(5, (int)($in['tasks_min'] ?? $cur['tasks_min'])));
        $tMax = max($tMin, min(5, (int)($in['tasks_max'] ?? $cur['tasks_max'])));
        $gMin = max(5, min(480, (int)($in['gap_min'] ?? $cur['gap_min'])));
        $gMax = max($gMin, min(480, (int)($in['gap_max'] ?? $cur['gap_max'])));
        $tpl = strtoupper((string)($in['template'] ?? $cur['template']));
        if (!in_array($tpl, ['LIGHT', 'NORMAL', 'HIGH', 'CUSTOM'], true)) $tpl = 'NORMAL';
        $plannerOn = array_key_exists('planner_enabled', $in) ? (!empty($in['planner_enabled']) ? 1 : 0) : (int)$cur['planner_enabled'];
        $limits = self::cleanLimits($in['limits_json'] ?? ($cur['limits_json'] ?? null));
        $bh = strtoupper((string)($in['search_behavior'] ?? $cur['search_behavior']));
        if (!in_array($bh, ['SEARCH_ONLY', 'SEARCH_VISIT', 'DIRECT'], true)) $bh = 'SEARCH_VISIT';
        $depth = (int)($in['max_result_depth'] ?? $cur['max_result_depth']);
        $depth = max(1, min(30, $depth));
        try {
            db()->prepare('INSERT INTO activity_configs (profile_id, enabled, schedule_start, schedule_end,
                    interval_minutes, max_tabs, keep_required_tabs, required_pages, search_queries,
                    activity_mode, auto_start_profile, maintain_always, store_queries, pause_until,
                    active_days, sessions_min, sessions_max, tasks_min, tasks_max, gap_min, gap_max,
                    limits_json, template, planner_enabled, search_behavior, max_result_depth)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), schedule_start=VALUES(schedule_start),
                    schedule_end=VALUES(schedule_end), interval_minutes=VALUES(interval_minutes),
                    max_tabs=VALUES(max_tabs), keep_required_tabs=VALUES(keep_required_tabs),
                    required_pages=VALUES(required_pages), search_queries=VALUES(search_queries),
                    activity_mode=VALUES(activity_mode), auto_start_profile=VALUES(auto_start_profile),
                    maintain_always=VALUES(maintain_always), store_queries=VALUES(store_queries),
                    pause_until=VALUES(pause_until), active_days=VALUES(active_days),
                    sessions_min=VALUES(sessions_min), sessions_max=VALUES(sessions_max),
                    tasks_min=VALUES(tasks_min), tasks_max=VALUES(tasks_max),
                    gap_min=VALUES(gap_min), gap_max=VALUES(gap_max),
                    limits_json=VALUES(limits_json), template=VALUES(template),
                    planner_enabled=VALUES(planner_enabled),
                    search_behavior=VALUES(search_behavior), max_result_depth=VALUES(max_result_depth)')
                ->execute([$profileId, $enabled, $ss . ':00', $se . ':00', $iv, $mt,
                    !empty($in['keep_required_tabs']) || !array_key_exists('keep_required_tabs', $in) ? 1 : 0,
                    json_encode(array_values($rp), JSON_UNESCAPED_UNICODE),
                    json_encode($sq, JSON_UNESCAPED_UNICODE),
                    in_array(($in['activity_mode'] ?? 'maintain'), ['maintain', 'search', 'full'], true)
                        ? (string)($in['activity_mode'] ?? 'maintain') : 'maintain',
                    !empty($in['auto_start_profile']) ? 1 : 0,
                    !empty($in['maintain_always']) ? 1 : 0,
                    !empty($in['store_queries']) ? 1 : 0,
                    $pauseUntil, $days, $sMin, $sMax, $tMin, $tMax, $gMin, $gMax,
                    $limits, $tpl, $plannerOn, $bh, $depth]);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['db_error']];
        }
        return ['ok' => true, 'errors' => $errors];
    }

    /** Templates LIGHT/NORMAL/HIGH (§44-45). Tra ve patch ap dung len config. */
    public const TEMPLATES = [
        'LIGHT' => ['schedule_start' => '09:00', 'schedule_end' => '21:00', 'sessions_min' => 3,
            'sessions_max' => 5, 'tasks_min' => 1, 'tasks_max' => 2, 'gap_min' => 60, 'gap_max' => 180,
            'limits' => ['SEARCH' => [1, 2], 'WEBSITE' => [1, 3], 'GMAIL' => [1, 2], 'DRIVE' => [0, 1], 'CALENDAR' => [0, 1]]],
        'NORMAL' => ['schedule_start' => '08:00', 'schedule_end' => '22:00', 'sessions_min' => 6,
            'sessions_max' => 10, 'tasks_min' => 1, 'tasks_max' => 3, 'gap_min' => 30, 'gap_max' => 120,
            'limits' => ['SEARCH' => [2, 4], 'WEBSITE' => [2, 5], 'GMAIL' => [1, 3], 'DRIVE' => [0, 2], 'CALENDAR' => [0, 2]]],
        'HIGH' => ['schedule_start' => '07:00', 'schedule_end' => '23:00', 'sessions_min' => 10,
            'sessions_max' => 16, 'tasks_min' => 2, 'tasks_max' => 4, 'gap_min' => 15, 'gap_max' => 60,
            'limits' => ['SEARCH' => [4, 8], 'WEBSITE' => [4, 8], 'GMAIL' => [2, 4], 'DRIVE' => [1, 3], 'CALENDAR' => [1, 2]]],
    ];

    /** @return array patch (template + CUSTOM giu nguyen) */
    public static function applyTemplate(string $tpl): array
    {
        $tpl = strtoupper($tpl);
        if (!isset(self::TEMPLATES[$tpl])) return ['template' => 'CUSTOM'];
        $t = self::TEMPLATES[$tpl];
        return ['template' => $tpl, 'schedule_start' => $t['schedule_start'], 'schedule_end' => $t['schedule_end'],
            'sessions_min' => $t['sessions_min'], 'sessions_max' => $t['sessions_max'],
            'tasks_min' => $t['tasks_min'], 'tasks_max' => $t['tasks_max'],
            'gap_min' => $t['gap_min'], 'gap_max' => $t['gap_max'],
            'limits_json' => json_encode($t['limits'], JSON_UNESCAPED_UNICODE)];
    }

    /** @return array<string,array{0:int,1:int}> min/max moi loai task/ngay */
    public static function limitsFor(array $cfg): array
    {
        $def = self::TEMPLATES['NORMAL']['limits'];
        try {
            $j = json_decode((string)($cfg['limits_json'] ?? ''), true);
            if (is_array($j)) {
                foreach ($j as $k => $v) {
                    $k = strtoupper((string)$k);
                    if (isset($def[$k]) && is_array($v) && count($v) >= 2) {
                        $def[$k] = [max(0, (int)$v[0]), max((int)$v[0], (int)$v[1])];
                    }
                }
            }
        } catch (Throwable $e) {
        }
        return $def;
    }

    private static function cleanDays(string $v): string
    {
        $out = [];
        foreach (explode(',', $v) as $d) {
            $d = (int)trim($d);
            if ($d >= 1 && $d <= 7 && !in_array($d, $out, true)) $out[] = $d;
        }
        if (!$out) $out = [1, 2, 3, 4, 5, 6, 7];
        sort($out);
        return implode(',', $out);
    }

    /** @return string JSON limits hop le */
    private static function cleanLimits($v): string
    {
        $def = self::TEMPLATES['NORMAL']['limits'];
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v)) return json_encode($def, JSON_UNESCAPED_UNICODE);
        foreach ($v as $k => $vv) {
            $k = strtoupper((string)$k);
            if (!isset($def[$k]) || !is_array($vv) || count($vv) < 2) {
                unset($v[$k]);
                continue;
            }
            $v[$k] = [max(0, (int)$vv[0]), max((int)$vv[0], (int)$vv[1])];
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    private static function cleanTime(string $v, string $fb): string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m)) {
            $h = max(0, min(23, (int)$m[1]));
            $mi = max(0, min(59, (int)$m[2]));
            return sprintf('%02d:%02d', $h, $mi);
        }
        return $fb;
    }

    // ================= URL matching (§7) =================

    public static function validHttpUrl(string $u): bool
    {
        $u = trim($u);
        if (!str_starts_with(strtolower($u), 'http://') && !str_starts_with(strtolower($u), 'https://')) return false;
        $h = parse_url($u, PHP_URL_HOST);
        return is_string($h) && $h !== '' && str_contains($h, '.');
    }

    public static function hostOf(string $u): string
    {
        $h = parse_url(strtolower(trim($u)), PHP_URL_HOST);
        return is_string($h) ? $h : '';
    }

    /** Host chuan hoa de so khop (lowercase, bo www. dau). */
    public static function normHost(string $u): string
    {
        $h = self::hostOf($u);
        if (str_starts_with($h, 'www.')) $h = substr($h, 4);
        return $h;
    }

    /**
     * $pattern: full URL hoac domain (vd mail.google.com).
     * Match khi host tab == host pattern hoac subdomain cua no.
     * Vd tab mail.google.com/mail/u/0/#inbox match pattern mail.google.com.
     */
    public static function domainMatch(string $tabUrl, string $pattern): bool
    {
        $th = self::normHost($tabUrl);
        if ($th === '') return false;
        $pattern = trim($pattern);
        $ph = str_contains($pattern, '://') ? self::normHost($pattern) : strtolower(ltrim($pattern, '.'));
        if (str_starts_with($ph, 'www.')) $ph = substr($ph, 4);
        if ($ph === '') return false;
        return $th === $ph || str_ends_with($th, '.' . $ph);
    }

    public static function domainOf(string $u): string
    {
        return self::normHost($u);
    }

    // ================= Runtime state =================

    private static function stateFile(int $profileId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_activity_' . $profileId . '.json';
    }

    public static function stateGet(int $profileId): array
    {
        $f = self::stateFile($profileId);
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    private static function stateSet(int $profileId, array $patch): array
    {
        $s = self::stateGet($profileId) + ['auto_tabs' => []];
        foreach ($patch as $k => $v) $s[$k] = $v;
        @file_put_contents(self::stateFile($profileId), json_encode($s, JSON_UNESCAPED_UNICODE));
        return $s;
    }

    public static function stateClear(int $profileId): void
    {
        @unlink(self::stateFile($profileId));
    }

    /** @return string[] queries vua dung gan nhat (selector tranh lap) */
    public static function recentQueries(int $profileId): array
    {
        $st = self::stateGet($profileId);
        $q = (array)($st['recent_queries'] ?? []);
        return array_values(array_filter(array_map('strval', $q)));
    }

    public static function pushRecentQuery(int $profileId, string $query): void
    {
        $st = self::stateGet($profileId);
        $q = self::recentQueries($profileId);
        array_unshift($q, $query);
        $st['recent_queries'] = array_slice(array_values(array_unique($q)), 0, 5);
        self::stateSet($profileId, $st);
    }

    /** @return string[] domains 3 visits gan nhat (§28) */
    public static function recentDomains(int $profileId): array
    {
        $st = self::stateGet($profileId);
        $d = (array)($st['recent_domains'] ?? []);
        return array_values(array_filter(array_map('strval', $d)));
    }

    public static function pushRecentDomain(int $profileId, string $domain): void
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') return;
        $st = self::stateGet($profileId);
        $d = self::recentDomains($profileId);
        array_unshift($d, $domain);
        $st['recent_domains'] = array_slice(array_values(array_unique($d)), 0, 3);
        self::stateSet($profileId, $st);
    }

    // ================= CDP helpers =================

    private static function portOf(int $profileId): int
    {
        try {
            $st = db()->prepare('SELECT debug_port FROM profiles WHERE id=?');
            $st->execute([$profileId]);
            $port = (int)($st->fetchColumn() ?? 0);
            return ($port > 0 && cdp_reachable($port)) ? $port : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function openTab(int $port, string $url): ?string
    {
        $r = cdp_http($port, 'PUT', '/json/new?' . urlencode($url), 2000);
        if ($r === null) return null;
        $t = json_decode($r['body'], true);
        return is_array($t) && !empty($t['id']) ? (string)$t['id'] : null;
    }

    private static function navigateTab(int $port, string $tabId, string $url): bool
    {
        try {
            foreach (cdp_page_targets($port) as $t) {
                if ((string)($t['id'] ?? '') === $tabId && !empty($t['webSocketDebuggerUrl'])) {
                    return cdp_ws_send($port, (string)$t['webSocketDebuggerUrl'],
                        json_encode(['id' => 41, 'method' => 'Page.navigate', 'params' => ['url' => $url]]));
                }
            }
        } catch (Throwable $e) {
        }
        return false;
    }

    /** Phat hien challenge/CAPTCHA tren tab (khong bypass, chi danh dau BLOCKED). */
    private static function isBlocked(int $port, string $tabId): bool
    {
        $d = AccountDataCollector::eval($port, $tabId,
            "(()=>{try{const u=location.href,t=document.title||'';"
            . "const b=(document.body?document.body.innerText.slice(0,2000):'').toLowerCase();"
            . "if(/\\/sorry\\/|unusual traffic|recaptcha|captcha|confirm you are not a robot|verify you are human|before you continue/i.test(u+' '+t+' '+b.slice(0,800)))return true;"
            . "return !!document.querySelector('form[action*=\"sorry\" i], #recaptcha, .g-recaptcha')}catch(e){return false}})()");
        return is_array($d) && !empty($d['value']);
    }

    // ================= History (§16) =================

    public static function record(int $profileId, string $task, string $domain, string $result,
        int $ms = 0, ?string $error = null, ?string $detail = null): void
    {
        self::ensureTables();
        try {
            db()->prepare('INSERT INTO activity_history (profile_id, task_type, domain, result, duration_ms, error_code, detail)
                VALUES (?,?,?,?,?,?,?)')
                ->execute([$profileId, $task, mb_substr($domain, 0, 190), $result, $ms, $error,
                    $detail !== null ? mb_substr($detail, 0, 2000) : null]);
            db()->prepare('DELETE FROM activity_history WHERE profile_id=? AND id NOT IN
                (SELECT id FROM (SELECT id FROM activity_history WHERE profile_id=? ORDER BY id DESC LIMIT 200) t)')
                ->execute([$profileId, $profileId]);
        } catch (Throwable $e) {
        }
        try {
            SyncLogger::info('activity', '[ACTIVITY] profile=' . $profileId . ' task=' . $task
                . ' domain=' . $domain . ' result=' . $result
                . ($ms ? ' duration=' . $ms . 'ms' : '') . ($error ? ' error=' . $error : ''), $profileId);
        } catch (Throwable $e) {
        }
    }

    public static function history(int $profileId, string $filter = 'all', int $limit = 100): array
    {
        self::ensureTables();
        $limit = max(1, min(200, $limit));
        $w = '';
        if ($filter === 'search') $w = " AND task_type='OPEN_SEARCH'";
        elseif ($filter === 'site') $w = " AND task_type IN ('ENSURE_TAB','OPEN_PAGE','CHECK_TAB')";
        elseif ($filter === 'error') $w = " AND (error_code IS NOT NULL OR result IN ('BLOCKED','MISSING'))";
        try {
            $st = db()->prepare('SELECT created_at, task_type, domain, result, duration_ms, error_code, detail'
                . ' FROM activity_history WHERE profile_id=?' . $w . ' ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$profileId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    // ================= Tasks =================

    /**
     * Chay 1 task (manual run + scheduler dung chung).
     * @param array{type:string, page?:string, url?:string, query?:string} $task
     * @return array{ok, result, error?, ms}
     */
    public static function runTask(int $profileId, array $task): array
    {
        $t0 = microtime(true);
        $ms = fn() => (int)round((microtime(true) - $t0) * 1000);
        $type = (string)($task['type'] ?? '');
        // MAX 1 active task/profile (§9)
        $st = self::stateGet($profileId);
        if (!empty($st['running_task']) && (microtime(true) - (float)($st['running_ts'] ?? 0)) < 300) {
            return ['ok' => false, 'result' => self::R_WAIT, 'error' => 'task_running', 'ms' => $ms()];
        }
        self::stateSet($profileId, ['running_task' => $type, 'running_ts' => microtime(true)]);
        try {
            $port = self::portOf($profileId);
            if ($port <= 0) {
                return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_CDP, 'ms' => $ms()];
            }
            switch ($type) {
                case self::T_ENSURE:
                    return self::taskEnsure($profileId, $port, (string)($task['page'] ?? ''), $ms);
                case self::T_OPEN:
                    return self::taskOpen($profileId, $port, (string)($task['url'] ?? ''), $ms, false);
                case self::T_SEARCH:
                    return self::taskSearch($profileId, $port, (string)($task['query'] ?? ''), $ms);
                case self::T_SEARCH_VISIT:
                    return self::taskSearchVisit($profileId, $port, (string)($task['query'] ?? ''),
                        (int)($task['depth'] ?? 0), (bool)($task['test_mode'] ?? false), $ms);
                case self::T_WEBSITE:
                    return self::taskWebsite($profileId, $port, (array)($task['exclude'] ?? []), $ms);
                case self::T_CHECK:
                    return self::taskCheck($profileId, $port, $ms);
                case self::T_CLOSE_AUTO:
                    return self::taskCloseAuto($profileId, $port, $ms);
                default:
                    return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => 'unknown_task', 'ms' => $ms()];
            }
        } finally {
            self::stateSet($profileId, ['running_task' => null, 'running_ts' => 0]);
        }
    }

    /** @return string[] urls can duy tri tu config */
    public static function requiredUrls(array $cfg): array
    {
        $out = [];
        foreach ((array)($cfg['required_pages'] ?? []) as $item) {
            if (is_string($item) && isset(self::PRESETS[$item])) $out[] = self::PRESETS[$item];
            elseif (is_array($item) && !empty($item['url'])) $out[] = (string)$item['url'];
        }
        return array_values(array_unique($out));
    }

    private static function liveTabs(int $port): array
    {
        try {
            return cdp_page_targets($port);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Loc automation set: bo targetId da chet. */
    private static function pruneAuto(array $auto, array $live): array
    {
        $ids = [];
        foreach ($live as $t) $ids[(string)($t['id'] ?? '')] = true;
        foreach (array_keys($auto) as $tid) {
            if (!isset($ids[$tid])) unset($auto[$tid]);
        }
        return $auto;
    }

    /** ENSURE_TAB (§6): reuse neu ton tai (bat ky owner), khong duplicate. */
    private static function taskEnsure(int $profileId, int $port, string $page, callable $ms): array
    {
        $url = isset(self::PRESETS[$page]) ? self::PRESETS[$page] : $page;
        if (!self::validHttpUrl($url)) {
            self::record($profileId, self::T_ENSURE, $page, self::R_SKIPPED, $ms(), self::E_INVALID_URL);
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_INVALID_URL, 'ms' => $ms()];
        }
        $cfg = self::getConfig($profileId);
        $live = self::liveTabs($port);
        $st = self::stateGet($profileId);
        $auto = self::pruneAuto((array)($st['auto_tabs'] ?? []), $live);
        // Ownership bind truoc (§26): automation da mo cho requirement nay
        // (URL thuc te co the drift do redirect Dang nhap) -> REUSED, khong mo moi.
        foreach ($auto as $tid => $info) {
            if (self::domainMatch((string)($info['url'] ?? ''), $url)) {
                self::record($profileId, self::T_ENSURE, self::domainOf($url), self::R_REUSED, $ms());
                return ['ok' => true, 'result' => self::R_REUSED, 'ms' => $ms(), 'tab' => (string)$tid];
            }
        }
        foreach ($live as $t) {
            if (self::domainMatch((string)($t['url'] ?? ''), $url)) {
                // Bind (khong mo moi) — tab co the cua USER/SESSION (§26)
                self::record($profileId, self::T_ENSURE, self::domainOf($url), self::R_REUSED, $ms());
                return ['ok' => true, 'result' => self::R_REUSED, 'ms' => $ms(), 'tab' => (string)($t['id'] ?? '')];
            }
        }
        // Mo moi: check tab limit (§21)
        if (count($auto) >= max(1, (int)$cfg['max_tabs'])) {
            // Reuse automation search tab cu (navigate lai) thay vi mo vo han
            $reuse = null;
            foreach ($auto as $tid => $info) {
                if (($info['kind'] ?? '') === 'search') {
                    $reuse = $tid;
                    break;
                }
            }
            if ($reuse !== null) {
                if (self::navigateTab($port, (string)$reuse, $url)) {
                    $auto[$reuse] = ['url' => $url, 'kind' => 'required', 'at' => time()];
                    self::stateSet($profileId, ['auto_tabs' => $auto]);
                    self::record($profileId, self::T_ENSURE, self::domainOf($url), self::R_REUSED, $ms());
                    return ['ok' => true, 'result' => self::R_REUSED, 'ms' => $ms(), 'tab' => (string)$reuse];
                }
            }
            self::record($profileId, self::T_ENSURE, self::domainOf($url), self::R_SKIPPED, $ms(), self::E_TAB_LIMIT);
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_TAB_LIMIT, 'ms' => $ms()];
        }
        $tid = self::openTab($port, $url);
        if ($tid === null) {
            // Retry 1 lan transient (§33)
            usleep(500000);
            $tid = self::openTab($port, $url);
        }
        if ($tid === null) {
            self::record($profileId, self::T_ENSURE, self::domainOf($url), self::R_SKIPPED, $ms(), self::E_CDP);
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_CDP, 'ms' => $ms()];
        }
        $auto[$tid] = ['url' => $url, 'kind' => 'required', 'at' => time()];
        self::stateSet($profileId, ['auto_tabs' => $auto]);
        self::record($profileId, self::T_ENSURE, self::domainOf($url), self::R_OPENED, $ms());
        return ['ok' => true, 'result' => self::R_OPENED, 'ms' => $ms(), 'tab' => $tid];
    }

    /** OPEN_PAGE: nhu ensure nhung luon mo tab automation moi (khong reuse user tab). */
    private static function taskOpen(int $profileId, int $port, string $url, callable $ms, bool $isSearch): array
    {
        if (!self::validHttpUrl($url)) {
            self::record($profileId, $isSearch ? self::T_SEARCH : self::T_OPEN,
                self::domainOf($url), self::R_SKIPPED, $ms(), self::E_INVALID_URL);
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_INVALID_URL, 'ms' => $ms()];
        }
        $tid = self::openTab($port, $url);
        if ($tid === null) {
            usleep(500000);
            $tid = self::openTab($port, $url);
        }
        if ($tid === null) {
            self::record($profileId, $isSearch ? self::T_SEARCH : self::T_OPEN,
                self::domainOf($url), self::R_SKIPPED, $ms(), self::E_CDP);
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_CDP, 'ms' => $ms()];
        }
        $st = self::stateGet($profileId);
        $auto = (array)($st['auto_tabs'] ?? []);
        $auto[$tid] = ['url' => $url, 'kind' => $isSearch ? 'search' : 'page', 'at' => time()];
        self::stateSet($profileId, ['auto_tabs' => $auto]);
        return ['ok' => true, 'result' => self::R_OPENED, 'ms' => $ms(), 'tab' => $tid];
    }

    /** OPEN_SEARCH (§8, §22): reuse 1 automation tab, khong click results, BLOCKED thi dung. */
    private static function taskSearch(int $profileId, int $port, string $query, callable $ms): array
    {
        $query = trim($query);
        $cfg = self::getConfig($profileId);
        if ($query === '') {
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => 'empty_query', 'ms' => $ms()];
        }
        $url = 'https://www.google.com/search?q=' . urlencode($query);
        $live = self::liveTabs($port);
        $st = self::stateGet($profileId);
        $auto = self::pruneAuto((array)($st['auto_tabs'] ?? []), $live);
        $reuse = null;
        foreach ($auto as $tid => $info) {
            if (($info['kind'] ?? '') === 'search') {
                $reuse = $tid;
                break;
            }
        }
        if ($reuse !== null) {
            $tid = (string)$reuse;
            if (!self::navigateTab($port, $tid, $url)) {
                $reuse = null;
                unset($auto[$tid]);
            }
        }
        if ($reuse === null) {
            if (count($auto) >= max(1, (int)$cfg['max_tabs'])) {
                self::record($profileId, self::T_SEARCH, 'google.com', self::R_SKIPPED, $ms(), self::E_TAB_LIMIT);
                return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_TAB_LIMIT, 'ms' => $ms()];
            }
            $reuse = self::openTab($port, $url);
            if ($reuse === null) {
                usleep(500000);
                $reuse = self::openTab($port, $url);
            }
            if ($reuse === null) {
                self::record($profileId, self::T_SEARCH, 'google.com', self::R_SKIPPED, $ms(), self::E_CDP);
                return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_CDP, 'ms' => $ms()];
            }
            $auto[$reuse] = ['url' => $url, 'kind' => 'search', 'at' => time()];
            self::stateSet($profileId, ['auto_tabs' => $auto]);
        } else {
            $auto[$reuse] = ['url' => $url, 'kind' => 'search', 'at' => time()];
            self::stateSet($profileId, ['auto_tabs' => $auto]);
        }
        // Cho load nhe roi check challenge (khong cho page load day du)
        usleep(2000000);
        if (self::isBlocked($port, (string)$reuse)) {
            $detail = !empty($cfg['store_queries']) ? $query : ('qhash=' . substr(md5($query), 0, 8));
            self::record($profileId, self::T_SEARCH, 'google.com', self::R_BLOCKED, $ms(), self::E_BLOCKED, $detail);
            try {
                require_once __DIR__ . '/EventBus.php';
                EventBus::emit(AppEvent::TASK_FAILED, AppEvent::MOD_AUTO_ACTIVITY, AppEvent::SEV_WARNING,
                    'Auto Activity bị chặn', "Profile #$profileId: Google challenge khi tìm kiếm",
                    ['profile_id' => $profileId, 'status' => 'FAILED',
                        'data' => ['task' => self::T_SEARCH, 'domain' => 'google.com']]);
            } catch (Throwable $e) {
            }
            return ['ok' => false, 'result' => self::R_BLOCKED, 'error' => self::E_BLOCKED, 'ms' => $ms()];
        }
        $detail = !empty($cfg['store_queries']) ? $query : ('qhash=' . substr(md5($query), 0, 8));
        self::record($profileId, self::T_SEARCH, 'google.com', self::R_SUCCESS, $ms(), null, $detail);
        return ['ok' => true, 'result' => self::R_SUCCESS, 'ms' => $ms(), 'tab' => (string)$reuse];
    }

    /** OPEN_RANDOM_WEBSITE (§7, §11): pick tu pool, mo tab automation, khong click quang cao. */
    private static function taskWebsite(int $profileId, int $port, array $exclude, callable $ms): array
    {
        $pick = self::pickWebsite($exclude);
        if (!$pick) {
            self::record($profileId, self::T_WEBSITE, '', self::R_SKIPPED, $ms(), 'empty_pool');
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => 'empty_pool', 'ms' => $ms()];
        }
        $url = (string)$pick['url'];
        $cfg = self::getConfig($profileId);
        $live = self::liveTabs($port);
        $st = self::stateGet($profileId);
        $auto = self::pruneAuto((array)($st['auto_tabs'] ?? []), $live);
        // Tab limit: reuse search tab cu nhu ensure
        if (count($auto) >= max(1, (int)$cfg['max_tabs'])) {
            foreach ($auto as $tid => $info) {
                if (($info['kind'] ?? '') === 'search' && self::navigateTab($port, (string)$tid, $url)) {
                    $auto[$tid] = ['url' => $url, 'kind' => 'page', 'at' => time()];
                    self::stateSet($profileId, ['auto_tabs' => $auto]);
                    self::record($profileId, self::T_WEBSITE, self::domainOf($url), self::R_REUSED, $ms());
                    return ['ok' => true, 'result' => self::R_REUSED, 'ms' => $ms(), 'tab' => (string)$tid];
                }
            }
            self::record($profileId, self::T_WEBSITE, self::domainOf($url), self::R_SKIPPED, $ms(), self::E_TAB_LIMIT);
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => self::E_TAB_LIMIT, 'ms' => $ms()];
        }
        $r = self::taskOpen($profileId, $port, $url, $ms, false);
        if (!empty($r['ok'])) {
            self::record($profileId, self::T_WEBSITE, self::domainOf($url), self::R_OPENED, $ms());
            $r['result'] = self::R_OPENED;
        } else {
            self::record($profileId, self::T_WEBSITE, self::domainOf($url), self::R_SKIPPED, $ms(), $r['error'] ?? null);
        }
        return $r;
    }

    /**
     * SEARCH_VISIT (§19): search -> parse organic -> loc pool -> visit 1 random.
     * Khong approved result -> SEARCH SUCCESS + VISIT SKIPPED (§24).
     * @return array{ok, result, ...}
     */
    private static function taskSearchVisit(int $profileId, int $port, string $query,
        int $depth = 0, bool $testMode = false, ?callable $ms = null): array
    {
        $t0 = microtime(true);
        $ms = $ms ?? fn() => (int)round((microtime(true) - $t0) * 1000);
        $query = trim($query);
        $cfg = self::getConfig($profileId);
        if ($query === '') {
            return ['ok' => false, 'result' => self::R_SKIPPED, 'error' => 'empty_query', 'ms' => $ms()];
        }
        if ($depth <= 0) $depth = (int)$cfg['max_result_depth'];
        // Buoc 1: search (reuse search tab automation)
        $sr = self::taskSearch($profileId, $port, $query, $ms);
        if (empty($sr['ok'])) return $sr; // BLOCKED/SKIPPED lan truyen
        $tabId = (string)($sr['tab'] ?? '');
        $detail = ['query' => $testMode ? $query : ('qhash=' . substr(md5($query), 0, 8)),
            'organic' => 0, 'approved' => 0, 'selected' => null, 'visit' => 'SKIPPED', 'reason' => ''];
        if ($tabId === '') {
            self::record($profileId, self::T_SEARCH_VISIT, 'google.com', self::R_SUCCESS, $ms(), null,
                json_encode($detail, JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'result' => self::R_SUCCESS, 'ms' => $ms(), 'detail' => $detail];
        }
        // Buoc 2: parse organic (defensive §48)
        $parsed = self::extractOrganic($port, $tabId);
        if ($parsed === null) {
            $detail['visit'] = 'SKIPPED';
            $detail['reason'] = 'RESULT_PARSE_FAILED';
            self::record($profileId, self::T_SEARCH_VISIT, 'google.com', self::R_SUCCESS, $ms(),
                'RESULT_PARSE_FAILED', json_encode($detail, JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'result' => self::R_SUCCESS, 'ms' => $ms(), 'detail' => $detail];
        }
        // Buoc 3: loc pool
        require_once __DIR__ . '/ActivityContentSelector.php';
        $sel = ActivityContentSelector::select_approved_search_result($parsed, $depth, !$testMode);
        $detail['organic'] = (int)$sel['organic_count'];
        $detail['approved'] = count($sel['approved']);
        if (empty($sel['picked'])) {
            $detail['reason'] = 'NO_APPROVED_RESULT';
            self::record($profileId, self::T_SEARCH_VISIT, 'google.com', self::R_SUCCESS, $ms(), null,
                json_encode($detail, JSON_UNESCAPED_UNICODE));
            self::pushRecentQuery($profileId, $query);
            return ['ok' => true, 'result' => self::R_SUCCESS, 'ms' => $ms(), 'detail' => $detail];
        }
        // Buoc 4: visit candidate (automation tab, khong click quang cao — navigate truc tiep)
        $picked = $sel['picked'];
        $detail['selected'] = $picked['domain'];
        $vr = self::taskOpen($profileId, $port, (string)$picked['url'], $ms, false);
        if (!empty($vr['ok']) && !empty($vr['tab']) && self::waitPageReady($port, (string)$vr['tab'], 8000)) {
            $detail['visit'] = 'SUCCESS';
            self::pushRecentDomain($profileId, (string)$picked['domain']);
            self::pushRecentQuery($profileId, $query);
            if (!$testMode) {
                self::record($profileId, self::T_SEARCH_VISIT, (string)$picked['domain'],
                    self::R_SUCCESS, $ms(), null, json_encode($detail, JSON_UNESCAPED_UNICODE));
            }
            return ['ok' => true, 'result' => self::R_SUCCESS, 'ms' => $ms(),
                'tab' => $vr['tab'], 'detail' => $detail];
        }
        $detail['visit'] = 'FAILED';
        $detail['reason'] = (string)($vr['error'] ?? 'VISIT_FAILED');
        if (!$testMode) {
            self::record($profileId, self::T_SEARCH_VISIT, (string)$picked['domain'],
                'VISIT_FAILED', $ms(), $detail['reason'], json_encode($detail, JSON_UNESCAPED_UNICODE));
        }
        return ['ok' => false, 'result' => 'VISIT_FAILED', 'error' => $detail['reason'],
            'ms' => $ms(), 'detail' => $detail];
    }

    /**
     * Trich organic results tu Google SERP (defensive: DOM doi -> null, khong crash).
     * @return array[]|null [{url, title, sponsored}]
     */
    private static function extractOrganic(int $port, string $tabId): ?array
    {
        $js = "(()=>{try{const out=[];const seen=new Set();"
            . "const adSel='[data-text-ad],.uEierd,#tads,#tads a,.commercial-unit,.commercial-unit a,[data-rw],.Krnil';"
            . "const isAd=(el)=>{try{"
            . "if(el.closest(adSel))return true;"
            . "let p=el;for(let i=0;i<4&&p;i++){p=p.parentElement;if(!p)break;"
            . "const t=(p.innerText||'').trim().slice(0,24).toLowerCase();"
            . "if(t.indexOf('sponsored')===0||t.indexOf('được tài trợ')===0||t.indexOf('quảng cáo')===0||t.indexOf('ad ' )===0||t==='ad')return true;}"
            . "return false;}catch(e){return false}};"
            . "const links=document.querySelectorAll('a[href]');"
            . "for(const a of links){try{"
            . "const href=a.getAttribute('href')||'';"
            . "if(!href||href.charAt(0)==='#'||href.indexOf('javascript:')===0)continue;"
            . "if(href.indexOf('/search?')===0||href.indexOf('/advanced_search')===0)continue;"
            . "const h3=a.querySelector('h3');"
            . "if(!h3)continue;"
            . "const title=(h3.innerText||'').trim().slice(0,150);"
            . "if(!title)continue;"
            . "if(seen.has(href))continue;seen.add(href);"
            . "out.push({url:href,title:title,sponsored:isAd(a)});"
            . "if(out.length>=30)break;"
            . "}catch(e){}}"
            . "return out;}catch(e){return null}})()";
        try {
            $d = AccountDataCollector::eval($port, $tabId, $js);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($d) || !array_key_exists('value', $d)) return null;
        $v = $d['value'];
        if (!is_array($v)) return null;
        $out = [];
        foreach ($v as $r) {
            if (!is_array($r) || empty($r['url'])) continue;
            $out[] = ['url' => (string)$r['url'], 'title' => (string)($r['title'] ?? ''),
                'sponsored' => !empty($r['sponsored'])];
        }
        return $out;
    }

    /**
     * Doi page ready (poll readyState, toi da $timeoutMs). Khong sleep(10) (§47).
     */
    private static function waitPageReady(int $port, string $tabId, int $timeoutMs = 8000): bool
    {
        $deadline = microtime(true) + max(1000, $timeoutMs) / 1000;
        while (microtime(true) < $deadline) {
            try {
                $d = AccountDataCollector::eval($port, $tabId,
                    "(()=>{try{return document.readyState||''}catch(e){return ''}})()");
                if (is_array($d) && ($d['value'] ?? '') === 'complete') return true;
            } catch (Throwable $e) {
                return false;
            }
            usleep(500000);
        }
        return false;
    }

    /** CHECK_TAB: verify required hien dien (khong mo gi). */
    private static function taskCheck(int $profileId, int $port, callable $ms): array
    {
        $cfg = self::getConfig($profileId);
        $live = self::liveTabs($port);
        $missing = [];
        foreach (self::requiredUrls($cfg) as $url) {
            $found = false;
            foreach ($live as $t) {
                if (self::domainMatch((string)($t['url'] ?? ''), $url)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) $missing[] = self::domainOf($url);
        }
        if ($missing) {
            self::record($profileId, self::T_CHECK, implode(',', $missing), self::R_MISSING, $ms());
            return ['ok' => false, 'result' => self::R_MISSING, 'ms' => $ms(), 'missing' => $missing];
        }
        self::record($profileId, self::T_CHECK, 'all', self::R_CHECKED, $ms());
        return ['ok' => true, 'result' => self::R_CHECKED, 'ms' => $ms()];
    }

    /**
     * CLOSE_AUTOMATION_TAB: chi dong tab AUTOMATION (targetId con song + khong
     * khop required hien tai). KHONG bao gio dong tab USER.
     */
    private static function taskCloseAuto(int $profileId, int $port, callable $ms): array
    {
        $cfg = self::getConfig($profileId);
        $live = self::liveTabs($port);
        $st = self::stateGet($profileId);
        $auto = self::pruneAuto((array)($st['auto_tabs'] ?? []), $live);
        $req = self::requiredUrls($cfg);
        $closed = 0;
        foreach (array_keys($auto) as $tid) {
            $info = $auto[$tid];
            $needed = false;
            foreach ($req as $ru) {
                if (self::domainMatch((string)($info['url'] ?? ''), $ru)) {
                    $needed = true;
                    break;
                }
            }
            if (($info['kind'] ?? '') === 'search' && $needed === false) {
                // Giu 1 search tab cho reuse; dong search tab thua
                $keepOne = false;
                foreach ($auto as $tid2 => $info2) {
                    if ($tid2 !== $tid && ($info2['kind'] ?? '') === 'search') {
                        $keepOne = true;
                        break;
                    }
                }
                if ($keepOne) {
                    AccountDataCollector::closeTab($port, (string)$tid);
                    unset($auto[$tid]);
                    $closed++;
                }
                continue;
            }
            if (!$needed && ($info['kind'] ?? '') !== 'search') {
                AccountDataCollector::closeTab($port, (string)$tid);
                unset($auto[$tid]);
                $closed++;
            }
        }
        self::stateSet($profileId, ['auto_tabs' => $auto]);
        self::record($profileId, self::T_CLOSE_AUTO, $closed . ' tabs', self::R_CLOSED, $ms());
        return ['ok' => true, 'result' => self::R_CLOSED, 'ms' => $ms(), 'closed' => $closed];
    }

    // ================= Website Pool (§5-7) =================

    public const WEB_CATEGORIES = ['NEWS', 'TECH', 'WORK', 'EDUCATION', 'REFERENCE', 'TOOLS', 'CUSTOM'];

    /** @return array[] */
    public static function webList(?string $category = null, bool $enabledOnly = false): array
    {
        self::ensureTables();
        try {
            $w = [];
            $p = [];
            if ($category !== null && $category !== '' && $category !== 'all') {
                $w[] = 'category=?';
                $p[] = $category;
            }
            if ($enabledOnly) $w[] = 'enabled=1';
            $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $st = db()->prepare("SELECT * FROM activity_websites $where ORDER BY enabled DESC, use_count ASC, id ASC LIMIT 500");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{ok, id?, error?} */
    public static function webSave(?int $id, array $in): array
    {
        self::ensureTables();
        $url = trim((string)($in['url'] ?? ''));
        // Chap nhan domain tran (§11): tu them https://; reject scheme la
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            if (preg_match('#^(javascript|file|data|ftp|about):#i', $url)) {
                return ['ok' => false, 'error' => 'Scheme không hỗ trợ'];
            }
            $url = 'https://' . ltrim($url, '/');
        }
        if (!self::validHttpUrl($url)) return ['ok' => false, 'error' => 'URL không hợp lệ'];
        $cat = strtoupper((string)($in['category'] ?? 'CUSTOM'));
        if (!in_array($cat, self::WEB_CATEGORIES, true)) $cat = 'CUSTOM';
        $name = mb_substr(trim((string)($in['name'] ?? '')) ?: self::hostOf($url), 0, 120);
        $weight = max(1, min(10, (int)($in['weight'] ?? 1)));
        $enabled = array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : 1;
        $domain = self::normHost($url);
        try {
            if ($id > 0) {
                db()->prepare('UPDATE activity_websites SET name=?, url=?, domain=?, category=?, enabled=?, weight=? WHERE id=?')
                    ->execute([$name, $url, $domain, $cat, $enabled, $weight, $id]);
                return ['ok' => true, 'id' => $id];
            }
            // Dedupe theo domain (§12, §79): www/https khac nhau nhung cung domain -> 1 row
            $ex = db()->prepare('SELECT id FROM activity_websites WHERE domain=? LIMIT 1');
            $ex->execute([$domain]);
            $dup = $ex->fetchColumn();
            if ($dup) {
                db()->prepare('UPDATE activity_websites SET name=?, url=?, category=?, enabled=?, weight=? WHERE id=?')
                    ->execute([$name, $url, $cat, $enabled, $weight, (int)$dup]);
                return ['ok' => true, 'id' => (int)$dup, 'duplicate' => true];
            }
            db()->prepare('INSERT INTO activity_websites (name, url, domain, category, enabled, weight)
                VALUES (?,?,?,?,?,?)')
                ->execute([$name, $url, $domain, $cat, $enabled, $weight]);
            return ['ok' => true, 'id' => (int)db()->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function webDelete(int $id): bool
    {
        self::ensureTables();
        try {
            $st = db()->prepare('DELETE FROM activity_websites WHERE id=?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Import nhieu dong URL (§5). @return array{added, duplicate, skipped} */
    public static function webImport(string $text, string $category = 'CUSTOM'): array
    {
        $added = 0;
        $dup = 0;
        $skipped = 0;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Ho tro "Name | https://..." hoac URL tran
            $url = $line;
            $name = '';
            if (str_contains($line, '|')) {
                [$name, $url] = array_map('trim', explode('|', $line, 2));
            }
            $r = self::webSave(null, ['name' => $name, 'url' => $url, 'category' => $category]);
            if (!empty($r['ok'])) {
                if (!empty($r['duplicate'])) $dup++;
                else $added++;
            } else $skipped++;
        }
        return ['added' => $added, 'duplicate' => $dup, 'skipped' => $skipped];
    }

    // ================= Search Pool (§8-9) =================

    /** @return array[] */
    public static function searchList(bool $enabledOnly = false): array
    {
        self::ensureTables();
        try {
            $w = $enabledOnly ? 'WHERE enabled=1' : '';
            return db()->query("SELECT *, (use_today=CURDATE()) AS is_today FROM activity_search_pool $w ORDER BY enabled DESC, use_today_count ASC, use_count ASC, id ASC LIMIT 500")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{ok, id?, error?} */
    public static function searchSave(?int $id, array $in): array
    {
        self::ensureTables();
        $q = trim((string)($in['query'] ?? ''));
        if ($q === '' || mb_strlen($q) > 200) return ['ok' => false, 'error' => 'Query không hợp lệ'];
        $cat = strtoupper((string)($in['category'] ?? 'CUSTOM'));
        if (!in_array($cat, self::WEB_CATEGORIES, true)) $cat = 'CUSTOM';
        $weight = max(1, min(10, (int)($in['weight'] ?? 1)));
        $enabled = array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : 1;
        try {
            if ($id > 0) {
                db()->prepare('UPDATE activity_search_pool SET query=?, category=?, enabled=?, weight=? WHERE id=?')
                    ->execute([$q, $cat, $enabled, $weight, $id]);
                return ['ok' => true, 'id' => $id];
            }
            db()->prepare('INSERT INTO activity_search_pool (query, category, enabled, weight)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE category=VALUES(category),
                enabled=VALUES(enabled), weight=VALUES(weight)')
                ->execute([$q, $cat, $enabled, $weight]);
            return ['ok' => true, 'id' => (int)db()->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function searchDelete(int $id): bool
    {
        self::ensureTables();
        try {
            $st = db()->prepare('DELETE FROM activity_search_pool WHERE id=?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Paste nhieu query (§8). @return array{added, skipped} */
    public static function searchImport(string $text, string $category = 'CUSTOM'): array
    {
        $added = 0;
        $skipped = 0;
        $seen = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $q = trim($line);
            if ($q === '' || mb_strlen($q) > 200 || isset($seen[$q])) {
                if ($q !== '') $skipped++;
                continue;
            }
            $seen[$q] = true;
            $r = self::searchSave(null, ['query' => $q, 'category' => $category]);
            if (!empty($r['ok'])) $added++;
            else $skipped++;
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Migrate 1 lan: gom search_queries rieng le cac profile vao pool chung.
     * Giu nguyen cot cu (fallback).
     */
    public static function seedPoolsFromConfigs(): int
    {
        self::ensureTables();
        if (get_setting('act_pool_seeded', '0') === '1') return 0;
        $n = 0;
        try {
            foreach (db()->query('SELECT search_queries FROM activity_configs')->fetchAll() as $r) {
                $qs = json_decode((string)($r['search_queries'] ?? ''), true);
                if (!is_array($qs)) continue;
                foreach ($qs as $q) {
                    $q = trim((string)$q);
                    if ($q === '') continue;
                    $s = self::searchSave(null, ['query' => $q]);
                    if (!empty($s['ok'])) $n++;
                }
            }
        } catch (Throwable $e) {
        }
        set_setting('act_pool_seeded', '1');
        return $n;
    }

    /**
     * Weighted random pick (weight cao + it dung tertulis uu tien).
     * @param array[] $rows (co weight, use_count, id)
     */
    private static function weightedPick(array $rows): ?array
    {
        if (!$rows) return null;
        $total = 0;
        $weights = [];
        foreach ($rows as $i => $r) {
            $w = max(1, (int)($r['weight'] ?? 1)) * 10 / (1 + (int)($r['use_count'] ?? 0));
            $weights[$i] = $w;
            $total += $w;
        }
        $roll = mt_rand() / mt_getrandmax() * $total;
        foreach ($weights as $i => $w) {
            $roll -= $w;
            if ($roll <= 0) return $rows[$i];
        }
        return $rows[array_key_last($rows)];
    }

    /**
     * Chon query tu pool (§9): enabled, chua dat usage_today cap, it dung nhat.
     * Fallback: search_queries cu cua profile.
     */
    public static function pickSearch(int $profileId, int $dailyCap = 0): ?array
    {
        self::ensureTables();
        try {
            $rows = db()->query("SELECT * FROM activity_search_pool WHERE enabled=1
                AND (use_today IS NULL OR use_today<>CURDATE() OR use_today_count<8)
                ORDER BY use_today_count ASC, use_count ASC LIMIT 50")->fetchAll();
            if ($dailyCap > 0) {
                $rows = array_values(array_filter($rows, fn($r) =>
                    (int)($r['use_today'] === date('Y-m-d') ? $r['use_today_count'] : 0) < $dailyCap));
            }
            $pick = self::weightedPick($rows);
            if ($pick) {
                $today = date('Y-m-d');
                db()->prepare('UPDATE activity_search_pool SET last_used_at=NOW(), use_count=use_count+1,
                        use_today_count=CASE WHEN use_today=? THEN use_today_count+1 ELSE 1 END,
                        use_today=? WHERE id=?')
                    ->execute([$today, $today, (int)$pick['id']]);
                return $pick;
            }
        } catch (Throwable $e) {
        }
        // Fallback: queries cu (random, khong tuan tu)
        $cfg = self::getConfig($profileId);
        $qs = (array)($cfg['search_queries'] ?? []);
        if ($qs) return ['query' => $qs[array_rand($qs)], 'id' => 0];
        return null;
    }

    /**
     * Chon website tu pool (§7): enabled, tranh domain vua dung gan + trong session.
     * @param string[] $excludeDomains
     */
    public static function pickWebsite(array $excludeDomains = []): ?array
    {
        self::ensureTables();
        try {
            $rows = self::webList(null, true);
            if ($excludeDomains) {
                $ex = array_map('strtolower', $excludeDomains);
                $rows = array_values(array_filter($rows, fn($r) => !in_array(strtolower((string)$r['domain']), $ex, true)));
                if (!$rows) $rows = self::webList(null, true); // het thi tha long
            }
            $pick = self::weightedPick($rows);
            if ($pick) {
                db()->prepare('UPDATE activity_websites SET last_used_at=NOW(), use_count=use_count+1 WHERE id=?')
                    ->execute([(int)$pick['id']]);
                return $pick;
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    /** Dem tasks hom nay theo nhom (§14). @return array<string,int> */
    public static function countToday(int $profileId): array
    {
        self::ensureTables();
        $out = ['SEARCH' => 0, 'WEBSITE' => 0, 'GMAIL' => 0, 'DRIVE' => 0, 'CALENDAR' => 0, 'OTHER' => 0];
        try {
            $rows = db()->prepare("SELECT task_type, domain, COUNT(*) c FROM activity_history
                WHERE profile_id=? AND created_at>=CURDATE() AND result NOT IN ('SKIPPED')
                GROUP BY task_type, domain");
            $rows->execute([$profileId]);
            foreach ($rows->fetchAll() as $r) {
                $k = 'OTHER';
                if (($r['task_type'] ?? '') === 'OPEN_SEARCH') $k = 'SEARCH';
                elseif (($r['task_type'] ?? '') === 'OPEN_RANDOM_WEBSITE') $k = 'WEBSITE';
                else {
                    $d = strtolower((string)($r['domain'] ?? ''));
                    if (str_contains($d, 'mail.google')) $k = 'GMAIL';
                    elseif (str_contains($d, 'drive.google')) $k = 'DRIVE';
                    elseif (str_contains($d, 'calendar.google')) $k = 'CALENDAR';
                }
                $out[$k] += (int)$r['c'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    // ================= Cycle (scheduler goi) =================

    /**
     * Chay 1 cycle: ensure required (+1 search xoay vong neu mode cho phep).
     * @return array{tasks:array}
     */
    public static function runCycle(int $profileId): array
    {
        $cfg = self::getConfig($profileId);
        $out = [];
        $mode = (string)($cfg['activity_mode'] ?? 'maintain');
        if (!empty($cfg['keep_required_tabs'])) {
            foreach (self::requiredUrls($cfg) as $url) {
                $key = array_search($url, self::PRESETS, true);
                $out[] = self::runTask($profileId, ['type' => self::T_ENSURE, 'page' => $key !== false ? $key : $url]);
            }
        }
        if (in_array($mode, ['search', 'full'], true) && !empty($cfg['search_queries'])) {
            $st = self::stateGet($profileId);
            $idx = (int)($st['search_idx'] ?? 0);
            $q = $cfg['search_queries'][$idx % count($cfg['search_queries'])];
            $r = self::runTask($profileId, ['type' => self::T_SEARCH, 'query' => $q]);
            $out[] = $r;
            // BLOCKED -> dung search luon cycle nay (khong thu query khac)
            if (($r['result'] ?? '') !== self::R_BLOCKED) {
                self::stateSet($profileId, ['search_idx' => $idx + 1]);
            }
        }
        try {
            self::ensureTables();
            db()->prepare('UPDATE activity_configs SET last_run_at=NOW() WHERE profile_id=?')
                ->execute([$profileId]);
        } catch (Throwable $e) {
        }
        return ['tasks' => $out];
    }
}
