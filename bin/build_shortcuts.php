<?php
declare(strict_types=1);
// Tao shortcut taskbar cho tung kenh, gan AppUserModelID trung vai_khi mo bang app.
// Buoc 1: tao .lnk (WScript.Shell). Buoc 2 (tien trinh khac): ghi AUMI + xac minh.
require_once __DIR__ . '/../config.php';

$rows = db()->query(
    'SELECT id, name, user_data_dir FROM profiles ORDER BY id'
)->fetchAll();

$list = [];
foreach ($rows as $p) {
    $base = basename(rtrim((string)$p['user_data_dir'], '/\\'));
    // Quy tac quan sat tu Chrome (dir "K__nh_5" -> AUMI "...Knh5"): bo dau "_"
    $mid = str_replace('_', '', $base);
    $list[] = [
        'id'   => (int)$p['id'],
        'name' => $p['name'],
        'aumi' => 'Chrome.' . $mid . '.Default',
    ];
}

$outDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'shortcuts');
if ($outDir === false) {
    $outDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'shortcuts';
    @mkdir($outDir, 0777, true);
    $outDir = realpath($outDir) ?: $outDir;
}
$tmp    = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_shortcuts.json';
file_put_contents($tmp, json_encode($list, JSON_UNESCAPED_UNICODE));

function pshell(string $file, string $json, string $outDir): array
{
    $ps = 'powershell -NoProfile -Sta -ExecutionPolicy Bypass -File '
        . '"' . str_replace('"', '""', $file) . '" -Json "' . str_replace('"', '""', $json)
        . '" -OutDir "' . str_replace('"', '""', $outDir) . '"';
    $out = [];
    exec($ps, $out);
    return $out;
}

$out = pshell(__DIR__ . DIRECTORY_SEPARATOR . 'create_shortcuts.ps1', $tmp, $outDir);
usleep(500000);
$out = pshell(__DIR__ . DIRECTORY_SEPARATOR . 'pin_shortcuts.ps1', $tmp, $outDir);
foreach ($out as $line) {
    if ($line !== '') echo $line . "\n";
}