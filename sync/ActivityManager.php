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
            'pause_until' => null, 'last_run_at' => null];
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
        try {
            db()->prepare('INSERT INTO activity_configs (profile_id, enabled, schedule_start, schedule_end,
                    interval_minutes, max_tabs, keep_required_tabs, required_pages, search_queries,
                    activity_mode, auto_start_profile, maintain_always, store_queries, pause_until)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), schedule_start=VALUES(schedule_start),
                    schedule_end=VALUES(schedule_end), interval_minutes=VALUES(interval_minutes),
                    max_tabs=VALUES(max_tabs), keep_required_tabs=VALUES(keep_required_tabs),
                    required_pages=VALUES(required_pages), search_queries=VALUES(search_queries),
                    activity_mode=VALUES(activity_mode), auto_start_profile=VALUES(auto_start_profile),
                    maintain_always=VALUES(maintain_always), store_queries=VALUES(store_queries),
                    pause_until=VALUES(pause_until)')
                ->execute([$profileId, $enabled, $ss . ':00', $se . ':00', $iv, $mt,
                    !empty($in['keep_required_tabs']) || !array_key_exists('keep_required_tabs', $in) ? 1 : 0,
                    json_encode(array_values($rp), JSON_UNESCAPED_UNICODE),
                    json_encode($sq, JSON_UNESCAPED_UNICODE),
                    in_array(($in['activity_mode'] ?? 'maintain'), ['maintain', 'search', 'full'], true)
                        ? (string)($in['activity_mode'] ?? 'maintain') : 'maintain',
                    !empty($in['auto_start_profile']) ? 1 : 0,
                    !empty($in['maintain_always']) ? 1 : 0,
                    !empty($in['store_queries']) ? 1 : 0,
                    $pauseUntil]);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['db_error']];
        }
        return ['ok' => true, 'errors' => $errors];
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
