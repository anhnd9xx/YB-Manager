<?php
declare(strict_types=1);
/**
 * SyncSettingsService - Single Source of Truth cho Global Settings (module Settings trung tam).
 * Boc tren bang `settings` key/value san co (config.php:get_setting), KHONG tao config system thu 2.
 * Nguyen tac: Profile KHONG copy window size; BrowserManager doc snapshot tai thoi diem Launch.
 *
 * Keys (luu TEXT trong DB, validate khi doc/save):
 *   window_fixed  '1'|'0'   (Apply Fixed Window Size)
 *   window_preset small|standard|hd|hd_plus|laptop|fhd|qhd|uhd|custom
 *   window_width  400..7680
 *   window_height 300..4320
 *   window_position auto|cascade|grid|custom
 *   window_gap    0..100
 *   window_x, window_y  (custom start, -10000..10000, clamp vao work area khi apply)
 *   window_monitor 'primary'|'<id>'
 *
 * Nhom Window Layout (Smart Auto Arrange, PHASE 2):
 *   layout_mode smart_auto|grid|horizontal|vertical|cascade|compact
 *   layout_size_mode auto_fit|keep_size
 *   layout_monitor 'primary'|'<id>'|'all'
 *   layout_multi '1'|'0' (phân phối sang nhiều monitor)
 *   layout_gap_x, layout_gap_y 0..100
 *   layout_min_w 200..4000, layout_min_h 150..3000 (candidate nho hon bi reject/penalty)
 *   layout_respect_taskbar, layout_keep_visible, layout_auto_launch '1'|'0'
 *   layout_reflow off|ask|auto
 *   layout_fallback auto|multi|compact|force_fit
 *   layout_compact_x, layout_compact_y 0..2000 (offset cheo khi overlap)
 */
require_once __DIR__ . '/../config.php';

class SyncSettingsService
{
    public const PRESETS = [
        'small'    => [800, 600],
        'standard' => [1024, 768],
        'hd'       => [1280, 720],
        'hd_plus'  => [1280, 800],
        'laptop'   => [1366, 768],
        'fhd'      => [1920, 1080],
        'qhd'      => [2560, 1440],
        'uhd'      => [3840, 2160],
        'custom'   => null,
    ];

    public const POSITIONS = ['auto', 'cascade', 'grid', 'custom'];

    public const LAYOUT_MODES = ['smart_auto', 'grid', 'horizontal', 'vertical', 'cascade', 'compact'];
    public const LAYOUT_SIZE_MODES = ['auto_fit', 'keep_size'];
    public const LAYOUT_REFLOWS = ['off', 'ask', 'auto'];
    public const LAYOUT_FALLBACKS = ['auto', 'multi', 'compact', 'force_fit'];
    public const LAYOUT_DISTRIBUTIONS = ['smart', 'equal', 'sequential', 'manual'];
    public const LAYOUT_SIZE_BALANCES = ['similar', 'maximize'];
    public const LAYOUT_DISCONNECTS = ['ask', 'auto'];

    public static function defaults(): array
    {
        return array_merge([
            'window_fixed' => '1',
            'window_preset' => 'hd',
            'window_width' => '1280',
            'window_height' => '720',
            'window_position' => 'auto',
            'window_gap' => '5',
            'window_x' => '0',
            'window_y' => '0',
            'window_monitor' => 'primary',
        ], self::layoutDefaults(), self::accountDefaults());
    }

    /** Default Window Layout (spec muc 48). Tach rieng de UI/API dung lai. */
    public static function layoutDefaults(): array
    {
        return [
            'layout_mode' => 'smart_auto',
            'layout_size_mode' => 'auto_fit',
            'layout_monitor' => 'primary',
            'layout_multi' => '0',
            'layout_gap_x' => '5',
            'layout_gap_y' => '5',
            'layout_min_w' => '500',
            'layout_min_h' => '400',
            'layout_respect_taskbar' => '1',
            'layout_keep_visible' => '1',
            'layout_auto_launch' => '0',
            'layout_reflow' => 'ask',
            'layout_fallback' => 'auto',
            'layout_compact_x' => '150',
            'layout_compact_y' => '40',
            // Multi-monitor selection (device name CSV, on dinh hon numeric id)
            'layout_monitors' => '',
            'layout_distribution' => 'smart',
            'layout_size_balance' => 'similar',
            'layout_remember_monitors' => '1',
            'layout_respect_dpi' => '1',
            'layout_keep_inside' => '1',
            'layout_disconnect' => 'ask',
            // Danh rieng cho Synchronize sau nay (MAIN rieng monitor)
            'layout_main_monitor' => '',
            'layout_controlled_monitors' => '',
        ];
    }

