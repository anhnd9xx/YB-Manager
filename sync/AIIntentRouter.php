<?php
declare(strict_types=1);
/**
 * AIIntentRouter - Phan loai message Telegram (khong doan roi sua code).
 * Intents: TOOL_COMMAND | DEV_FOLLOWUP | PLAN_REQUEST | DEV_REQUEST |
 *          RUNTIME_QUESTION | HYBRID_DIAGNOSIS | PROJECT_QUESTION | CHAT | CLARIFY.
 */
require_once __DIR__ . '/../config.php';

class AIIntentRouter
{
    public const TOOL_COMMAND = 'TOOL_COMMAND';
    public const DEV_FOLLOWUP = 'DEV_FOLLOWUP';
    public const PLAN_REQUEST = 'PLAN_REQUEST';
    public const DEV_REQUEST = 'DEV_REQUEST';
    public const RUNTIME_QUESTION = 'RUNTIME_QUESTION';
    public const HYBRID_DIAGNOSIS = 'HYBRID_DIAGNOSIS';
    public const PROJECT_QUESTION = 'PROJECT_QUESTION';
    public const ARCHITECTURE_QUESTION = 'ARCHITECTURE_QUESTION';
    public const BUG_ANALYSIS = 'BUG_ANALYSIS';
    public const CODE_REVIEW = 'CODE_REVIEW';
    public const TEST_REQUEST = 'TEST_REQUEST';
    public const CHAT = 'CHAT';
    public const CLARIFY = 'CLARIFY';

