<?php
declare(strict_types=1);

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
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function get_setting(string $key, string $default = ''): string
{
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

function chrome_path(): string
{
    return get_setting('chrome_path', CHROME_DEFAULT_PATH);
}

function proxy_timeout(): int
{
    return max(2, (int)get_setting('proxy_timeout', '5'));
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

// =================== Health check chung (dung cho browser.php, profiles.php, sync.php) ===================

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
    $wantWebrtc = (($p['webrtc_protection'] ?? 'default') !== 'default');
    $hasWebrtc = strpos($cmd, '--webrtc-ip-handling-policy') !== false;
    if ($wantWebrtc !== $hasWebrtc) return false;
    $wantLang = channel_fingerprint((int)($p['id'] ?? 0))['lang'];
    $hasLang = strpos($cmd, '--accept-lang=' . $wantLang) !== false;
    if (!$hasLang) return false;
    return true;
}

/** Port relay local cho proxy co credential (9400 + proxy_id), tra ve null neu proxy khong can relay */
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

function proxy_relay_port(int $proxyId): int
{
    return 9400 + ((int)$proxyId % 100);
}

/** Kiem tra relay co lang nghe tren port khong */
function relay_listening(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($fp) { fclose($fp); return true; }
    return false;
}

/** Kiem tra relay THUC SU phuc vu duoc (CONNECT + upstream): tranh reuse relay chet/hong */
function relay_healthy(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if (!$fp) return false;
    fwrite($fp, "CONNECT www.gstatic.com:443 HTTP/1.1\r\nHost: www.gstatic.com:443\r\n\r\n");
    stream_set_timeout($fp, 6);
    $buf = '';
    $deadline = microtime(true) + 6;
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

/** Khoi dong relay local (proxy_relay.php) de Chrome goi khong credential; relay them Authorization ra proxy that. Tra ve port hoac null. */
function start_proxy_relay(array $p): ?int
{
    $port = expected_relay_port($p);
    if ($port === null) return null;
    if (relay_healthy($port)) return $port;
    // relay cu song port nhung hong (vi du proc_open duoi Apache) -> dong truoc khi spawn lai
    stop_proxy_relay($port);
    $php = php_cli_binary();
    if ($php === '') return null;
    $relay = __DIR__ . '/proxy_relay.php';
    // Spawn bang PowerShell Start-Process (dam bao relay doc lap voi process cha - proc_open duoi Apache khong phuc vu duoc socket)
    $args = "'-f','" . str_replace("'", "''", $relay) . "','$port','" . str_replace("'", "''", (string)$p['proxy_host']) . "'," . (int)$p['proxy_port'] . ",'" . str_replace("'", "''", (string)($p['proxy_user'] ?? '')) . "','" . str_replace("'", "''", (string)($p['proxy_pass'] ?? '')) . "'";
    $ps = "Start-Process -FilePath '" . str_replace("'", "''", $php) . "' -ArgumentList @($args) -WindowStyle Hidden";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $ps) . '"';
    @shell_exec($cmd);
    for ($i = 0; $i < 30; $i++) {
        usleep(150000);
        if (relay_healthy($port)) return $port;
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

/** Dong relay tre port (chi kill process dang LISTENING tren port reserved 9400+) */
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

/** Kiem tra CDP endpoint cua 1 debug port co lang nghe khong (TCP connect 0.5s, khong lay du lieu) */
function cdp_reachable(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($fp) { fclose($fp); return true; }
    return false;
}

/** CDP: lay danh sach page targets cua debug port (/json/list) */
function cdp_page_targets(int $port): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $json = @file_get_contents('http://127.0.0.1:' . $port . '/json/list', false, $ctx);
    if ($json === false) return [];
    $arr = json_decode($json, true);
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
 */
function channel_fingerprint(int $profileId): array
{
    $idx = max(1, min(5, $profileId - 46));
    $i = $idx - 1;
    return [
        'index'              => $idx,
        'hardwareConcurrency'=> [4, 8, 4, 6, 8][$i],
        'deviceMemory'       => [8, 4, 4, 6, 8][$i],
        'timezoneId'         => ['Asia/Ho_Chi_Minh', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Manila', 'Asia/Jakarta'][$i],
        // getTimezoneOffset (phút, đông dương = GMT - giờ địa phương): VN/Bangkok/Jakarta UTC+7 => -420, SG/Manila UTC+8 => -480
        'tzOffset'           => [-420, -420, -480, -480, -420][$i],
        'lang'               => ['vi-VN,vi;q=0.9,en;q=0.8', 'en-US,en;q=0.9,vi;q=0.8', 'vi-VN,vi;q=0.9,en;q=0.8', 'en-GB,en;q=0.9,vi;q=0.8', 'vi-VN,vi;q=0.9,en;q=0.8'][$i],
    ];
}

/**
 * Dung chung de launch Chrome (browser.php va sync.php).
 * Gom cac cuoc goi chung: user-agent, WebRTC, remote-debugging, proxy.
 */
function build_chrome_command(array $p, string $url = 'https://www.youtube.com', ?int $port = null, ?int $relayPort = null): array
{
    $cmd = [
        chrome_path(),
        '--user-data-dir=' . $p['user_data_dir'],
        '--no-first-run',
        '--no-default-browser-check',
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

    // Chống lộ IP thật qua WebRTC: dùng ĐÚNG dạng Chrome đọc được (2 cờ riêng).
    // disable_non_proxied_udp = cấm UDP không đi qua proxy -> STUN/WebRTC không thấy IP thật.
    if (($p['webrtc_protection'] ?? 'default') !== 'default') {
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

    $cmd[] = $url;
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

/** Fire-and-forget launch Chrome (khong cho thoat), dung chung browser.php + sync.php.
 * Voi proxy co credential: start_proxy_relay BEEN LA guard song/chet cua proxy
 * (khong can test_proxy rieng -> mo kenh nhanh, khong 2 lan noi len proxy lien tiep). */
function launch_chrome(array $p, string $url, ?int $port): void
{
    $relayPort = start_proxy_relay($p);
    // proxy co credential nhung relay khong len duoc (proxy chet/hong) -> dung mo Chrome,
    // tra loi loi de UI bao ngay (tranh mo ra "no internet").
    if ($relayPort === null && expected_relay_port($p) !== null) {
        db()->prepare('UPDATE proxies SET status=?, last_check=NOW() WHERE id=?')
            ->execute(['dead', (int)($p['proxy_id'] ?? 0)]);
        throw new RuntimeException(
            'Proxy cua kenh khong phan hoi nen khong mo duoc Chrome. Gan proxy khac hoac thu lai.'
        );
    }
    $cmd = build_chrome_command($p, $url, $port, $relayPort);
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
}

/** Dong toan bo process Chrome cua 1 profile (theo user_data_dir) */
function kill_chrome_processes(array $p): void
{
    $udir = $p['user_data_dir'];
    $ps = 'powershell -NoProfile -Command '
        . '"Get-CimInstance Win32_Process -Filter \"Name=' . "'" . 'chrome.exe' . "'" . '\" '
        . '| Where-Object { $_.CommandLine -like ' . "'" . '*' . $udir . '*' . "'" . ' } '
        . '| ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"';
    exec($ps);
    // cho process thoat het truoc khi launch instance moi
    usleep(700000);
    // don tab title keeper cua kenh nay (tab moi khong con can gan ten)
    kill_tab_title_keeper((int)($p['debug_port'] ?? 0));
    // don relay neu khong con profile nao dung proxy nay
    stop_proxy_relay_if_unused($p);
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

/** Parse chuoi proxy thanh mang (dung chung cho proxies.php, profiles.php) */
function parse_proxy_string(string $s): ?array
{
    // Dang ho tro:
    //   host:port
    //   host:port:user:pass
    //   protocol://host:port
    //   protocol://user:pass@host:port
    $protocol = 'http';
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