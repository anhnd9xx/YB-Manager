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
        // Mention DEV-xxx cu the
        if (preg_match('/dev-(\d{1,6})/i', $t, $m)) {
            return ['intent' => self::DEV_FOLLOWUP, 'confidence' => 'medium', 'dev_job' => 'DEV-' . $m[1]];
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
            self::PLAN_REQUEST, self::DEV_REQUEST, self::DEV_FOLLOWUP => 'DEVELOPER',
            self::TOOL_COMMAND => 'COMMAND',
            default => 'VIEWER',
        };
    }
}