    /** Doc + validate toan bo window settings, tra ve snapshot sach (int/bool). Corrupt -> fallback default + log. */
    public static function getWindowSettings(): array
    {
        $raw = [];
        foreach (['window_fixed', 'window_preset', 'window_width', 'window_height', 'window_position', 'window_gap', 'window_x', 'window_y', 'window_monitor'] as $k) {
            try {
                $raw[$k] = get_setting($k, (string)self::defaults()[$k]);
            } catch (Throwable $e) {
                $raw[$k] = (string)self::defaults()[$k];
            }
        }
        return self::normalize($raw, true);
    }

    /** Alias snapshot: lay 1 ban copy tai thoi diem launch (chong race khi user Save giua launch). */
    public static function snapshot(): array
    {
        return self::getWindowSettings();
    }

    /** Doc + validate nhom Window Layout. Corrupt -> fallback default + log. */
    public static function getLayoutSettings(): array
    {
        $raw = [];
        foreach (self::layoutDefaults() as $k => $v) {
            try {
                $raw[$k] = get_setting($k, (string)$v);
            } catch (Throwable $e) {
                $raw[$k] = (string)$v;
            }
        }
        return self::normalizeLayout($raw, true);
    }

    /** Alias snapshot cho LayoutSession (toan session dung chung 1 snapshot). */
    public static function layoutSnapshot(): array
    {
        return self::getLayoutSettings();
    }

