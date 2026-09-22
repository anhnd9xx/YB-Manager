<?php
declare(strict_types=1);
/**
 * WindowPlacementManager - SINGLE OWNER cho vi tri that cua cua so Chrome.
 *
 * Nhiem vu:
 *  - enumerate monitors (qua SyncWindowDiscovery, device name \\.\DISPLAYx, GIU toa do am)
 *  - detect monitor cua HWND (MonitorFromWindow da co trong discovery -> monitorId)
 *  - luu monitor cuoi + rect + normalized rect khi dong (save_window_placement)
 *  - resolve monitor khi start (FIXED > LAST > fallback user-config > primary)
 *  - build startup rect TRUOC Popen (resolve_startup_rect)
 *  - verify/correct placement sau khi HWND ready (tolerance, khong SetForeground)
 *  - placement guard chung ~1600ms (100/350/800/1500ms), KHONG thread/profile
 *
 * WindowLayoutManager chi TINH layout (calculate), ChromeWindowManager chi phat
 * event HWND ready. TabSession/Proxy/Title KHONG duoc tu move window.
 */
require_once __DIR__ . '/WindowDiscovery.php';
require_once __DIR__ . '/WindowManager.php';
require_once __DIR__ . '/SyncLogger.php';

class WindowPlacementManager
{
    public const POSITION_TOLERANCE = 20;
    public const GUARD_DURATION_MS = 1600;
    /** @var int[] cac moc verify trong guard */
    public const GUARD_CHECKS_MS = [100, 350, 800, 1500];

    /** Cache topology 5s de khong EnumDisplayMonitors lien tuc */
    private static ?array $monCache = null;
    private static float $monCacheAt = 0.0;

    // ================= Monitor model =================

