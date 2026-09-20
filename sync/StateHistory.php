<?php
declare(strict_types=1);
/**
 * StateHistory - persist transition runtime/evaluation/proxy/monitoring.
 * Giu <=500 records/profile (history lon van phan trang duoc).
 */
require_once __DIR__ . '/../config.php';

class StateHistory
{
    public const CAT_EVAL = 'evaluation';
    public const CAT_RUNTIME = 'runtime';
    public const CAT_PROXY = 'proxy';
    public const CAT_MON = 'monitoring';

    public static function record(int $profileId, string $category, ?string $old, ?string $new, ?string $reason = null): void
    {
        if ($profileId <= 0) return;
        if ($old !== null && $new !== null && $old === $new) return; // khong spam giong nhau
        try {
            db()->prepare('INSERT INTO state_history (profile_id, ts, category, old_value, new_value, reason) VALUES (?,?, ?, ?, ?, ?)')
                ->execute([$profileId, date('Y-m-d H:i:s'), $category, $old, $new, $reason !== null ? mb_substr($reason, 0, 200) : null]);
            // Trim: giu 500 moi nhat/profile
            db()->prepare('DELETE FROM state_history WHERE profile_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM state_history WHERE profile_id=? ORDER BY id DESC LIMIT 500) t)')
                ->execute([$profileId, $profileId]);
        } catch (Throwable $e) {
        }
    }

    /** Timeline he thong (filter category/profile/tu-ngay). */
    public static function timeline(?int $profileId = null, ?string $category = null, ?string $from = null, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        try {
            $w = [];
            $p = [];
            if ($profileId !== null && $profileId > 0) {
                $w[] = 'h.profile_id=?';
                $p[] = $profileId;
            }
            if ($category !== null && $category !== '' && $category !== 'all') {
                $w[] = 'h.category=?';
                $p[] = $category;
            }
            if ($from !== null && $from !== '') {
                $w[] = 'h.ts>=?';
                $p[] = $from;
            }
            $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $st = db()->prepare("SELECT h.*, p.name AS profile_name FROM state_history h LEFT JOIN profiles p ON p.id=h.profile_id $where ORDER BY h.id DESC LIMIT $limit OFFSET $offset");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}