    /**
     * @return array{intent, confidence:low|medium|high, command?, dev_job?, text?}
     */
    public static function classify(string $text, array $ctx = []): array
    {
        $t = trim($text);
        $low = mb_strtolower($t);
        // 1. TOOL_COMMAND: /lenh dang ky
        if (str_starts_with($t, '/')) {
            $cmd = strtolower(preg_replace('/[^a-z0-9_.-].*$/', '', substr($t, 1)));
            try {
                require_once __DIR__ . '/CommandRegistry.php';
                if (CommandRegistry::find($cmd) !== null) {
                    return ['intent' => self::TOOL_COMMAND, 'confidence' => 'high', 'command' => $cmd];
                }
            } catch (Throwable $e) {
            }
            // /lenh la ma khong dang ky -> co the la cau hoi AI
            return ['intent' => self::CHAT, 'confidence' => 'low', 'text' => $t];
        }
        // 2. DEV_FOLLOWUP: dang co active dev job + khong phai lenh moi ro rang
        $activeJob = (string)($ctx['active_dev_job'] ?? '');
        if ($activeJob !== '' && !self::looksLikeNewRequest($low)) {
            return ['intent' => self::DEV_FOLLOWUP, 'confidence' => 'medium', 'dev_job' => $activeJob];
        }
        // Mention DEV-xxx / DIAG-xxx cu the
        if (preg_match('/dev-(\d{1,6})/i', $t, $m)) {
            // "fix DEV-204" -> DEV_REQUEST voi context; "DEV-204 sao roi" -> followup
            if (self::hasAny($low, ['sửa', 'sua', 'fix', 'code', 'triển khai', 'trien khai'])) {
                return ['intent' => self::DEV_REQUEST, 'confidence' => 'high', 'dev_job' => 'DEV-' . $m[1],
                    'from_ref' => true];
            }
            return ['intent' => self::DEV_FOLLOWUP, 'confidence' => 'medium', 'dev_job' => 'DEV-' . $m[1]];
        }
        if (preg_match('/diag-(\d{1,6})/i', $t, $m)) {
            if (self::hasAny($low, ['sửa', 'sua', 'fix', 'code'])) {
                return ['intent' => self::DEV_REQUEST, 'confidence' => 'high', 'diag' => 'DIAG-' . $m[1],
                    'from_ref' => true];
            }
            return ['intent' => self::BUG_ANALYSIS, 'confidence' => 'medium', 'diag' => 'DIAG-' . $m[1]];
        }
        // "fix loi do" (coi DIAG vua tao gan nhat cua chat)
        if (preg_match('/fix (lỗi|lỗi đó|loi|bug).{0,10}(đó|do|nay|này)?/i', $t)
            || str_contains($low, 'sửa lỗi đó') || str_contains($low, 'sua loi do')) {
            if (!empty($ctx['last_diag'])) {
                return ['intent' => self::DEV_REQUEST, 'confidence' => 'medium',
                    'diag' => $ctx['last_diag'], 'from_ref' => true];
            }
        }
        // 3. DEV_REQUEST truoc PLAN — tru khi cau mo dau bang dong tu LAP PLAN
        // ("len phuong an code module X" = PLAN; "bat dau code theo phuong an" = DEV).
        $startsPlan = (bool)preg_match('/^(lên|lập|len|lap|thiết kế|thiet ke|đề xuất|de xuat|cho tôi|cho toi|hay|hãy)\b/u', $low);
        $execMarker = self::hasAny($low, ['bắt đầu code', 'bat dau code', 'code luôn', 'code luon',
            'code theo', 'sửa lỗi', 'sua loi', 'fix ', 'triển khai', 'trien khai', 'code cho']);
        if ($execMarker || !$startsPlan) {
            if (self::hasAny($low, ['bắt đầu code', 'bat dau code', 'code luôn', 'code luon',
                'code theo', 'code cho', 'code module', 'sửa lỗi', 'sua loi', 'fix bug', 'fix lỗi',
                'sửa bug', 'sua bug', 'triển khai code', 'trien khai', 'viết module', 'viet module',
                'tạo module', 'tao module', 'thêm module', 'them module', 'implement',
                'sửa code', 'sua code', 'fix '])) {
                return ['intent' => self::DEV_REQUEST, 'confidence' => 'high'];
            }
        }
        // 4. PLAN_REQUEST
        if (self::hasAny($low, ['lập kế hoạch', 'lap ke hoach', 'lên phương án', 'len phuong an', 'phương án',
            'phuong an', 'lên plan', 'len plan', 'thiết kế giải pháp', 'thiet ke giai phap', 'đề xuất kiến trúc'])) {
            return ['intent' => self::PLAN_REQUEST, 'confidence' => 'high'];
        }
        // 4b. CODE_REVIEW / TEST_REQUEST
        if (self::hasAny($low, ['review code', 'review giúp', 'review giup', 'kiểm tra code', 'kiem tra code',
            'đánh giá code', 'danh gia code'])) {
            return ['intent' => self::CODE_REVIEW, 'confidence' => 'medium'];
        }
        if (self::hasAny($low, ['chạy test', 'chay test', 'test thử', 'test thu', 'kiểm thử', 'kiem thu'])) {
            return ['intent' => self::TEST_REQUEST, 'confidence' => 'medium'];
        }
        // 4c. ARCHITECTURE_QUESTION (kien truc tong the)
        if (self::hasAny($low, ['kiến trúc tổng', 'kien truc tong', 'architecture', 'toàn bộ hệ thống',
            'toan bo he thong', 'luồng hoạt động', 'luong hoat dong', 'thiết kế tổng', 'thiet ke tong'])) {
            return ['intent' => self::ARCHITECTURE_QUESTION, 'confidence' => 'medium'];
        }
        // 4d. BUG_ANALYSIS (phan tich bug, chua chac doi fix ngay)
        if (self::hasAny($low, ['phân tích lỗi', 'phan tich loi', 'phân tích bug', 'phan tich bug',
            'bug này', 'bug nay', 'lỗi này', 'loi nay'])) {
            return ['intent' => self::BUG_ANALYSIS, 'confidence' => 'medium'];
        }
        // 5. HYBRID_DIAGNOSIS (tai sao + su co runtime)
        if (self::hasAny($low, ['tại sao', 'tai sao', 'vì sao', 'vi sao', 'lỗi gì', 'loi gi', 'bị gì', 'bi gi',
            'không hoạt động', 'khong hoat dong', 'offline', 'chết', 'chet', 'treo', 'đơ', 'sập', 'sap'])
            && self::hasAny($low, ['telegram', 'receiver', 'polling', 'worker', 'chrome', 'kênh', 'kenh',
                'proxy', 'tool', 'hệ thống', 'he thong', 'job', 'đánh giá', 'danh gia'])) {
            return ['intent' => self::HYBRID_DIAGNOSIS, 'confidence' => 'medium'];
        }
        // 6. RUNTIME_QUESTION
        if (self::hasAny($low, ['đang chạy không', 'dang chay khong', 'có chạy không', 'co chay khong',
            'trạng thái hiện tại', 'trang thai hien tai', 'bao nhiêu', 'bao nhieu', 'mấy ', 'mấy kênh',
            'có online', 'co online', 'health', 'tình hình', 'tinh hinh'])) {
            return ['intent' => self::RUNTIME_QUESTION, 'confidence' => 'medium'];
        }
        // 7. PROJECT_QUESTION (source/architecture)
        if (self::hasAny($low, ['nằm ở đâu', 'nam o dau', 'file nào', 'file nao', 'class nào', 'class nao',
            'hoạt động thế nào', 'hoat dong the nao', 'kiến trúc', 'kien truc', 'module nào', 'module nao',
            'code ở đâu', 'code o dau', 'hàm nào', 'ham nao', 'lưu ở đâu', 'luu o dau', 'database', 'bảng nào',
            'bang nao', 'giải thích', 'giai thich'])) {
            return ['intent' => self::PROJECT_QUESTION, 'confidence' => 'medium'];
        }
        // 8. CHAT mac dinh (confidence thap neu dai/mo ho giua Q&A va dev)
        if (mb_strlen($t) > 200) {
            return ['intent' => self::CLARIFY, 'confidence' => 'low'];
        }
        return ['intent' => self::CHAT, 'confidence' => 'low'];
    }

