<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$id = (int)($_GET['id'] ?? 0);
$key = 'ch' . $id;
$m = ['w' => 0, 'h' => 0, 'tab' => null, 'tabTitle' => '', 'ts' => 0];
$f = __DIR__ . '/frames/' . $key . '/meta.json';
if (is_file($f)) {
    $mm = json_decode((string)file_get_contents($f), true);
    if (is_array($mm)) $m = array_merge($m, $mm);
}
if ($m['tab']) {
    $st = db()->prepare('SELECT debug_port FROM profiles WHERE id=?');
    $st->execute([$id]);
    $port = (int)$st->fetchColumn();
    foreach (cdp_page_targets($port) as $t) {
        if ($t['id'] === $m['tab']) { $m['tabTitle'] = $t['title']; break; }
    }
}
echo json_encode($m, JSON_UNESCAPED_UNICODE);