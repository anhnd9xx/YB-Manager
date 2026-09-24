<?php
declare(strict_types=1);

// Server canonical timezone: Asia/Ho_Chi_Minh (UTC+7), KHOP voi MySQL SYSTEM
// (truoc day PHP mac dinh Europe/Berlin -> date() lech 5h so voi NOW(), card
// hien "5 gio truoc" ngay sau khi danh gia xong). API tra ISO8601 kem offset
// (+07:00) de browser parse khong mo ho; DB giu wall-time naive nhu cu.
date_default_timezone_set('Asia/Ho_Chi_Minh');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'yt_manager');
define('DB_USER', 'root');
define('DB_PASS', '');

define('BASE_DIR', __DIR__);
define('PROFILES_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'profiles');
define('CHROME_DEFAULT_PATH', 'C:\Program Files\Google\Chrome\Application\chrome.exe');

if (!is_dir(PROFILES_DIR)) {
    mkdir(PROFILES_DIR, 0777, true);
}

function db(): PDO
{
    // Giu trong $GLOBALS (thay vi static) de db_reconnect() reset duoc —
    // worker chay nhieu gio/sleep-wake phai tu phuc hoi "MySQL gone away".
    if (!isset($GLOBALS['__pdo']) || !($GLOBALS['__pdo'] instanceof PDO)) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $GLOBALS['__pdo'] = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $GLOBALS['__pdo'];
}

/** Xoa handle PDO hien tai de db() tao lai o lan goi sau. */
function db_reconnect(): void
{
    unset($GLOBALS['__pdo']);
}

/**
 * Ping DB; stale handle (sleep/wake, wait_timeout) -> reconnect 1 lan.
 * Worker goi moi vong poll (re ~1 query/25s, khong dang ke).
 */
function db_ping(): bool
{
    try {
        db()->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        try {
            db_reconnect();
            db()->query('SELECT 1');
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function get_setting(string $key, string $default = ''): string
{
    // Override trong-process (set_setting vua ghi) uu tien truoc cache/DB
    if (isset($GLOBALS['__setting_override'][$key])) {
        $v = (string)$GLOBALS['__setting_override'][$key];
        return $v !== '' ? $v : $default;
    }
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings')->fetchAll() as $row) {
                $cache[$row['skey']] = $row['svalue'];
            }
        } catch (Throwable $e) {
            // co the bang settings chua ton tai
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

/** Ghi setting DB + override trong-process (tranh stale cache sau save). */
function set_setting(string $key, string $value): void
{
    try {
        db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute([$key, $value]);
    } catch (Throwable $e) {
    }
    if (!isset($GLOBALS['__setting_override']) || !is_array($GLOBALS['__setting_override'])) {
        $GLOBALS['__setting_override'] = [];
    }
    $GLOBALS['__setting_override'][$key] = $value;
}

function chrome_path(): string
{
    return get_setting('chrome_path', CHROME_DEFAULT_PATH);
}

function proxy_timeout(): int
{
    return max(2, (int)get_setting('proxy_timeout', '5'));
}

/**
 * Chuyen wall-time DB ('Y-m-d H:i:s', Asia/Ho_Chi_Minh) sang ISO8601 kem offset.
 * Frontend parse chinh xac moi timezone, khong doan mo.
 */
function iso_ts(?string $naive): ?string
{
    if ($naive === null || trim($naive) === '') return null;
    $s = trim($naive);
    if (strpos($s, 'T') !== false) return $s; // da ISO
    // Wall-time DB cung mui gio server (date_default_timezone_set) -> gan offset that
    return str_replace(' ', 'T', $s) . date('P', strtotime($s) ?: time());
}

/** Gio hien tai dang ISO8601 kem offset (cho API result). */
function now_iso(): string
{
    return date('Y-m-d\TH:i:sP');
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    // BOM UTF-8 tu client Windows (PowerShell/curl) lam hong json_decode
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // Mot so client gui body kieu form-urlencoded fallback
        parse_str($raw, $data);
    }
    return is_array($data) ? $data : [];
}

function safe_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    return $name === '' ? 'profile' : $name;
}

/**
 * Tra ve duong dan user_data_dir DUY NHAT cho 1 kenh moi.
 * Vi safe_name co the an truong 2 ten khac nhau ve cung 1 thu muc
 * (vd "Kênh A" va "Kenh_A") - neu da co kenh dung thu muc do thi them suffix,
 * => moi kenh luon co Chrome profile rieng, khong bao gio dung chung data.
 */
function unique_user_data_dir(string $name): string
{
    $stmt = db()->prepare('SELECT id FROM profiles WHERE user_data_dir = ? LIMIT 1');
    $base = PROFILES_DIR . DIRECTORY_SEPARATOR . safe_name($name);
    $dir = $base;
    $i = 1;
    $stmt->execute([$dir]);
    while ($stmt->fetch()) {
        $dir = $base . '_' . (++$i);
        $stmt->execute([$dir]);
    }
    return $dir;
}

function log_action(?int $profileId, string $action, ?string $detail = null): void
{
    try {
        $stmt = db()->prepare('INSERT INTO activity_logs (profile_id, action, detail) VALUES (?, ?, ?)');
        $stmt->execute([$profileId, $action, $detail]);
    } catch (Throwable $e) {
        // log khong duoc phep lam hong luong chinh
    }
}

// =================== Health check chung (dung cho browser.php, profiles.php) ===================

/**
 * Scan MOT LAN toan bo process chrome.exe dang chay (1 luot PowerShell duy nhat),
 * tra ve map user_data_dir (lowercase) => false|true|cmdline:
 *   - neu co process chinh (khong co --type=): cmdline cua process do
 *   - nguoc lai: true (dang chay, chua thay process chinh)
 * Cache theo request. Gom ca is_chrome_running lan chrome_main_cmdline de chi 1 luot PS.
 */
function chrome_running_info(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $ps = 'powershell -NoProfile -Command '
        . '"Get-CimInstance Win32_Process -Filter \"Name=' . "'" . 'chrome.exe' . "'" . '\" '
        . '| Select-Object -ExpandProperty CommandLine"';
    $out = [];
    exec($ps, $out);
    foreach ($out as $line) {
        if (stripos($line, '--user-data-dir=') === false) continue;
        if (preg_match('/--user-data-dir=([^\s"]+)/', $line, $m)) {
            $dir = strtolower(rtrim($m[1], '"'));
            $isMain = stripos($line, '--type=') === false;
            if (!isset($cache[$dir])) {
                $cache[$dir] = $isMain ? $line : true;
            } elseif (!is_string($cache[$dir]) && $isMain) {
                $cache[$dir] = $line;
            }
        }
    }
    return $cache;
}

function running_chrome_dirs(): array
{
    $out = [];
    foreach (chrome_running_info() as $dir => $_v) $out[$dir] = true;
    return $out;
}

/** Lay command line cua process Chrome CHINH (khong phai renderer/gpu...) cua 1 user_data_dir. null neu khong chay. */
function chrome_main_cmdline(string $dir): ?string
{
    $v = chrome_running_info()[strtolower(trim($dir))] ?? null;
    return is_string($v) ? $v : null;
}

function is_chrome_running(array $p): bool
{
    $dir = trim((string)($p['user_data_dir'] ?? ''));
    if ($dir === '') return false;
    $map = running_chrome_dirs();
    return isset($map[strtolower($dir)]);
}

/** Trang thai thuc te cua profile: scan process 1 lan, khong goi CDP neu khong chay */
function profile_live(array $p): bool
{
    $dir = trim((string)($p['user_data_dir'] ?? ''));
    if ($dir === '') return false;
    return isset(running_chrome_dirs()[strtolower($dir)]);
}

/** Chrome dang chay co dung config proxy/ua/fingerprint nhu trong DB khong? (dung de biet co can restart khi config doi) */
function chrome_cmdline_matches_config(array $p): bool
{
    $cmd = chrome_main_cmdline($p['user_data_dir']);
    if ($cmd === null) return false; // khong chay
    $wantProxy = !empty($p['proxy_host']);
    $hasProxy = strpos($cmd, '--proxy-server') !== false;
    if ($wantProxy !== $hasProxy) return false;
    if ($wantProxy) {
        $expected = proxy_server_arg($p, expected_relay_port($p));
        if (strpos($cmd, '--proxy-server=' . $expected) === false) return false;
    }
    $wantUa = !empty($p['user_agent']);
    $hasUa = strpos($cmd, '--user-agent=') !== false;
    if ($wantUa !== $hasUa) return false;
    // Thay doi webrtc_protection / accept-lang -> restart de ap dung fingerprint moi
    $wantWebrtc = (($p['webrtc_protection'] ?? 'default') === 'disable_nonproxied_udp');
    $hasWebrtc = strpos($cmd, '--webrtc-ip-handling-policy') !== false;
    if ($wantWebrtc !== $hasWebrtc) return false;
    $wantLang = channel_fingerprint((int)($p['id'] ?? 0))['lang'];
    $hasLang = strpos($cmd, '--accept-lang=' . $wantLang) !== false;
    if (!$hasLang) return false;
    return true;
}

/** Port relay local cho proxy co credential (9400 + proxy_id, MOI proxy 1 port RIENG),
 *  tra ve null neu proxy khong can relay.
 *  LƯU Ý: khong dung %100 nua (proxy 14 va 114 truoc day dung chung port -> kill nham relay nhau). */
function expected_relay_port(array $p): ?int
{
    if (empty($p['proxy_host']) || empty($p['proxy_port'])) return null;
    if (empty($p['proxy_user']) && empty($p['proxy_pass'])) return null;
    return proxy_relay_port((int)($p['proxy_id'] ?? 0));
}

/** Tao chuoi --proxy-server tu config; neu co credential va relayPort thi dung relay local (fix loi Chrome bo auth cho subresource) */
function proxy_server_arg(array $p, ?int $relayPort = null): string
{
    if (empty($p['proxy_host'])) return '';
    $protocol = ($p['proxy_protocol'] === 'socks5') ? 'socks5' : 'http';
    $addr = $p['proxy_host'] . ':' . $p['proxy_port'];
    if (!empty($p['proxy_user']) && !empty($p['proxy_pass'])) {
        if ($relayPort !== null) return 'http://127.0.0.1:' . $relayPort;
        $addr = $protocol . '://' . rawurlencode($p['proxy_user']) . ':' . rawurlencode($p['proxy_pass']) . '@' . $addr;
    } else {
        $addr = $protocol . '://' . $addr;
    }
    return $addr;
}

/** Port relay = 9400 + proxy_id (duy nhat moi proxy; proxy_id toi da ~56000 trong range port).
 *  Range debug port (9200-9399) tach biet de khong bao gio dung do. */
function proxy_relay_port(int $proxyId): int
{
    $port = 9400 + (int)$proxyId;
    return max(9401, min(65535, $port));
}

/** Kiem tra relay co lang nghe tren port khong */
function relay_listening(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($fp) { fclose($fp); return true; }
    return false;
}

/** Kiem tra relay THUC SU phuc vu duoc (CONNECT + upstream): tranh reuse relay chet/hong.
 *  Timeout LONG (12s) vi proxy xa/thap (VD proxy Mỹ RTT 1-4s) can thoi gian CONNECT roi
 *  moi nhan 200; timeout 6s truoc day lam relay SONG-nhung-cham bi bao "chet" (409/restart). */
function relay_healthy(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if (!$fp) return false;
    fwrite($fp, "CONNECT www.gstatic.com:443 HTTP/1.1\r\nHost: www.gstatic.com:443\r\n\r\n");
    stream_set_timeout($fp, 12);
    $buf = '';
    $deadline = microtime(true) + 12;
    while (strpos($buf, "\r\n") === false && microtime(true) < $deadline) {
        $c = fread($fp, 4096);
        if ($c === '' || $c === false) break;
        $buf .= $c;
    }
    fclose($fp);
    return stripos($buf, ' 200') !== false;
}

/** Tim php CLI (duoi Apache PHP_BINARY co the la httpd.exe - can fallback ve php.exe) */
function php_cli_binary(): string
{
    if (stripos(basename((string)PHP_BINARY), 'php') === 0 && is_file((string)PHP_BINARY)) {
        return (string)PHP_BINARY;
    }
    $cand = 'C:/xampp/php/php.exe';
    return is_file($cand) ? $cand : '';
}

/** Khoi dong relay local (proxy_relay.php) de Chrome goi khong credential; relay them Authorization ra proxy that. Tra ve port hoac null.
 *  $quick=true (batch open): tin relay_listening, KHONG cho relay_healthy 12s (mo hang loat muot). */
function start_proxy_relay(array $p, bool $quick = false): ?int
{
    $port = expected_relay_port($p);
    if ($port === null) return null;
    // Relay dang len PORT va THUC SU phuc vu duoc -> dung ngay (khong khoi dong lai)
    if (relay_listening($port)) {
        if ($quick) return $port;
        if (relay_healthy($port)) return $port;
        // Relay song port nhung chua/phuc-vu cham -> KHONG giet (tranh mat ket noi kenh dang chay);
        // qui ve kiem tra lai o vong sau. Tra ve port de khoi treo open kenh.
        return $port;
    }
    // relay cu con giu port (socket treo) -> don truoc khi spawn lai
    stop_proxy_relay($port);
    $php = php_cli_binary();
    if ($php === '') return null;
    $relay = __DIR__ . '/proxy_relay.php';
    // Spawn bang PowerShell Start-Process (dam bao relay doc lap voi process cha - proc_open duoi Apache khong phuc vu duoc socket)
    $args = "'-f','" . str_replace("'", "''", $relay) . "','$port','" . str_replace("'", "''", (string)$p['proxy_host']) . "'," . (int)$p['proxy_port'] . ",'" . str_replace("'", "''", (string)($p['proxy_user'] ?? '')) . "','" . str_replace("'", "''", (string)($p['proxy_pass'] ?? '')) . "'";
    $ps = "Start-Process -FilePath '" . str_replace("'", "''", $php) . "' -ArgumentList @($args) -WindowStyle Hidden";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $ps) . '"';
    @shell_exec($cmd);
    // Cho relay len PORT truoc (nhanh, 0.5s/check); sau do xac nhan e2e 1 lan
    // (proxy Mỹ RTT cao -> can nhieu thoi gian hon 4.5s cua loop cu).
    for ($i = 0; $i < 20; $i++) {
        usleep(300000);
        if (relay_listening($port)) {
            if (relay_healthy($port)) return $port;
            return $port; // song port + da qua async, cho du chan -> tra ve luon
        }
    }
    return null;
}

/** Khoi dong tabtitle_keeper.php de tab moi cung gan duoc ten kenh (CDP watcher); keeper tu doc ten tu DB theo profile id */
function start_tab_title_keeper(int $port, int $profileId): void
{
    if ($port < 1 || $profileId < 1) return;
    $php = php_cli_binary();
    if ($php === '') return;
    $keeper = __DIR__ . '/tabtitle_keeper.php';
    $args = "'-f','" . str_replace("'", "''", $keeper) . "','$port','$profileId'";
    $ps = "Start-Process -FilePath '" . str_replace("'", "''", $php) . "' -ArgumentList @($args) -WindowStyle Hidden";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $ps) . '"';
    @shell_exec($cmd);
    // badge ten kenh tren taskbar cho tat ca kenh dang mo (tien trinh overlay_keeper danh rieng)
    start_overlay_keeper();
}

