<?php
declare(strict_types=1);
/**
 * EntityResolver - Giai quyet entity chuong trinh tu ngon ngu tu nhien.
 * "Kênh 8" / "channel 8" / "kênh test" / "proxy 4" / "job vừa rồi" /
 * "Telegram" / "Auto Activity" / "nhóm US" -> canonical entity.
 */
require_once __DIR__ . '/../config.php';

class EntityResolver
{
    /**
     * @param array $context (last_job_id, last_entity_*, dev_job...)
     * @return array{type, id?, name?, query?} type: CHANNEL|PROXY|JOB|DEVJOB|DIAG|MODULE|GROUP|NONE
     */
    public static function resolve(string $text, array $context = []): array
    {
        require_once __DIR__ . '/AIIntentRouter.php';
        $ent = AIIntentRouter::extractEntities($text);
        $low = mb_strtolower($text);
        // 1. Job codes cu the
        if (preg_match('/\b((?:JOB|EVA|BRW|ACT|PRX|SYS)-[A-Z0-9-]{3,})\b/i', $text, $m)) {
            return ['type' => 'JOB', 'name' => strtoupper($m[1])];
        }
        if (preg_match('/\b(DEV-\d{1,6})\b/i', $text, $m)) {
            return ['type' => 'DEVJOB', 'name' => strtoupper($m[1])];
        }
        if (preg_match('/\b(DIAG-\d{1,6})\b/i', $text, $m)) {
            return ['type' => 'DIAG', 'name' => strtoupper($m[1])];
        }
        // 2. "job vua roi / job nay" -> context
        if (preg_match('/job (vừa rồi|vua roi|lúc nãy|luc nay|này|nay|đó|do)/u', $low)
            || str_contains($low, 'công việc vừa') || str_contains($low, 'cong viec vua')) {
            if (!empty($context['last_job_id'])) {
                return ['type' => 'JOB', 'name' => (string)$context['last_job_id'], 'resolved_from' => 'context'];
            }
            return ['type' => 'JOB', 'query' => 'last'];
        }
        // 3. Channel theo id
        if (isset($ent['channel_id'])) {
            $c = self::channelById((int)$ent['channel_id']);
            if ($c) return ['type' => 'CHANNEL', 'id' => (int)$c['id'], 'name' => (string)$c['name']];
            return ['type' => 'CHANNEL', 'id' => (int)$ent['channel_id'], 'missing' => true];
        }
        // 4. Channel theo ten/handle ("kenh test")
        if (preg_match('/(?:kênh|kenh|channel)\s+([a-z0-9_][a-z0-9_ .\-]{1,60})/iu', $text, $m)) {
            $q = trim($m[1]);
            if (!is_numeric($q)) {
                $c = self::channelByName($q);
                if ($c) return ['type' => 'CHANNEL', 'id' => (int)$c['id'], 'name' => (string)$c['name']];
                return ['type' => 'GROUP', 'query' => $q];
            }
        }
        // 5. Proxy theo id
        if (preg_match('/proxy\s+(\d{1,6})/i', $text, $m)) {
            $p = self::proxyById((int)$m[1]);
            if ($p) return ['type' => 'PROXY', 'id' => (int)$p['id'], 'name' => (string)($p['name'] ?? ('proxy#' . $p['id']))];
            return ['type' => 'PROXY', 'id' => (int)$m[1], 'missing' => true];
        }
        // 6. Module alias
        $modMap = ['telegram' => 'TELEGRAM', 'receiver' => 'TELEGRAM', 'polling' => 'TELEGRAM',
            'auto' => 'AUTO_ACTIVITY', 'nuôi mail' => 'AUTO_ACTIVITY', 'nuoi mail' => 'AUTO_ACTIVITY',
            'activity' => 'AUTO_ACTIVITY', 'đánh giá' => 'EVALUATION', 'danh gia' => 'EVALUATION',
            'evaluation' => 'EVALUATION', 'chrome' => 'CHROME', 'proxy' => 'PROXY',
            'job' => 'JOBS', 'thông báo' => 'NOTIFICATION', 'report' => 'NOTIFICATION',
            'sức khỏe' => 'HEALTH', 'lịch' => 'SCHEDULER', 'sync' => 'SYNCHRONIZE', 'kênh' => 'CHANNEL'];
        foreach ($modMap as $k => $v) {
            if (str_contains($low, $k)) return ['type' => 'MODULE', 'name' => $v];
        }
        // 7. "nhom X" -> GROUP (de buoc sau xu ly)
        if (preg_match('/nhóm\s+([a-z0-9_][a-z0-9_ .\-]{0,40})/iu', $text, $m)) {
            return ['type' => 'GROUP', 'query' => trim($m[1])];
        }
        return ['type' => 'NONE'];
    }

    public static function channelById(int $id): ?array
    {
        try {
            $st = db()->prepare('SELECT id, name, channel_handle, status, proxy_id FROM profiles WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function channelByName(string $q): ?array
    {
        try {
            $st = db()->prepare('SELECT id, name, channel_handle, status, proxy_id FROM profiles
                WHERE name LIKE ? OR channel_handle LIKE ? ORDER BY id LIMIT 1');
            $st->execute(['%' . mb_substr($q, 0, 60) . '%', '%' . mb_substr($q, 0, 60) . '%']);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function proxyById(int $id): ?array
    {
        try {
            $st = db()->prepare('SELECT id, name, host, port, status FROM proxies WHERE id=?');
            $st->execute([$id]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
