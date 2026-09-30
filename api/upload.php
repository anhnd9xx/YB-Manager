<?php
declare(strict_types=1);
/**
 * api/upload.php - YouTube Upload Manager V1 endpoints.
 * library | import_folder | import_csv | folders | queue | queue_assign |
 * queue_reorder | queue_remove | config | config_bulk | slots | uploads |
 * schedules | templates | template_save | credential | auth_status |
 * health | kpis | dryrun | run_once | pause | retry_failed | upcoming | today
 */
require_once __DIR__ . '/../sync/UploadStore.php';
require_once __DIR__ . '/../sync/VideoLibraryService.php';
require_once __DIR__ . '/../sync/ChannelQueueService.php';
require_once __DIR__ . '/../sync/UploadPlanner.php';
require_once __DIR__ . '/../sync/PublishScheduler.php';
require_once __DIR__ . '/../sync/UploadHealthService.php';
require_once __DIR__ . '/../sync/JobManager.php';
require_once __DIR__ . '/../sync/SyncLogger.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'kpis';

try {
    switch ($action) {
        case 'kpis':
            json_out(['ok' => true, 'data' => PublishScheduler::kpis()]);
            break;

        case 'health':
            json_out(['ok' => true, 'data' => UploadHealthService::snapshot()]);
            break;

        case 'library': {
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => VideoLibraryService::list(
                ['status' => (string)($b['status'] ?? ''), 'q' => (string)($b['q'] ?? '')],
                (int)($b['page'] ?? 1), (int)($b['per'] ?? 20))]);
            break;
        }

        case 'import_folder': {
            $b = $method === 'GET' ? $_GET : json_body();
            $path = trim((string)($b['path'] ?? ''));
            if ($path === '') json_out(['ok' => false, 'message' => 'Thieu path'], 400);
            $r = VideoLibraryService::importFolder($path,
                !array_key_exists('recursive', $b) || !empty($b['recursive']),
                !array_key_exists('skip_imported', $b) || !empty($b['skip_imported']),
                isset($b['channel_id']) ? (int)$b['channel_id'] : null,
                !empty($b['auto_assign']) && isset($b['channel_id']));
            json_out(['ok' => true, 'data' => $r]);
            break;
        }

        case 'import_csv': {
            $b = $method === 'GET' ? $_GET : json_body();
            $text = (string)($b['text'] ?? '');
            if (trim($text) === '') json_out(['ok' => false, 'message' => 'Trống'], 400);
            json_out(['ok' => true, 'data' => VideoLibraryService::importCsv($text)]);
            break;
        }

        case 'folders': {
            UploadStore::ensureSchema();
            $b = $method === 'GET' ? $_GET : json_body();
            if (isset($b['watch']) && isset($b['path'])) {
                db()->prepare('INSERT INTO video_source_folders (path, watch_enabled, last_scan_at)
                        VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE watch_enabled=VALUES(watch_enabled)')
                    ->execute([mb_substr(trim((string)$b['path']), 0, 1000), !empty($b['watch']) ? 1 : 0]);
            }
            json_out(['ok' => true, 'data' => db()->query('SELECT * FROM video_source_folders ORDER BY id DESC')->fetchAll()]);
            break;
        }

        case 'queue': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $b = $method === 'GET' ? $_GET : json_body();
            json_out(['ok' => true, 'data' => [
                'counts' => ChannelQueueService::counts($id),
                'list' => ChannelQueueService::list($id, (int)($b['page'] ?? 1), (int)($b['per'] ?? 20)),
                'config' => UploadStore::getConfig($id)]]);
            break;
        }

        case 'queue_assign': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            $assets = array_map('intval', (array)($b['asset_ids'] ?? []));
            if ($id <= 0 || !$assets) json_out(['ok' => false, 'message' => 'Thieu id/assets'], 400);
            json_out(['ok' => true, 'data' => ChannelQueueService::assign($id, $assets)]);
            break;
        }

        case 'queue_reorder': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            json_out(ChannelQueueService::reorder($id, array_map('intval', (array)($b['queue_ids'] ?? []))));
            break;
        }

        case 'queue_remove': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            json_out(['ok' => true, 'data' => ['removed' => ChannelQueueService::remove($id, array_map('intval', (array)($b['queue_ids'] ?? [])))]]);
            break;
        }

        case 'queue_skip': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            json_out(['ok' => true, 'data' => ['skipped' => ChannelQueueService::skip($id, array_map('intval', (array)($b['queue_ids'] ?? [])))]]);
            break;
        }

        case 'config': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            json_out(['ok' => true, 'data' => UploadStore::getConfig($id)]);
            break;
        }

        case 'config_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $r = UploadStore::saveConfig($id, $b);
            if (!$r['ok']) json_out(['ok' => false, 'message' => 'Loi luu'], 500);
            // Doi config -> refresh slots tuong lai chua gan upload (khong doi tick)
            try {
                UploadPlanner::refreshSlots($id);
            } catch (Throwable $e) {
            }
            json_out(['ok' => true, 'data' => UploadStore::getConfig($id), 'warnings' => $r['errors']]);
            break;
        }

        case 'config_bulk': {
            $b = $method === 'GET' ? $_GET : json_body();
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['ids'] ?? [])))));
            if (!$ids) json_out(['ok' => false, 'message' => 'Chua chon kenh'], 400);
            $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
            $out = UploadStore::bulkSave($ids, $patch);
            foreach ($ids as $pid) {
                try {
                    UploadPlanner::refreshSlots($pid);
                } catch (Throwable $e) {
                }
            }
            json_out(['ok' => true, 'data' => $out]);
            break;
        }

        case 'slots': {
            $id = (int)($_GET['id'] ?? 0);
            UploadStore::ensureSchema();
            try {
                if ($id > 0) {
                    $st = db()->prepare('SELECT * FROM video_publish_schedules WHERE profile_id=? AND publish_at>=DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY publish_at ASC LIMIT 60');
                    $st->execute([$id]);
                    json_out(['ok' => true, 'data' => $st->fetchAll()]);
                }
                $rows = db()->query('SELECT * FROM video_publish_schedules WHERE publish_at>=DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY publish_at ASC LIMIT 200')->fetchAll();
                json_out(['ok' => true, 'data' => $rows]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Loi'], 500);
            }
            break;
        }

        case 'uploads': {
            UploadStore::ensureSchema();
            $b = $method === 'GET' ? $_GET : json_body();
            $page = max(1, (int)($b['page'] ?? 1));
            $per = max(5, min(100, (int)($b['per'] ?? 20)));
            $id = (int)($b['id'] ?? ($_GET['id'] ?? 0));
            try {
                $w = $id > 0 ? 'WHERE u.profile_id=' . $id : '';
                $total = (int)db()->query("SELECT COUNT(*) FROM video_uploads u $w")->fetchColumn();
                $off = ($page - 1) * $per;
                $rows = db()->query("SELECT u.*, a.filename FROM video_uploads u
                    LEFT JOIN video_assets a ON a.id=u.video_asset_id $w
                    ORDER BY u.id DESC LIMIT $per OFFSET $off")->fetchAll();
                json_out(['ok' => true, 'data' => ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Loi'], 500);
            }
            break;
        }

        case 'templates':
            json_out(['ok' => true, 'data' => UploadStore::templates()]);
            break;

        case 'template_save': {
            $b = $method === 'GET' ? $_GET : json_body();
            $r = UploadStore::saveTemplate(isset($b['id']) ? (int)$b['id'] : null, $b);
            json_out($r['ok'] ? ['ok' => true, 'data' => ['id' => $r['id']]] : ['ok' => false, 'message' => 'Loi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'credential': {
            // Luu OAuth ref (khong log secret). GET ?id= -> trang thai (khong secret)
            if ($method === 'GET' && empty($_GET['youtube_channel_id'])) {
                $id = (int)($_GET['id'] ?? 0);
                json_out(['ok' => true, 'data' => UploadStore::getCredential($id)]);
            }
            $b = json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $ref = ['client_id' => (string)($b['client_id'] ?? ''),
                'client_secret' => (string)($b['client_secret'] ?? ''),
                'refresh_token' => (string)($b['refresh_token'] ?? '')];
            if ($ref['refresh_token'] === '') json_out(['ok' => false, 'message' => 'Thieu refresh_token'], 400);
            $r = UploadStore::saveCredential($id, (string)($b['youtube_channel_id'] ?? ''), $ref);
            json_out($r['ok'] ? ['ok' => true] : ['ok' => false, 'message' => 'Loi'], $r['ok'] ? 200 : 400);
            break;
        }

        case 'auth_status': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $cfg = UploadStore::getConfig($id);
            if (strtoupper((string)$cfg['upload_provider']) !== 'YOUTUBE_API') {
                json_out(['ok' => true, 'data' => ['auth_status' => 'SIMULATED', 'provider' => 'SIMULATED']]);
            }
            require_once __DIR__ . '/../sync/UploadProvider.php';
            $r = YouTubeApiProvider::make('YOUTUBE_API')->validateAuth($id);
            $cur = UploadStore::getCredential($id);
            json_out(['ok' => $r['ok'], 'data' => ['auth_status' => $cur['auth_status'], 'error' => $r['error'] ?? null]]);
            break;
        }

        case 'dryrun': {
            // Chay thu lich: khong upload that. Preview next video + upload/publish plan.
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $cfg = UploadStore::getConfig($id);
            UploadPlanner::ensureSlots($id);
            try {
                $st = db()->prepare('SELECT * FROM video_publish_schedules WHERE profile_id=? AND status=? AND publish_at>NOW() ORDER BY publish_at ASC LIMIT 1');
                $st->execute([$id, 'PLANNED']);
                $slot = $st->fetch() ?: null;
            } catch (Throwable $e) {
                $slot = null;
            }
            $counts = ChannelQueueService::counts($id);
            // Next video KHONG claim (chi doc): lay READY dau theo mode
            $nextFile = null;
            try {
                $order = $cfg['queue_mode'] === 'FILENAME_ASC' ? 'a.filename ASC' : ($cfg['queue_mode'] === 'CREATED_TIME' ? 'a.created_at ASC' : 'q.queue_position ASC');
                $st = db()->prepare("SELECT a.filename FROM channel_video_queue q JOIN video_assets a ON a.id=q.video_asset_id
                    WHERE q.profile_id=? AND q.status='READY' ORDER BY $order LIMIT 1");
                $st->execute([$id]);
                $nextFile = $st->fetchColumn() ?: null;
            } catch (Throwable $e) {
            }
            $upAt = null;
            if ($slot) {
                $upAt = date('Y-m-d H:i:s', strtotime((string)$slot['publish_at']) - max(15, (int)$cfg['pre_upload_minutes']) * 60);
            }
            json_out(['ok' => true, 'data' => [
                'next_file' => $nextFile, 'queue_ready' => $counts['ready'],
                'upload_planned' => $upAt, 'publish' => $slot['publish_at'] ?? null,
                'provider' => (string)$cfg['upload_provider']]]);
            break;
        }

        case 'run_once': {
            // Len ke hoach ngay 1 upload cho kenh (dung executor that qua JobManager)
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            if (UploadPlanner::uploadBusy($id)) {
                json_out(['ok' => false, 'message' => 'Kenh dang co upload active'], 409);
            }
            $cfg = UploadStore::getConfig($id);
            $item = ChannelQueueService::next($id, (string)$cfg['queue_mode'], 'manual');
            if (!$item) json_out(['ok' => false, 'message' => 'Queue het video READY'], 400);
            $pre = UploadPlanner::preflight($id, ['source_path' => $item['source_path'], 'checksum' => $item['checksum']]);
            if (!$pre['ok']) {
                ChannelQueueService::release((int)$item['id']);
                json_out(['ok' => false, 'message' => 'Preflight: ' . implode(',', $pre['errors'])], 400);
            }
            require_once __DIR__ . '/../sync/JobManager.php';
            db()->prepare('INSERT INTO video_uploads (profile_id, video_asset_id, queue_id, provider, status, file_checksum)
                VALUES (?,?,?,?,\'QUEUED\',?)')
                ->execute([$id, (int)$item['video_asset_id'], (int)$item['id'],
                    strtoupper((string)$cfg['upload_provider']), (string)$item['checksum']]);
            $uid = (int)db()->lastInsertId();
            $job = JobManager::create('UPLOAD', 'Manual upload #' . $uid . ' ch=' . $id, [$id], 'UI',
                ['job_type' => 'YOUTUBE_UPLOAD', 'created_by' => 'run_once',
                    'notify_on_complete' => 1, 'notify_on_failure' => 1, 'resumable' => 1,
                    'command_id' => 'upl-' . $uid]);
            db()->prepare('UPDATE video_uploads SET job_id=? WHERE id=?')->execute([$job['job_id'], $uid]);
            JobManager::ensureWorker();
            json_out(['ok' => true, 'data' => ['upload_id' => $uid, 'job_id' => $job['job_id']]]);
            break;
        }

        case 'pause': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            if ($id <= 0) json_out(['ok' => false, 'message' => 'Thieu id'], 400);
            $mode = (string)($b['mode'] ?? 'off');
            $until = null;
            if ($mode === '1d') $until = date('Y-m-d H:i:s', time() + 86400);
            elseif ($mode === 'until' && !empty($b['until'])) $until = date('Y-m-d H:i:s', strtotime((string)$b['until']) ?: time());
            elseif ($mode === 'off') $until = null;
            else $until = date('Y-m-d H:i:s', time() + 86400 * 365 * 5); // indefinitely ~ far
            $cfg = UploadStore::getConfig($id);
            $cfg['paused_until'] = $until;
            $r = UploadStore::saveConfig($id, $cfg);
            json_out(['ok' => $r['ok'], 'data' => ['paused_until' => $until]]);
            break;
        }

        case 'retry_failed': {
            $b = $method === 'GET' ? $_GET : json_body();
            $id = (int)($b['id'] ?? 0);
            UploadStore::ensureSchema();
            try {
                if ($id > 0) {
                    db()->prepare("UPDATE video_uploads SET status='RETRYING', next_attempt_at=NOW(), attempt=0
                        WHERE profile_id=? AND status='FAILED'")->execute([$id]);
                } else {
                    db()->prepare("UPDATE video_uploads SET status='RETRYING', next_attempt_at=NOW(), attempt=0
                        WHERE status='FAILED'")->execute();
                }
                json_out(['ok' => true]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Loi'], 500);
            }
            break;
        }

        case 'today': {
            // Lich hom nay (Daily plan view)
            UploadStore::ensureSchema();
            try {
                $rows = db()->query("SELECT s.*, p.name AS channel_name, a.filename FROM video_publish_schedules s
                    LEFT JOIN profiles p ON p.id=s.profile_id
                    LEFT JOIN video_assets a ON a.id=s.video_asset_id
                    WHERE DATE(s.publish_at)=CURDATE() ORDER BY s.publish_at ASC")->fetchAll();
                json_out(['ok' => true, 'data' => $rows]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Loi'], 500);
            }
            break;
        }

        case 'upcoming': {
            UploadStore::ensureSchema();
            try {
                $rows = db()->query("SELECT s.*, p.name AS channel_name, a.filename, u.status AS upload_status, u.provider_video_id
                    FROM video_publish_schedules s
                    LEFT JOIN profiles p ON p.id=s.profile_id
                    LEFT JOIN video_assets a ON a.id=s.video_asset_id
                    LEFT JOIN video_uploads u ON u.id=s.upload_id
                    WHERE s.publish_at>=NOW() AND s.publish_at<=DATE_ADD(NOW(), INTERVAL 7 DAY)
                    ORDER BY s.publish_at ASC LIMIT 200")->fetchAll();
                json_out(['ok' => true, 'data' => $rows]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'message' => 'Loi'], 500);
            }
            break;
        }

        default:
            json_out(['ok' => false, 'message' => 'Action khong hop le'], 400);
    }
} catch (Throwable $e) {
    SyncLogger::error('api', 'upload exception', null, $e);
    json_out(['ok' => false, 'message' => 'Loi he thong: ' . $e->getMessage()], 500);
}