/** Dong tabtitle_keeper cua 1 CDP port (goi khi dong Chrome kenh do) */
function kill_tab_title_keeper(int $port): void
{
    if ($port < 1) return;
    $ps = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process '
        . '| Where-Object { $_.CommandLine -like ' . "'" . '*tabtitle_keeper.php ' . (int)$port . '*' . "'" . ' } '
        . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"';
    @shell_exec($ps);
}

/** Khoi dong overlay_keeper.ps1 (badge ten kenh goc duoi nút taskbar cho MOI cua so kenh).
 *  Trung lap duoc chinh overlay_keeper.ps1 tu loai tru bang file-lock (FileShare.None giu toi khi thoat). */
function start_overlay_keeper(): void
{
    $keeper = __DIR__ . '/bin/overlay_keeper.ps1';
    $ps = "Start-Process -FilePath 'powershell' -ArgumentList @('-NoProfile','-Sta','-ExecutionPolicy','Bypass','-File','"
        . str_replace("'", "''", $keeper) . "') -WindowStyle Hidden";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $ps) . '"';
    @shell_exec($cmd);
}

/** Khoi dong relay watchdog (auto-heal relay chet cho kenh dang chay). Lock file trong script loai trung lap.
 *  Test lock truoc khi spawn: dang chay thi skip (mo hang loat khong spawn thua ps moi launch). */
function start_relay_watchdog(): void
{
    $wd = __DIR__ . '/bin/relay_watchdog.php';
    $php = php_cli_binary();
    if ($php === '' || !is_file($wd)) return;
    try {
        $lockFile = __DIR__ . '/bin/.relay_watchdog.lock';
        $fh = @fopen($lockFile, 'c');
        if ($fh) {
            if (!@flock($fh, LOCK_EX | LOCK_NB)) {
                @fclose($fh);
                return; // dang chay -> skip spawn
            }
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    } catch (Throwable $e) {
    }
    // Launch detached: second instance tu-exit via lock file
    $args = "'-f','" . str_replace("'", "''", $wd) . "'";
    $ps = "Start-Process -FilePath '" . str_replace("'", "''", $php) . "' -ArgumentList @($args) -WindowStyle Hidden";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $ps) . '"';
    @shell_exec($cmd);
}

/** Dong relay tren port (chi kill process dang LISTENING tren port do) */
function stop_proxy_relay(int $port): void
{
    $ps = 'powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort ' . (int)$port
        . ' -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }"';
    @shell_exec($ps);
}

