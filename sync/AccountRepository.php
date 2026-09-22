<?php
declare(strict_types=1);
/**
 * AccountRepository - Persistence cho module Account Evaluation.
 * Bang account_states (1 row/profile) + account_history (append-only).
 * Khong bao gio luu password/token/cookie (chi diem + trang thai).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AccountEvaluation.php';

class AccountRepository
{
    /**
     * Dam bao moi profile co state row. Profile cu: imported_at = created_at
     * (backward compat, Managed Days co y nghia ngay). Tra ve so row tao moi.
     */
    public static function ensureAll(): int
    {
        try {
            $st = db()->prepare(
                'INSERT IGNORE INTO account_states (profile_id, imported_at)
                 SELECT id, created_at FROM profiles'
            );
            $st->execute();
            return (int)$st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function ensure(int $profileId): ?array
    {
        try {
            db()->prepare(
                'INSERT IGNORE INTO account_states (profile_id, imported_at)
                 SELECT id, created_at FROM profiles WHERE id=?'
            )->execute([$profileId]);
            return self::load($profileId);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function load(int $profileId): ?array
    {
        try {
            $st = db()->prepare('SELECT * FROM account_states WHERE profile_id=?');
            $st->execute([$profileId]);
            $r = $st->fetch();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Tat ca states join ten profile (cho list + filter). */
    public static function listAll(): array
    {
        try {
            self::ensureAll();
            return db()->query(
                'SELECT s.*, p.name, p.status AS browser_status,
                        TIMESTAMPDIFF(SECOND, s.imported_at, NOW()) AS managed_seconds
                 FROM account_states s JOIN profiles p ON p.id = s.profile_id
                 ORDER BY s.profile_id'
            )->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Luu ket qua 1 lan check: counters + scores/stage + append history.
     * $outcome: 'success'|'failed'. $signals: nhu engine (de tinh channel_name...).
     * Tra ve row moi (da gop managed_days).
     */
    public static function saveResult(int $profileId, string $outcome, array $signals, array $eval): ?array
    {
        try {
            $st = self::ensure($profileId);
            if (!$st) return null;
            $now = date('Y-m-d H:i:s');
            $succ = (int)$st['success_count'] + ($outcome === 'success' ? 1 : 0);
            $fail = (int)$st['fail_count'] + ($outcome === 'failed' ? 1 : 0);
            $consec = $outcome === 'success' ? 0 : (int)$st['consec_fails'] + 1;
            $channelName = $signals['channelName'] ?? $st['channel_name'];
            if (($signals['channel'] ?? '') === 'none') $channelName = null;
            db()->prepare(
                'UPDATE account_states SET last_checked_at=?, last_success_at=IF(?=\'success\',?,last_success_at),
                        success_count=?, fail_count=?, consec_fails=?,
                        login_state=?, session_state=?, youtube_state=?,
                        channel_state=?, channel_name=?,
                        security_challenge=?, recovery_required=?,
                        stability=?, confidence=?, stage=? WHERE profile_id=?'
            )->execute([$now, $outcome, $now, $succ, $fail, $consec,
                $signals['login'] ?? 'unknown', $signals['session'] ?? 'unknown',
                $signals['youtube'] ?? 'unknown', $signals['channel'] ?? 'unknown', $channelName,
                !empty($signals['challenge']) ? 1 : 0, !empty($signals['recovery']) ? 1 : 0,
                (int)$eval['stability'], (int)$eval['confidence'], (string)$eval['stage'], $profileId]);
            db()->prepare(
                'INSERT INTO account_history (profile_id, checked_at, stability, confidence, stage, reasons, warnings)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$profileId, $now, (int)$eval['stability'], (int)$eval['confidence'],
                (string)$eval['stage'], json_encode($eval['reasons'] ?? []), json_encode($eval['warnings'] ?? [])]);
            $row = self::load($profileId);
            if ($row) $row['managed_days'] = (int)floor((time() - strtotime((string)$row['imported_at'])) / 86400);
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Lich su moi nhat truoc (limit 100). reasons/warnings decode san. */
    public static function history(int $profileId, int $limit = 100): array
    {
        try {
            $limit = max(1, min(100, $limit));
            $hasEval = (bool)db()->query("SHOW COLUMNS FROM account_history LIKE 'eval_status'")->fetch();
            $st = db()->prepare(
                'SELECT checked_at, stability, confidence, stage, reasons, warnings'
                . ($hasEval ? ', eval_status, prev_status, duration_ms, reason' : '')
                . ' FROM account_history WHERE profile_id=? ORDER BY id DESC LIMIT ' . $limit
            );
            $st->execute([$profileId]);
            $rows = $st->fetchAll();
            foreach ($rows as &$r) {
                $r['reasons'] = json_decode((string)($r['reasons'] ?? '[]'), true) ?: [];
                $r['warnings'] = json_decode((string)($r['warnings'] ?? '[]'), true) ?: [];
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Danh profile den han check background (running + qua interval). */
    public static function dueProfiles(int $intervalMin, int $limit): array
    {
        $intervalMin = max(1, min(10080, $intervalMin));
        $limit = max(1, min(100, (int)$limit));
        try {
            self::ensureAll();
            $st = db()->prepare(
                "SELECT s.profile_id FROM account_states s JOIN profiles p ON p.id = s.profile_id
                 WHERE p.status = 'running'
                   AND (s.last_checked_at IS NULL OR s.last_checked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
                 ORDER BY s.last_checked_at IS NULL DESC, s.last_checked_at ASC LIMIT $limit"
            );
            $st->execute([$intervalMin]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            // Bo profile dang transitional (STARTING/CLOSING): khong danh gia giua batch (§36)
            try {
                require_once __DIR__ . '/ChromeBatchManager.php';
                $ids = array_values(array_filter($ids, fn($pid) => !ChromeBatchManager::isBusy($pid)));
            } catch (Throwable $e) {
            }
            return $ids;
        } catch (Throwable $e) {
            return [];
        }
    }
}
