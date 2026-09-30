<?php
declare(strict_types=1);
/**
 * VideoLibraryService - Thu vien video chung: import file/folder/CSV,
 * checksum SHA-256 dedupe, file-stability gate, folder watcher scan.
 * Khong xoa source (KEEP default).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UploadStore.php';
require_once __DIR__ . '/SyncLogger.php';

class VideoLibraryService
{
    public const STABLE_SEC = 20;

    public static function checksum(string $path): ?string
    {
        try {
            $ctx = hash_init('sha256');
            $fh = @fopen($path, 'rb');
            if (!$fh) return null;
            while (!feof($fh)) {
                $c = fread($fh, 1048576);
                if ($c === false) break;
                hash_update($ctx, $c);
            }
            fclose($fh);
            return hash_final($ctx);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function isVideoFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, UploadStore::ASSET_EXTS, true);
    }

    /** File on dinh (size khong doi) trong STABLE_SEC — tranh half-copied. */
    public static function fileStable(string $path, int $stableSec = self::STABLE_SEC): bool
    {
        if (!is_file($path)) return false;
        $s1 = filesize($path);
        if ($s1 === false || $s1 <= 0) return false;
        sleep(min(3, $stableSec));
        clearstatcache(true, $path);
        $s2 = filesize($path);
        if ($s2 === false || $s2 !== $s1) return false;
        if ($stableSec <= 3) return true;
        sleep(min(5, $stableSec - 3));
        clearstatcache(true, $path);
        return filesize($path) === $s1;
    }

    /**
     * Import 1 file (dedupe checksum). @return array{ok,id?,dedupe?,error?}
     */
    public static function importFile(string $path, string $sourceType = 'MANUAL', bool $requireStable = true): array
    {
        UploadStore::ensureSchema();
        if (!is_file($path)) return ['ok' => false, 'error' => 'FILE_MISSING'];
        if (!self::isVideoFile($path)) return ['ok' => false, 'error' => 'UNSUPPORTED_FORMAT'];
        $size = filesize($path);
        if ($size === false || $size <= 0) return ['ok' => false, 'error' => 'EMPTY_FILE'];
        if ($size > UploadStore::MAX_FILE_BYTES) return ['ok' => false, 'error' => 'FILE_TOO_LARGE'];
        if ($requireStable && !self::fileStable($path)) {
            return ['ok' => false, 'error' => 'FILE_CHANGING'];
        }
        $sum = self::checksum($path);
        if ($sum === null) return ['ok' => false, 'error' => 'CHECKSUM_FAILED'];
        try {
            $st = db()->prepare('SELECT id, status FROM video_assets WHERE checksum=?');
            $st->execute([$sum]);
            if ($ex = $st->fetch()) {
                return ['ok' => true, 'id' => (int)$ex['id'], 'dedupe' => true];
            }
            $real = realpath($path) ?: $path;
            db()->prepare("INSERT INTO video_assets (source_type, source_path, filename, extension, size_bytes, checksum, status)
                VALUES (?,?,?,?,?,?, 'NEW')")
                ->execute([$sourceType, mb_substr($real, 0, 1000),
                    mb_substr(basename($path), 0, 255),
                    strtolower(pathinfo($path, PATHINFO_EXTENSION)), $size, $sum]);
            $id = (int)db()->lastInsertId();
            db()->prepare("UPDATE video_assets SET status='READY' WHERE id=?")->execute([$id]);
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            // Race duplicate checksum -> lay row co san
            try {
                $st = db()->prepare('SELECT id FROM video_assets WHERE checksum=?');
                $st->execute([$sum]);
                if ($ex = $st->fetchColumn()) return ['ok' => true, 'id' => (int)$ex, 'dedupe' => true];
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /**
     * Import folder (de quy tuy chon). @return array{scanned,imported,duplicate,skipped,errors[]}
     */
    public static function importFolder(string $path, bool $recursive = true, bool $skipImported = true,
        ?int $channelId = null, bool $autoAssign = false, bool $requireStable = true): array
    {
        UploadStore::ensureSchema();
        $out = ['scanned' => 0, 'imported' => 0, 'duplicate' => 0, 'skipped' => 0, 'errors' => []];
        if (!is_dir($path)) {
            $out['errors'][] = 'FOLDER_MISSING';
            return $out;
        }
        try {
            $it = $recursive
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
                : new DirectoryIterator($path);
            foreach ($it as $f) {
                if ($f->isDir()) continue;
                $p = $f->getPathname();
                if (!self::isVideoFile($p)) continue;
                $out['scanned']++;
                if ($skipImported) {
                    try {
                        $sum0 = self::checksum($p);
                        if ($sum0 !== null) {
                            $st = db()->prepare('SELECT id FROM video_assets WHERE checksum=?');
                            $st->execute([$sum0]);
                            $aid = $st->fetchColumn();
                            if ($aid) {
                                $out['duplicate']++;
                                if ($autoAssign && $channelId) {
                                    require_once __DIR__ . '/ChannelQueueService.php';
                                    ChannelQueueService::assign($channelId, [(int)$aid]);
                                }
                                continue;
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
                $r = self::importFile($p, 'FOLDER_IMPORT', $requireStable);
                if (!empty($r['ok'])) {
                    if (!empty($r['dedupe'])) $out['duplicate']++;
                    else $out['imported']++;
                    if ($autoAssign && $channelId && !empty($r['id'])) {
                        require_once __DIR__ . '/ChannelQueueService.php';
                        ChannelQueueService::assign($channelId, [(int)$r['id']]);
                    }
                } else {
                    if (($r['error'] ?? '') === 'FILE_CHANGING') $out['skipped']++;
                    else $out['errors'][] = basename($p) . ':' . ($r['error'] ?? '?');
                }
            }
        } catch (Throwable $e) {
            $out['errors'][] = 'scan_error';
        }
        try {
            db()->prepare('INSERT INTO video_source_folders (path, include_subdirs, skip_imported, last_scan_at)
                    VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE include_subdirs=VALUES(include_subdirs),
                    skip_imported=VALUES(skip_imported), last_scan_at=NOW()')
                ->execute([mb_substr(realpath($path) ?: $path, 0, 1000), $recursive ? 1 : 0, $skipImported ? 1 : 0]);
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Import CSV: file,title,description,tags,channel,publish_date,publish_time,thumbnail.
     * @return array{rows,valid,missing_files,invalid_channels,duplicate,errors[]}
     */
    public static function importCsv(string $text, bool $enqueue = true, bool $requireStable = true): array
    {
        UploadStore::ensureSchema();
        $out = ['rows' => 0, 'valid' => 0, 'missing_files' => 0, 'invalid_channels' => 0, 'duplicate' => 0, 'errors' => []];
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        if (!$lines) return $out;
        $header = array_map(fn($s) => strtolower(trim((string)$s)), str_getcsv(array_shift($lines)));
        if (!in_array('file', $header, true)) {
            $out['errors'][] = 'missing_file_column';
            return $out;
        }
        require_once __DIR__ . '/ChannelQueueService.php';
        foreach ($lines as $ln => $line) {
            if (trim($line) === '') continue;
            $out['rows']++;
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $h) $row[$h] = trim((string)($cols[$i] ?? ''));
            if (empty($row['file']) || !is_file($row['file'])) {
                $out['missing_files']++;
                continue;
            }
            $pid = 0;
            if (!empty($row['channel'])) {
                try {
                    if (ctype_digit($row['channel'])) {
                        $st = db()->prepare('SELECT id FROM profiles WHERE id=?');
                        $st->execute([(int)$row['channel']]);
                        $found = $st->fetchColumn();
                        $pid = $found ? (int)$found : 0;
                    } else {
                        $st = db()->prepare('SELECT id FROM profiles WHERE name=?');
                        $st->execute([$row['channel']]);
                        $found = $st->fetchColumn();
                        $pid = $found ? (int)$found : 0;
                    }
                } catch (Throwable $e) {
                }
                if (!$pid) {
                    $out['invalid_channels']++;
                    continue;
                }
            }
            $r = self::importFile($row['file'], 'MANUAL', $requireStable);
            if (empty($r['ok'])) {
                $out['errors'][] = basename($row['file']) . ':' . ($r['error'] ?? '?');
                continue;
            }
            if (!empty($r['dedupe'])) $out['duplicate']++;
            else $out['valid']++;
            if ($enqueue && $pid && !empty($r['id'])) {
                $meta = [];
                foreach (['title' => 'title', 'description' => 'description', 'tags' => 'tags',
                             'publish_date' => 'publish_date', 'publish_time' => 'publish_time',
                             'thumbnail' => 'thumbnail'] as $k => $ck) {
                    if (!empty($row[$ck])) $meta[$k] = $row[$ck];
                }
                ChannelQueueService::assign($pid, [(int)$r['id']], $meta ?: null);
            }
        }
        return $out;
    }

    /** Quet cac folder dang watch (debounced): file moi on dinh -> import (+auto assign dedicated). */
    public static function scanWatchedFolders(): array
    {
        UploadStore::ensureSchema();
        $out = ['folders' => 0, 'imported' => 0, 'duplicate' => 0, 'skipped' => 0];
        try {
            $folders = db()->query('SELECT * FROM video_source_folders WHERE watch_enabled=1')->fetchAll();
        } catch (Throwable $e) {
            return $out;
        }
        // Dedicated channel folders
        try {
            $cfgs = db()->query("SELECT profile_id, folder_path FROM channel_upload_configs
                WHERE upload_enabled=1 AND content_source='DEDICATED_FOLDER' AND folder_path<>''")->fetchAll();
        } catch (Throwable $e) {
            $cfgs = [];
        }
        $dedicated = [];
        foreach ($cfgs as $c) {
            $dedicated[mb_strtolower(trim((string)$c['folder_path']))] = (int)$c['profile_id'];
        }
        foreach ($folders as $f) {
            $out['folders']++;
            $key = mb_strtolower(trim((string)$f['path']));
            $r = self::importFolder((string)$f['path'], !empty($f['include_subdirs']), true,
                $dedicated[$key] ?? null, isset($dedicated[$key]));
            $out['imported'] += $r['imported'];
            $out['duplicate'] += $r['duplicate'];
            $out['skipped'] += $r['skipped'];
        }
        return $out;
    }

    public static function list(array $filter = [], int $page = 1, int $per = 20): array
    {
        UploadStore::ensureSchema();
        $page = max(1, $page);
        $per = max(5, min(100, $per));
        $w = [];
        $p = [];
        if (!empty($filter['status'])) {
            $w[] = 'a.status=?';
            $p[] = (string)$filter['status'];
        }
        if (!empty($filter['q'])) {
            $w[] = 'a.filename LIKE ?';
            $p[] = '%' . (string)$filter['q'] . '%';
        }
        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM video_assets a $where");
            $st->execute($p);
            $total = (int)$st->fetchColumn();
            $off = ($page - 1) * $per;
            $st = db()->prepare("SELECT a.*, (SELECT GROUP_CONCAT(q.profile_id) FROM channel_video_queue q
                    WHERE q.video_asset_id=a.id AND q.status NOT IN ('CONSUMED')) AS queued_for
                FROM video_assets a $where ORDER BY a.id DESC LIMIT $per OFFSET $off");
            $st->execute($p);
            return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'per' => $per];
        } catch (Throwable $e) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per' => $per];
        }
    }
}