/** Dong relay cua proxy khi khong con profile nao dang chay dung no */
function stop_proxy_relay_if_unused(array $p): void
{
    if (empty($p['proxy_id'])) return;
    $port = proxy_relay_port((int)$p['proxy_id']);
    $st = db()->prepare("SELECT COUNT(*) FROM profiles WHERE proxy_id=? AND status='running' AND id<>?");
    $st->execute([(int)$p['proxy_id'], (int)($p['id'] ?? 0)]);
    if ((int)$st->fetchColumn() > 0) return;
    stop_proxy_relay($port);
}

/** Port debug (CDP) co dang bi profile khac giu trong DB khong? */
function debug_port_taken_by_other(int $port, int $selfId): bool
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM profiles WHERE debug_port=? AND id<>?');
        $st->execute([$port, $selfId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Cấp debug port (CDP) cho 1 profile — dùng cho browser.php.
 * Range chính 9200-9399 (tách biệt relay 9400+ nên không bao giờ đụng relay).
 * Bỏ qua: port đã cấp trong request này, port đang LISTEN (bất kỳ process nào),
 * port profile khác đang giữ trong DB. Persist + log assign_port.
 */
function allocate_debug_port(array $p): ?int
{
    static $used = [];
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0) return null;
    $claim = function (int $port) use (&$used, $id): bool {
        if (isset($used[$port])) return false;
        if (relay_listening($port)) return false; // dang LISTEN = đã có chủ (CDP/relay/app khác)
        if (debug_port_taken_by_other($port, $id)) return false;
        $used[$port] = true;
        db()->prepare('UPDATE profiles SET debug_port=? WHERE id=?')->execute([$port, $id]);
        log_action($id, 'assign_port', 'Debug port ' . $port);
        return true;
    };
    // Tái dùng port đã lưu nếu vẫn sạch
    if (!empty($p['debug_port'])) {
        $port = (int)$p['debug_port'];
        if (!isset($used[$port]) && !relay_listening($port) && !debug_port_taken_by_other($port, $id)) {
            $used[$port] = true;
            return $port;
        }
    }
    // Quét range chính, ưu tiên 9200+id (quy ước cũ)
    for ($off = 0; $off < 200; $off++) {
        $port = 9200 + (($id + $off) % 200);
        if ($claim($port)) return $port;
    }
    // Tràn range: quét tiếp từ 9500 trở lên
    for ($port = 9500; $port <= 65535; $port++) {
        if ($claim($port)) return $port;
    }
    return null;
}

/** Kiem tra CDP endpoint cua 1 debug port co lang nghe khong (TCP connect 0.5s, khong lay du lieu) */
function cdp_reachable(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($fp) { fclose($fp); return true; }
    return false;
}

/**
 * DevTools HTTP qua raw socket (thay file_get_contents).
 * LY DO: PHP http wrapper ton dung 2x`timeout` moi call DevTools (server giu
 * connection, wrapper doi EOF roi request lai) - do thuc te: /json/list 2s,
 * /json/new 12s. Raw socket + doc dung Content-Length: ~1-5ms.
 * Ket noi truc tiep 127.0.0.1, KHONG qua proxy (loopback bypass).
 * @return array{code:int, body:string}|null (null = loi transport/timeout)
 */
function cdp_http(int $port, string $method, string $path, int $timeoutMs = 1500): ?array
{
    if ($port <= 0) return null;
    $method = strtoupper($method) === 'PUT' ? 'PUT' : 'GET';
    $deadline = microtime(true) + max(200, $timeoutMs) / 1000;
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.5);
    if (!$fp) return null;
    stream_set_blocking($fp, false);
    $req = "$method $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n";
    if (@fwrite($fp, $req) === false) {
        fclose($fp);
        return null;
    }
    $buf = '';
    $hdrEnd = false;
    $code = 0;
    $clen = null;
    while (microtime(true) < $deadline) {
        $r = [$fp];
        $w = null;
        $e = null;
        $msLeft = (int)max(0, ($deadline - microtime(true)) * 1000000);
        if (@stream_select($r, $w, $e, 0, min($msLeft, 200000)) !== 1) continue;
        $c = @fread($fp, 65536);
        if ($c === false || $c === '') break;
        $buf .= $c;
        if (!$hdrEnd) {
            $p = strpos($buf, "\r\n\r\n");
            if ($p === false) {
                if (strlen($buf) > 16384) break;
                continue;
            }
            $hdrEnd = true;
            $hdr = substr($buf, 0, $p);
            $buf = substr($buf, $p + 4);
            if (preg_match('#^HTTP/\S+\s+(\d+)#m', $hdr, $m)) $code = (int)$m[1];
            if (preg_match('#Content-Length:\s*(\d+)#i', $hdr, $m)) $clen = (int)$m[1];
            if ($clen === 0) break;
        }
        if ($clen !== null && strlen($buf) >= $clen) {
            $buf = substr($buf, 0, $clen);
            break;
        }
    }
    fclose($fp);
    if (!$hdrEnd) return null;
    return ['code' => $code, 'body' => $clen !== null ? substr($buf, 0, $clen) : $buf];
}

/** CDP: lay danh sach page targets cua debug port (/json/list) */
function cdp_page_targets(int $port): array
{
    $r = cdp_http($port, 'GET', '/json/list', 1500);
    if ($r === null) return [];
    $arr = json_decode($r['body'], true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        if (($t['type'] ?? '') === 'page' && !empty($t['id']) && !empty($t['webSocketDebuggerUrl'])) {
            $out[] = [
                'id'    => $t['id'],
                'url'   => $t['url'] ?? '',
                'title' => $t['title'] ?? '',
                'webSocketDebuggerUrl' => $t['webSocketDebuggerUrl'],
            ];
        }
    }
    return $out;
}

