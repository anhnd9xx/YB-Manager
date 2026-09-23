<?php
declare(strict_types=1);
/**
 * ReportManager - Bao cao dinh ky + tuy chon + lich su (§25-§27, §34-§35, §37).
 * ReportDocument: title/summary/sections/metrics/warnings/generated_at.
 * Renderers theo module (Evaluation/AutoActivity/Browser/Proxy) nhung chung document.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class ReportManager
{
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS report_history (
                report_id VARCHAR(64) PRIMARY KEY,
                type VARCHAR(20) NOT NULL,
                module VARCHAR(30) NOT NULL DEFAULT '',
                range_start DATETIME NOT NULL,
                range_end DATETIME NOT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delivery_status VARCHAR(15) NOT NULL DEFAULT 'LOCAL',
                document TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    /** @return array ReportDocument */
    public static function buildDaily(?string $day = null): array
    {
        $day = $day ?? date('Y-m-d');
        $start = $day . ' 00:00:00';
        $end = $day . ' 23:59:59';
        $sections = [];
        $sections[] = self::evaluationSection($start, $end);
        $sections[] = self::activitySection($start, $end);
        $sections[] = self::proxySection();
        $sections[] = self::alertSection($start, $end);
        try {
            $online = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
        } catch (Throwable $e) {
            $online = 0;
        }
        try {
            $total = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        } catch (Throwable $e) {
            $total = 0;
        }
        $doc = ['title' => '📊 BÁO CÁO CUỐI NGÀY ' . date('d/m', strtotime($day)),
            'summary' => "Tổng kênh: $total · Chrome online hiện tại: $online",
            'sections' => $sections, 'warnings' => [],
            'generated_at' => date('d/m/Y H:i')];
        self::saveHistory('DAILY', '', $start, $end, $doc);
        return $doc;
    }

    /** @return array ReportDocument */
    public static function buildWeekly(?string $endDay = null): array
    {
        $end = ($endDay ?? date('Y-m-d')) . ' 23:59:59';
        $start = date('Y-m-d 00:00:00', strtotime($end) - 6 * 86400);
        $sections = [];
        $sections[] = self::evaluationSection($start, $end, true);
        $sections[] = self::activitySection($start, $end, true);
        $sections[] = self::proxySection();
        // success rate tuan
        try {
            $row = db()->query("SELECT
                    SUM(CASE WHEN error_code IS NULL AND result NOT IN ('BLOCKED','MISSING','SKIPPED') THEN 1 ELSE 0 END) okc,
                    COUNT(*) total
                FROM activity_history WHERE created_at BETWEEN '$start' AND '$end'")->fetch();
            $rate = $row && (int)$row['total'] > 0
                ? round(100 * (int)$row['okc'] / (int)$row['total'], 1) : 0;
            $sections[] = ['heading' => 'Hiệu suất tuần',
                'lines' => ["Task success rate: $rate% (" . (int)($row['okc'] ?? 0) . '/' . (int)($row['total'] ?? 0) . ')']];
        } catch (Throwable $e) {
        }
        $doc = ['title' => '📊 TỔNG KẾT TUẦN',
            'summary' => date('d/m', strtotime($start)) . ' → ' . date('d/m', strtotime($end)),
            'sections' => $sections, 'warnings' => [],
            'generated_at' => date('d/m/Y H:i')];
        self::saveHistory('WEEKLY', '', $start, $end, $doc);
        return $doc;
    }

    /** @return array ReportDocument tuy chon theo range */
    public static function buildRange(string $start, string $end): array
    {
        $sections = [];
        $sections[] = self::evaluationSection($start, $end, true);
        $sections[] = self::activitySection($start, $end, true);
        $sections[] = self::alertSection($start, $end);
        $doc = ['title' => '📊 BÁO CÁO ' . date('d/m H:i', strtotime($start)) . ' → ' . date('d/m H:i', strtotime($end)),
            'summary' => '', 'sections' => $sections, 'warnings' => [],
            'generated_at' => date('d/m/Y H:i')];
        self::saveHistory('CUSTOM', '', $start, $end, $doc);
        return $doc;
    }

    // ---- Renderers (module-specific, chung document) ----

    private static function evaluationSection(string $start, string $end, bool $fromHistory = false): array
    {
        $lines = [];
        try {
            if ($fromHistory) {
                // Dem tu app_events batch completed trong range
                $rows = db()->query("SELECT data FROM app_events WHERE module='EVALUATION'
                    AND event_type='BATCH_COMPLETED' AND created_at BETWEEN '$start' AND '$end'
                    ORDER BY created_at DESC LIMIT 20")->fetchAll();
                $tot = ['total' => 0, 'active' => 0, 'no_channel' => 0, 'need_login' => 0, 'verify' => 0, 'tech' => 0];
                foreach ($rows as $r) {
                    $d = json_decode((string)($r['data'] ?? ''), true);
                    if (!is_array($d)) continue;
                    $tot['total'] += (int)($d['total'] ?? 0);
                    $tot['active'] += (int)($d['active'] ?? 0);
                    $tot['no_channel'] += (int)($d['signed_in_no_channel'] ?? 0);
                    $tot['need_login'] += (int)($d['need_login'] ?? 0);
                    $tot['verify'] += (int)($d['verification'] ?? 0);
                    $tot['tech'] += (int)($d['technical_errors'] ?? 0);
                }
                $lines[] = 'Tổng lượt đánh giá: ' . $tot['total'];
                $lines[] = 'Hoạt động: ' . $tot['active'];
                $lines[] = 'Đã login, chưa có kênh: ' . $tot['no_channel'];
                $lines[] = 'Cần login: ' . $tot['need_login'];
                $lines[] = 'Cần xác minh: ' . $tot['verify'];
                $lines[] = 'Lỗi kiểm tra (kỹ thuật, không phải lỗi kênh): ' . $tot['tech'];
            } else {
                // Hien tai: dem truc tiep account_states
                $q = fn($w) => (int)db()->query("SELECT COUNT(*) FROM account_states WHERE $w")->fetchColumn();
                $lines[] = 'Hoạt động: ' . $q("eval_status='ACTIVE'");
                $lines[] = 'Sẵn sàng tạo kênh: ' . $q("account_channel_state='SIGNED_IN_NO_CHANNEL'");
                $lines[] = 'Cần đăng nhập: ' . $q("eval_status='LOGIN_REQUIRED'");
                $lines[] = 'Cần xác minh: ' . $q("eval_status='VERIFICATION_REQUIRED'");
                $lines[] = 'Có vấn đề: ' . $q("eval_status IN ('CHANNEL_UNAVAILABLE','RESTRICTED','ERROR')");
            }
        } catch (Throwable $e) {
            $lines[] = 'Không đọc được dữ liệu đánh giá';
        }
        return ['heading' => 'Đánh giá kênh', 'lines' => $lines];
    }

    private static function activitySection(string $start, string $end, bool $detail = false): array
    {
        $lines = [];
        try {
            $row = db()->query("SELECT
                    SUM(CASE WHEN error_code IS NULL AND result NOT IN ('BLOCKED','MISSING','SKIPPED') THEN 1 ELSE 0 END) okc,
                    SUM(CASE WHEN error_code IS NOT NULL OR result IN ('BLOCKED','MISSING') THEN 1 ELSE 0 END) failc,
                    COUNT(*) total
                FROM activity_history WHERE created_at BETWEEN '$start' AND '$end'")->fetch();
            $lines[] = 'Success: ' . (int)($row['okc'] ?? 0);
            $lines[] = 'Failed: ' . (int)($row['failc'] ?? 0);
            if ($detail) $lines[] = 'Tasks completed: ' . (int)($row['total'] ?? 0);
        } catch (Throwable $e) {
            $lines[] = 'Không đọc được dữ liệu activity';
        }
        return ['heading' => 'Auto Activity', 'lines' => $lines];
    }

    private static function proxySection(): array
    {
        $lines = [];
        try {
            $ok = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='alive'")->fetchColumn();
            $err = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='dead'")->fetchColumn();
            $lines[] = "OK: $ok";
            $lines[] = "Error: $err";
        } catch (Throwable $e) {
            $lines[] = 'Không đọc được dữ liệu proxy';
        }
        return ['heading' => 'Proxy', 'lines' => $lines];
    }

    private static function alertSection(string $start, string $end): array
    {
        $lines = [];
        try {
            $new = (int)db()->query("SELECT COUNT(*) FROM channel_alerts WHERE first_seen BETWEEN '$start' AND '$end'")->fetchColumn();
            $res = (int)db()->query("SELECT COUNT(*) FROM channel_alerts WHERE resolved_at BETWEEN '$start' AND '$end'")->fetchColumn();
            $open = (int)db()->query("SELECT COUNT(*) FROM channel_alerts WHERE status='OPEN'")->fetchColumn();
            $lines[] = "Cảnh báo mới: $new";
            $lines[] = "Đã xử lý: $res";
            $lines[] = "Còn mở: $open";
        } catch (Throwable $e) {
            $lines[] = 'Không đọc được dữ liệu cảnh báo';
        }
        return ['heading' => 'Cảnh báo', 'lines' => $lines];
    }

    private static function saveHistory(string $type, string $module, string $start, string $end, array $doc): string
    {
        self::ensureTable();
        $rid = 'rp_' . date('YmdHis') . '_' . substr(md5($type . $start . microtime(true)), 0, 6);
        try {
            db()->prepare('INSERT INTO report_history (report_id, type, module, range_start, range_end, generated_at, delivery_status, document)
                VALUES (?,?,?,?,?,NOW(),?,?)')
                ->execute([$rid, $type, $module, $start, $end, 'LOCAL', json_encode($doc, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
        }
        return $rid;
    }

    public static function markDelivered(string $reportId, string $status): void
    {
        try {
            self::ensureTable();
            db()->prepare('UPDATE report_history SET delivery_status=? WHERE report_id=?')
                ->execute([$status, $reportId]);
        } catch (Throwable $e) {
        }
    }

    public static function history(int $limit = 50): array
    {
        self::ensureTable();
        $limit = max(1, min(100, $limit));
        try {
            return db()->query('SELECT report_id, type, module, range_start, range_end, generated_at, delivery_status
                FROM report_history ORDER BY generated_at DESC LIMIT ' . $limit)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function get(string $reportId): ?array
    {
        self::ensureTable();
        try {
            $st = db()->prepare('SELECT * FROM report_history WHERE report_id=?');
            $st->execute([$reportId]);
            $r = $st->fetch();
            if (!$r) return null;
            $r['document'] = json_decode((string)($r['document'] ?? ''), true);
            return $r;
        } catch (Throwable $e) {
            return null;
        }
    }
}
