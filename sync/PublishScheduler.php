<?php
declare(strict_types=1);
/**
 * PublishScheduler - Tick nhe (goi tu daemon supervised, 60s): sinh slots,
 * plan uploads due, chot publishes toi gio, queue-low alerts, folder watch,
 * daily report. Idempotent — goi 2 lan khong duplicate.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UploadStore.php';
require_once __DIR__ . '/UploadPlanner.php';
require_once __DIR__ . '/ChannelQueueService.php';
require_once __DIR__ . '/SyncLogger.php';

class PublishScheduler
{
    /**
     * @return array{slots:int, planned:int, published:int, alerts:int}
     */
    public static function tick(): array
    {
        static $last = 0;
        // Throttle: khong can chay moi giay (slots theo ngay, lead theo phut)
        if ((time() - $last) < 60) return ['slots' => 0, 'planned' => 0, 'published' => 0, 'alerts' => 0];
        $last = time();
        UploadStore::ensureSchema();
        $out = ['slots' => 0, 'planned' => 0, 'published' => 0, 'alerts' => 0];
        try {
            $ids = db()->query('SELECT profile_id FROM channel_upload_configs
                WHERE upload_enabled=1 AND daily_enabled=1')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return $out;
        }
        foreach (array_map('intval', (array)$ids) as $pid) {
            try {
                $out['slots'] += UploadPlanner::ensureSlots($pid);
            } catch (Throwable $e) {
            }
        }
        try {
            $pl = UploadPlanner::planDueUploads();
            $out['planned'] = (int)($pl['planned'] ?? 0);
        } catch (Throwable $e) {
        }
        try {
            $out['planned'] += UploadPlanner::retryDue();
        } catch (Throwable $e) {
        }
        try {
            $out['published'] = self::settleDuePublishes();
        } catch (Throwable $e) {
        }
        try {
            $out['alerts'] = self::queueLowAlerts();
        } catch (Throwable $e) {
        }
        try {
            require_once __DIR__ . '/VideoLibraryService.php';
            VideoLibraryService::scanWatchedFolders();
        } catch (Throwable $e) {
        }
        try {
            self::dailyReport();
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Slot toi gio publish + upload SCHEDULED/READY -> PUBLISHED (simulated tu dong;
     * API that: YouTube tu public theo publishAt — chi verify nhe).
     */
    public static function settleDuePublishes(): int
    {
        UploadStore::ensureSchema();
        $n = 0;
        try {
            $rows = db()->query("SELECT s.*, u.provider, u.provider_video_id, u.status AS ustatus
                FROM video_publish_schedules s LEFT JOIN video_uploads u ON u.id=s.upload_id
                WHERE s.status IN ('RESERVED','SCHEDULED') AND s.publish_at<=NOW() LIMIT 20")->fetchAll();
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($rows as $s) {
            $sid = (int)$s['id'];
            try {
                if (strtoupper((string)($s['provider'] ?? 'SIMULATED')) === 'YOUTUBE_API'
                    && !empty($s['provider_video_id'])) {
                    require_once __DIR__ . '/UploadProvider.php';
                    $st = YouTubeApiProvider::make('YOUTUBE_API')->getUploadStatus(
                        (int)$s['profile_id'], (string)$s['provider_video_id']);
                    if (!empty($st['ok']) && ($st['status'] ?? '') === 'PUBLISHED') {
                        db()->prepare("UPDATE video_publish_schedules SET status='PUBLISHED',
                            provider_status='PUBLISHED', published_at=NOW() WHERE id=?")->execute([$sid]);
                        if (!empty($s['upload_id'])) {
                            db()->prepare("UPDATE video_uploads SET status='PUBLISHED' WHERE id=?")
                                ->execute([(int)$s['upload_id']]);
                        }
                        if (!empty($s['video_asset_id'])) {
                            ChannelQueueService::consume((int)self::queueIdOf((int)$s['profile_id'], (int)$s['video_asset_id']));
                        }
                        $n++;
                        continue;
                    }
                    // Chua public that -> giu SCHEDULED, doi tick sau
                    continue;
                }
                // SIMULATED: coi nhu published dung gio
                db()->prepare("UPDATE video_publish_schedules SET status='PUBLISHED',
                    provider_status='PUBLISHED', published_at=NOW() WHERE id=?")->execute([$sid]);
                if (!empty($s['upload_id'])) {
                    db()->prepare("UPDATE video_uploads SET status='PUBLISHED' WHERE id=?")
                        ->execute([(int)$s['upload_id']]);
                }
                if (!empty($s['video_asset_id'])) {
                    ChannelQueueService::consume((int)self::queueIdOf((int)$s['profile_id'], (int)$s['video_asset_id']));
                }
                $n++;
            } catch (Throwable $e) {
            }
        }
        // Missed slots (qua deadline + STRICT): SKIP + alert nhe
        try {
            $rows = db()->query("SELECT s.id, s.profile_id FROM video_publish_schedules s
                JOIN channel_upload_configs c ON c.profile_id=s.profile_id
                WHERE s.status='PLANNED' AND s.publish_at<=NOW() AND c.missed_policy='SKIP' LIMIT 20")->fetchAll();
            foreach ($rows as $r) {
                db()->prepare("UPDATE video_publish_schedules SET status='SKIPPED' WHERE id=?")
                    ->execute([(int)$r['id']]);
            }
        } catch (Throwable $e) {
        }
        return $n;
    }

    private static function queueIdOf(int $profileId, int $assetId): int
    {
        try {
            $st = db()->prepare('SELECT id FROM channel_video_queue WHERE profile_id=? AND video_asset_id=? LIMIT 1');
            $st->execute([$profileId, $assetId]);
            return (int)($st->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Queue sap het: alert 1 lan/ngay/kenh (Telegram qua EventBus rules). */
    public static function queueLowAlerts(): int
    {
        UploadStore::ensureSchema();
        $n = 0;
        try {
            $rows = db()->query('SELECT profile_id, queue_low_threshold FROM channel_upload_configs
                WHERE upload_enabled=1 AND daily_enabled=1 AND queue_low_threshold>0')->fetchAll();
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($rows as $r) {
            $pid = (int)$r['profile_id'];
            $c = ChannelQueueService::counts($pid);
            if ($c['ready'] >= (int)$r['queue_low_threshold']) continue;
            $key = 'uplow_' . $pid . '_' . date('Y-m-d');
            try {
                $seen = get_setting($key, '');
                if ($seen !== '') continue;
                require_once __DIR__ . '/EventBus.php';
                EventBus::emit(AppEvent::WARNING, AppEvent::MOD_UPLOAD, AppEvent::SEV_WARNING,
                    "Queue Kênh #$pid chỉ còn {$c['ready']} video sẵn sàng",
                    "Ready: {$c['ready']} (ngưỡng {$r['queue_low_threshold']})",
                    ['profile_id' => $pid, 'status' => 'WARNING']);
                set_setting($key, '1');
                $n++;
            } catch (Throwable $e) {
            }
        }
        return $n;
    }

    /** Daily report 1 lan/ngay (sau 22:00) qua EventBus (-> Telegram theo rules). */
    public static function dailyReport(): void
    {
        try {
            if (date('H:i') < '22:00' || get_setting('up_summary_date', '') === date('Y-m-d')) return;
            $pub = (int)db()->query("SELECT COUNT(*) FROM video_publish_schedules
                WHERE status='PUBLISHED' AND DATE(published_at)=CURDATE()")->fetchColumn();
            $sched = (int)db()->query("SELECT COUNT(*) FROM video_publish_schedules
                WHERE status IN ('RESERVED','SCHEDULED') AND DATE(publish_at)=DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
            $upl = (int)db()->query("SELECT COUNT(*) FROM video_uploads
                WHERE status IN ('QUEUED','UPLOADING','RETRYING')")->fetchColumn();
            $fail = (int)db()->query("SELECT COUNT(*) FROM video_uploads
                WHERE status='FAILED' AND DATE(completed_at)=CURDATE()")->fetchColumn();
            $low = (int)db()->query('SELECT COUNT(*) FROM channel_upload_configs c WHERE upload_enabled=1
                AND (SELECT COUNT(*) FROM channel_video_queue q WHERE q.profile_id=c.profile_id AND q.status=\'READY\') < c.queue_low_threshold')->fetchColumn();
            require_once __DIR__ . '/EventBus.php';
            EventBus::emit(AppEvent::DAILY_SUMMARY, AppEvent::MOD_UPLOAD, AppEvent::SEV_SUCCESS,
                'YOUTUBE UPLOAD REPORT',
                "Published today: $pub\nScheduled tomorrow: $sched\nUploading: $upl\nFailed: $fail\nLow queues: $low",
                ['status' => 'SUCCESS', 'data' => ['published' => $pub, 'scheduled' => $sched,
                    'uploading' => $upl, 'failed' => $fail, 'low' => $low]]);
            set_setting('up_summary_date', date('Y-m-d'));
        } catch (Throwable $e) {
        }
    }

    /** @return array kpis cho overview/widget */
    public static function kpis(): array
    {
        UploadStore::ensureSchema();
        $out = ['channels' => 0, 'ready' => 0, 'uploading' => 0, 'scheduled' => 0,
            'published_today' => 0, 'failed' => 0, 'low' => 0];
        try {
            $out['channels'] = (int)db()->query('SELECT COUNT(*) FROM channel_upload_configs WHERE upload_enabled=1')->fetchColumn();
            $out['ready'] = (int)db()->query("SELECT COUNT(*) FROM channel_video_queue WHERE status='READY'")->fetchColumn();
            $out['uploading'] = (int)db()->query("SELECT COUNT(*) FROM video_uploads WHERE status IN ('QUEUED','UPLOADING','RETRYING')")->fetchColumn();
            $out['scheduled'] = (int)db()->query("SELECT COUNT(*) FROM video_publish_schedules WHERE status IN ('RESERVED','SCHEDULED') AND publish_at>NOW()")->fetchColumn();
            $out['published_today'] = (int)db()->query("SELECT COUNT(*) FROM video_publish_schedules WHERE status='PUBLISHED' AND DATE(published_at)=CURDATE()")->fetchColumn();
            $out['failed'] = (int)db()->query("SELECT COUNT(*) FROM video_uploads WHERE status='FAILED'")->fetchColumn();
            $out['low'] = (int)db()->query('SELECT COUNT(*) FROM channel_upload_configs c WHERE upload_enabled=1
                AND (SELECT COUNT(*) FROM channel_video_queue q WHERE q.profile_id=c.profile_id AND q.status=\'READY\') < c.queue_low_threshold')->fetchColumn();
        } catch (Throwable $e) {
        }
        return $out;
    }
}
