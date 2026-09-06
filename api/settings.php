<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

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
    return [
        'chrome_path'   => CHROME_DEFAULT_PATH,
        'home_url'      => 'https://www.youtube.com',
        'proxy_timeout' => '5',
        'auto_refresh'  => '1',
    ];
}

function normalize_setting(string $key, ?string $value)
{
    if ($value === null) return $value;
    switch ($key) {
        case 'auto_refresh':
            return $value === '1' || $value === 'true';
        case 'proxy_timeout':
            return (int)$value;
        default:
            return $value;
    }
}