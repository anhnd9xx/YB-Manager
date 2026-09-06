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
 * tra ve map user_data_dir => true (lowercase). Cache theo request.
 * Truoc day moi profile goi 1 lan PowerShell -> vai chuc giay cho 20+ kenh.
 */
function running_chrome_dirs(): array
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
            $cache[strtolower(rtrim($m[1], '"'))] = true;
        }
    }
    return $cache;
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

/** Lay toan bo command line cua process Chrome CHINH (khong phai renderer/gpu...) cua 1 user_data_dir. null neu khong chay. */
function chrome_main_cmdline(string $dir): ?string
{
    $ps = 'powershell -NoProfile -Command '
        . '"Get-CimInstance Win32_Process -Filter \"Name=' . "'" . 'chrome.exe' . "'" . '\" '
        . '| Where-Object { $_.CommandLine -like ' . "'" . '*' . $dir . '*' . "'" . ' -and $_.CommandLine -notlike ' . "'" . '*--type=*' . "'" . ' } '
        . '| Select-Object -First 1 -ExpandProperty CommandLine"';
    $out = shell_exec($ps . ' 2>NUL');
    return $out ? trim($out) : null;
}

/** Chrome dang chay co dung config proxy/ua nhu trong DB khong? (dung de biet co can restart khi config doi) */
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

/** Khoi dong relay local (proxy_relay.php) de Chrome goi khong credential; relay them Authorization ra proxy that. Tra ve port hoac null. */
function start_proxy_relay(array $p): ?int
{
    $port = expected_relay_port($p);
    if ($port === null) return null;
    if (relay_healthy($port)) return $port;
    // relay cu song port nhung hong (vi du proc_open duoi Apache) -> dong truoc khi spawn lai
    stop_proxy_relay($port);
    $php = '';
    if (stripos(basename((string)PHP_BINARY), 'php') === 0 && is_file((string)PHP_BINARY)) {
        $php = (string)PHP_BINARY;
    } else {
        $cand = 'C:/xampp/php/php.exe';
        if (is_file($cand)) { $php = $cand; }
    }
    if ($php === '' || !is_file($php)) return null;
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

/** Kiem tra CDP endpoint cua 1 debug port co phan hoi khong (timeout 1s) */
function cdp_reachable(int $port): bool
{
    $ctx = stream_context_create(['http' => ['timeout' => 1, 'method' => 'GET', 'ignore_errors' => true]]);
    $res = @file_get_contents('http://127.0.0.1:' . $port . '/json/version', false, $ctx);
    return $res !== false;
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

    // Chống lộ IP thật qua WebRTC
    if (($p['webrtc_protection'] ?? 'default') !== 'default') {
        $cmd[] = '--disable-webrtc';
        if (($p['webrtc_protection'] ?? 'default') === 'disable_nonproxied_udp') {
            $cmd[] = '--force-webrtc-ip-handling-policy=disable_non_proxied_udp';
        }
    }

    // Mo cong remote debugging de web app dieu khien tab qua CDP
    if ($port) {
        $cmd[] = '--remote-debugging-port=' . $port;
        $cmd[] = '--remote-allow-origins=*';
    }

    if (!empty($p['proxy_host'])) {
        $proxyAddr = proxy_server_arg($p, $relayPort);
        $cmd[] = '--proxy-server=' . $proxyAddr;
        $cmd[] = '--proxy-bypass-list=<local>';
    }

    $cmd[] = $url;
    return $cmd;
}

/** Fire-and-forget launch Chrome (khong cho thoat), dung chung browser.php + sync.php */
function launch_chrome(array $p, string $url, ?int $port): void
{
    $relayPort = start_proxy_relay($p);
    $cmd = build_chrome_command($p, $url, $port, $relayPort);
    $quoted = array_map(function ($arg) {
        return '"' . $arg . '"';
    }, $cmd);
    $shell = 'start "" /B ' . implode(' ', $quoted) . ' > NUL 2>&1';
    pclose(popen($shell, 'r'));
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
    $timeout = proxy_timeout();

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