<?php
declare(strict_types=1);
// Launcher cho shortcut taskbar: mo kenh theo dung quy trinh app (relay + keeper + Chrome).
// Reuse API browser.php (action=open) chi voi params gan truc tiep vi chay CLI.
$id = (int)($argv[1] ?? 0);
if ($id <= 0) exit(1);
$_GET['action'] = 'open';
$_GET['id'] = (string)$id;
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/../api/browser.php';