<?php
declare(strict_types=1);
/**
 * TelegramFAQService - Cau hoi thuong gap: STATIC (text co dinh) + DYNAMIC
 * (map sang RuntimeQuery action -> luon runtime moi). Match: exact ->
 * parameterized -> keyword -> AI Intent fallback (khong chan cau phuc tap).
 */
require_once __DIR__ . '/../config.php';

class TelegramFAQService
{
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS telegram_faq_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
                question VARCHAR(255) NOT NULL DEFAULT '',
                answer_template TEXT NULL,
                match_type VARCHAR(15) NOT NULL DEFAULT 'KEYWORD',
                keywords_json TEXT NULL,
                action_id VARCHAR(60) NULL,
                intent_override VARCHAR(30) NULL,
                priority INT NOT NULL DEFAULT 0,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_faq_cat (category, enabled, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
        self::seedDefaults();
    }

    public static function normalize(string $text): string
    {
        $t = trim(mb_strtolower($text));
        $t = preg_replace('/\s+/u', ' ', $t);
        return trim((string)$t, " \t\n\r\0\x0B?!.,");
    }

    private static function seedDefaults(): void
    {
        static $seeded = false;
        if ($seeded) return;
        $seeded = true;
        // [category, question, answer, match_type, keywords[], action_id, priority]
        $defs = [
            ['GENERAL', 'Bạn làm được gì?', "Tôi có thể kiểm tra trạng thái YT Manager, hỏi về kênh/proxy/jobs, phân tích project, chẩn đoán lỗi và tạo Dev Job.", 'KEYWORD', ['làm được gì', 'lam duoc gi', 'có thể làm gì', 'co the lam gi'], null, 10],
            ['SYSTEM', 'tool hiện thế nào?', null, 'KEYWORD', ['tool hiện', 'tool hien', 'tool đang sao', 'tool dang sao', 'hệ thống đang sao', 'he thong dang sao', 'hệ thống ổn', 'he thong on', 'tool có ổn', 'tool co on'], 'system.status', 10],
            ['CHANNEL', 'có bao nhiêu kênh?', null, 'KEYWORD', ['bao nhiêu kênh', 'bao nhieu kenh', 'kênh nào đang chạy', 'kenh nao dang chay', 'chrome đang chạy bao nhiêu'], 'channel.summary', 10],
            ['PROXY', 'proxy nào lỗi?', null, 'KEYWORD', ['proxy nào lỗi', 'proxy nao loi', 'proxy hiện thế nào', 'proxy hien the nao'], 'proxy.status_summary', 10],
            ['TELEGRAM', 'Telegram ổn không?', null, 'KEYWORD', ['telegram ổn', 'telegram on', 'bot có đang online', 'bot co dang online', 'receiver có chạy', 'receiver co chay'], 'telegram.status', 10],
            ['JOB', 'job nào đang chạy?', null, 'KEYWORD', ['job nào đang chạy', 'job nao dang chay', 'có việc nào đang chạy', 'co viec nao dang chay', 'job lúc nãy sao rồi', 'job luc nay sao roi'], 'job.summary', 10],
            ['AUTO', 'auto hôm nay thế nào?', null, 'KEYWORD', ['auto hôm nay', 'auto hom nay', 'auto đang chạy', 'auto dang chay'], 'auto_activity.status', 10],
            ['AI DEV', 'AI Dev làm gì?', "AI Dev có thể đọc project, phân tích lỗi, lập kế hoạch và tạo Dev Job trong môi trường phát triển có kiểm soát.", 'KEYWORD', ['ai dev làm gì', 'ai dev lam gi', 'ai dev dùng để làm gì', 'ai dev dùng làm gì'], null, 10],
            ['PROJECT', 'có thể hỏi code không?', "Có. Bạn có thể hỏi file, class, module, luồng xử lý hoặc kiến trúc của YT Manager.", 'KEYWORD', ['hỏi code', 'hoi code', 'hỏi project', 'hoi project'], null, 10],
            ['DEVELOPMENT', 'làm sao sửa code?', "Hãy mô tả phần cần sửa. Tôi sẽ phân tích trước, sau đó bạn có thể yêu cầu tạo Dev Job.", 'KEYWORD', ['làm sao sửa code', 'lam sao sua code', 'làm sao tạo dev job', 'tao dev job'], null, 10],
            // Parameterized (§15): kenh/proxy/job + Trang thai
            ['CHANNEL', 'kênh {id} thế nào?', null, 'PATTERN', ['kênh {id} thế nào', 'kenh {id} the nao', 'kênh {id} đang sao'], 'channel.status', 20],
            ['PROXY', 'proxy {id} thế nào?', null, 'PATTERN', ['proxy {id} thế nào', 'proxy {id} the nao'], 'proxy.status_summary', 20],
        ];
        try {
            foreach ($defs as [$cat, $q, $ans, $mt, $kws, $act, $pri]) {
                $st = db()->prepare('SELECT id FROM telegram_faq_entries WHERE category=? AND question=? LIMIT 1');
                $st->execute([$cat, $q]);
                if ($st->fetch()) continue;
                db()->prepare('INSERT INTO telegram_faq_entries (category, question, answer_template,
                        match_type, keywords_json, action_id, priority, enabled)
                    VALUES (?,?,?,?,?,?,?,1)')
                    ->execute([$cat, $q, $ans, $mt, json_encode(array_values($kws), JSON_UNESCAPED_UNICODE), $act, $pri]);
            }
        } catch (Throwable $e) {
        }
    }

