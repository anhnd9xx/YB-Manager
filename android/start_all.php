<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset=utf-8><title>YT Remote - Start Daemons</title></head><body style="background:#111318;color:#e8eaf0;font-family:sans-serif;padding:20px"><h3>YT Remote – Start Screencast Daemons</h3>';

foreach (db()->query('SELECT id, name, status, debug_port FROM profiles WHERE status="running" ORDER BY id') as $p) {
    $port = (int)$p['debug_port'];
    $id = (int)$p['id'];
    $key = 'ch' . $id;
    $dir = __DIR__ . '/frames/' . $key;
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $php = 'C:\xampp\php\php.exe';
    $script = __DIR__ . '\\scd.php';
    $log = $dir . '\\daemon.log';
    $cmd = "start \"\" cmd /c \"$php\" \"$script\" $port $id >> \"$log\" 2>&1";
    $alive = false;
    $pf = $dir . '/daemon.pid';
    if (is_file($pf)) {
        $pid = (int)trim((string)file_get_contents($pf));
        if ($pid > 0) {
            $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
            if (is_string($out) && strpos($out, (string)$pid) !== false) $alive = true;
        }
    }
    if ($alive) {
        echo "<div>✅ <b>{$p['name']}</b> (port $port) – already running (pid $pid)</div>";
    } else {
        pclose(popen($cmd, 'r'));
        echo "<div>⏳ <b>{$p['name']}</b> (port $port) – started, checking...</div>";
    }
}

echo '<hr><p>Đợi 5 giây rồi truy cập <a href="index.html" style="color:#7dd3fc">YT Remote</a>.</p>';
echo '<script>setTimeout(()=>window.open("index.html","_self"),5000)</script>';
echo '</body></html>';