    /**
     * Chuan hoa nhom layout. Chap nhan key day du (layout_*) va short (mode, gapX...).
     * Tra ve ['mode','sizeMode','monitor','multi','gapX','gapY','minW','minH',
     *          'respectTaskbar','keepVisible','autoLaunch','reflow','fallback','compactX','compactY',
     *          'monitors'=>[device names],'distribution','sizeBalance','rememberMonitors',
     *          'respectDpi','keepInside','disconnect','mainMonitor','controlledMonitors'=>[]]
     */
    public static function normalizeLayout(array $in, bool $fromDb = false): array
    {
        $d = self::layoutDefaults();
        $g = function (array $in, string $long, string $short, $fb) {
            if (array_key_exists($long, $in)) return $in[$long];
            if (array_key_exists($short, $in)) return $in[$short];
            return $fb;
        };
        $bad = [];
        $mode = strtolower(trim((string)$g($in, 'layout_mode', 'mode', $d['layout_mode'])));
        if (!in_array($mode, self::LAYOUT_MODES, true)) {
            $bad[] = 'mode';
            $mode = 'smart_auto';
        }
        $sizeMode = strtolower(trim((string)$g($in, 'layout_size_mode', 'sizeMode', $d['layout_size_mode'])));
        if (!in_array($sizeMode, self::LAYOUT_SIZE_MODES, true)) {
            $bad[] = 'sizeMode';
            $sizeMode = 'auto_fit';
        }
        $mon = strtolower(trim((string)$g($in, 'layout_monitor', 'monitor', $d['layout_monitor'])));
        if ($mon !== 'primary' && $mon !== 'all' && (!ctype_digit($mon) || (int)$mon <= 0)) {
            $bad[] = 'monitor';
            $mon = 'primary';
        }
        $gapX = self::toInt($g($in, 'layout_gap_x', 'gapX', 5), 5);
        $gapY = self::toInt($g($in, 'layout_gap_y', 'gapY', 5), 5);
        if ($gapX < 0 || $gapX > 100) {
            $bad[] = 'gapX';
            $gapX = min(100, max(0, $gapX));
        }
        if ($gapY < 0 || $gapY > 100) {
            $bad[] = 'gapY';
            $gapY = min(100, max(0, $gapY));
        }
        $minW = self::toInt($g($in, 'layout_min_w', 'minW', 500), 500);
        $minH = self::toInt($g($in, 'layout_min_h', 'minH', 400), 400);
        if ($minW < 200 || $minW > 4000) {
            $bad[] = 'minW';
            $minW = 500;
        }
        if ($minH < 150 || $minH > 3000) {
            $bad[] = 'minH';
            $minH = 400;
        }
        $reflow = strtolower(trim((string)$g($in, 'layout_reflow', 'reflow', $d['layout_reflow'])));
        if (!in_array($reflow, self::LAYOUT_REFLOWS, true)) {
            $bad[] = 'reflow';
            $reflow = 'ask';
        }
        $fallback = strtolower(trim((string)$g($in, 'layout_fallback', 'fallback', $d['layout_fallback'])));
        if (!in_array($fallback, self::LAYOUT_FALLBACKS, true)) {
            $bad[] = 'fallback';
            $fallback = 'auto';
        }
        $compactX = self::toInt($g($in, 'layout_compact_x', 'compactX', 150), 150);
        $compactY = self::toInt($g($in, 'layout_compact_y', 'compactY', 40), 40);
        if ($compactX < 0 || $compactX > 2000) {
            $bad[] = 'compactX';
            $compactX = min(2000, max(0, $compactX));
        }
        if ($compactY < 0 || $compactY > 2000) {
            $bad[] = 'compactY';
            $compactY = min(2000, max(0, $compactY));
        }
        $b = fn($long, $short, $fb) => self::toBool($g($in, $long, $short, $fb));
        // Monitor selection: CSV device names (\\\\.\\DISPLAY1...), rong = khong gioi han
        $monCsv = trim((string)$g($in, 'layout_monitors', 'monitors', $d['layout_monitors']));
        $monList = $monCsv === '' ? [] : array_values(array_unique(array_filter(array_map(
            fn($s) => trim((string)$s), explode(',', $monCsv)), fn($s) => $s !== '')));
        $dist = strtolower(trim((string)$g($in, 'layout_distribution', 'distribution', $d['layout_distribution'])));
        if (!in_array($dist, self::LAYOUT_DISTRIBUTIONS, true)) {
            $bad[] = 'distribution';
            $dist = 'smart';
        }
        $bal = strtolower(trim((string)$g($in, 'layout_size_balance', 'sizeBalance', $d['layout_size_balance'])));
        if (!in_array($bal, self::LAYOUT_SIZE_BALANCES, true)) {
            $bad[] = 'sizeBalance';
            $bal = 'similar';
        }
        $disc = strtolower(trim((string)$g($in, 'layout_disconnect', 'disconnect', $d['layout_disconnect'])));
        if (!in_array($disc, self::LAYOUT_DISCONNECTS, true)) {
            $bad[] = 'disconnect';
            $disc = 'ask';
        }
        $mainMon = trim((string)$g($in, 'layout_main_monitor', 'mainMonitor', $d['layout_main_monitor']));
        $ctlCsv = trim((string)$g($in, 'layout_controlled_monitors', 'controlledMonitors', $d['layout_controlled_monitors']));
        $ctlList = $ctlCsv === '' ? [] : array_values(array_unique(array_filter(array_map(
            fn($s) => trim((string)$s), explode(',', $ctlCsv)), fn($s) => $s !== '')));
        if ($fromDb && $bad) {
            try {
                require_once __DIR__ . '/SyncLogger.php';
                SyncLogger::warn('settings', '[Settings][WARN] Invalid layout settings (' . implode(',', $bad) . '). Using safe defaults.');
            } catch (Throwable $e) {
            }
        }
        return [
            'mode' => $mode, 'sizeMode' => $sizeMode, 'monitor' => $mon,
            'multi' => $b('layout_multi', 'multi', $d['layout_multi']),
            'gapX' => $gapX, 'gapY' => $gapY, 'minW' => $minW, 'minH' => $minH,
            'respectTaskbar' => $b('layout_respect_taskbar', 'respectTaskbar', $d['layout_respect_taskbar']),
            'keepVisible' => $b('layout_keep_visible', 'keepVisible', $d['layout_keep_visible']),
            'autoLaunch' => $b('layout_auto_launch', 'autoLaunch', $d['layout_auto_launch']),
            'reflow' => $reflow, 'fallback' => $fallback,
            'compactX' => $compactX, 'compactY' => $compactY,
            'monitors' => $monList, 'distribution' => $dist, 'sizeBalance' => $bal,
            'rememberMonitors' => $b('layout_remember_monitors', 'rememberMonitors', $d['layout_remember_monitors']),
            'respectDpi' => $b('layout_respect_dpi', 'respectDpi', $d['layout_respect_dpi']),
            'keepInside' => $b('layout_keep_inside', 'keepInside', $d['layout_keep_inside']),
            'disconnect' => $disc, 'mainMonitor' => $mainMon, 'controlledMonitors' => $ctlList,
        ];
    }

