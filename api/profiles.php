<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            require_once __DIR__ . '/../sync/AccountRepository.php';
            require_once __DIR__ . '/../sync/TabSessionStore.php';
            AccountRepository::ensureAll();
            $hasPlace = false;
            $hasEval = false;
            try {
                $c = db()->query("SHOW COLUMNS FROM profiles LIKE 'monitor_mode'")->fetch();
                $hasPlace = (bool)$c;
                $e = db()->query("SHOW COLUMNS FROM account_states LIKE 'eval_status'")->fetch();
                $hasEval = (bool)$e;
            } catch (Throwable $e) {
            }
            $profiles = db()->query(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.status AS proxy_status,
                        s.stage AS acc_stage, s.stability AS acc_stability, s.confidence AS acc_confidence,
                        s.channel_state AS acc_channel, s.channel_name AS acc_channel_name,
                        s.consec_fails AS acc_consec, s.last_checked_at AS acc_checked,
                        s.login_state AS acc_login, s.session_state AS acc_session,
                        s.youtube_state AS acc_youtube, s.success_count AS acc_success,
                        s.fail_count AS acc_fail,
                        TIMESTAMPDIFF(DAY, s.imported_at, NOW()) AS acc_days
                 FROM profiles p
                 LEFT JOIN proxies pr ON pr.id = p.proxy_id
                 LEFT JOIN account_states s ON s.profile_id = p.id
                 ORDER BY p.id DESC'
            )->fetchAll();
            // Eval status rieng biet runtime Chrome (khong tron). Watchdog 1 lan/list.
            $evalMap = [];
            if ($hasEval) {
                try {
                    require_once __DIR__ . '/../sync/ChannelEvaluationManager.php';
                    ChannelEvaluationManager::watchdog();
                    $cols = 'profile_id, eval_status, last_known_status, last_successful_check_at, last_attempt_at, last_error, infra_status, last_completed_at, last_status_changed_at, auth_status, channel_presence, channel_verified_at, last_known_presence, channel_legacy';
                    try {
                        if (db()->query("SHOW COLUMNS FROM account_states LIKE 'last_attempt_status'")->fetch()) {
                            $cols .= ', last_attempt_status, last_error_code, last_error_message, eval_stage';
                        }
                    } catch (Throwable $e2) {
                    }
                    foreach (db()->query("SELECT $cols FROM account_states") as $er) {
                        $evalMap[(int)$er['profile_id']] = $er;
                    }
                } catch (Throwable $e) {
                }
            }
            // Dong bo trang thai voi thuc te (Chrome bi tat/ngat tay thi cap nhat lai)
            $tabCounts = TabSessionStore::counts(array_map(fn($r) => (int)$r['id'], $profiles));
            foreach ($profiles as &$row) {
                $row['status'] = refresh_profile_status($row);
                $tc = $tabCounts[(int)$row['id']] ?? ['count' => 0, 'saved_at' => null];
                $row['tab_count_saved'] = (int)$tc['count'];
                $row['tab_saved_at'] = $tc['saved_at'];
                $em = $evalMap[(int)$row['id']] ?? null;
                $row['eval_status'] = $em ? (string)($em['eval_status'] ?? 'UNCHECKED') : 'UNCHECKED';
                $row['eval_known'] = $em ? ($em['last_known_status'] ?? null) : null;
                $row['eval_attempt'] = $em ? ($em['last_attempt_at'] ?? null) : null;
                $row['eval_error'] = $em ? ($em['last_error'] ?? null) : null;
                $row['eval_attempt_status'] = $em ? ($em['last_attempt_status'] ?? null) : null;
                $row['eval_error_code'] = $em ? ($em['last_error_code'] ?? null) : null;
                $row['eval_stage'] = $em ? ($em['eval_stage'] ?? null) : null;
                $row['infra_status'] = $em ? ($em['infra_status'] ?? null) : null;
                $row['eval_completed'] = $em && !empty($em['last_completed_at']) ? iso_ts($em['last_completed_at']) : null;
                $row['eval_success_at'] = $em && !empty($em['last_successful_check_at']) ? iso_ts($em['last_successful_check_at']) : null;
                $row['eval_changed_at'] = $em && !empty($em['last_status_changed_at']) ? iso_ts($em['last_status_changed_at']) : null;
                $row['auth_status'] = $em ? ($em['auth_status'] ?? null) : null;
                $row['channel_presence'] = $em ? ($em['channel_presence'] ?? 'NOT_CHECKED') : 'NOT_CHECKED';
                $row['channel_verified_at'] = $em && !empty($em['channel_verified_at']) ? iso_ts($em['channel_verified_at']) : null;
                $row['last_known_presence'] = $em ? ($em['last_known_presence'] ?? null) : null;
            }
            unset($row);
            json_out(['ok' => true, 'data' => $profiles]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.status AS proxy_status
                 FROM profiles p
                 LEFT JOIN proxies pr ON pr.id = p.proxy_id
                 WHERE p.id = ?'
            );
            $stmt->execute([$id]);
            $profile = $stmt->fetch();
            $profile ? json_out(['ok' => true, 'data' => $profile])
                     : json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            break;

        case 'add':
            $b = json_body();
            if (empty($b['name'])) json_out(['ok' => false, 'message' => 'Thieu ten profile'], 400);
            $userDir = unique_user_data_dir($b['name']);
            if (!is_dir($userDir)) {
                mkdir($userDir, 0777, true);
            }
            $webrtc = $b['webrtc_protection'] ?? 'default';
            if (!in_array($webrtc, ['default','disable_nonproxied_udp'], true)) $webrtc = 'default';
            $platform = (string)($b['platform'] ?? 'youtube');
            if (!in_array($platform, ['youtube', 'tiktok', 'facebook', 'other'], true)) $platform = 'youtube';
            $stmt = db()->prepare('INSERT INTO profiles (name, platform, channel_handle, user_agent, webrtc_protection, proxy_id, user_data_dir)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                trim($b['name']),
                $platform,
                $b['channel_handle'] ?? null,
                isset($b['user_agent']) && trim((string)$b['user_agent']) !== '' ? trim((string)$b['user_agent']) : null,
                $webrtc,
                resolve_profile_proxy(null, $b, 0),
                $userDir,
            ]);
            $newId = (int)db()->lastInsertId();
            log_action($newId, 'create', 'Tao profile');
            json_out(['ok' => true, 'id' => $newId], 201);
            break;

        // Tao nhieu kenh cung luc. Ten mac dinh: <prefix> STT (STT bat dau tu sau id lon nhat
        // de khong tai dung ten kenh vua xoa), VD: Kênh 52... khi id lon nhat la 51.
        case 'add_bulk':
            $b = json_body();
            $count = max(1, (int)($b['count'] ?? 1));
            $count = min($count, 200);
            $platform = (string)($b['platform'] ?? 'youtube');
            if (!in_array($platform, ['youtube', 'tiktok', 'facebook', 'other'], true)) $platform = 'youtube';
            $proxy_id = !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null;
            $prefix = trim((string)($b['prefix'] ?? ''));
            if ($prefix === '') $prefix = 'Kênh';

            // Tim so thu tu bat dau tu sau id lon nhat (khong dung COUNT de tranh
            // tai dung ten kenh vua xoa), roi bo qua ten nao da ton tai.
            $start = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM profiles')->fetchColumn() + 1;
            $taken = [];
            foreach (db()->query('SELECT name FROM profiles')->fetchAll(PDO::FETCH_COLUMN) as $nm) {
                $taken[$nm] = true;
            }

            $insert = db()->prepare(
                'INSERT INTO profiles (name, platform, channel_handle, proxy_id, user_data_dir)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ids = [];
            $num = $start;
            for ($i = 0; $i < $count; $i++) {
                do {
                    $name = $prefix . ' ' . ($num++);
                } while (isset($taken[$name]));
                $taken[$name] = true;
                $userDir = unique_user_data_dir($name);
                if (!is_dir($userDir)) mkdir($userDir, 0777, true);
                $insert->execute([$name, $platform, null, $proxy_id, $userDir]);
                $ids[] = (int)db()->lastInsertId();
            }
            log_action(null, 'create_bulk', "Tao $count kenh ($prefix)");
            json_out(['ok' => true, 'ids' => $ids, 'count' => $count], 201);
            break;

        // Gan cung 1 proxy cho nhieu kenh da chon.
        case 'assign_proxy_bulk':
            $b = json_body();
            $ids = array_filter(array_map('intval', (array)($b['ids'] ?? [])));
            $proxy_id = !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null;
            if (empty($ids)) json_out(['ok' => false, 'message' => 'Chua chon kenh nao'], 400);
            if ($proxy_id !== null) {
                $chk = db()->prepare('SELECT id FROM proxies WHERE id=?');
                $chk->execute([$proxy_id]);
                if (!$chk->fetch()) json_out(['ok' => false, 'message' => 'Proxy khong ton tai'], 404);
            }
            // Dem kenh BI THAY THE proxy cu (de UI bao ro thay vi "gan")
            $replaced = 0;
            if ($proxy_id !== null) {
                $inIds = implode(',', array_fill(0, count($ids), '?'));
                $qOld = db()->prepare("SELECT COUNT(*) FROM profiles WHERE id IN ($inIds) AND proxy_id IS NOT NULL AND proxy_id <> ?");
                $qOld->execute(array_merge($ids, [$proxy_id]));
                $replaced = (int)$qOld->fetchColumn();
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("UPDATE profiles SET proxy_id=? WHERE id IN ($in)");
            $stmt->execute(array_merge([$proxy_id], $ids));
            json_out(['ok' => true, 'updated' => count($ids), 'replaced' => $replaced]);
            break;

        // Gan danh sach proxy (paste nhieu dong) cho nhieu kenh theo thu tu:
        // kenh 1 <- proxy dong 1, kenh 2 <- proxy dong 2, ... Tu dong tao proxy chua co.
        // $b['protocol']: loai mac dinh cho dong khong ghi prefix (dong co prefix giu theo prefix).
        // Ghi de proxy cu: kenh da co proxy se BI THAY THE (tra ve replaced).
        case 'assign_proxy_list_bulk':
            $b = json_body();
            $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? []))));
            $lines = preg_split('/\r?\n/', trim((string)($b['list'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if (empty($ids)) json_out(['ok' => false, 'message' => 'Chua chon kenh nao'], 400);
            if (empty($lines)) json_out(['ok' => false, 'message' => 'Chua nhap danh sach proxy'], 400);
            $defProto = strtolower(trim((string)($b['protocol'] ?? 'http')));
            if (!in_array($defProto, ['http', 'https', 'socks4', 'socks5', 'ssh'], true)) $defProto = 'http';

            $proxyIds = [];
            foreach ($lines as $line) {
                $proxy = parse_proxy_string(trim($line), $defProto);
                if (!$proxy) continue;
                $proxyIds[] = find_or_create_proxy($proxy);
            }
            if (empty($proxyIds)) json_out(['ok' => false, 'message' => 'Khong co proxy nao hop le trong danh sach'], 400);

            // Nho proxy cu de bao so luong BI THAY THE
            $oldMap = [];
            $inIds = implode(',', array_fill(0, count($ids), '?'));
            $qOld = db()->prepare("SELECT id, proxy_id FROM profiles WHERE id IN ($inIds)");
            $qOld->execute($ids);
            foreach ($qOld->fetchAll() as $r) $oldMap[(int)$r['id']] = $r['proxy_id'] === null ? null : (int)$r['proxy_id'];

            $updated = 0;
            $replaced = 0;
            $update = db()->prepare('UPDATE profiles SET proxy_id=? WHERE id=?');
            foreach ($ids as $i => $profileId) {
                if (!isset($proxyIds[$i])) break; // thieu proxy: cac kenh con lai giu nguyen
                $update->execute([$proxyIds[$i], (int)$profileId]);
                $updated++;
                if (!empty($oldMap[(int)$profileId]) && (int)$oldMap[(int)$profileId] !== (int)$proxyIds[$i]) $replaced++;
            }
            json_out(['ok' => true, 'updated' => $updated, 'replaced' => $replaced, 'proxy_count' => count($proxyIds)]);
            break;

        case 'update':
            $b = json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);

            // Lay gia tri hien tai de giu nguyen khi request khong gui (vd: doi ten, sua handle)
            $hasPlace = false;
            try {
                $hasPlace = (bool)db()->query("SHOW COLUMNS FROM profiles LIKE 'monitor_mode'")->fetch();
            } catch (Throwable $e) {
            }
            $cur = db()->prepare('SELECT name, platform, user_agent, webrtc_protection, proxy_id'
                . ($hasPlace ? ', monitor_mode, fixed_monitor_device' : '') . ' FROM profiles WHERE id = ?');
            $cur->execute([$id]);
            $curRow = $cur->fetch();
            if (!$curRow) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);

            $newName = trim((string)($b['name'] ?? ''));
            if ($newName === '') $newName = (string)$curRow['name']; // khong bao gio luu ten rong
            $newPlatform = (string)($b['platform'] ?? $curRow['platform']);
            if (!in_array($newPlatform, ['youtube', 'tiktok', 'facebook', 'other'], true)) {
                $newPlatform = (string)$curRow['platform'];
            }

            if (array_key_exists('user_agent', $b)) {
                $ua = trim((string)$b['user_agent']) !== '' ? trim((string)$b['user_agent']) : null;
            } else {
                $ua = $curRow['user_agent'];
            }
            $webrtc = array_key_exists('webrtc_protection', $b)
                ? (in_array($b['webrtc_protection'], ['default','disable_nonproxied_udp'], true) ? $b['webrtc_protection'] : 'default')
                : $curRow['webrtc_protection'];
            // Proxy resolve server-side (authoritative). Khong gui gi -> giu proxy cu.
            $proxyId = resolve_profile_proxy($curRow['proxy_id'], $b, $id);

            $monMode = $hasPlace
                ? (isset($b['monitor_mode']) ? strtoupper(trim((string)$b['monitor_mode'])) : (string)($curRow['monitor_mode'] ?? 'LAST'))
                : 'LAST';
            if (!in_array($monMode, ['LAST', 'FIXED', 'AUTO'], true)) $monMode = 'LAST';
            $fixedMon = $hasPlace
                ? trim((string)($b['fixed_monitor_device'] ?? ($curRow['fixed_monitor_device'] ?? '')))
                : '';
            if ($hasPlace) {
                $stmt = db()->prepare('UPDATE profiles SET name=?, platform=?, channel_handle=?, user_agent=?, webrtc_protection=?, proxy_id=?, monitor_mode=?, fixed_monitor_device=? WHERE id=?');
                $stmt->execute([$newName, $newPlatform, $b['channel_handle'] ?? null, $ua, $webrtc, $proxyId, $monMode, $fixedMon, $id]);
            } else {
                $stmt = db()->prepare('UPDATE profiles SET name=?, platform=?, channel_handle=?, user_agent=?, webrtc_protection=?, proxy_id=? WHERE id=?');
                $stmt->execute([$newName, $newPlatform, $b['channel_handle'] ?? null, $ua, $webrtc, $proxyId, $id]);
            }
            json_out(['ok' => true]);
            break;

        // Luu placement hien tai (user keo tay xong bam luu / Stop tu luu)
        case 'save_placement':
            $b = json_body();
            $pid = (int)($b['id'] ?? $_GET['id'] ?? 0);
            if ($pid <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $st = db()->prepare('SELECT * FROM profiles WHERE id=?');
            $st->execute([$pid]);
            $pr = $st->fetch();
            if (!$pr) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            require_once __DIR__ . '/../sync/WindowPlacementManager.php';
            $hwnd = null;
            try {
                foreach (SyncWindowDiscovery::discover(false)['windows'] as $w) {
                    if ($w->profileId !== null && (int)$w->profileId === $pid && $w->class === 'Chrome_WidgetWin_1' && $w->visible) {
                        $hwnd = $w->hwnd;
                        break;
                    }
                }
            } catch (Throwable $e) {
            }
            $ok = WindowPlacementManager::save_window_placement($pr, $hwnd);
            json_out(['ok' => $ok]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.password AS proxy_pass
                 FROM profiles p LEFT JOIN proxies pr ON pr.id = p.proxy_id WHERE p.id = ?'
            );
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            delete_profile($p);
            json_out(['ok' => true, 'message' => 'Da xoa profile ' . $p['name'] . ' va cache']);
            break;

        case 'duplicate':
            $id = (int)($_GET['id'] ?? (json_body()['id'] ?? 0));
            $stmt = db()->prepare('SELECT * FROM profiles WHERE id = ?');
            $stmt->execute([$id]);
            $src = $stmt->fetch();
            if (!$src) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);
            $newName = $src['name'] . ' (copy)';
            $userDir = unique_user_data_dir($newName);
            if (!is_dir($userDir)) mkdir($userDir, 0777, true);
            db()->prepare('INSERT INTO profiles (name, platform, channel_handle, user_agent, webrtc_protection, proxy_id, user_data_dir)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$newName, $src['platform'], $src['channel_handle'], $src['user_agent'], $src['webrtc_protection'] ?? 'default', $src['proxy_id'], $userDir]);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
            break;

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}