/** Gui 1 lenh CDP qua WebSocket raw (RFC 6455, khong can thu vien). Tra ve true neu ket noi OK. */
function cdp_ws_send(int $port, string $wsUrl, string $payload): bool
{
    $u = parse_url($wsUrl);
    if (!$u || ($u['scheme'] ?? '') !== 'ws' || empty($u['path'])) return false;
    $port = (int)($u['port'] ?? $port);
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
    if (!$fp) return false;
    $key = base64_encode(random_bytes(16));
    $path = $u['path'];
    if (!empty($u['query'])) $path .= '?' . $u['query'];
    fwrite($fp, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    stream_set_timeout($fp, 2);
    $hdr = '';
    while (strpos($hdr, "\r\n\r\n") === false) {
        $c = fread($fp, 4096);
        if ($c === false || $c === '') break;
        $hdr .= $c;
    }
    if (strpos($hdr, ' 101 ') === false) { fclose($fp); return false; }
    $len = strlen($payload);
    if ($len < 126) {
        $h = chr(0x81) . chr(0x80 | $len);
    } elseif ($len < 65536) {
        $h = chr(0x81) . chr(0x80 | 126) . pack('n', $len);
    } else {
        $h = chr(0x81) . chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
    fwrite($fp, $h . $mask . $masked);
    // doc phan hoi best-effort (gioi han 2s), co the bo qua
    $dl = microtime(true) + 2;
    $buf = '';
    while (microtime(true) < $dl) {
        $r = [$fp];
        $w = null;
        $e = null;
        if (@stream_select($r, $w, $e, 0, 300000) === 1) {
            $d = fread($fp, 65536);
            if ($d === false || $d === '') break;
            $buf .= $d;
            if (strpos($buf, '"error"') !== false) break;
        }
    }
    fclose($fp);
    return true;
}

/**
 * Gui NHIEU lenh CDP qua CUNG 1 ket noi WebSocket-roi-doc (giu session page song để
 * Emulation.set*override không bị detach reset), roi doc ket qua cua 1 lenh cuoi cung.
 * Vi CDP reset Emulation override khi session WS dong, nen phai gui override + evaluate
 * tren cung mot socket; neu chi cdp_ws_send roi dong ngay - Emulation bi mat (khong ap).
 * Tra ve value cua lenh co id=$readId (neu co), nguoc lai null.
 */
function cdp_ws_batch(int $port, string $wsUrl, array $cmds, int $readId = 0): ?string
{
    $u = parse_url($wsUrl);
    $port = (int)($u['port'] ?? $port);
    $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
    if (!$fp) return null;
    $path = $u['path'] ?? '';
    if (!empty($u['query'])) $path .= '?' . $u['query'];
    $key = base64_encode(random_bytes(16));
    fwrite($fp, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $hdr = '';
    while (strpos($hdr, "\r\n\r\n") === false) { $c = fread($fp, 8192); if ($c === false || $c === '') { fclose($fp); return null; } $hdr .= $c; }
    if (strpos($hdr, ' 101 ') === false) { fclose($fp); return null; }
    foreach ($cmds as $payload) {
        $len = strlen($payload);
        if ($len < 126) { $h = chr(0x81) . chr(0x80 | $len); }
        elseif ($len < 65536) { $h = chr(0x81) . chr(0x80 | 126) . pack('n', $len); }
        else { $h = chr(0x81) . chr(0x80 | 127) . pack('J', $len); }
        $mask = random_bytes(4); $msg = '';
        for ($i = 0; $i < $len; $i++) $msg .= $payload[$i] ^ $mask[$i % 4];
        fwrite($fp, $h . $mask . $msg);
        if ($readId > 0) usleep(150000);
    }
    if ($readId === 0) { fclose($fp); return null; }
    $buf = ''; $dl = microtime(true) + 3;
    while (microtime(true) < $dl) {
        $r = [$fp]; $w = null; $e = null;
        if (@stream_select($r, $w, $e, 0, 500000) === 1) {
            $d = fread($fp, 65536);
            if ($d === false || $d === '') break;
            $buf .= $d;
            if (strpos($buf, '"id":' . $readId) !== false && strpos(substr($buf, -4096), '}') !== false) break;
        }
    }
    fclose($fp);
    $idx = strrpos($buf, '"id":' . $readId);
    if ($idx === false) return null;
    $seg = substr($buf, $idx);
    // $seg bat dau bang "id":99,... -> boc vao object de json_decode hop le
    $res = json_decode('{' . $seg, true);
    if (isset($res['result']['result']['value'])) {
        $v = $res['result']['result']['value'];
        return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    if (isset($res['error'])) return 'ERR:' . json_encode($res['error']);
    return null;
}

/** Gan/bam ten kenh len tieu de tab: "Ten kenh | <tieu de trang>", dung cho moi lan tai trang sau (CDP). */
function chrome_tab_title_prefix(int $port, string $name): void
{
    $name = trim($name);
    if ($name === '' || !$port) return;
    $nJs = json_encode($name, JSON_UNESCAPED_UNICODE);
    $src = "(()=>{const N=$nJs;const P=N+' | ';const A=()=>{const t=document.title;const n=P+t.split(P).join('').trim();if(t!==n)document.title=n;};try{A();new MutationObserver(A).observe(document.documentElement,{subtree:true,childList:true,characterData:true});}catch(e){}setInterval(A,300);})();";
    $expr = "(()=>{const N=$nJs;const P=N+' | ';const t=document.title;const n=P+t.split(P).join('').trim();if(t!==n)document.title=n;})()";
    $deadline = microtime(true) + 1.5;
    while (microtime(true) < $deadline) {
        $targets = cdp_page_targets($port);
        foreach ($targets as $t) {
            cdp_ws_send($port, $t['webSocketDebuggerUrl'], json_encode(['id' => 1, 'method' => 'Page.addScriptToEvaluateOnNewDocument', 'params' => ['source' => $src]]));
            cdp_ws_send($port, $t['webSocketDebuggerUrl'], json_encode(['id' => 2, 'method' => 'Runtime.evaluate', 'params' => ['expression' => $expr]]));
        }
        if ($targets) return;
        usleep(250000);
    }
}

/**
 * User-Agent thật của Chrome đang dùng (đọc file version của chrome.exe, cache).
 * Giữ UA giống hệt nhau ở MỌI kênh vì UA Chrome chính hãng vốn đồng nhất cho cả hàng tỷ
 * máy -> đây chính là "lớp nặc danh" tự nhiên; fake UA khác nhau dễ bị Google soi kiểu
 * "UA không khớp fingerprint" hơn là giúp ích cho việc tách account.
 */
function chrome_user_agent(): string
{
    static $ua = null;
    if ($ua !== null) return $ua;
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.7977.77 Safari/537.36';
    $ps = @shell_exec('powershell -NoProfile -Command "(Get-Item \"' . chrome_path() . '\").VersionInfo.ProductVersion"');
    $ps = is_string($ps) ? trim((string)preg_replace('/[^0-9.]/', '', $ps)) : '';
    if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ps)) {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . $ps . ' Safari/537.36';
    }
    return $ua;
}

/**
 * Fingerprint ẩn riêng theo kênh để giảm khả năng Google nối 2 account trên 2 kênh:
 * hardwareConcurrency + deviceMemory + timezone + accept-language đều khác nhau từng kênh,
 * sinh ổn định từ profileId (không đổi mỗi lần mở). Các giá trị cùng "nhóm" (mem/CPU khớp nhau).
 *
 * LƯU Ý: id 47..51 giữ NGUYÊN 5 combo cũ (tương thích kênh đã warm trước đây);
 * id khác sinh xác định (deterministic) từ hash id -> mỗi kênh 1 fingerprint riêng,
 * không còn giới hạn 5 combo chung cho cả farm.
 */
function channel_fingerprint(int $profileId): array
{
    // 5 combo legacy (giữ nguyên cho kênh đã warm: 47->1 ... 51->5)
    static $legacy = [
        ['hardwareConcurrency' => 4, 'deviceMemory' => 8, 'timezoneId' => 'Asia/Ho_Chi_Minh', 'tzOffset' => -420, 'lang' => 'vi-VN,vi;q=0.9,en;q=0.8'],
        ['hardwareConcurrency' => 8, 'deviceMemory' => 4, 'timezoneId' => 'Asia/Bangkok',     'tzOffset' => -420, 'lang' => 'en-US,en;q=0.9,vi;q=0.8'],
        ['hardwareConcurrency' => 4, 'deviceMemory' => 4, 'timezoneId' => 'Asia/Singapore',   'tzOffset' => -480, 'lang' => 'vi-VN,vi;q=0.9,en;q=0.8'],
        ['hardwareConcurrency' => 6, 'deviceMemory' => 6, 'timezoneId' => 'Asia/Manila',      'tzOffset' => -480, 'lang' => 'en-GB,en;q=0.9,vi;q=0.8'],
        ['hardwareConcurrency' => 8, 'deviceMemory' => 8, 'timezoneId' => 'Asia/Jakarta',     'tzOffset' => -420, 'lang' => 'vi-VN,vi;q=0.9,en;q=0.8'],
    ];
    if ($profileId >= 47 && $profileId <= 51) {
        $fp = $legacy[$profileId - 47];
        $fp['index'] = $profileId - 46;
        return $fp;
    }
    // Kênh mới: hash id -> chọn từ pool (ổn định, không đổi giữa các lần mở)
    $h = md5('ytm-fp-v1:' . $profileId, true);
    $b = array_values(unpack('C*', $h));
    $cpus = [2, 4, 6, 8, 12, 16];
    $mems = [2, 4, 6, 8];
    // timezone + offset GMT tương ứng (tránh múi giờ DST để offset ổn định)
    $tzs = [
        ['Asia/Ho_Chi_Minh', -420], ['Asia/Bangkok', -420], ['Asia/Jakarta', -420],
        ['Asia/Singapore', -480], ['Asia/Manila', -480], ['Asia/Kuala_Lumpur', -480],
        ['Asia/Hong_Kong', -480], ['Asia/Shanghai', -480], ['Asia/Taipei', -480],
        ['Asia/Tokyo', -540], ['Asia/Seoul', -540],
    ];
    $langs = [
        'vi-VN,vi;q=0.9,en;q=0.8', 'en-US,en;q=0.9,vi;q=0.8', 'en-GB,en;q=0.9,vi;q=0.8',
        'vi-VN,vi;q=0.9', 'en-US,en;q=0.9', 'id-ID,id;q=0.9,en;q=0.8',
    ];
    $cpu = $cpus[$b[0] % count($cpus)];
    // RAM theo nhóm hợp lý với CPU (tránh combo phi thực tế như 16 CPU + 2GB)
    $memPool = $cpu >= 12 ? [8, 16] : ($cpu >= 6 ? [4, 6, 8] : [2, 4, 6, 8]);
    // deviceMemory của Chrome chỉ nhận 0.25/0.5/1/2/4/8 -> clamp về 8 max
    $mem = min(8, $memPool[$b[1] % count($memPool)]);
    $tz = $tzs[$b[2] % count($tzs)];
    return [
        'index'               => 100 + ($b[3] % 900),
        'hardwareConcurrency' => $cpu,
        'deviceMemory'        => $mem,
        'timezoneId'          => $tz[0],
        'tzOffset'            => $tz[1],
        'lang'                => $langs[$b[4] % count($langs)],
    ];
}

/**
 * Dung chung de launch Chrome (browser.php).
 * Gom cac cuoc goi chung: user-agent, WebRTC, remote-debugging, proxy.
 */
function build_chrome_command(array $p, ?string $url = null, ?int $port = null, ?int $relayPort = null, array $startupUrls = [], ?array $windowRect = null): array
{
    // Trang mo mac dinh: lay tu setting "home_url" (mac dinh google.com)
    if ($url === null) {
        $url = get_setting('home_url', 'https://www.google.com/');
    }
    $cmd = [
        chrome_path(),
        '--user-data-dir=' . $p['user_data_dir'],
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-session-crashed-bubble',
        '--new-window',
    ];

    // User-Agent theo cau hinh kenh (neu co) de giam nhan dien bot
    if (!empty($p['user_agent'])) {
        $cmd[] = '--user-agent=' . $p['user_agent'];
    }

    // Fingerprint ẩn riêng từng kênh qua launch flag BỀN (không phụ thuộc CDP session):
    //   --accept-lang=... -> navigator.language + Accept-Language theo kênh
    //   --lang=vi         -> ngôn ngữ giao diện theo kênh
    // (timezone & hardwareConcurrency & deviceMemory do tabtitle_keeper polyfill JS áp bền)
    $finger = channel_fingerprint((int)($p['id'] ?? 0));
    $cmd[] = '--accept-lang=' . $finger['lang'];
    $cmd[] = '--lang=' . substr($finger['lang'], 0, 5);

    // Chống phát tán tín hiệu máy/identity ra Google từ background của mỗi kênh
    // (UMA/metrics, sync, crashpad, component update, safe-browsing pings... đều mang
    // machine_id chung -> dù proxy khác nhau vẫn nối được các kênh). Các cờ này chỉ
    // bỏ mấy dịch vụ nền, KHÔNG ảnh hưởng duyệt web thường.
    $cmd[] = '--disable-background-networking';
    $cmd[] = '--disable-sync';
    $cmd[] = '--disable-breakpad';
    $cmd[] = '--disable-component-update';
    $cmd[] = '--no-pings';
    $cmd[] = '--disable-domain-reliability';
    $cmd[] = '--disable-default-apps';
    $cmd[] = '--disable-backgrounding-occluded-windows';
    $cmd[] = '--disable-renderer-backgrounding';
    $cmd[] = '--disable-background-timer-throttling';

    // Chống lộ IP thật qua WebRTC: dùng ĐÚNG dạng Chrome đọc được (2 cờ riêng).
    // disable_non_proxied_udp = cấm UDP không đi qua proxy -> STUN/WebRTC không thấy IP thật.
    // LƯU Ý: Chrome desktop KHÔNG có flag tắt hẳn WebRTC (chỉ có extension/policy);
    // 'disable_nonproxied_udp' là mức bảo vệ mạnh nhất qua command-line (vẫn giữ gọi video).
    if (($p['webrtc_protection'] ?? 'default') === 'disable_nonproxied_udp') {
        $cmd[] = '--webrtc-ip-handling-policy=disable_non_proxied_udp';
        $cmd[] = '--force-webrtc-ip-handling-policy';
    }

    // Mo cong remote debugging de web app dieu khien tab qua CDP.
    // QUAN TRONG: chi cho origin localhost/app cua minh ket noi CDP (khong dung '*':
    // '*') — neu dung '*' moi trang web bat ky (ke ca site ma USER dang xem o 1 kenh
    // khac) co the mo WebSocket vao CDP, doc/dom cookie cua MOI kenh qua mang.
    if ($port) {
        $cmd[] = '--remote-debugging-port=' . $port;
        $cmd[] = '--remote-allow-origins=http://localhost';
    }

    if (!empty($p['proxy_host'])) {
        $proxyAddr = proxy_server_arg($p, $relayPort);
        $cmd[] = '--proxy-server=' . $proxyAddr;
        $cmd[] = '--proxy-bypass-list=<local>';
    }

    // Multi-monitor placement: --window-position/--window-size TRUOC Popen de Chrome
    // xuat hien truc tiep tai monitor dich (khong flash o primary). GIU toa do am
    // (vd --window-position=-1920,0), KHONG sanitize ve 0.
    if (is_array($windowRect) && isset($windowRect['x'], $windowRect['y'], $windowRect['w'], $windowRect['h'])) {
        $cmd[] = '--window-position=' . (int)$windowRect['x'] . ',' . (int)$windowRect['y'];
        $cmd[] = '--window-size=' . (int)$windowRect['w'] . ',' . (int)$windowRect['h'];
    }

    // Prelaunch session restore: append TOAN BO URLs theo dung thu tu de Chrome mo
    // ngay N tab tu frame dau (khong blank, khong NTP thua, khong navigate sau).
    $startupUrls = array_values(array_filter(array_map(fn($u) => trim((string)$u), $startupUrls)));
    if ($startupUrls) {
        foreach ($startupUrls as $su) $cmd[] = $su;
    } else {
        $cmd[] = $url;
    }
    return $cmd;
}

/**
 * Set ten profile Chrome (file Default/Preferences) cho kenh = "Kenh<id>" (ASCII, khong space)
 * truoc khi launch. Chrome dung ten profile de tao AppUserModelID cua cua so
 * ("Chrome.<hash>.<profile_name>") -> moi kenh duoc Windows tach rieng tren taskbar.
 * Chi ghi khi Chrome chua chay (goi tu launch_chrome truoc start /B).
 */
function ensure_chrome_profile_name(array $p): void
{
    $dir = trim((string)($p['user_data_dir'] ?? ''));
    $id = (int)($p['id'] ?? 0);
    if ($dir === '' || $id <= 0) return;
    $name = 'Kenh' . $id;
    $base = rtrim($dir, '/\\');

    // 1) Preferences cua profile Default
    $prefPath = $base . DIRECTORY_SEPARATOR . 'Default' . DIRECTORY_SEPARATOR . 'Preferences';
    $data = ['account_info' => [], 'profile' => ['name' => $name]];
    if (is_file($prefPath)) {
        $cur = json_decode((string)@file_get_contents($prefPath), true);
        if (is_array($cur)) $data = $cur;
    } else {
        @mkdir($base . DIRECTORY_SEPARATOR . 'Default', 0777, true);
    }
    $data['profile']['name'] = $name;
    // Luon hien thanh dau trang (bookmarks bar) tren moi tab cua kenh.
    $data['bookmark_bar']['show_on_all_tabs'] = true;
    // Chinh exit crash truoc do -> Chrome hien bubble "khoi phuc trang" (cua so phu
    // Chrome_WidgetWin_1 gay nhieu placement/verify). Reset de mo sach.
    if (isset($data['profile']['exit_type']) && $data['profile']['exit_type'] === 'Crashed') {
        $data['profile']['exit_type'] = 'Normal';
    }
    if (array_key_exists('exited_cleanly', $data['profile']) && $data['profile']['exited_cleanly'] === false) {
        $data['profile']['exited_cleanly'] = true;
    }
    @file_put_contents($prefPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // 2) Local State -> profile.info_cache.<dir profile>.name (Chrome dung cai nay de tao AUMI)
    $lsPath = $base . DIRECTORY_SEPARATOR . 'Local State';
    $ls = is_file($lsPath) ? json_decode((string)@file_get_contents($lsPath), true) : [];
    if (!is_array($ls)) $ls = [];
    if (!isset($ls['profile']['info_cache']) || !is_array($ls['profile']['info_cache'])) {
        $ls['profile']['info_cache'] = [];
    }
    // tim key profile Default trong info_cache; neu khong co thi tao
    $defaultKey = 'Default';
    if (isset($ls['profile']['info_cache'][$defaultKey]) && is_array($ls['profile']['info_cache'][$defaultKey])) {
        $ls['profile']['info_cache'][$defaultKey]['name'] = $name;
    } else {
        $ls['profile']['info_cache'] = array_merge([$defaultKey => ['name' => $name, 'user_name' => $name]], $ls['profile']['info_cache']);
    }
    @file_put_contents($lsPath, json_encode($ls, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** Fire-and-forget launch Chrome (khong cho thoat), dung cho browser.php.
 * Voi proxy co credential: start_proxy_relay BEEN LA guard song/chet cua proxy
 * (khong can test_proxy rieng -> mo kenh nhanh, khong 2 lan noi len proxy lien tiep).
 * $opts['skipSessionInject']=true: mo URL chi dinh (Studio/Dashboard), khong inject session.
 * $opts['quickRelay']=true: batch open — tin relay_listening, khong cho healthy 12s.
 * Mac dinh: prelaunch restore - load session tu DB (nhanh, khong can Chrome chay)
 * va append URLs vao command de Chrome mo dung tabs ngay frame dau. */
function launch_chrome(array $p, string $url, ?int $port, array $opts = []): void
{
    $relayPort = start_proxy_relay($p, !empty($opts['quickRelay']));
    // proxy co credential nhung relay khong len duoc (proxy chet/hong) -> dung mo Chrome,
    // tra loi loi de UI bao ngay (tranh mo ra "no internet").
    if ($relayPort === null && expected_relay_port($p) !== null) {
        db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
            ->execute(['dead', (int)($p['proxy_id'] ?? 0)]);
        throw new RuntimeException(
            'Proxy cua kenh khong phan hoi nen khong mo duoc Chrome. Gan proxy khac hoac thu lai.'
        );
    }
    // Prelaunch session restore (§1-2): doc session TRUOC khi Popen, inject URLs vao command.
    $startupUrls = [];
    $restoreSnapFile = null;
    if (empty($opts['skipSessionInject'])) {
        try {
            require_once __DIR__ . '/sync/SettingsService.php';
            require_once __DIR__ . '/sync/TabSessionStore.php';
            require_once __DIR__ . '/sync/SyncLogger.php';
            $autoRes = get_setting('tab_autorestore', '1');
            if ($autoRes === '1' || $autoRes === 'true') {
                $tPre = microtime(true);
                $sess = TabSessionStore::getUrlsForLaunch((int)($p['id'] ?? 0));
                $msPre = (int)round((microtime(true) - $tPre) * 1000);
                try {
                    require_once __DIR__ . '/sync/SyncLogger.php';
                    SyncLogger::info('tab_session', '[PERF] preload ' . count($sess['urls'] ?? [])
                        . ' session URLs #' . (int)($p['id'] ?? 0) . ": {$msPre}ms", (int)($p['id'] ?? 0));
                } catch (Throwable $e2) {
                }
                if ($sess !== null && !empty($sess['urls'])) {
                    $startupUrls = $sess['urls'];
                    SyncLogger::info('tab_session', '[SESSION] #' . (int)($p['id'] ?? 0)
                        . ' loaded ' . count($startupUrls) . ' URLs before launch', (int)($p['id'] ?? 0));
                }
            }
        } catch (Throwable $e) {
            $startupUrls = [];
        }
    }
    // Multi-monitor placement (flow: session -> monitor -> rect -> command -> Popen).
    // Chrome mo truc tiep tai monitor dich, khong qua about:blank roi move.
    $placeMonitor = null;
    $placeRect = null;
    try {
        require_once __DIR__ . '/sync/WindowPlacementManager.php';
        WindowPlacementManager::ensureDpiAwareness();
        // Enrich monitor fields neu caller chua join (backward-compatible khi cot chua migrate)
        if (!array_key_exists('monitor_mode', $p)) {
            try {
                $st = db()->prepare('SELECT monitor_mode, fixed_monitor_device, last_monitor_device, last_window_rect, last_window_rect_norm FROM profiles WHERE id=?');
                $st->execute([(int)($p['id'] ?? 0)]);
                if ($r = $st->fetch()) $p = array_merge($p, $r);
            } catch (Throwable $e2) {
            }
        }
        $placeMonitor = WindowPlacementManager::resolve_target_monitor($p);
        $snap0 = null;
        try {
            require_once __DIR__ . '/sync/SettingsService.php';
            $snap0 = SyncSettingsService::snapshot();
        } catch (Throwable $e2) {
            $snap0 = null;
        }
        $fbSize = $snap0 ? ['w' => (int)$snap0['width'], 'h' => (int)$snap0['height']] : ['w' => 1280, 'h' => 720];
        $placeRect = WindowPlacementManager::resolve_startup_rect($p, $placeMonitor, $fbSize);
        // StartPlan bat bien (§11, §26): batch da resolve monitor+rect -> dung lai,
        // KHONG resolve lai (khong cursor/primary/monitors[0]/app monitor).
        if (!empty($opts['placeOverride']) && is_array($opts['placeOverride'])
            && !empty($opts['placeOverride']['monitor']) && !empty($opts['placeOverride']['rect'])) {
            $placeMonitor = $opts['placeOverride']['monitor'];
            $placeRect = $opts['placeOverride']['rect'];
        }
        if ($placeMonitor !== null && $placeRect !== null) {
            try {
                require_once __DIR__ . '/sync/SyncLogger.php';
                SyncLogger::info('placement', '[PLACEMENT PLAN] profile=#' . (int)($p['id'] ?? 0)
                    . ' monitor=' . $placeMonitor['device_name']
                    . ' rect=(' . $placeRect['x'] . ',' . $placeRect['y'] . ',' . $placeRect['w'] . 'x' . $placeRect['h'] . ')', (int)($p['id'] ?? 0));
                SyncLogger::info('placement', '[LAUNCH] profile=#' . (int)($p['id'] ?? 0)
                    . ' position=' . $placeRect['x'] . ',' . $placeRect['y']
                    . ' size=' . $placeRect['w'] . 'x' . $placeRect['h'], (int)($p['id'] ?? 0));
            } catch (Throwable $e2) {
            }
        }
    } catch (Throwable $e) {
        $placeMonitor = null;
        $placeRect = null;
    }
    $cmd = build_chrome_command($p, $url, $port, $relayPort, $startupUrls, $placeRect);
    if ($startupUrls) {
        SyncLogger::info('tab_session', '[LAUNCH] #' . (int)($p['id'] ?? 0)
            . ' launching with ' . count($startupUrls) . ' startup tabs', (int)($p['id'] ?? 0));
        SyncLogger::info('tab_session', '[SESSION] #' . (int)($p['id'] ?? 0)
            . ' URLs injected at process launch', (int)($p['id'] ?? 0));
        // Snapshot cho activator (giong apply_window: file thay argv)
        $restoreSnapFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'ytm_restore_' . (int)($p['id'] ?? 0) . '_' . getmypid() . '.json';
        if (@file_put_contents($restoreSnapFile, json_encode(
            ['urls' => $startupUrls, 'activeUrl' => $sess['activeUrl'] ?? null])) === false) {
            $restoreSnapFile = null;
        }
    }
    // Placement lock TRUOC Popen (§12): STARTING/VERIFYING -> locked,
    // AutoArrange khong duoc move. apply_window clear khi STABLE/xong.
    // Generation token (§28): worker cu thay gen doi phai discard.
    try {
        if ($placeRect !== null && $placeMonitor !== null) {
            require_once __DIR__ . '/sync/WindowPlacementManager.php';
            $gen = isset($opts['generation']) ? (int)$opts['generation'] : null;
            $bb = isset($opts['batchId']) ? (string)$opts['batchId'] : null;
            WindowPlacementManager::startGuard((int)($p['id'] ?? 0), 0, $placeRect, $placeMonitor, $bb, $gen);
        }
    } catch (Throwable $e) {
    }
    $quoted = array_map(function ($arg) {
        return '"' . $arg . '"';
    }, $cmd);
    // Dinh danh taskbar rieng cho kenh: set ten profile Chrome truoc khi launch
    // (AUMI cua so Chrome theo ten profile -> moi kenh 1 nut rieng tren taskbar,
    // khong gom chung vo nut "Google Chrome" cua nhung kenh khac).
    ensure_chrome_profile_name($p);
    $shell = 'start "" /B ' . implode(' ', $quoted) . ' > NUL 2>&1';
    pclose(popen($shell, 'r'));
    // ten kenh tren tab do tabtitle_keeper lo (khong cha de title xong moi tra loi -> mo nhanh)
    if ($port) {
        start_tab_title_keeper($port, (int)($p['id'] ?? 0));
    }
    // relay watchdog: tu bật lai relay khi chet (chay ring, de phong ko phai mo lai kenh)
    start_relay_watchdog();
    // Tab Session activator (fire-and-forget): chi kich hoat tab cu, KHONG
    // navigate/create (URLs da inject vao command). Khong session -> khong spawn.
    try {
        if ($restoreSnapFile !== null) {
            $php = php_cli_binary();
            $scr = __DIR__ . '/bin/restore_tabs.php';
            if ($php !== '' && is_file($scr)) {
                $cmdR = 'start "" /B "' . $php . '" -f "' . $scr . '" -- ' . (int)($p['id'] ?? 0) . ' "' . $restoreSnapFile . '" > NUL 2>&1';
                pclose(popen($cmdR, 'r'));
            } else {
                @unlink($restoreSnapFile);
            }
        }
    } catch (Throwable $e) {
        // activator loi khong duoc pha launch Chrome
    }
    // Window apply (SSOT + placement guard): luon spawn khi co placeRect (giam flash
    // primary + guard Chrome tu restore sai monitor), ngoai ra giu hanh vi cu theo
    // Global Window Settings. Snapshot truyen qua FILE.
    try {
        require_once __DIR__ . '/sync/SettingsService.php';
        $snap = SyncSettingsService::snapshot();
        $needApply = ($snap['fixed'] || $snap['position'] !== 'auto') || ($placeRect !== null);
        if ($needApply) {
            // Gan placement dich vao snapshot de apply_window uu tien (khong ep ve primary)
            if ($placeRect !== null) {
                $snap['placement_rect'] = $placeRect;
                $snap['placement_monitor'] = $placeMonitor['device_name'] ?? '';
            }
            $php = php_cli_binary();
            $scr = __DIR__ . '/bin/apply_window.php';
            if ($php !== '' && is_file($scr)) {
                $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
                    . 'ytm_win_' . (int)($p['id'] ?? 0) . '_' . getmypid() . '.json';
                if (@file_put_contents($tmp, json_encode($snap)) !== false) {
                    $cmd2 = 'start "" /B "' . $php . '" -f "' . $scr . '" -- ' . (int)($p['id'] ?? 0) . ' "' . $tmp . '" > NUL 2>&1';
                    pclose(popen($cmd2, 'r'));
                }
            }
        }
    } catch (Throwable $e) {
        // applier loi khong duoc pha launch Chrome
    }
}

/** Proxy non-relay vua test alive (<5ph) -> skip retest khi mo hang loat. */
function proxy_recently_alive(array $p): bool
{
    if (empty($p['proxy_host']) || expected_relay_port($p) !== null) return false;
    if (($p['proxy_status'] ?? '') !== 'alive') return false;
    if (empty($p['proxy_last_check'])) return false;
    try {
        return (time() - strtotime((string)$p['proxy_last_check'])) < 300;
    } catch (Throwable $e) {
        return false;
    }
}

/** Con process Chrome nao cua user_data_dir khong? (1 luot WMI, dung de poll sau kill) */
function chrome_processes_alive(string $udir): bool
{    $udir = trim($udir);
    if ($udir === '') return false;
    $like = '*' . str_replace("'", "''", $udir) . '*';
    $ps = 'powershell -NoProfile -Command '
        . '"Get-CimInstance Win32_Process -Filter \"Name=' . "'" . 'chrome.exe' . "'" . '\" '
        . '| Where-Object { $_.CommandLine -like ' . "'" . $like . "'" . ' } '
        . '| Select-Object -First 1 | ForEach-Object { $_.ProcessId }"';
    $out = [];
    @exec($ps, $out);
    foreach ($out as $line) {
        if (trim((string)$line) !== '') return true;
    }
    return false;
}

/** Dong NHẸ NHÀNG cac window Chrome cua 1 profile (PostMessage WM_CLOSE 1 luot,
 *  cho process tu thoat toi da 3s). Tra ve true neu tat ca process thoat sach
 *  (khong can kill). CHI cham window thuộc user-data-dir cua profile (khong
 *  dung Chrome ngoai tool). Loi -> false, caller fallback kill. */
function close_chrome_gracefully(array $p): bool
{
    $t0 = microtime(true);
    try {
        require_once __DIR__ . '/sync/WindowDiscovery.php';
        $hwnds = [];
        foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
            if ($w->profileId !== null && (int)$w->profileId === (int)($p['id'] ?? 0)
                && $w->class === 'Chrome_WidgetWin_1' && $w->visible) {
                $hwnds[] = $w->hwnd;
            }
        }
        $hwnds = array_values(array_unique(array_filter(array_map('intval', $hwnds))));
        if (!$hwnds) return (bool)!chrome_processes_alive((string)($p['user_data_dir'] ?? ''));
        $script = __DIR__ . '/sync/win32_close.ps1';
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $script . '"'
            . ' -Hwnd "' . implode(',', $hwnds) . '"';
        @shell_exec($cmd);
        $msDispatch = (int)round((microtime(true) - $t0) * 1000);
        try {
            require_once __DIR__ . '/sync/SyncLogger.php';
            SyncLogger::info('browser_perf', '[PERF] WM_CLOSE dispatch ' . count($hwnds)
                . ' (#' . (int)($p['id'] ?? 0) . '): ' . $msDispatch . 'ms', (int)($p['id'] ?? 0));
        } catch (Throwable $e) {
        }
        // Cho process tu dong (poll 100ms, toi da 3s) thay vi force kill ngay
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            if (!chrome_processes_alive((string)($p['user_data_dir'] ?? ''))) {
                $ms = (int)round((microtime(true) - $t0) * 1000);
                try {
                    SyncLogger::info('browser_perf', '[PERF] graceful close #' . (int)($p['id'] ?? 0)
                        . ": {$ms}ms", (int)($p['id'] ?? 0));
                } catch (Throwable $e) {
                }
                return true;
            }
            usleep(100000);
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/** Dong toan bo process Chrome cua 1 profile (theo user_data_dir) */
function kill_chrome_processes(array $p): void
{
    // Graceful truoc: WM_CLOSE de Chrome flush profile/cookie (3s), that bai -> fallback kill
    close_chrome_gracefully($p);    $udir = $p['user_data_dir'];
    $ps = 'powershell -NoProfile -Command '
        . '"Get-CimInstance Win32_Process -Filter \"Name=' . "'" . 'chrome.exe' . "'" . '\" '
        . '| Where-Object { $_.CommandLine -like ' . "'" . '*' . $udir . '*' . "'" . ' } '
        . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"';
    exec($ps);
    // Cho process thoat that (poll 100ms, toi da ~2s) thay vi sleep mu 700ms:
    // may nhanh ve ngay sau 100ms, may cham van duoc cho du.
    for ($i = 0; $i < 20; $i++) {
        if (!chrome_processes_alive((string)$udir)) break;
        usleep(100000);
    }
    // don tab title keeper cua kenh nay (tab moi khong con can gan ten)
    kill_tab_title_keeper((int)($p['debug_port'] ?? 0));
    // don relay neu khong con profile nao dung proxy nay
    stop_proxy_relay_if_unused($p);
    // mo khoa placement guard (window khong con)
    try {
        require_once __DIR__ . '/sync/WindowPlacementManager.php';
        WindowPlacementManager::clearGuard((int)($p['id'] ?? 0));
    } catch (Throwable $e) {
    }
}

/**
 * Kiem tra va dong bo trang thai profile voi thuc te.
 * Tra ve trang thai chinh xac ('running'/'stopped') va update DB khi doi.
 */
function refresh_profile_status(array $p): string
{
    $status = profile_live($p) ? 'running' : 'stopped';

    if ($status !== mb_strtolower((string)($p['status'] ?? ''))) {
        db()->prepare('UPDATE profiles SET status=? WHERE id=?')->execute([$status, (int)$p['id']]);
    }
    return $status;
}

/** Parse chuoi proxy thanh mang (dung chung cho proxies.php, profiles.php).
 *  $defaultProtocol ap dung cho dong KHONG ghi ro protocol; dong co prefix
 *  protocol:// van uu tien theo prefix. Luon whitelist ve 4 loai ho tro. */
function parse_proxy_string(string $s, string $defaultProtocol = 'http'): ?array
{
    // Dang ho tro:
    //   host:port
    //   host:port:user:pass
    //   protocol://host:port
    //   protocol://user:pass@host:port
    $protocol = strtolower(trim($defaultProtocol));
    if (!in_array($protocol, ['http', 'https', 'socks4', 'socks5', 'ssh'], true)) $protocol = 'http';
    $auth = null;

    if (preg_match('#^(https?|socks4|socks5|ssh)://#i', $s, $m)) {
        $protocol = strtolower($m[1]);
        $s = preg_replace('#^(https?|socks4|socks5|ssh)://#i', '', $s);
        // co the co user:pass@
        if (preg_match('#^(.*?):(.*?)@(.*)$#', $s, $m)) {
            $auth = [urldecode($m[1]), urldecode($m[2])];
            $s = $m[3];
        }
    } elseif (preg_match('#^(.*?):(.*?)@(.*)$#', $s, $m)) {
        $auth = [$m[1], $m[2]];
        $s = $m[3];
    }

    $parts = explode(':', $s);
    $host = trim($parts[0] ?? '');
    $port = (int)trim($parts[1] ?? '0');
    if ($host === '' || $port <= 0) return null;

    $username = $auth[0] ?? null;
    $password = $auth[1] ?? null;
    if (!$username && isset($parts[2]) && $parts[2] !== '') {
        $username = $parts[2];
        $password = $parts[3] ?? null;
    }

    return [
        'name'     => $host . ':' . $port,
        'host'     => $host,
        'port'     => $port,
        'username' => $username,
        'password' => $password,
        'protocol' => $protocol,
        'country'  => '',
    ];
}

/** Tim proxy theo host+port trong DB; neu chua co thi tao moi. Tra ve proxy_id (int) hoac null. */
function find_or_create_proxy(array $p): ?int
{
    $host = $p['host'];
    $port = (int)$p['port'];
    $stmt = db()->prepare('SELECT id FROM proxies WHERE host=? AND port=? LIMIT 1');
    $stmt->execute([$host, $port]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    db()->prepare('INSERT INTO proxies (name, host, port, username, password, protocol, country)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $p['name'] ?? ($host . ':' . $port),
            $host,
            $port,
            $p['username'] ?? null,
            $p['password'] ?? null,
            $p['protocol'] ?? 'http',
            strtoupper($p['country'] ?? ''),
        ]);
    return (int)db()->lastInsertId();
}

/**
 * Resolve proxy_id tu payload modal (NONE/SAVED/MANUAL), dung chung add/update.
 * Khong co proxy_mode trong payload -> proxy_id truc tiep (client cu) hoac giu hien tai.
 */
function resolve_profile_proxy($currentProxyId, array $b, int $profileId = 0)
{
    if (array_key_exists('proxy_mode', $b)) {
        $pmode = strtoupper(trim((string)$b['proxy_mode']));
        if ($pmode === 'NONE') return null;
        if ($pmode === 'SAVED') {
            $pid2 = !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null;
            if ($pid2 !== null) {
                $chk = db()->prepare('SELECT id FROM proxies WHERE id=?');
                $chk->execute([$pid2]);
                if (!$chk->fetch()) json_out(['ok' => false, 'message' => 'Proxy khong ton tai'], 404);
            }
            return $pid2;
        }
        if ($pmode === 'MANUAL') {
            $proto = strtolower(trim((string)($b['proxy_protocol'] ?? 'http')));
            if (!in_array($proto, ['http', 'https', 'socks4', 'socks5'], true)) {
                json_out(['ok' => false, 'message' => 'Protocol khong hop le'], 400);
            }
            $mhost = trim((string)($b['proxy_host'] ?? ''));
            $mport = (int)($b['proxy_port'] ?? 0);
            if ($mhost === '' || $mport < 1 || $mport > 65535) {
                json_out(['ok' => false, 'message' => 'Host/port proxy khong hop le'], 400);
            }
            $muser = trim((string)($b['proxy_username'] ?? ''));
            $mpass = (string)($b['proxy_password'] ?? '');
            if ($muser === '') $mpass = '';
            return resolve_manual_proxy($profileId, $mhost, $mport, $proto,
                $muser !== '' ? $muser : null, $muser !== '' ? $mpass : null);
        }
        json_out(['ok' => false, 'message' => 'proxy_mode khong hop le'], 400);
    }
    if (array_key_exists('proxy_id', $b)) {
        return !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null;
    }
    return $currentProxyId;
}

/**
 * Resolve proxy thu cong cho 1 profile (modal Sua kenh).
 * - Trung khop host+port+protocol+user -> reuse id (khong dup).
 * - Trung host+port nhung record chi profile nay dung -> update tai cho.
 * - Trung host+port nhung profile khac cung dung + auth khac -> tao moi.
 * Khong log password (chi mask).
 */
function resolve_manual_proxy(int $profileId, string $host, int $port, string $protocol, ?string $user, ?string $pass): int
{
    $st = db()->prepare('SELECT * FROM proxies WHERE host=? AND port=?');
    $st->execute([$host, $port]);
    $cands = $st->fetchAll();
    foreach ($cands as $c) {
        if (strtolower((string)$c['protocol']) === strtolower($protocol)
            && (string)($c['username'] ?? '') === (string)($user ?? '')) {
            if ((string)($c['password'] ?? '') !== (string)($pass ?? '')) {
                db()->prepare('UPDATE proxies SET password=? WHERE id=?')->execute([$pass, (int)$c['id']]);
            }
            return (int)$c['id'];
        }
    }
    if ($cands) {
        // Record dau tien: neu chi profile nay dung -> update tai cho (giu id on dinh)
        $first = $cands[0];
        $cnt = db()->prepare('SELECT COUNT(*) FROM profiles WHERE proxy_id=? AND id<>?');
        $cnt->execute([(int)$first['id'], $profileId]);
        if ((int)$cnt->fetchColumn() === 0) {
            db()->prepare('UPDATE proxies SET protocol=?, username=?, password=?, name=? WHERE id=?')
                ->execute([$protocol, $user, $pass, $host . ':' . $port, (int)$first['id']]);
            try {
                log_action($profileId, 'proxy_manual', mask_proxy_url($protocol, $host, $port, $user));
            } catch (Throwable $e) {
            }
            return (int)$first['id'];
        }
    }
    db()->prepare('INSERT INTO proxies (name, host, port, username, password, protocol, country) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$host . ':' . $port, $host, $port, $user, $pass, $protocol, '']);
    $newId = (int)db()->lastInsertId();
    try {
        log_action($profileId, 'proxy_manual', mask_proxy_url($protocol, $host, $port, $user));
    } catch (Throwable $e) {
    }
    return $newId;
}

/** Xoa de quy mot thu muc (va moi noi dung ben trong). Khong bao loi khi khong ton tai. */
function rrmdir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) return;
    $items = @scandir($dir);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        is_dir($path) && !is_link($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Dong scd.php daemon (screencast) cua 1 profile theo daemon.pid */
function kill_frame_daemon(int $id): void
{
    if ($id <= 0) return;
    $pidFile = __DIR__ . '/android/frames/ch' . $id . '/daemon.pid';
    if (!is_file($pidFile)) return;
    $pid = (int)trim((string)file_get_contents($pidFile));
    if ($pid <= 0) return;
    $ps = 'powershell -NoProfile -Command '
        . '"Get-CimInstance Win32_Process -Filter \"Name=' . "'" . 'php.exe' . "'" . '\" '
        . '| Where-Object { $_.ProcessId -eq ' . $pid . ' } '
        . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"';
    @shell_exec($ps);
}

/** Dong scd daemon roi xoa record khoi DB (da ghi log) */
function delete_profile(array $p): void
{
    $id = (int)$p['id'];

    // 1. Dong Chrome kenh nay (mac dinh cung kill tabtitle_keeper + stop relay neu khong con kenh nao dung)
    kill_chrome_processes($p);
    usleep(500000);

    // 2. Dong scd daemon screencast
    kill_frame_daemon($id);

    // 3. Xoa cache Chrome (user_data_dir — thu muc nang nhat, con GBs).
    //    Chrome co the con giu khoa file ngay sau khi kill -> retry toi ~5s cho den khi het.
    if (!empty($p['user_data_dir'])) {
        $udir = $p['user_data_dir'];
        rrmdir($udir);
        for ($i = 0; $i < 5 && is_dir($udir); $i++) {
            usleep(1000000); // 1s, toi da ~5s
            rrmdir($udir);
        }
    }

    // 4. Xoa khung hinh daemon (frame.jpg, meta.json, daemon.log, daemon.pid, ctrl.json...)
    rrmdir(__DIR__ . '/android/frames/ch' . $id);

    // 5. Xoa DB row (activity_logs.profile_id tu SET NULL on delete)
    log_action($id, 'delete', 'Xoa profile (da xoa cache)');
    db()->prepare('DELETE FROM profiles WHERE id = ?')->execute([$id]);
}

/** Test 1 proxy co song khong (dung chung cho proxies.php va browser.php) */
function test_proxy(array $p): bool
{
    $host = $p['host'];
    $port = (int)$p['port'];
    // cap 3s cho kiem tra trong luong open kenh (deadline du nho de khong lam UI to mau)
    $timeout = min(proxy_timeout(), 3);

    $ch = curl_init('http://www.google.com/generate_204');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_NOBODY         => true,
    ]);

    $proxyAddr = $host . ':' . $port;
    switch ($p['protocol'] ?? 'http') {
        case 'socks5':
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            break;
        case 'socks4':
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            break;
        default:
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            break;
    }

    if (!empty($p['username']) || !empty($p['password'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['username'] . ':' . $p['password']);
    }

    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 400;
}

/** Mask password de log/toast an toan: socks5://user:***@host:port */
function mask_proxy_url(string $protocol, string $host, int $port, ?string $user = null): string
{
    $auth = ($user !== null && $user !== '') ? $user . ':***@' : '';
    return strtolower($protocol) . '://' . $auth . $host . ':' . $port;
}

/** Dat proxy opts len curl handle (dung chung cho test thuong + chi tiet). */
function proxy_curl_apply($ch, array $p): void
{
    $proxyAddr = $p['host'] . ':' . (int)$p['port'];
    switch (strtolower($p['protocol'] ?? 'http')) {
        case 'socks5':
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            break;
        case 'socks4':
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            break;
        default: // http + https (Chrome coi https nhu http)
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            break;
    }
    if (!empty($p['username']) || !empty($p['password'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['username'] . ':' . $p['password']);
    }
}

/** Map curl errno -> error code than thien (khong lo password). */
function proxy_curl_errcode(int $errno): string
{
    if (in_array($errno, [28], true)) return 'Timeout';
    if (in_array($errno, [56, 7], true)) return 'Connection refused';
    if (in_array($errno, [6], true)) return 'DNS error';
    if (in_array($errno, [67], true)) return 'Authentication failed';
    return 'Connection failed';
}

/**
 * Test proxy chi tiet cho modal Sua kenh: {ok, ms, ip, error}.
 * Khong tao record, khong tra password, khong log password.
 * Chay sync trong request (timeout 10s); client goi async + co Abort.
 */
function test_proxy_detailed(array $p, int $timeout = 10): array
{
    $timeout = max(3, min(15, $timeout));
    $t0 = microtime(true);
    $ch = curl_init('http://www.google.com/generate_204');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_NOBODY         => true,
    ]);
    proxy_curl_apply($ch, $p);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($errno === 0 && $code >= 200 && $code < 400) {
        // Lay IP egress (best-effort, timeout ngan rieng)
        $ip = null;
        $ch2 = curl_init('https://api.ipify.org');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        proxy_curl_apply($ch2, $p);
        $body = curl_exec($ch2);
        if (is_string($body) && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', trim($body))) {
            $ip = trim($body);
        }
        curl_close($ch2);
        return ['ok' => true, 'ms' => $ms, 'ip' => $ip];
    }
    $reason = $errno !== 0 ? proxy_curl_errcode($errno) : ('HTTP ' . $code);
    if ($errno !== 0 && $err !== '') $reason .= ' (' . mb_substr($err, 0, 80) . ')';
    return ['ok' => false, 'ms' => $ms, 'ip' => null, 'error' => $reason];
}