    /**
     * Validate nhom layout truoc khi save. Chi kiem tra key CO MAT trong input
     * (partial-save friendly); normalized chi gom key da gui (khong ghi de key khac
     * trong DB bang default). Tra ve [ok, errors[], normalized].
     */
    public static function validateLayoutForSave(array $in): array
    {
        $errors = [];
        $has = fn(string $k) => array_key_exists($k, $in);
        if ($has('layout_mode')) {
            $mode = strtolower(trim((string)$in['layout_mode']));
            if (!in_array($mode, self::LAYOUT_MODES, true)) $errors[] = 'Layout mode khong hop le';
        }
        if ($has('layout_size_mode')) {
            $sm = strtolower(trim((string)$in['layout_size_mode']));
            if (!in_array($sm, self::LAYOUT_SIZE_MODES, true)) $errors[] = 'Size mode khong hop le';
        }
        if ($has('layout_monitor')) {
            $mon = strtolower(trim((string)$in['layout_monitor']));
            if ($mon !== 'primary' && $mon !== 'all' && (!ctype_digit($mon) || (int)$mon <= 0)) $errors[] = 'Monitor khong hop le';
        }
        foreach (['layout_gap_x' => [0, 100], 'layout_gap_y' => [0, 100]] as $k => [$lo, $hi]) {
            if ($has($k)) {
                $v = self::toInt($in[$k], -1);
                if ($v < $lo || $v > $hi) $errors[] = "$k phai $lo..$hi";
            }
        }
        if ($has('layout_min_w')) {
            $mw = self::toInt($in['layout_min_w'], 0);
            if ($mw < 200 || $mw > 4000) $errors[] = 'layout_min_w phai 200..4000';
        }
        if ($has('layout_min_h')) {
            $mh = self::toInt($in['layout_min_h'], 0);
            if ($mh < 150 || $mh > 3000) $errors[] = 'layout_min_h phai 150..3000';
        }
        if ($has('layout_reflow')) {
            $rf = strtolower(trim((string)$in['layout_reflow']));
            if (!in_array($rf, self::LAYOUT_REFLOWS, true)) $errors[] = 'Reflow khong hop le';
        }
        if ($has('layout_fallback')) {
            $fbk = strtolower(trim((string)$in['layout_fallback']));
            if (!in_array($fbk, self::LAYOUT_FALLBACKS, true)) $errors[] = 'Fallback khong hop le';
        }
        foreach (['layout_compact_x' => [0, 2000], 'layout_compact_y' => [0, 2000]] as $k => [$lo, $hi]) {
            if ($has($k)) {
                $v = self::toInt($in[$k], -1);
                if ($v < $lo || $v > $hi) $errors[] = "$k phai $lo..$hi";
            }
        }
        if ($has('layout_distribution')) {
            if (!in_array(strtolower(trim((string)$in['layout_distribution'])), self::LAYOUT_DISTRIBUTIONS, true)) {
                $errors[] = 'Distribution khong hop le';
            }
        }
        if ($has('layout_size_balance')) {
            if (!in_array(strtolower(trim((string)$in['layout_size_balance'])), self::LAYOUT_SIZE_BALANCES, true)) {
                $errors[] = 'Size balance khong hop le';
            }
        }
        if ($has('layout_disconnect')) {
            if (!in_array(strtolower(trim((string)$in['layout_disconnect'])), self::LAYOUT_DISCONNECTS, true)) {
                $errors[] = 'Disconnect mode khong hop le';
            }
        }
        // layout_monitors / main / controlled: CSV tu do (mapvoi monitor live khi arrange,
        // ten la khong ton tai thi bo qua) -> khong can validate chat
        if ($errors) return ['ok' => false, 'errors' => $errors, 'normalized' => []];
        // Normalize FULL roi chi lay key da gui (giữ DB key khac nguyen ven)
        $n = self::normalizeLayout($in);
        $map = [
            'layout_mode' => $n['mode'],
            'layout_size_mode' => $n['sizeMode'],
            'layout_monitor' => $n['monitor'],
            'layout_multi' => $n['multi'] ? '1' : '0',
            'layout_gap_x' => (string)$n['gapX'],
            'layout_gap_y' => (string)$n['gapY'],
            'layout_min_w' => (string)$n['minW'],
            'layout_min_h' => (string)$n['minH'],
            'layout_respect_taskbar' => $n['respectTaskbar'] ? '1' : '0',
            'layout_keep_visible' => $n['keepVisible'] ? '1' : '0',
            'layout_auto_launch' => $n['autoLaunch'] ? '1' : '0',
            'layout_reflow' => $n['reflow'],
            'layout_fallback' => $n['fallback'],
            'layout_compact_x' => (string)$n['compactX'],
            'layout_compact_y' => (string)$n['compactY'],
            'layout_monitors' => implode(',', $n['monitors']),
            'layout_distribution' => $n['distribution'],
            'layout_size_balance' => $n['sizeBalance'],
            'layout_remember_monitors' => $n['rememberMonitors'] ? '1' : '0',
            'layout_respect_dpi' => $n['respectDpi'] ? '1' : '0',
            'layout_keep_inside' => $n['keepInside'] ? '1' : '0',
            'layout_disconnect' => $n['disconnect'],
            'layout_main_monitor' => $n['mainMonitor'],
            'layout_controlled_monitors' => implode(',', $n['controlledMonitors']),
        ];
        $out = [];
        foreach ($map as $k => $v) {
            if ($has($k)) $out[$k] = $v;
        }
        return ['ok' => true, 'errors' => [], 'normalized' => $out];
    }

