<?php
declare(strict_types=1);
/**
 * AlertCenter - Alert tong hop (system + module) voi dedup theo alert_key.
 * Status: OPEN | ACKNOWLEDGED | RESOLVED. Recovery: ERROR -> ok thi resolve.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class AlertCenter
{
    public const OPEN = 'OPEN';
    public const ACK = 'ACKNOWLEDGED';
    public const RESOLVED = 'RESOLVED';

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alert_key VARCHAR(120) NOT NULL,
                module VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
                type VARCHAR(60) NOT NULL DEFAULT '',
                severity VARCHAR(15) NOT NULL DEFAULT 'WARNING',
                title VARCHAR(190) NOT NULL DEFAULT '',
                message VARCHAR(500) NOT NULL DEFAULT '',
                profile_id INT NULL,
                job_id VARCHAR(32) NULL,
                status VARCHAR(15) NOT NULL DEFAULT 'OPEN',
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                occurrence_count INT NOT NULL DEFAULT 1,
                UNIQUE KEY uq_alert_key (alert_key),
                KEY idx_alerts_status (status, severity, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /**
     * Mo hoac dem occurrences (dedup §23). Tra ve id.
     */
    public static function open(string $module, string $type, string $severity, string $title,
        string $message = '', array $opts = []): ?int
    {
        self::ensureTable();
        $severity = strtoupper($severity);
        if (!in_array($severity, ['CRITICAL', 'ERROR', 'WARNING', 'INFO'], true)) $severity = 'WARNING';
        $key = (string)($opts['alert_key'] ?? ($module . ':' . $type
            . (!empty($opts['profile_id']) ? ':' . (int)$opts['profile_id'] : '')
            . (!empty($opts['job_id']) ? ':' . $opts['job_id'] : '')));
        $key = mb_substr($key, 0, 120);
        $now = date('Y-m-d H:i:s');
        try {
            $st = db()->prepare("SELECT id, occurrence_count, status FROM alerts WHERE alert_key=? LIMIT 1");
            $st->execute([$key]);
            $ex = $st->fetch();
            if ($ex) {
                if (($ex['status'] ?? '') === self::RESOLVED) {
                    // Tai phat sau resolve -> mo lai chu ky moi (giu lich su occurrences)
                    db()->prepare("UPDATE alerts SET status='OPEN', severity=?, title=?, message=?,
                            profile_id=?, job_id=?, last_seen_at=?, resolved_at=NULL,
                            occurrence_count=occurrence_count+1 WHERE id=?")
                        ->execute([$severity, mb_substr($title, 0, 190), mb_substr($message, 0, 500),
                            $opts['profile_id'] ?? null, $opts['job_id'] ?? null, $now, (int)$ex['id']]);
                } else {
                    db()->prepare("UPDATE alerts SET occurrence_count=occurrence_count+1, last_seen_at=?,
                            severity=?, title=?, message=? WHERE id=?")
                        ->execute([$now, $severity, mb_substr($title, 0, 190),
                            mb_substr($message, 0, 500), (int)$ex['id']]);
                }
                return (int)$ex['id'];
            }
            db()->prepare("INSERT INTO alerts (alert_key, module, type, severity, title, message,
                    profile_id, job_id, status, first_seen_at, last_seen_at)
                VALUES (?,?,?,?,?,?,?,?, 'OPEN', ?, ?)")
                ->execute([$key, mb_substr($module, 0, 30), mb_substr($type, 0, 60), $severity,
                    mb_substr($title, 0, 190), mb_substr($message, 0, 500),
                    $opts['profile_id'] ?? null, $opts['job_id'] ?? null, $now, $now]);
            $id = (int)db()->lastInsertId();
            // Emit event cho Notification (CRITICAL/ERROR theo rules hien co)
            try {
                require_once __DIR__ . '/EventBus.php';
                $sev = $severity === 'CRITICAL' ? AppEvent::SEV_CRITICAL
                    : ($severity === 'ERROR' ? AppEvent::SEV_ERROR
                    : ($severity === 'INFO' ? AppEvent::SEV_INFO : AppEvent::SEV_WARNING));
                EventBus::emit(AppEvent::STATUS_CHANGED, $module, $sev, $title, $message,
                    ['status' => 'OPEN', 'data' => ['alert_key' => $key, 'alert_id' => $id]]);
            } catch (Throwable $e) {
            }
            return $id;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Recovery (§25): resolve + optional Telegram recovery notice. */
    public static function resolveKey(string $alertKey, bool $notifyRecovery = false): void
    {
        self::ensureTable();
        try {
            $st = db()->prepare("UPDATE alerts SET status='RESOLVED', resolved_at=NOW()
                WHERE alert_key=? AND status IN ('OPEN','ACKNOWLEDGED')");
            $st->execute([$alertKey]);
            if ($st->rowCount() > 0 && $notifyRecovery) {
                try {
                    require_once __DIR__ . '/EventBus.php';
                    EventBus::emit(AppEvent::STATUS_CHANGED, 'SYSTEM', AppEvent::SEV_SUCCESS,
                        'Đã hoạt động bình thường trở lại', '✅ ' . $alertKey . ' đã phục hồi',
                        ['status' => 'RESOLVED', 'data' => ['alert_key' => $alertKey]]);
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
        }
    }

    public static function ack(int $id): bool
    {
        self::ensureTable();
        try {
            $st = db()->prepare("UPDATE alerts SET status='ACKNOWLEDGED' WHERE id=? AND status='OPEN'");
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function resolveId(int $id): bool
    {
        self::ensureTable();
        try {
            $st = db()->prepare("UPDATE alerts SET status='RESOLVED', resolved_at=NOW() WHERE id=? AND status IN ('OPEN','ACKNOWLEDGED')");
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array[] moi nhat truoc */
    public static function list(?string $status = 'OPEN', int $limit = 50, int $offset = 0,
        ?string $severity = null): array
    {
        self::ensureTable();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        try {
            $w = [];
            $p = [];
            if ($status !== null && $status !== '' && $status !== 'all') {
                $w[] = 'status=?';
                $p[] = $status;
            }
            if ($severity !== null && $severity !== '' && $severity !== 'all') {
                $w[] = 'severity=?';
                $p[] = $severity;
            }
            $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $st = db()->prepare("SELECT * FROM alerts $where ORDER BY last_seen_at DESC LIMIT $limit OFFSET $offset");
            $st->execute($p);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function counts(): array
    {
        self::ensureTable();
        $out = ['CRITICAL' => 0, 'ERROR' => 0, 'WARNING' => 0, 'INFO' => 0, 'total' => 0];
        try {
            $rows = db()->query("SELECT severity, COUNT(*) c FROM alerts WHERE status='OPEN' GROUP BY severity")->fetchAll();
            foreach ($rows as $r) {
                $s = strtoupper((string)$r['severity']);
                if (!isset($out[$s])) $out[$s] = 0;
                $out[$s] += (int)$r['c'];
                $out['total'] += (int)$r['c'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
