<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $profiles = db()->query(
                'SELECT p.*, pr.host AS proxy_host, pr.port AS proxy_port, pr.protocol AS proxy_protocol,
                        pr.username AS proxy_user, pr.status AS proxy_status
                 FROM profiles p
                 LEFT JOIN proxies pr ON pr.id = p.proxy_id
                 ORDER BY p.id DESC'
            )->fetchAll();
            // Dong bo trang thai voi thuc te (Chrome bi tat/ngat tay thi cap nhat lai)
            foreach ($profiles as &$row) {
                $row['status'] = refresh_profile_status($row);
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
            if (!in_array($webrtc, ['default','disable_nonproxied_udp','disabled'], true)) $webrtc = 'default';
            $stmt = db()->prepare('INSERT INTO profiles (name, platform, channel_handle, user_agent, webrtc_protection, proxy_id, user_data_dir)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                trim($b['name']),
                $b['platform'] ?? 'youtube',
                $b['channel_handle'] ?? null,
                isset($b['user_agent']) && trim((string)$b['user_agent']) !== '' ? trim((string)$b['user_agent']) : null,
                $webrtc,
                !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null,
                $userDir,
            ]);
            $newId = (int)db()->lastInsertId();
            log_action($newId, 'create', 'Tao profile');
            json_out(['ok' => true, 'id' => $newId], 201);
            break;

        // Tao nhieu kenh cung luc. Ten mac dinh: <prefix> STT (STT bat dau tu so thu tu tiep theo
        // cua tong so kenh dang co), VD: Kênh 4, Kênh 5... khi da co 3 kenh.
        case 'add_bulk':
            $b = json_body();
            $count = max(1, (int)($b['count'] ?? 1));
            $count = min($count, 200);
            $platform = $b['platform'] ?? 'youtube';
            $proxy_id = !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null;
            $prefix = trim((string)($b['prefix'] ?? ''));
            if ($prefix === '') $prefix = 'Kênh';

            // Tim so thu tu bat dau de khong trung ten voi kenh da co.
            $start = 1;
            $existing = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
            if ($existing > 0) {
                $start = $existing + 1;
            }

            $insert = db()->prepare(
                'INSERT INTO profiles (name, platform, channel_handle, proxy_id, user_data_dir)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $num = $start + $i;
                $name = $prefix . ' ' . $num;
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
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("UPDATE profiles SET proxy_id=? WHERE id IN ($in)");
            $stmt->execute(array_merge([$proxy_id], $ids));
            json_out(['ok' => true, 'updated' => count($ids)]);
            break;

        // Gan danh sach proxy (paste nhieu dong) cho nhieu kenh theo thu tu:
        // kenh 1 <- proxy dong 1, kenh 2 <- proxy dong 2, ... Tu dong tao proxy chua co.
        case 'assign_proxy_list_bulk':
            $b = json_body();
            $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? []))));
            $lines = preg_split('/\r?\n/', trim((string)($b['list'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if (empty($ids)) json_out(['ok' => false, 'message' => 'Chua chon kenh nao'], 400);
            if (empty($lines)) json_out(['ok' => false, 'message' => 'Chua nhap danh sach proxy'], 400);

            $proxyIds = [];
            foreach ($lines as $line) {
                $proxy = parse_proxy_string(trim($line));
                if (!$proxy) continue;
                $proxyIds[] = find_or_create_proxy($proxy);
            }
            if (empty($proxyIds)) json_out(['ok' => false, 'message' => 'Khong co proxy nao hop le trong danh sach'], 400);

            $updated = 0;
            $update = db()->prepare('UPDATE profiles SET proxy_id=? WHERE id=?');
            foreach ($ids as $i => $profileId) {
                if (!isset($proxyIds[$i])) break; // thieu proxy: cac kenh con lai giu nguyen
                $update->execute([$proxyIds[$i], (int)$profileId]);
                $updated++;
            }
            json_out(['ok' => true, 'updated' => $updated, 'proxy_count' => count($proxyIds)]);
            break;

        case 'update':
            $b = json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);

            // Lay gia tri hien tai de giu nguyen UA/WebRTC khi request khong gui (vd: doi ten, sua handle)
            $cur = db()->prepare('SELECT user_agent, webrtc_protection FROM profiles WHERE id = ?');
            $cur->execute([$id]);
            $curRow = $cur->fetch();
            if (!$curRow) json_out(['ok' => false, 'message' => 'Khong tim thay profile'], 404);

            if (array_key_exists('user_agent', $b)) {
                $ua = trim((string)$b['user_agent']) !== '' ? trim((string)$b['user_agent']) : null;
            } else {
                $ua = $curRow['user_agent'];
            }
            $webrtc = array_key_exists('webrtc_protection', $b)
                ? (in_array($b['webrtc_protection'], ['default','disable_nonproxied_udp','disabled'], true) ? $b['webrtc_protection'] : 'default')
                : $curRow['webrtc_protection'];

            $stmt = db()->prepare('UPDATE profiles SET name=?, platform=?, channel_handle=?, user_agent=?, webrtc_protection=?, proxy_id=? WHERE id=?');
            $stmt->execute([
                trim($b['name'] ?? ''),
                $b['platform'] ?? 'youtube',
                $b['channel_handle'] ?? null,
                $ua,
                $webrtc,
                !empty($b['proxy_id']) ? (int)$b['proxy_id'] : null,
                $id,
            ]);
            json_out(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? (json_body()['id'] ?? 0));
            db()->prepare('DELETE FROM profiles WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
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