    /**
     * Chuan hoa + clamp. $fromDb=true: log warning khi gia tri corrupt (khong xoa file user).
     * Tra ve ['fixed'=>bool,'preset'=>string,'width'=>int,'height'=>int,'position'=>string,
     *          'gap'=>int,'x'=>int,'y'=>int,'monitor'=>string]
     */
    public static function normalize(array $in, bool $fromDb = false): array
    {
        $d = self::defaults();
        // Chap nhan ca 2 kieu key: window_* (DB/UI) va short (snapshot tu getWindowSettings)
        $g = function (array $in, string $long, string $short, $fb) {
            if (array_key_exists($long, $in)) return $in[$long];
            if (array_key_exists($short, $in)) return $in[$short];
            return $fb;
        };
        $bad = [];
        $preset = strtolower(trim((string)$g($in, 'window_preset', 'preset', $d['window_preset'])));
        if (!array_key_exists($preset, self::PRESETS)) {
            $bad[] = 'preset';
            $preset = 'hd';
        }
        $width = self::toInt($g($in, 'window_width', 'width', 1280), 1280);
        $height = self::toInt($g($in, 'window_height', 'height', 720), 720);
        // Chon preset (khac custom) -> ep W/H theo preset, bo qua so user go
        if ($preset !== 'custom' && self::PRESETS[$preset] !== null) {
            $width = self::PRESETS[$preset][0];
            $height = self::PRESETS[$preset][1];
        }
        if ($width < 400 || $width > 7680) {
            $bad[] = 'width';
            $width = min(7680, max(400, $width ?: 1280));
            if ($width < 400 || $width > 7680) $width = 1280;
        }
        if ($height < 300 || $height > 4320) {
            $bad[] = 'height';
            $height = min(4320, max(300, $height ?: 720));
            if ($height < 300 || $height > 4320) $height = 720;
        }
        $pos = strtolower(trim((string)$g($in, 'window_position', 'position', $d['window_position'])));
        if (!in_array($pos, self::POSITIONS, true)) {
            $bad[] = 'position';
            $pos = 'auto';
        }
        $gap = self::toInt($g($in, 'window_gap', 'gap', 5), 5);
        if ($gap < 0 || $gap > 100) {
            $bad[] = 'gap';
            $gap = min(100, max(0, $gap));
        }
        $x = self::toInt($g($in, 'window_x', 'x', 0), 0);
        $y = self::toInt($g($in, 'window_y', 'y', 0), 0);
        $x = min(10000, max(-10000, $x));
        $y = min(10000, max(-10000, $y));
        $mon = strtolower(trim((string)$g($in, 'window_monitor', 'monitor', $d['window_monitor'])));
        if ($mon !== 'primary' && (!ctype_digit($mon) || (int)$mon <= 0)) {
            $bad[] = 'monitor';
            $mon = 'primary';
        }
        $rawFixed = $g($in, 'window_fixed', 'fixed', $d['window_fixed']);
        $fixed = $rawFixed === '1' || $rawFixed === 'true' || $rawFixed === true || $rawFixed === 1;
        if ($fromDb && $bad) {
            try {
                require_once __DIR__ . '/SyncLogger.php';
                SyncLogger::warn('settings', '[Settings][WARN] Invalid settings (' . implode(',', $bad) . '). Using safe defaults.');
            } catch (Throwable $e) {
            }
        }
        return [
            'fixed' => $fixed, 'preset' => $preset, 'width' => $width, 'height' => $height,
            'position' => $pos, 'gap' => $gap, 'x' => $x, 'y' => $y, 'monitor' => $mon,
        ];
    }

