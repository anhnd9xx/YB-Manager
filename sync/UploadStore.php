<?php
declare(strict_types=1);
/**
 * UploadStore - Schema + CRUD cho YouTube Upload Manager V1.
 * Tables: video_assets, video_source_folders, channel_upload_configs,
 *   channel_video_queue, video_uploads, video_publish_schedules, metadata_templates,
 *   upload_credentials. Reuse naming convention project (ensure* + migrate columns).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class UploadStore
{
    public const ASSET_EXTS = ['mp4', 'mov', 'mkv', 'webm'];
    public const MAX_FILE_BYTES = 137438953472; // 128GB (YouTube limit tham khao)

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS video_assets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_type VARCHAR(15) NOT NULL DEFAULT 'MANUAL',
                source_path VARCHAR(1000) NOT NULL DEFAULT '',
                filename VARCHAR(255) NOT NULL DEFAULT '',
                extension VARCHAR(10) NOT NULL DEFAULT '',
                size_bytes BIGINT NOT NULL DEFAULT 0,
                checksum VARCHAR(64) NOT NULL DEFAULT '',
                status VARCHAR(15) NOT NULL DEFAULT 'NEW',
                error_code VARCHAR(40) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_asset_checksum (checksum),
                KEY idx_asset_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS video_source_folders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                path VARCHAR(1000) NOT NULL DEFAULT '',
                include_subdirs TINYINT(1) NOT NULL DEFAULT 1,
                watch_enabled TINYINT(1) NOT NULL DEFAULT 0,
                skip_imported TINYINT(1) NOT NULL DEFAULT 1,
                last_scan_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_folder_path (path(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS channel_upload_configs (
                profile_id INT PRIMARY KEY,
                upload_enabled TINYINT(1) NOT NULL DEFAULT 0,
                content_source VARCHAR(20) NOT NULL DEFAULT 'SHARED_LIBRARY',
                folder_path VARCHAR(1000) NOT NULL DEFAULT '',
                queue_mode VARCHAR(15) NOT NULL DEFAULT 'MANUAL',
                upload_provider VARCHAR(20) NOT NULL DEFAULT 'SIMULATED',
                daily_enabled TINYINT(1) NOT NULL DEFAULT 0,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Ho_Chi_Minh',
                videos_per_day INT NOT NULL DEFAULT 1,
                publish_slots TEXT NULL,
                weekdays VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
                pre_upload_minutes INT NOT NULL DEFAULT 180,
                visibility VARCHAR(15) NOT NULL DEFAULT 'SCHEDULED',
                metadata_template_id INT NULL,
                playlist_id VARCHAR(128) NOT NULL DEFAULT '',
                category_id VARCHAR(16) NOT NULL DEFAULT '22',
                language VARCHAR(16) NOT NULL DEFAULT 'vi',
                max_attempts INT NOT NULL DEFAULT 4,
                replacement_policy VARCHAR(15) NOT NULL DEFAULT 'AUTO_REPLACE',
                missed_policy VARCHAR(15) NOT NULL DEFAULT 'SKIP',
                deadline_minutes INT NOT NULL DEFAULT 30,
                queue_low_threshold INT NOT NULL DEFAULT 3,
                paused_until DATETIME NULL,
                config_version INT NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS channel_video_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                video_asset_id INT NOT NULL,
                queue_position INT NOT NULL DEFAULT 0,
                status VARCHAR(15) NOT NULL DEFAULT 'READY',
                reserved_at DATETIME NULL,
                reserved_job VARCHAR(32) NULL,
                consumed_at DATETIME NULL,
                metadata_override TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_queue_asset (profile_id, video_asset_id),
                KEY idx_queue_pick (profile_id, status, queue_position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS video_uploads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                video_asset_id INT NOT NULL,
                queue_id INT NULL,
                job_id VARCHAR(32) NOT NULL DEFAULT '',
                provider VARCHAR(20) NOT NULL DEFAULT 'SIMULATED',
                provider_video_id VARCHAR(128) NOT NULL DEFAULT '',
                upload_session_ref VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(15) NOT NULL DEFAULT 'QUEUED',
                progress TINYINT NOT NULL DEFAULT 0,
                bytes_uploaded BIGINT NOT NULL DEFAULT 0,
                attempt INT NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL,
                error_code VARCHAR(40) NULL,
                error_message VARCHAR(500) NULL,
                file_checksum VARCHAR(64) NOT NULL DEFAULT '',
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_upload_status (status, next_attempt_at),
                KEY idx_upload_channel (profile_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS video_publish_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                video_asset_id INT NULL,
                upload_id INT NULL,
                publish_at DATETIME NOT NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Ho_Chi_Minh',
                status VARCHAR(15) NOT NULL DEFAULT 'PLANNED',
                provider_status VARCHAR(40) NOT NULL DEFAULT '',
                published_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_slot (profile_id, publish_at),
                KEY idx_sched_due (status, publish_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS metadata_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL DEFAULT '',
                title_template VARCHAR(255) NOT NULL DEFAULT '{filename}',
                description_template TEXT NULL,
                tags VARCHAR(500) NOT NULL DEFAULT '',
                category_id VARCHAR(16) NOT NULL DEFAULT '22',
                visibility VARCHAR(15) NOT NULL DEFAULT 'SCHEDULED',
                playlist_id VARCHAR(128) NOT NULL DEFAULT '',
                language VARCHAR(16) NOT NULL DEFAULT 'vi',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS upload_credentials (
                profile_id INT PRIMARY KEY,
                provider VARCHAR(20) NOT NULL DEFAULT 'YOUTUBE_API',
                youtube_channel_id VARCHAR(128) NOT NULL DEFAULT '',
                credential_ref TEXT NULL,
                auth_status VARCHAR(20) NOT NULL DEFAULT 'NOT_CONFIGURED',
                last_auth_check DATETIME NULL,
                last_error VARCHAR(200) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Default template
            try {
                $n = (int)db()->query('SELECT COUNT(*) FROM metadata_templates')->fetchColumn();
                if ($n === 0) {
                    db()->prepare("INSERT INTO metadata_templates (name, title_template, description_template, tags)
                        VALUES ('Default','{filename}','','')")->execute();
                }
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
        }
    }

    // ================= Channel config =================

    public static function defaultConfig(int $profileId): array
    {
        return ['profile_id' => $profileId, 'upload_enabled' => 0,
            'content_source' => 'SHARED_LIBRARY', 'folder_path' => '',
            'queue_mode' => 'MANUAL', 'upload_provider' => 'SIMULATED',
            'daily_enabled' => 0, 'timezone' => 'Asia/Ho_Chi_Minh',
            'videos_per_day' => 1, 'publish_slots' => ['19:30'],
            'weekdays' => '1,2,3,4,5,6,7', 'pre_upload_minutes' => 180,
            'visibility' => 'SCHEDULED', 'metadata_template_id' => null,
            'playlist_id' => '', 'category_id' => '22', 'language' => 'vi',
            'max_attempts' => 4, 'replacement_policy' => 'AUTO_REPLACE',
            'missed_policy' => 'SKIP', 'deadline_minutes' => 30,
            'queue_low_threshold' => 3, 'paused_until' => null, 'config_version' => 1];
    }

    public static function getConfig(int $profileId): array
    {
        self::ensureSchema();
        try {
            $st = db()->prepare('SELECT * FROM channel_upload_configs WHERE profile_id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            if (!$r) return self::defaultConfig($profileId);
            $slots = json_decode((string)($r['publish_slots'] ?? ''), true);
            $r['publish_slots'] = is_array($slots) && $slots ? array_values($slots) : ['19:30'];
            foreach (['upload_enabled', 'daily_enabled', 'videos_per_day', 'pre_upload_minutes',
                         'max_attempts', 'deadline_minutes', 'queue_low_threshold', 'config_version'] as $k) {
                $r[$k] = (int)($r[$k] ?? 0);
            }
            return $r;
        } catch (Throwable $e) {
            return self::defaultConfig($profileId);
        }
    }

    /** @return array{ok, errors[], config_version?} */
    public static function saveConfig(int $profileId, array $in): array
    {
        self::ensureSchema();
        $cur = self::getConfig($profileId);
        $errors = [];
        $cs = strtoupper((string)($in['content_source'] ?? $cur['content_source']));
        if (!in_array($cs, ['SHARED_LIBRARY', 'DEDICATED_FOLDER', 'CUSTOM_QUEUE'], true)) $cs = 'SHARED_LIBRARY';
        $qm = strtoupper((string)($in['queue_mode'] ?? $cur['queue_mode']));
        if (!in_array($qm, ['MANUAL', 'FILENAME_ASC', 'CREATED_TIME', 'RANDOM'], true)) $qm = 'MANUAL';
        $prov = strtoupper((string)($in['upload_provider'] ?? $cur['upload_provider']));
        if (!in_array($prov, ['YOUTUBE_API', 'SIMULATED'], true)) $prov = 'SIMULATED';
        $slots = $in['publish_slots'] ?? $cur['publish_slots'];
        if (is_string($slots)) $slots = explode(',', $slots);
        $clean = [];
        foreach ((array)$slots as $s) {
            $s = trim((string)$s);
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
                $clean[] = sprintf('%02d:%02d', max(0, min(23, (int)$m[1])), max(0, min(59, (int)$m[2])));
            }
        }
        if (!$clean) {
            $clean = ['19:30'];
            $errors[] = 'slots_invalid_default';
        }
        $clean = array_values(array_unique($clean));
        sort($clean);
        $wd = self::cleanWeekdays((string)($in['weekdays'] ?? $cur['weekdays']));
        $vis = strtoupper((string)($in['visibility'] ?? $cur['visibility']));
        if (!in_array($vis, ['PRIVATE', 'UNLISTED', 'PUBLIC', 'SCHEDULED'], true)) $vis = 'SCHEDULED';
        $vpd = max(1, min(10, (int)($in['videos_per_day'] ?? $cur['videos_per_day'])));
        // slots < videos_per_day -> lap lai slots theo thu tu (MULTIPLE_SLOTS hon so slot)
        while (count($clean) < $vpd) $clean = array_merge($clean, $clean);
        $tz = trim((string)($in['timezone'] ?? $cur['timezone'])) ?: 'Asia/Ho_Chi_Minh';
        try {
            new DateTimeZone($tz);
        } catch (Throwable $e) {
            $tz = 'Asia/Ho_Chi_Minh';
            $errors[] = 'timezone_invalid';
        }
        $row = [
            'upload_enabled' => array_key_exists('upload_enabled', $in) ? (!empty($in['upload_enabled']) ? 1 : 0) : (int)$cur['upload_enabled'],
            'content_source' => $cs,
            'folder_path' => rtrim(trim((string)($in['folder_path'] ?? $cur['folder_path'])), '/\\'),
            'queue_mode' => $qm,
            'upload_provider' => $prov,
            'daily_enabled' => array_key_exists('daily_enabled', $in) ? (!empty($in['daily_enabled']) ? 1 : 0) : (int)$cur['daily_enabled'],
            'timezone' => $tz,
            'videos_per_day' => $vpd,
            'publish_slots' => json_encode(array_slice($clean, 0, 10), JSON_UNESCAPED_UNICODE),
            'weekdays' => $wd,
            'pre_upload_minutes' => max(15, min(2880, (int)($in['pre_upload_minutes'] ?? $cur['pre_upload_minutes']))),
            'visibility' => $vis,
            'metadata_template_id' => isset($in['metadata_template_id']) && (int)$in['metadata_template_id'] > 0 ? (int)$in['metadata_template_id'] : null,
            'playlist_id' => mb_substr(trim((string)($in['playlist_id'] ?? $cur['playlist_id'])), 0, 128),
            'category_id' => mb_substr(trim((string)($in['category_id'] ?? $cur['category_id'] ?: '22')), 0, 16),
            'language' => mb_substr(trim((string)($in['language'] ?? $cur['language'] ?: 'vi')), 0, 16),
            'max_attempts' => max(1, min(10, (int)($in['max_attempts'] ?? $cur['max_attempts']))),
            'replacement_policy' => in_array(strtoupper((string)($in['replacement_policy'] ?? $cur['replacement_policy'])), ['AUTO_REPLACE', 'ASK', 'SKIP_SLOT'], true) ? strtoupper((string)($in['replacement_policy'] ?? $cur['replacement_policy'])) : 'AUTO_REPLACE',
            'missed_policy' => in_array(strtoupper((string)($in['missed_policy'] ?? $cur['missed_policy'])), ['SKIP', 'LATE'], true) ? strtoupper((string)($in['missed_policy'] ?? $cur['missed_policy'])) : 'SKIP',
            'deadline_minutes' => max(0, min(720, (int)($in['deadline_minutes'] ?? $cur['deadline_minutes']))),
            'queue_low_threshold' => max(0, min(50, (int)($in['queue_low_threshold'] ?? $cur['queue_low_threshold']))),
            'paused_until' => array_key_exists('paused_until', $in) ? $in['paused_until'] : ($cur['paused_until'] ?? null),
        ];
        try {
            $cols = implode(',', array_keys($row));
            $qs = implode(',', array_fill(0, count($row), '?'));
            $upd = implode(',', array_map(fn($k) => "$k=VALUES($k)", array_keys($row)));
            db()->prepare("INSERT INTO channel_upload_configs (profile_id, $cols)
                VALUES (?, $qs) ON DUPLICATE KEY UPDATE $upd, config_version=config_version+1")
                ->execute(array_merge([$profileId], array_values($row)));
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['db_error']];
        }
        $new = self::getConfig($profileId);
        return ['ok' => true, 'errors' => $errors, 'config_version' => (int)($new['config_version'] ?? 1)];
    }

    private static function cleanWeekdays(string $v): string
    {
        $out = [];
        foreach (explode(',', $v) as $d) {
            $d = (int)trim($d);
            if ($d >= 1 && $d <= 7 && !in_array($d, $out, true)) $out[] = $d;
        }
        if (!$out) $out = [1, 2, 3, 4, 5, 6, 7];
        sort($out);
        return implode(',', $out);
    }

    /** Bulk upsert configs: @return array{requested,created,updated,failed,config_versions} */
    public static function bulkSave(array $ids, array $patch): array
    {
        self::ensureSchema();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $created = 0;
        $updated = 0;
        $failed = [];
        $versions = [];
        foreach ($ids as $pid) {
            try {
                $ex = db()->prepare('SELECT id FROM profiles WHERE id=?');
                $ex->execute([$pid]);
                if (!$ex->fetchColumn()) {
                    $failed[] = ['id' => $pid, 'error' => 'PROFILE_NOT_FOUND'];
                    continue;
                }
                $had = db()->prepare('SELECT profile_id FROM channel_upload_configs WHERE profile_id=?');
                $had->execute([$pid]);
                $isUpdate = (bool)$had->fetchColumn();
                $merged = array_merge(self::getConfig($pid), $patch);
                $r = self::saveConfig($pid, $merged);
                if (empty($r['ok'])) {
                    $failed[] = ['id' => $pid, 'error' => 'SAVE_FAILED'];
                    continue;
                }
                if ($isUpdate) $updated++;
                else $created++;
                $versions[$pid] = (int)($r['config_version'] ?? 1);
            } catch (Throwable $e) {
                $failed[] = ['id' => $pid, 'error' => 'EXCEPTION'];
            }
        }
        return ['requested' => count($ids), 'created' => $created, 'updated' => $updated,
            'failed' => $failed, 'config_versions' => $versions];
    }

    // ================= Metadata templates =================

    public static function templates(): array
    {
        self::ensureSchema();
        try {
            return db()->query('SELECT * FROM metadata_templates ORDER BY id')->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function saveTemplate(?int $id, array $in): array
    {
        self::ensureSchema();
        $row = ['name' => mb_substr(trim((string)($in['name'] ?? 'Template')), 0, 120),
            'title_template' => mb_substr(trim((string)($in['title_template'] ?? '{filename}')), 0, 255) ?: '{filename}',
            'description_template' => (string)($in['description_template'] ?? ''),
            'tags' => mb_substr(trim((string)($in['tags'] ?? '')), 0, 500),
            'category_id' => mb_substr(trim((string)($in['category_id'] ?? '22')), 0, 16),
            'visibility' => in_array(strtoupper((string)($in['visibility'] ?? 'SCHEDULED')), ['PRIVATE', 'UNLISTED', 'PUBLIC', 'SCHEDULED'], true) ? strtoupper((string)($in['visibility'] ?? 'SCHEDULED')) : 'SCHEDULED',
            'playlist_id' => mb_substr(trim((string)($in['playlist_id'] ?? '')), 0, 128),
            'language' => mb_substr(trim((string)($in['language'] ?? 'vi')), 0, 16)];
        try {
            if ($id > 0) {
                db()->prepare('UPDATE metadata_templates SET name=?, title_template=?, description_template=?,
                    tags=?, category_id=?, visibility=?, playlist_id=?, language=? WHERE id=?')
                    ->execute([$row['name'], $row['title_template'], $row['description_template'],
                        $row['tags'], $row['category_id'], $row['visibility'], $row['playlist_id'], $row['language'], $id]);
                return ['ok' => true, 'id' => $id];
            }
            db()->prepare('INSERT INTO metadata_templates (name, title_template, description_template, tags, category_id, visibility, playlist_id, language)
                VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$row['name'], $row['title_template'], $row['description_template'],
                    $row['tags'], $row['category_id'], $row['visibility'], $row['playlist_id'], $row['language']]);
            return ['ok' => true, 'id' => (int)db()->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /** Render template variables (khong sinh spam). */
    public static function renderMetadata(array $tpl, array $ctx): array
    {
        $vars = [
            '{filename}' => (string)($ctx['filename'] ?? ''),
            '{channel_name}' => (string)($ctx['channel_name'] ?? ''),
            '{date}' => (string)($ctx['date'] ?? date('Y-m-d')),
            '{index}' => (string)($ctx['index'] ?? ''),
        ];
        $rep = fn($s) => trim((string)strtr((string)$s, $vars));
        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($tpl['tags'] ?? '')))));
        return ['title' => mb_substr($rep($tpl['title_template'] ?? '{filename}') ?: (string)($ctx['filename'] ?? 'Untitled'), 0, 100),
            'description' => $rep($tpl['description_template'] ?? ''),
            'tags' => array_slice($tags, 0, 30),
            'category_id' => (string)($tpl['category_id'] ?? '22'),
            'visibility' => (string)($tpl['visibility'] ?? 'SCHEDULED'),
            'playlist_id' => (string)($tpl['playlist_id'] ?? ''),
            'language' => (string)($tpl['language'] ?? 'vi')];
    }

    // ================= Credentials (khong log secret) =================

    public static function getCredential(int $profileId): array
    {
        self::ensureSchema();
        try {
            $st = db()->prepare('SELECT profile_id, provider, youtube_channel_id, auth_status, last_auth_check, last_error FROM upload_credentials WHERE profile_id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            if ($r) return $r;
        } catch (Throwable $e) {
        }
        return ['profile_id' => $profileId, 'provider' => 'YOUTUBE_API',
            'youtube_channel_id' => '', 'auth_status' => 'NOT_CONFIGURED',
            'last_auth_check' => null, 'last_error' => null];
    }

    /** $ref = ['client_id'=>..., 'client_secret'=>..., 'refresh_token'=>...] (luu JSON, khong log). */
    public static function saveCredential(int $profileId, string $youtubeChannelId, array $ref): array
    {
        self::ensureSchema();
        try {
            db()->prepare('INSERT INTO upload_credentials (profile_id, provider, youtube_channel_id, credential_ref, auth_status)
                    VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE youtube_channel_id=VALUES(youtube_channel_id),
                    credential_ref=VALUES(credential_ref), auth_status=VALUES(auth_status), last_error=NULL')
                ->execute([$profileId, 'YOUTUBE_API', mb_substr($youtubeChannelId, 0, 128),
                    json_encode($ref, JSON_UNESCAPED_UNICODE), 'VALID']);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function setAuthStatus(int $profileId, string $status, ?string $error = null): void
    {
        self::ensureSchema();
        if (!in_array($status, ['NOT_CONFIGURED', 'VALID', 'EXPIRED', 'REAUTH_REQUIRED', 'ERROR'], true)) return;
        try {
            db()->prepare('INSERT INTO upload_credentials (profile_id, auth_status, last_auth_check, last_error)
                    VALUES (?,?,NOW(),?) ON DUPLICATE KEY UPDATE auth_status=VALUES(auth_status),
                    last_auth_check=NOW(), last_error=VALUES(last_error)')
                ->execute([$profileId, $status, $error !== null ? mb_substr($error, 0, 200) : null]);
        } catch (Throwable $e) {
        }
    }

    /** Lay refresh token (khong bao gio tra secret ra logs). */
    public static function credentialSecret(int $profileId): ?array
    {
        self::ensureSchema();
        try {
            $st = db()->prepare('SELECT credential_ref FROM upload_credentials WHERE profile_id=?');
            $st->execute([$profileId]);
            $j = json_decode((string)($st->fetchColumn() ?? ''), true);
            if (is_array($j) && !empty($j['refresh_token'])) return $j;
        } catch (Throwable $e) {
        }
        return null;
    }
}