    private static function hasAny(string $hay, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($hay, $n)) return true;
        }
        return false;
    }

    /** Co phai yeu cau moi doc lap (khong phai followup)? */
    private static function looksLikeNewRequest(string $low): bool
    {
        return self::hasAny($low, ['code ', 'lập kế hoạch', 'lap ke hoach', 'phương án', 'phuong an',
            'thêm module', 'them module', 'kiểm tra ', 'kiem tra ', 'restart', 'trạng thái', 'trang thai',
            '/dev close', 'kết thúc', 'ket thuc']);
    }

    /** Role toi thieu cho intent. */
    public static function requiredRole(string $intent): string
    {
        return match ($intent) {
            self::PLAN_REQUEST, self::DEV_REQUEST, self::DEV_FOLLOWUP,
            self::CODE_REVIEW, self::TEST_REQUEST, self::BUG_ANALYSIS => 'DEVELOPER',
            self::TOOL_COMMAND => 'COMMAND',
            default => 'VIEWER',
        };
    }

    /**
     * Entity extraction: channel id, module, problem, refs.
     * @return array{channel_id?, module?, problem?, diag?, dev_job?}
     */
    public static function extractEntities(string $text): array
    {
        $out = [];
        $low = mb_strtolower($text);
        if (preg_match('/k[eê]nh\s+(\d{1,5})/u', $low, $m)) $out['channel_id'] = (int)$m[1];
        if (preg_match('/profile\s+(\d{1,5})/i', $text, $m)) $out['channel_id'] = (int)$m[1];
        if (preg_match('/\b(DEV-\d{1,6})\b/i', $text, $m)) $out['dev_job'] = strtoupper($m[1]);
        if (preg_match('/\b(DIAG-\d{1,6})\b/i', $text, $m)) $out['diag'] = strtoupper($m[1]);
        $modMap = ['telegram' => 'TELEGRAM', 'receiver' => 'TELEGRAM', 'polling' => 'TELEGRAM',
            'chrome' => 'CHROME', 'kênh' => 'CHANNEL', 'kenh' => 'CHANNEL',
            'đánh giá' => 'EVALUATION', 'danh gia' => 'EVALUATION', 'evaluation' => 'EVALUATION',
            'proxy' => 'PROXY', 'activity' => 'AUTO_ACTIVITY', 'nuôi mail' => 'AUTO_ACTIVITY',
            'job' => 'JOBS', 'thông báo' => 'NOTIFICATION', 'report' => 'NOTIFICATION',
            'sức khỏe' => 'HEALTH', 'lịch' => 'SCHEDULER', 'sync' => 'SYNCHRONIZE'];
        foreach ($modMap as $k => $v) {
            if (str_contains($low, $k)) {
                $out['module'] = $v;
                break;
            }
        }
        foreach (['login', 'auth', 'timeout', 'offline', 'chết', 'chet', 'treo', 'lỗi', 'loi', 'bug',
            'chậm', 'cham', 'sai', 'thiếu', 'thieu'] as $p) {
            if (str_contains($low, $p)) {
                $out['problem'] = $p;
                break;
            }
        }
        return $out;
    }
}