    /** Validate input tu UI/API truoc khi save. Tra ve [ok, errors[], normalized(9 keys string)]. */
    public static function validateForSave(array $in): array
    {
        $errors = [];
        $preset = strtolower(trim((string)($in['window_preset'] ?? 'hd')));
        if (!array_key_exists($preset, self::PRESETS)) $errors[] = 'Preset khong hop le';
        $w = self::toInt($in['window_width'] ?? null, 0);
        $h = self::toInt($in['window_height'] ?? null, 0);
        if ($preset === 'custom') {
            if ($w < 400 || $w > 7680) $errors[] = 'Width phai 400..7680';
            if ($h < 300 || $h > 4320) $errors[] = 'Height phai 300..4320';
        }
        $pos = strtolower(trim((string)($in['window_position'] ?? 'auto')));
        if (!in_array($pos, self::POSITIONS, true)) $errors[] = 'Position mode khong hop le';
        $gap = self::toInt($in['window_gap'] ?? null, -1);
        if ($gap < 0 || $gap > 100) $errors[] = 'Gap phai 0..100';
        $mon = strtolower(trim((string)($in['window_monitor'] ?? 'primary')));
        if ($mon !== 'primary' && (!ctype_digit($mon) || (int)$mon <= 0)) $errors[] = 'Monitor khong hop le';
        foreach (['window_x', 'window_y'] as $k) {
            $v = self::toInt($in[$k] ?? null, 0);
            if ($v < -10000 || $v > 10000) $errors[] = "$k phai -10000..10000";
        }
        if ($errors) return ['ok' => false, 'errors' => $errors, 'normalized' => []];
        $n = self::normalize($in);
        return ['ok' => true, 'errors' => [], 'normalized' => [
            'window_fixed' => $n['fixed'] ? '1' : '0',
            'window_preset' => $n['preset'],
            'window_width' => (string)$n['width'],
            'window_height' => (string)$n['height'],
            'window_position' => $n['position'],
            'window_gap' => (string)$n['gap'],
            'window_x' => (string)$n['x'],
            'window_y' => (string)$n['y'],
            'window_monitor' => $n['monitor'],
        ]];
    }

    /** Default Account Evaluation policy (diem noi bo, khong phai Google Trust). */
    public static function accountDefaults(): array
    {
        return [
            'acc_min_days' => '7',
            'acc_min_checks' => '10',
            'acc_ready_stability' => '80',
            'acc_ready_confidence' => '70',
            'acc_review_threshold' => '50',
            'acc_unavail_fails' => '20',
            'acc_check_interval_min' => '120',
            'acc_max_data_age_h' => '72',
            'acc_eval_on_start' => '0',
            'acc_background' => '0',
            'acc_batch' => '10',
            'acc_w_login' => '25',
            'acc_w_session' => '15',
            'acc_w_youtube' => '25',
            'acc_w_rate' => '25',
            'acc_w_consec' => '10',
        ];
    }

