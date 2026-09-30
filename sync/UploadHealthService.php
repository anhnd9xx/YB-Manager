<?php
declare(strict_types=1);
/**
 * UploadHealthService - Suc khoe Upload Manager: storage, auth, worker, provider.
 * DUNG cho diagnostics + widget, khong tu sua chua.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UploadStore.php';

class UploadHealthService
{
    /** @return array{storage:array, auth:array, worker:array, provider:array} */
    public static function snapshot(): array
    {
        UploadStore::ensureSchema();
        // Storage: folders accessible + disk free + missing files (mau 50 assets moi nhat)
        $storage = ['state' => 'HEALTHY', 'folders_ok' => 0, 'folders_bad' => 0,
            'missing_files' => 0, 'disk_free_gb' => null];
        try {
            $folders = db()->query('SELECT path FROM video_source_folders')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($folders as $p) {
                if (is_dir((string)$p)) $storage['folders_ok']++;
                else $storage['folders_bad']++;
            }
            $rows = db()->query("SELECT source_path FROM video_assets WHERE status NOT IN ('ARCHIVED') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $p) {
                if (!is_file((string)$p)) $storage['missing_files']++;
            }
            $free = @disk_free_space(sys_get_temp_dir());
            if ($free !== false) $storage['disk_free_gb'] = round($free / 1073741824, 1);
            if ($storage['folders_bad'] > 0 || $storage['missing_files'] > 5) $storage['state'] = 'DEGRADED';
        } catch (Throwable $e) {
            $storage['state'] = 'DEGRADED';
        }
        // Auth: dem theo trang thai
        $auth = ['valid' => 0, 'reauth' => 0, 'none' => 0];
        try {
            $rows = db()->query('SELECT auth_status, COUNT(*) c FROM upload_credentials GROUP BY auth_status')->fetchAll();
            foreach ($rows as $r) {
                $s = (string)$r['auth_status'];
                if ($s === 'VALID') $auth['valid'] += (int)$r['c'];
                elseif (in_array($s, ['EXPIRED', 'REAUTH_REQUIRED', 'ERROR'], true)) $auth['reauth'] += (int)$r['c'];
                else $auth['none'] += (int)$r['c'];
            }
        } catch (Throwable $e) {
        }
        // Worker: job_worker + activity daemon song khong
        $worker = ['job_worker' => false, 'scheduler' => false];
        try {
            foreach (['.job_worker.pid' => 'job_worker', '.activity_scheduler.pid' => 'scheduler'] as $f => $k) {
                $pf = __DIR__ . '/../bin/' . $f;
                if (!is_file($pf)) continue;
                $pid = (int)trim((string)@file_get_contents($pf));
                if ($pid <= 0) continue;
                $out = [];
                @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $out);
                foreach ($out as $line) {
                    if (preg_match('/^"([^"]+)","\s*' . $pid . '\b/', trim($line), $m)
                        && stripos($m[1], 'php') !== false) {
                        $worker[$k] = true;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        // Provider: cau hinh OAuth co san khong (can setup that de dung YOUTUBE_API)
        $provider = ['youtube_api_ready' => false];
        try {
            $n = (int)db()->query("SELECT COUNT(*) FROM upload_credentials WHERE auth_status='VALID'")->fetchColumn();
            $provider['youtube_api_ready'] = $n > 0;
        } catch (Throwable $e) {
        }
        return ['storage' => $storage, 'auth' => $auth, 'worker' => $worker, 'provider' => $provider];
    }
}
