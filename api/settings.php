<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sync/SettingsService.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            $settings = [];
            foreach (db()->query('SELECT skey, svalue FROM settings')->fetchAll() as $row) {
                $settings[$row['skey']] = normalize_setting($row['skey'], $row['svalue']);
            }
            // gop mac dinh neu thieu
            foreach (default_settings() as $k => $v) {
                if (!array_key_exists($k, $settings)) $settings[$k] = $v;
            }
            json_out(['ok' => true, 'data' => $settings]);
            break;

        case 'save':
            $b = json_body();
            // Validate nhom window truoc (tra 400 + message ro rang, khong luu nua voi)
            $winKeys = ['window_fixed', 'window_preset', 'window_width', 'window_height',
                        'window_position', 'window_gap', 'window_x', 'window_y', 'window_monitor'];
            $hasWin = false;
            foreach ($winKeys as $k) {
                if (array_key_exists($k, $b)) {
                    $hasWin = true;
                    break;
                }
            }
            if ($hasWin) {
                $v = SyncSettingsService::validateForSave($b);
                if (!$v['ok']) json_out(['ok' => false, 'message' => implode('; ', $v['errors'])], 400);
                foreach ($v['normalized'] as $k => $sv) $b[$k] = $sv;
            }
            // Validate nhom layout (rieng biet de bao loi dung nhom)
            $layoutKeys = array_keys(SyncSettingsService::layoutDefaults());
            $hasLayout = false;
            foreach ($layoutKeys as $k) {
                if (array_key_exists($k, $b)) {
                    $hasLayout = true;
                    break;
                }
            }
            if ($hasLayout) {
                $v = SyncSettingsService::validateLayoutForSave($b);
                if (!$v['ok']) json_out(['ok' => false, 'message' => implode('; ', $v['errors'])], 400);
                foreach ($v['normalized'] as $k => $sv) $b[$k] = $sv;
            }
            // Validate nhom account evaluation
            $accKeys = array_keys(SyncSettingsService::accountDefaults());
            $hasAcc = false;
            foreach ($accKeys as $k) {
                if (array_key_exists($k, $b)) {
                    $hasAcc = true;
                    break;
                }
            }
            if ($hasAcc) {
                $v = SyncSettingsService::validateAccountForSave($b);
                if (!$v['ok']) json_out(['ok' => false, 'message' => implode('; ', $v['errors'])], 400);
                foreach ($v['normalized'] as $k => $sv) $b[$k] = $sv;
            }
            // Validate nhom tab session (interval 10..3600s)
            if (array_key_exists('tab_autosave_interval', $b)) {
                $iv = (int)$b['tab_autosave_interval'];
                if ($iv < 10 || $iv > 3600) {
                    json_out(['ok' => false, 'message' => 'tab_autosave_interval phai 10..3600'], 400);
                }
                $b['tab_autosave_interval'] = (string)$iv;
            }
            $update = db()->prepare(
                'INSERT INTO settings (skey, svalue) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
            );
            $saved = 0;
            foreach (default_settings() as $k => $v) {
                if (array_key_exists($k, $b)) {
                    $update->execute([$k, (string)$b[$k]]);
                    $saved++;
                }
            }
            log_action(null, 'settings_save', "$saved mục");
            try {
                require_once __DIR__ . '/../sync/SyncLogger.php';
                SyncLogger::info('settings_saved', '[Settings] Settings saved successfully (' . $saved . ' muc)');
            } catch (Throwable $e) {
            }
            json_out(['ok' => true, 'saved' => $saved]);
            break;

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}

function default_settings(): array
{
    return array_merge([
        'chrome_path'   => CHROME_DEFAULT_PATH,
        'home_url'      => 'https://www.google.com/',
        'proxy_timeout' => '5',
        'auto_refresh'  => '1',
    ], SyncSettingsService::defaults(), SyncSettingsService::tabDefaults());
}

function normalize_setting(string $key, ?string $value)
{
    if ($value === null) return $value;
    switch ($key) {
        case 'auto_refresh':
        case 'window_fixed':
        case 'layout_multi':
        case 'layout_respect_taskbar':
        case 'layout_keep_visible':
        case 'layout_auto_launch':
        case 'layout_remember_monitors':
        case 'layout_respect_dpi':
        case 'layout_keep_inside':
        case 'acc_eval_on_start':
        case 'acc_background':
        case 'acc_auto_start':
        case 'acc_close_after':
        case 'tab_autosave':
        case 'tab_autorestore':
        case 'tab_remember_active':
            return $value === '1' || $value === 'true';
        case 'proxy_timeout':
        case 'window_width':
        case 'window_height':
        case 'window_gap':
        case 'window_x':
        case 'window_y':
        case 'layout_gap_x':
        case 'layout_gap_y':
        case 'layout_min_w':
        case 'layout_min_h':
        case 'layout_compact_x':
        case 'layout_compact_y':
        case 'acc_min_days':
        case 'acc_min_checks':
        case 'acc_ready_stability':
        case 'acc_ready_confidence':
        case 'acc_review_threshold':
        case 'acc_unavail_fails':
        case 'acc_check_interval_min':
        case 'acc_max_data_age_h':
        case 'acc_batch':
        case 'acc_concurrency':
        case 'acc_w_login':
        case 'acc_w_session':
        case 'acc_w_youtube':
        case 'acc_w_rate':
        case 'acc_w_consec':
        case 'tab_autosave_interval':
            return (int)$value;
        default:
            return $value;
    }
}