    /** Doc full account policy (so + bool chuan hoa). Corrupt -> default. */
    public static function getAccountPolicy(): array
    {
        $raw = [];
        foreach (self::accountDefaults() as $k => $v) {
            try {
                $raw[$k] = get_setting($k, (string)$v);
            } catch (Throwable $e) {
                $raw[$k] = (string)$v;
            }
        }
        $i = fn(string $k, int $fb, int $lo, int $hi) => min($hi, max($lo, self::toInt($raw[$k] ?? null, $fb)));
        $b = fn(string $k) => self::toBool($raw[$k] ?? '0');
        return [
            'minDays' => $i('acc_min_days', 7, 0, 365),
            'minChecks' => $i('acc_min_checks', 10, 1, 1000),
            'readyStability' => $i('acc_ready_stability', 80, 0, 100),
            'readyConfidence' => $i('acc_ready_confidence', 70, 0, 100),
            'reviewThreshold' => $i('acc_review_threshold', 50, 0, 100),
            'unavailFails' => $i('acc_unavail_fails', 20, 1, 1000),
            'checkIntervalMin' => $i('acc_check_interval_min', 120, 5, 10080),
            'maxAgeH' => $i('acc_max_data_age_h', 72, 1, 720),
            'evalOnStart' => $b('acc_eval_on_start'),
            'background' => $b('acc_background'),
            'batch' => $i('acc_batch', 10, 1, 100),
            'wLogin' => $i('acc_w_login', 25, 0, 100),
            'wSession' => $i('acc_w_session', 15, 0, 100),
            'wYoutube' => $i('acc_w_youtube', 25, 0, 100),
            'wRate' => $i('acc_w_rate', 25, 0, 100),
            'wConsec' => $i('acc_w_consec', 10, 0, 100),
        ];
    }

    /** Validate nhom account truoc khi save (partial-friendly). */
    public static function validateAccountForSave(array $in): array
    {
        $rules = [
            'acc_min_days' => [0, 365], 'acc_min_checks' => [1, 1000],
            'acc_ready_stability' => [0, 100], 'acc_ready_confidence' => [0, 100],
            'acc_review_threshold' => [0, 100], 'acc_unavail_fails' => [1, 1000],
            'acc_check_interval_min' => [5, 10080], 'acc_max_data_age_h' => [1, 720],
            'acc_batch' => [1, 100],
            'acc_w_login' => [0, 100], 'acc_w_session' => [0, 100],
            'acc_w_youtube' => [0, 100], 'acc_w_rate' => [0, 100], 'acc_w_consec' => [0, 100],
        ];
        $bools = ['acc_eval_on_start', 'acc_background'];
        $errors = [];
        foreach ($rules as $k => [$lo, $hi]) {
            if (array_key_exists($k, $in)) {
                $v = self::toInt($in[$k], -1);
                if ($v < $lo || $v > $hi) $errors[] = "$k phai $lo..$hi";
            }
        }
        if ($errors) return ['ok' => false, 'errors' => $errors, 'normalized' => []];
        $out = [];
        foreach (array_keys($rules) as $k) {
            if (array_key_exists($k, $in)) $out[$k] = (string)self::toInt($in[$k], 0);
        }
        foreach ($bools as $k) {
            if (array_key_exists($k, $in)) $out[$k] = self::toBool($in[$k]) ? '1' : '0';
        }
        return ['ok' => true, 'errors' => [], 'normalized' => $out];
    }

    private static function toInt($v, int $fb): int
    {
        if ($v === null || $v === '') return $fb;
        if (is_int($v)) return $v;
        $s = trim((string)$v);
        // Khong nhan abc, 12.5, so am lech kieu
        if (!preg_match('/^-?\d+$/', $s)) return $fb;
        return (int)$s;
    }

    private static function toBool($v): bool
    {
        return $v === '1' || $v === 'true' || $v === true || $v === 1;
    }
}