    /** @return array[] [{id,handle,name,left,top,right,bottom,work_*,width,height,work_width,work_height,is_primary}] */
    public static function monitors(bool $forceRefresh = false): array
    {
        $now = microtime(true);
        if (!$forceRefresh && self::$monCache !== null && ($now - self::$monCacheAt) < 5) {
            return self::$monCache;
        }
        $out = [];
        try {
            $raw = SyncWindowDiscovery::discover($forceRefresh ? false : true)['monitors'] ?? [];
        } catch (Throwable $e) {
            $raw = [];
        }
        foreach ($raw as $m) {
            $wa = $m['workArea'] ?? ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0];
            $res = $m['resolution'] ?? ['w' => 0, 'h' => 0];
            $x = (int)($wa['x'] ?? 0);
            $y = (int)($wa['y'] ?? 0);
            $w = (int)($wa['w'] ?? 0);
            $h = (int)($wa['h'] ?? 0);
            // QUAN TRONG: GIU NGUYEN toa do am (monitor ben trai/y am). KHONG max(0,x).
            $out[] = [
                'id' => (int)($m['id'] ?? 0),
                'handle' => $m['handle'] ?? null, // khong persist
                'device_name' => (string)($m['name'] ?? ''),
                'left' => $x,
                'top' => $y,
                'right' => $x + $w,
                'bottom' => $y + $h,
                'work_left' => $x,
                'work_top' => $y,
                'work_right' => $x + $w,
                'work_bottom' => $y + $h,
                'width' => (int)($res['w'] ?? $w),
                'height' => (int)($res['h'] ?? $h),
                'work_width' => $w,
                'work_height' => $h,
                'is_primary' => !empty($m['primary']),
                // giu them workArea goc cho engine cu
                'workArea' => $wa,
                'resolution' => $res,
                'dpi' => $m['dpi'] ?? ['x' => 96, 'y' => 96],
                'primary' => !empty($m['primary']),
                'name' => (string)($m['name'] ?? ''),
            ];
        }
        self::$monCache = $out;
        self::$monCacheAt = $now;
        foreach ($out as $mm) {
            SyncLogger::debug('monitor', '[MONITOR] device=' . $mm['device_name']
                . ' work=(' . $mm['work_left'] . ',' . $mm['work_top'] . ','
                . $mm['work_width'] . 'x' . $mm['work_height'] . ')'
                . ' primary=' . ($mm['is_primary'] ? 'True' : 'False'));
        }
        return $out;
    }

    public static function refreshMonitors(): array
    {
        return self::monitors(true);
    }

    public static function findByDevice(string $device): ?array
    {
        $device = trim($device);
        if ($device === '') return null;
        foreach (self::monitors() as $m) {
            if (strcasecmp($m['device_name'], $device) === 0) return $m;
        }
        return null;
    }

    public static function findById(int $id): ?array
    {
        foreach (self::monitors() as $m) {
            if ((int)$m['id'] === $id) return $m;
        }
        return null;
    }

    public static function primary(): ?array
    {
        foreach (self::monitors() as $m) {
            if (!empty($m['is_primary'])) return $m;
        }
        $all = self::monitors();
        return $all[0] ?? null;
    }

    /** Monitor chua 1 rect (center-in). Dung de detect sau khi luu. */
    public static function monitorForRect(int $x, int $y, int $w, int $h): ?array
    {
        $cx = $x + (int)($w / 2);
        $cy = $y + (int)($h / 2);
        foreach (self::monitors() as $m) {
            if ($cx >= $m['work_left'] && $cx < $m['work_right']
                && $cy >= $m['work_top'] && $cy < $m['work_bottom']) {
                return $m;
            }
        }
        // nearest fallback: tam gan nhat
        $best = null;
        $bestD = PHP_INT_MAX;
        foreach (self::monitors() as $m) {
            $nx = max($m['work_left'], min($cx, $m['work_right'] - 1));
            $ny = max($m['work_top'], min($cy, $m['work_bottom'] - 1));
            $d = ($nx - $cx) * ($nx - $cx) + ($ny - $cy) * ($ny - $cy);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $m;
            }
        }
        return $best;
    }

    /** Monitor cua HWND (qua discovery monitorId, KHONG dung window.x>=0). */
    public static function monitorForHwnd(int $hwnd): ?array
    {
        try {
            $w = SyncWindowManager::findWindow($hwnd);
        } catch (Throwable $e) {
            $w = null;
        }
        if ($w !== null && $w->monitorId !== null) {
            $m = self::findById((int)$w->monitorId);
            if ($m !== null) return $m;
        }
        // fallback theo rect center
        if ($w !== null && $w->rect !== null) {
            return self::monitorForRect($w->rect['x'], $w->rect['y'], $w->rect['w'], $w->rect['h']);
        }
        return null;
    }

    // ================= Profile helpers =================

    public static function profileRow(int $id): ?array
    {
        try {
            $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function hasPlacementCols(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $cols = db()->query('SHOW COLUMNS FROM profiles')->fetchAll(PDO::FETCH_COLUMN, 0);
            $has = in_array('monitor_mode', array_map('strtolower', array_map('strval', $cols)), true)
                || in_array('monitor_mode', $cols, true);
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    // ================= Save on close =================

    /**
     * Luu placement TRUOC WM_CLOSE (§8). Bo qua neu minimized (rect icon vo nghia).
     * Maximized: luu rcNormalPosition + state=MAXIMIZED (khong lay full-monitor rect).
     * @param SyncWindowInfo[]|null $windows scan chung (1 scan cho ca batch, khong scan lai)
     */
    public static function save_window_placement(array $profile, ?int $hwnd = null, ?array $windows = null): bool
    {
        if (!self::hasPlacementCols()) return false;
        $id = (int)($profile['id'] ?? 0);
        if ($id <= 0) return false;
        try {
            if ($hwnd === null || $hwnd <= 0) {
                $hwnd = self::hwndForProfile($id, (string)($profile['user_data_dir'] ?? ''));
            }
            if ($hwnd === null) return false;
            // Lay window info tuoi (khong cache) de co rect + minimized + monitorId
            $w = null;
            try {
                $list = $windows ?? SyncWindowDiscovery::discover(false)['windows'];
                foreach ($list as $cand) {
                    if ($cand->hwnd === $hwnd) {
                        $w = $cand;
                        break;
                    }
                }
            } catch (Throwable $e) {
            }
            if ($w === null || $w->rect === null) return false;
            if ($w->minimized) return false; // khong lay rect khi minimized
            // Maximized: dung normal rect (§8, §54)
            $state = 'normal';
            $x = (int)$w->rect['x'];
            $y = (int)$w->rect['y'];
            $ww = (int)$w->rect['w'];
            $hh = (int)$w->rect['h'];
            if (!empty($w->maximized) && $w->normalRect !== null
                && $w->normalRect['w'] > 0 && $w->normalRect['h'] > 0) {
                $x = (int)$w->normalRect['x'];
                $y = (int)$w->normalRect['y'];
                $ww = (int)$w->normalRect['w'];
                $hh = (int)$w->normalRect['h'];
                $state = 'maximized';
            }
            if ($ww <= 0 || $hh <= 0) return false;
            $mon = self::monitorForHwnd($hwnd);
            if ($mon === null) $mon = self::monitorForRect($x, $y, $ww, $hh);
            if ($mon === null) return false;
            // Normalized theo work area CHINH monitor do (khong clamp absolute x/y >= 0)
            $norm = [
                'x' => $mon['work_width'] > 0 ? ($x - $mon['work_left']) / $mon['work_width'] : 0,
                'y' => $mon['work_height'] > 0 ? ($y - $mon['work_top']) / $mon['work_height'] : 0,
                'width' => $mon['work_width'] > 0 ? $ww / $mon['work_width'] : 0,
                'height' => $mon['work_height'] > 0 ? $hh / $mon['work_height'] : 0,
            ];
            foreach ($norm as $k => $v) {
                if (!is_finite($v)) $norm[$k] = 0;
            }
            // clamp normalized hop ly (giua -0.5..1.5 cho x/y, 0.05..1.0 cho size)
            $norm['x'] = max(-0.5, min(1.5, (float)$norm['x']));
            $norm['y'] = max(-0.5, min(1.5, (float)$norm['y']));
            $norm['width'] = max(0.05, min(1.0, (float)$norm['width']));
            $norm['height'] = max(0.05, min(1.0, (float)$norm['height']));
            db()->prepare('UPDATE profiles SET last_monitor_device=?, last_window_rect=?, last_window_rect_norm=?, last_window_state=? WHERE id=?')
                ->execute([
                    (string)$mon['device_name'],
                    json_encode(['x' => $x, 'y' => $y, 'width' => $ww, 'height' => $hh], JSON_UNESCAPED_UNICODE),
                    json_encode($norm, JSON_UNESCAPED_UNICODE),
                    $state,
                    $id,
                ]);
            SyncLogger::info('placement', '[PLACEMENT SAVE] profile=#' . $id
                . ' monitor=' . $mon['device_name'] . ' rect=(' . $x . ',' . $y . ',' . $ww . 'x' . $hh . ') state=' . $state, $id);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function hwndForProfile(int $id, string $udir): ?int
    {
        try {
            foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
                if ($w->profileId !== null && (int)$w->profileId === $id
                    && $w->class === 'Chrome_WidgetWin_1' && $w->visible && !$w->minimized) {
                    return $w->hwnd;
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    // ================= Resolve on start =================

    /** @return array monitor (dang MonitorInfo) */
    public static function resolve_target_monitor(array $profile, ?array $layoutHint = null): ?array
    {
        $mode = strtoupper(trim((string)($profile['monitor_mode'] ?? 'LAST')));
        if (!in_array($mode, ['LAST', 'FIXED', 'AUTO'], true)) $mode = 'LAST';
        // FIXED: monitor user chon
        if ($mode === 'FIXED') {
            $m = self::findByDevice((string)($profile['fixed_monitor_device'] ?? ''));
            if ($m !== null) return $m;
        }
        // LAST: man hinh cuoi
        if ($mode === 'LAST' || $mode === 'FIXED') {
            $m = self::findByDevice((string)($profile['last_monitor_device'] ?? ''));
            if ($m !== null) return $m;
            // FIXED fallback: neu last rong thi dung fixed (da check) -> xuong fallback chung
            if ($mode === 'FIXED') {
                // tiep tuc xuong fallback
            } else {
                // LAST nhung chua co last -> dung global window_monitor cu (tuong thich)
                try {
                    $g = get_setting('window_monitor', 'primary');
                    if (strcasecmp($g, 'primary') !== 0 && ctype_digit((string)$g)) {
                        $gm = self::findById((int)$g);
                        if ($gm !== null) return $gm;
                    }
                } catch (Throwable $e) {
                }
            }
        }
        // AUTO: layout hint (WindowLayoutManager phan phoi) hoac global
        if ($layoutHint !== null && !empty($layoutHint['device_name'])) {
            $m = self::findByDevice((string)$layoutHint['device_name']);
            if ($m !== null) return $m;
        }
        if ($layoutHint !== null && isset($layoutHint['monitorId'])) {
            $m = self::findById((int)$layoutHint['monitorId']);
            if ($m !== null) return $m;
        }
        // Fallback an toan: fixed neu ton tai -> primary. KHONG crash, KHONG window ngoai vung nhin.
        $m = self::findByDevice((string)($profile['fixed_monitor_device'] ?? ''));
        if ($m !== null) return $m;
        return self::primary();
    }

    /**
     * Tinh rect TRUOC khi start. Uu tien normalized rect cua chinh monitor do,
     * kep trong work area CUA MONITOR DO (khong clamp theo primary).
     * @return array{x,y,w,h}
     */
    public static function resolve_startup_rect(array $profile, ?array $monitor, ?array $fallbackSize = null): ?array
    {
        if ($monitor === null) $monitor = self::primary();
        if ($monitor === null) return null;
        $wl = (int)$monitor['work_left'];
        $wt = (int)$monitor['work_top'];
        $ww = (int)$monitor['work_width'];
        $wh = (int)$monitor['work_height'];
        $norm = null;
        try {
            $raw = $profile['last_window_rect_norm'] ?? null;
            if (is_string($raw) && $raw !== '') $norm = json_decode($raw, true);
        } catch (Throwable $e) {
            $norm = null;
        }
        if (is_array($norm) && $ww > 0 && $wh > 0
            && isset($norm['x'], $norm['y'], $norm['width'], $norm['height'])) {
            $x = $wl + (int)round((float)$norm['x'] * $ww);
            $y = $wt + (int)round((float)$norm['y'] * $wh);
            $w = (int)round((float)$norm['width'] * $ww);
            $h = (int)round((float)$norm['height'] * $wh);
            $w = max(200, min($w, $ww));
            $h = max(150, min($h, $wh));
            // kep trong work area monitor nay (giu am hop le: vd wl=-1920)
            $x = max($wl, min($x, $wl + $ww - $w));
            $y = max($wt, min($y, $wt + $wh - $h));
            return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }
        // Khong co saved rect: giu kich thuoc global/fallback, dat goc work area + cascade nhe theo id
        $fw = (int)($fallbackSize['w'] ?? $fallbackSize['width'] ?? 1280);
        $fh = (int)($fallbackSize['h'] ?? $fallbackSize['height'] ?? 720);
        // Neu global fixed OFF thi van can size hop ly de --window-size (Chrome tu restore sau)
        $fw = max(200, min($fw, $ww));
        $fh = max(150, min($fh, $wh));
        $id = (int)($profile['id'] ?? 0);
        $step = 30;
        $k = $id > 0 ? (($id - 1) % 15) : 0;
        $x = $wl + 5 + $k * $step;
        $y = $wt + 5 + $k * $step;
        $x = max($wl, min($x, $wl + $ww - $fw));
        $y = max($wt, min($y, $wt + $wh - $fh));
        return ['x' => $x, 'y' => $y, 'w' => $fw, 'h' => $fh];
    }

    /** Window state da luu: 'maximized'|'normal' (§54). */
    public static function resolve_startup_state(array $profile): string
    {
        return strtolower(trim((string)($profile['last_window_state'] ?? 'normal'))) === 'maximized'
            ? 'maximized' : 'normal';
    }

    /** Build placement plan cho batch Start All (giữ assignment, launch parallel khong doi). */
    public static function buildPlacementPlan(array $profiles, ?array $fallbackSize = null): array
    {
        $plan = [];
        foreach ($profiles as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) continue;
            $mon = self::resolve_target_monitor($p);
            $rect = self::resolve_startup_rect($p, $mon, $fallbackSize);
            if ($mon === null || $rect === null) continue;
            $plan[$id] = ['monitor' => $mon, 'rect' => $rect];
            SyncLogger::info('placement', '[PLACEMENT PLAN] profile=#' . $id
                . ' monitor=' . $mon['device_name']
                . ' rect=(' . $rect['x'] . ',' . $rect['y'] . ',' . $rect['w'] . 'x' . $rect['h'] . ')', $id);
        }
        return $plan;
    }

    // ================= Verify / correct =================

    /** @return array{ok,expected,actual,dx,dy} */
    public static function verify_window_placement(array $profile, int $hwnd, array $targetRect, array $targetMonitor): array
    {
        $res = ['ok' => true, 'expected' => $targetMonitor['device_name'] ?? '', 'actual' => '', 'dx' => 0, 'dy' => 0];
        try {
            $w = SyncWindowManager::findWindow($hwnd);
        } catch (Throwable $e) {
            $w = null;
        }
        if ($w === null || $w->rect === null) return $res + ['ok' => true];
        $actualMon = self::monitorForHwnd($hwnd);
        $res['actual'] = $actualMon['device_name'] ?? '';
        $dx = abs($w->rect['x'] - $targetRect['x']);
        $dy = abs($w->rect['y'] - $targetRect['y']);
        $dw = abs($w->rect['w'] - $targetRect['w']);
        $dh = abs($w->rect['h'] - $targetRect['h']);
        $res['dx'] = $dx;
        $res['dy'] = $dy;
        $monOk = ($res['actual'] !== '' && $res['expected'] !== '')
            ? (strcasecmp($res['actual'], $res['expected']) === 0)
            : true;
        $rectOk = $dx <= self::POSITION_TOLERANCE && $dy <= self::POSITION_TOLERANCE
            && $dw <= self::POSITION_TOLERANCE && $dh <= self::POSITION_TOLERANCE;
        $res['ok'] = $monOk && $rectOk;
        return $res;
    }

    /** Apply rect that (SetWindowPos NOACTIVATE, khong SetForeground). */
    public static function apply_rect(int $hwnd, int $x, int $y, int $w, int $h): bool
    {
        if ($hwnd <= 0 || $w <= 0 || $h <= 0) return false;
        try {
            $r = SyncWindowManager::moveResize($hwnd, $x, $y, $w, $h);
            return !empty($r['ok']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function correct_window_placement(array $profile, int $hwnd, array $targetRect, array $targetMonitor): bool
    {
        $id = (int)($profile['id'] ?? 0);
        SyncLogger::info('placement', '[PLACEMENT CORRECT] profile=#' . $id
            . ' target=' . ($targetMonitor['device_name'] ?? '')
            . ' rect=(' . $targetRect['x'] . ',' . $targetRect['y'] . ',' . $targetRect['w'] . 'x' . $targetRect['h'] . ')', $id);
        return self::apply_rect($hwnd, $targetRect['x'], $targetRect['y'], $targetRect['w'], $targetRect['h']);
    }

    // ================= Guard (1 timer chung) =================

    private static function guardFile(int $profileId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_guard_' . $profileId . '.json';
    }

    /** Bat dau guard sau khi HWND ready (ghi file de apply_window poll chung). */
    public static function startGuard(int $profileId, int $hwnd, array $rect, array $monitor): void
    {
        @file_put_contents(self::guardFile($profileId), json_encode([
            'hwnd' => $hwnd, 'rect' => $rect,
            'monitor' => $monitor['device_name'] ?? '',
            't0' => microtime(true), 'checks' => self::GUARD_CHECKS_MS,
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function clearGuard(int $profileId): void
    {
        @unlink(self::guardFile($profileId));
    }

    /** Cap nhat HWND vao guard (viet luc Popen voi hwnd=0, apply_window dien sau). */
    public static function updateGuardHwnd(int $profileId, int $hwnd): void
    {
        $f = self::guardFile($profileId);
        if (!is_file($f)) return;
        try {
            $g = json_decode((string)@file_get_contents($f), true);
            if (!is_array($g)) return;
            $g['hwnd'] = $hwnd;
            @file_put_contents($f, json_encode($g, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        }
    }

    /** 1 tick guard (goi tu apply_window loop): tra ve 'stable'|'wait'|'done'. */
    public static function guardTick(int $profileId, array $profile): string
    {
        $f = self::guardFile($profileId);
        if (!is_file($f)) return 'done';
        $g = json_decode((string)@file_get_contents($f), true);
        if (!is_array($g)) {
            @unlink($f);
            return 'done';
        }
        $elapsed = (microtime(true) - (float)($g['t0'] ?? microtime(true))) * 1000;
        if ($elapsed >= self::GUARD_DURATION_MS) {
            SyncLogger::info('placement', '[PLACEMENT STABLE] profile=#' . $profileId, $profileId);
            @unlink($f);
            return 'done';
        }
        $hwnd = (int)($g['hwnd'] ?? 0);
        $rect = $g['rect'] ?? null;
        $monName = (string)($g['monitor'] ?? '');
        $mon = $monName !== '' ? (self::findByDevice($monName) ?? self::primary()) : self::primary();
        if (!is_array($rect) || $mon === null) return 'wait';
        $v = self::verify_window_placement($profile, $hwnd, $rect, $mon);
        if (!$v['ok']) {
            self::correct_window_placement($profile, $hwnd, $rect, $mon);
            SyncLogger::info('placement', '[PLACEMENT VERIFY] profile=#' . $profileId
                . ' expected=' . $v['expected'] . ' actual=' . $v['actual'] . ' result=CORRECT', $profileId);
        }
        return 'wait';
    }

    // ================= DPI =================

    /** Dam bao process DPI-aware (goi som). Khong crash neu API thieu. */
    public static function ensureDpiAwareness(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            // File ps1 rieng (khong inline C# qua command-line: quoting 3 lop de vo).
            $script = __DIR__ . '/win32_dpi.ps1';
            if (is_file($script)) {
                @shell_exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"');
            }
        } catch (Throwable $e) {
        }
    }
}