    /**
     * Match FAQ. @return array|null {entry, action_id?, answer?, entity?}
     * Thu tu: exact -> parameterized -> keyword. Khong match -> null (AI tiep).
     */
    public static function match(string $text, array $entity = []): ?array
    {
        self::ensureTable();
        $norm = self::normalize($text);
        if ($norm === '') return null;
        try {
            $rows = db()->query('SELECT * FROM telegram_faq_entries WHERE enabled=1 ORDER BY priority DESC, id ASC LIMIT 200')->fetchAll();
        } catch (Throwable $e) {
            return null;
        }
        // 1. Exact
        foreach ($rows as $r) {
            if (self::normalize((string)$r['question']) === $norm) {
                return self::hit($r, $entity);
            }
        }
        // 2. Parameterized: pattern "kenh {id} the nao"
        foreach ($rows as $r) {
            if (($r['match_type'] ?? '') !== 'PATTERN') continue;
            foreach (json_decode((string)($r['keywords_json'] ?? ''), true) ?: [] as $pat) {
                // Placeholder chu (preg_quote escape ca null byte)
                $tmp = str_replace('{id}', 'ZZIDZZ', self::normalize((string)$pat));
                $rx = '/^' . str_replace(['ZZIDZZ', ' '], ['(\\d{1,6})', '\\s+'],
                    preg_quote($tmp, '/')) . '$/u';
                if (preg_match($rx, $norm, $m)) {
                    $hit = self::hit($r, $entity);
                    if ($hit) {
                        $hit['param_id'] = (int)$m[1];
                        return $hit;
                    }
                }
            }
        }
        // 3. Keyword (tat ca keywords cua entry phai xuat hien? khong — 1 la du neu dai)
        $best = null;
        $bestScore = 0;
        foreach ($rows as $r) {
            if (($r['match_type'] ?? '') === 'PATTERN') continue;
            foreach (json_decode((string)($r['keywords_json'] ?? ''), true) ?: [] as $kw) {
                $kw = self::normalize((string)$kw);
                if ($kw === '') continue;
                if ($norm === $kw || str_contains($norm, $kw)) {
                    $score = mb_strlen($kw);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $r;
                    }
                }
            }
        }
        // Nguong: keyword dai (>=8 ky tu) hoac exact-ish moi an (tranh chan cau phuc tap §16)
        if ($best && $bestScore >= 8) return self::hit($best, $entity);
        return null;
    }

    private static function hit(array $r, array $entity): array
    {
        return ['entry' => $r, 'action_id' => $r['action_id'] ?? null,
            'answer' => $r['answer_template'] ?? null,
            'category' => $r['category'] ?? '', 'entity' => $entity];
    }

    /**
     * Tra loi FAQ: static text hoac chay action runtime.
     * @return array{text, buttons?}
     */
    public static function answer(array $hit): array
    {
        $action = (string)($hit['action_id'] ?? '');
        if ($action !== '') {
            try {
                require_once __DIR__ . '/RuntimeQueryService.php';
                $entity = is_array($hit['entity'] ?? null) ? $hit['entity'] : [];
                if (isset($hit['param_id'])) {
                    // Parameterized entity: kenh/proxy theo category
                    $cat = (string)($hit['category'] ?? '');
                    if ($cat === 'CHANNEL') $entity = ['type' => 'CHANNEL', 'id' => (int)$hit['param_id']];
                    elseif ($cat === 'PROXY') $entity = ['type' => 'PROXY', 'id' => (int)$hit['param_id']];
                }
                // channel.status can id; thieu id -> bao ro
                if ($action === 'channel.status' && empty($entity['id'])) {
                    return ['text' => 'Bạn muốn hỏi kênh nào? Thử "kênh 5 thế nào?"'];
                }
                return RuntimeQueryService::run($action, $entity);
            } catch (Throwable $e) {
                return ['text' => '❌ Lỗi lấy dữ liệu.'];
            }
        }
        return ['text' => (string)($hit['answer'] ?? '')];
    }

    // ================= CRUD (UI) =================

    /** @return array[] */
    public static function list(?string $category = null): array
    {
        self::ensureTable();
        try {
            if ($category !== null && $category !== '' && $category !== 'all') {
                $st = db()->prepare('SELECT * FROM telegram_faq_entries WHERE category=? ORDER BY priority DESC, id ASC LIMIT 200');
                $st->execute([$category]);
            } else {
                $st = db()->query('SELECT * FROM telegram_faq_entries ORDER BY category, priority DESC, id ASC LIMIT 200');
            }
            $rows = $st->fetchAll();
            foreach ($rows as &$r) {
                $r['keywords'] = json_decode((string)($r['keywords_json'] ?? ''), true) ?: [];
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{ok, id?, error?} */
    public static function save(?int $id, array $in): array
    {
        self::ensureTable();
        $q = trim((string)($in['question'] ?? ''));
        if ($q === '') return ['ok' => false, 'error' => 'Thiếu câu hỏi'];
        $type = strtoupper((string)($in['kind'] ?? 'STATIC'));
        $isAction = $type === 'ACTION' || $type === 'RUNTIME';
        $allowActions = ['system.status', 'channel.summary', 'channel.status', 'proxy.status_summary',
            'telegram.status', 'job.summary', 'auto_activity.status'];
        $action = $isAction ? trim((string)($in['action_id'] ?? '')) : null;
        if ($isAction && !in_array($action, $allowActions, true)) {
            return ['ok' => false, 'error' => 'Action không hỗ trợ'];
        }
        $kws = [];
        foreach (preg_split('/\r?\n/', (string)($in['keywords'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '') $kws[] = mb_substr($line, 0, 120);
        }
        $kws = array_values(array_unique($kws));
        try {
            if ($id > 0) {
                db()->prepare('UPDATE telegram_faq_entries SET category=?, question=?, answer_template=?,
                        match_type=?, keywords_json=?, action_id=?, priority=?, enabled=? WHERE id=?')
                    ->execute([mb_substr((string)($in['category'] ?? 'GENERAL'), 0, 30), mb_substr($q, 0, 255),
                        $isAction ? null : mb_substr((string)($in['answer'] ?? ''), 0, 2000),
                        $isAction ? 'KEYWORD' : 'EXACT',
                        json_encode($kws, JSON_UNESCAPED_UNICODE), $action,
                        (int)($in['priority'] ?? 0), array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : 1, $id]);
                return ['ok' => true, 'id' => $id];
            }
            db()->prepare('INSERT INTO telegram_faq_entries (category, question, answer_template,
                    match_type, keywords_json, action_id, priority, enabled)
                VALUES (?,?,?,?,?,?,?,?)')
                ->execute([mb_substr((string)($in['category'] ?? 'GENERAL'), 0, 30), mb_substr($q, 0, 255),
                    $isAction ? null : mb_substr((string)($in['answer'] ?? ''), 0, 2000),
                    $isAction ? 'KEYWORD' : 'EXACT',
                    json_encode($kws, JSON_UNESCAPED_UNICODE), $action,
                    (int)($in['priority'] ?? 0),
                    array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : 1]);
            return ['ok' => true, 'id' => (int)db()->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function toggle(int $id, bool $on): bool
    {
        self::ensureTable();
        try {
            $st = db()->prepare('UPDATE telegram_faq_entries SET enabled=? WHERE id=?');
            $st->execute([$on ? 1 : 0, $id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function delete(int $id): bool
    {
        self::ensureTable();
        try {
            $st = db()->prepare('DELETE FROM telegram_faq_entries WHERE id=?');
            $st->execute([$id]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
