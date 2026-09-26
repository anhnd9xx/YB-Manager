<?php
declare(strict_types=1);
/**
 * AIDevConsole - Cua ngo AI Dev qua Telegram (§1).
 * Telegram text KHONG bao gio la shell: chi parse intent -> validated request
 * -> service/job da dang ky. Tat ca code chay trong worktree + approval.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class AIDevConsole
{
    /** @return string[] ten lenh AI (worker uu tien COMMAND mode) */
    public static function commands(): array
    {
        return ['ai', 'plan', 'dev', 'devjobs', 'devclose', 'aiapprove', 'aireject'];
    }

    public static function register(): void
    {
        try {
            require_once __DIR__ . '/CommandRegistry.php';
            require_once __DIR__ . '/PermissionService.php';
            $defs = [
                ['name' => 'ai', 'module' => 'AIDEV', 'required_role' => 'VIEWER',
                    'description' => 'Hỏi về project YT Manager', 'args_hint' => '<câu hỏi>',
                    'handler' => [self::class, 'cmdAi']],
                ['name' => 'plan', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Lập phương án phát triển', 'args_hint' => '<yêu cầu>',
                    'handler' => [self::class, 'cmdPlan']],
                ['name' => 'dev', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Code theo yêu cầu (worktree + duyệt)', 'args_hint' => '<yêu cầu>',
                    'handler' => [self::class, 'cmdDev']],
                ['name' => 'devjobs', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Dev Jobs đang hoạt động', 'handler' => [self::class, 'cmdDevJobs']],
                ['name' => 'devclose', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Kết thúc phiên Dev', 'handler' => [self::class, 'cmdDevClose']],
                ['name' => 'aiapprove', 'module' => 'AIDEV', 'required_role' => 'ADMIN',
                    'description' => 'Duyệt Dev Job', 'args_hint' => '<DEV-id>',
                    'handler' => [self::class, 'cmdApprove']],
                ['name' => 'aireject', 'module' => 'AIDEV', 'required_role' => 'ADMIN',
                    'description' => 'Từ chối Dev Job', 'args_hint' => '<DEV-id>',
                    'handler' => [self::class, 'cmdReject']],
            ];
            foreach ($defs as $d) CommandRegistry::register($d);
        } catch (Throwable $e) {
        }
    }

    // ================= Command handlers (CommandRequest -> text) =================

    /** @return array{text} */
    public static function cmdAi(array $req): array
    {
        $args = trim((string)($req['args'] ?? ''));
        if ($args === '') return ['text' => "Dùng: /ai <câu hỏi về project>\nVD: /ai Telegram receiver nằm ở đâu?"];
        $chatId = (string)($req['chat_id'] ?? '');
        self::reply($chatId, '📚 Đang hỏi OpenCode, chờ chút...');
        self::spawnAnswer($chatId, (string)($req['user_id'] ?? ''),
            (string)($req['role'] ?? 'VIEWER'), 'qa', $args);
        return ['text' => ''];
    }

    public static function cmdPlan(array $req): array
    {
        $args = trim((string)($req['args'] ?? ''));
        if ($args === '') return ['text' => "Dùng: /plan <yêu cầu>\nVD: /plan thêm module Scheduler"];
        if (!PermissionService::canDev((string)($req['role'] ?? ''))) {
            return ['text' => '⛔ Cần quyền DEVELOPER để lập phương án.'];
        }
        $chatId = (string)($req['chat_id'] ?? '');
        self::reply($chatId, '📋 Đang lập phương án, tôi nhắn khi xong...');
        self::spawnAnswer($chatId, (string)($req['user_id'] ?? ''),
            (string)($req['role'] ?? ''), 'plan', $args);
        return ['text' => ''];
    }

    public static function cmdDev(array $req): array
    {
        $args = trim((string)($req['args'] ?? ''));
        if ($args === '') return ['text' => "Dùng: /dev <yêu cầu code>\nVD: /dev thêm model ScheduledJob"];
        if (!PermissionService::canDev((string)($req['role'] ?? ''))) {
            return ['text' => '⛔ Cần quyền DEVELOPER để tạo Dev Job.'];
        }
        return self::offerDev((string)($req['chat_id'] ?? ''), $args, true);
    }

    public static function cmdDevJobs(array $req): array
    {
        try {
            require_once __DIR__ . '/DevJobManager.php';
            $rows = DevJobManager::activeForProject();
            if (!$rows) return ['text' => 'Không có Dev Job đang hoạt động.'];
            $lines = [];
            foreach (array_slice($rows, 0, 8) as $j) {
                $lines[] = '🛠 ' . $j['job_code'] . ' · ' . $j['status'] . "\n" . mb_substr((string)$j['request'], 0, 80);
            }
            return ['text' => implode("\n\n", $lines)];
        } catch (Throwable $e) {
            return ['text' => 'Lỗi tải Dev Jobs.'];
        }
    }

    public static function cmdDevClose(array $req): array
    {
        require_once __DIR__ . '/DevJobManager.php';
        DevJobManager::clearActiveForChat((string)($req['chat_id'] ?? ''));
        return ['text' => 'Đã kết thúc phiên Dev.'];
    }

    public static function cmdApprove(array $req): array
    {
        $code = strtoupper(trim((string)($req['args'] ?? '')));
        if ($code === '') return ['text' => 'Dùng: /aiapprove <DEV-id>'];
        if (!PermissionService::canApprove((string)($req['role'] ?? ''))) {
            return ['text' => '⛔ Cần quyền ADMIN để duyệt.'];
        }
        require_once __DIR__ . '/DevJobManager.php';
        $r = DevJobManager::approve($code, 'telegram:' . ($req['user_id'] ?? ''));
        if (empty($r['ok']) && ($r['need'] ?? '') === 'CONFIRM2') {
            self::sendWithButtons((string)($req['chat_id'] ?? ''),
                '⚠ ' . ($r['message'] ?? 'Rủi ro cao') . "\nJob: $code",
                [['Tiếp tục duyệt', 'aidev:approve2:' . $code], ['Hủy', 'aidev:cancel:']]);
            return ['text' => ''];
        }
        if (empty($r['ok'])) return ['text' => '❌ ' . ($r['error'] ?? 'Lỗi')];
        // Apply ngay sau approve? Theo flow: approve -> user quyet dinh apply rieng.
        self::sendWithButtons((string)($req['chat_id'] ?? ''),
            "✅ $code đã duyệt.\nÁp dụng vào main?",
            [['Áp dụng', 'aidev:apply:' . $code], ['Để sau', 'aidev:cancel:']]);
        return ['text' => ''];
    }

    public static function cmdReject(array $req): array
    {
        $code = strtoupper(trim((string)($req['args'] ?? '')));
        if ($code === '') return ['text' => 'Dùng: /aireject <DEV-id>'];
        if (!PermissionService::canApprove((string)($req['role'] ?? ''))) {
            return ['text' => '⛔ Cần quyền ADMIN để từ chối.'];
        }
        require_once __DIR__ . '/DevJobManager.php';
        $r = DevJobManager::reject($code, 'telegram:' . ($req['user_id'] ?? ''));
        return ['text' => empty($r['ok']) ? '❌ ' . ($r['error'] ?? 'Lỗi') : "Đã từ chối $code. Main không đổi."];
    }

    // ================= Plain-text dispatch =================

    /**
     * Xu ly text thuong tu user hop le. Tra true neu da xu ly (worker return).
     */
    public static function handleText(string $chatId, string $userId, string $role, string $text): bool
    {
        try {
            require_once __DIR__ . '/AIIntentRouter.php';
            require_once __DIR__ . '/DevJobManager.php';
            // Setup session dang mo -> uu tien pairing, khong AI chen ngang
            try {
                require_once __DIR__ . '/TelegramSetup.php';
                if (TelegramSetup::active() !== null) return false;
            } catch (Throwable $e) {
            }
            $active = DevJobManager::activeForChat($chatId);
            // Rate limit: AI calls dat (10/phut/chat, chung voi command)
            $rate = PermissionService::rateCheck($chatId);
            if (empty($rate['ok'])) {
                self::reply($chatId, '⏳ Quá nhiều yêu cầu, thử lại sau 1 phút.');
                return true;
            }
            $cl = AIIntentRouter::classify($text, ['active_dev_job' => $active]);
            $intent = $cl['intent'];
            switch ($intent) {
                case AIIntentRouter::DEV_FOLLOWUP: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER.');
                        return true;
                    }
                    $job = DevJobManager::get((string)($cl['dev_job'] ?? $active));
                    if (!$job) {
                        DevJobManager::clearActiveForChat($chatId);
                        return false;
                    }
                    DevJobManager::setActiveForChat($chatId, (string)$job['job_code']);
                    $r = DevJobManager::followup((string)$job['job_code'], $text, 'telegram:' . $userId);
                    self::reply($chatId, empty($r['ok'])
                        ? '❌ ' . ($r['error'] ?? 'Lỗi')
                        : "💬 Đã gửi vào " . $job['job_code'] . '. AI đang xử lý, tôi báo khi có milestone.');
                    return true;
                }
                case AIIntentRouter::PLAN_REQUEST: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER để lập phương án.');
                        return true;
                    }
                    self::reply($chatId, '📋 Đang lập phương án, tôi nhắn khi xong...');
                    self::spawnAnswer($chatId, $userId, $role, 'plan', $text);
                    return true;
                }
                case AIIntentRouter::DEV_REQUEST: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER để tạo Dev Job.');
                        return true;
                    }
                    $r = self::offerDev($chatId, $text, false);
                    if (($r['__buttons'] ?? null)) self::sendWithButtons($chatId, $r['text'], $r['__buttons']);
                    else self::reply($chatId, $r['text']);
                    return true;
                }
                case AIIntentRouter::RUNTIME_QUESTION:
                    self::reply($chatId, self::answerRuntime($text));
                    return true;
                case AIIntentRouter::HYBRID_DIAGNOSIS: {
                    self::reply($chatId, '🔎 Đang phân tích project + runtime...');
                    self::spawnAnswer($chatId, $userId, $role, 'hybrid', $text);
                    return true;
                }
                case AIIntentRouter::PROJECT_QUESTION: {
                    self::reply($chatId, '📚 Đang hỏi OpenCode, chờ chút...');
                    self::spawnAnswer($chatId, $userId, $role, 'qa', $text);
                    return true;
                }
                case AIIntentRouter::CLARIFY:
                    self::sendWithButtons($chatId,
                        'Bạn muốn tôi làm gì với yêu cầu này?',
                        [['Hỏi về project', 'aidev:qa:'], ['Lập phương án', 'aidev:plan:'], ['Bỏ qua', 'aidev:cancel:']]);
                    set_setting('ai_pending_clarify_' . md5($chatId), mb_substr($text, 0, 1000));
                    return true;
                default:
                    return false; // CHAT mo: de worker xu ly nhu cu (log)
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    // ================= Q&A / Plan / Diagnosis =================

    /** @return array{text} */
    public static function answerQuestion(string $chatId, string $userId, string $question,
        string $role, bool $fromCommand): array
    {
        if (get_setting('ai_qa_enabled', '1') !== '1') {
            return ['text' => 'Chế độ Hỏi đáp AI đang tắt.'];
        }
        $oc = self::ensureOC($chatId);
        if ($oc !== '') return ['text' => $oc];
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            require_once __DIR__ . '/DevProjectRegistry.php';
            $cx = ProjectContextService::build(null, 5000);
            if (empty($cx['ok'])) return ['text' => '❌ ' . ($cx['error'] ?? 'Lỗi context')];
            $before = self::gitHead((string)$cx['root']);
            $prompt = "Trả lời câu hỏi về project (CHỈ ĐỌC, TUYỆT ĐỐI KHÔNG sửa file, không chạy lệnh destructive):\n"
                . "CÂU HỎI: $question\nCONTEXT:\n" . $cx['context']
                . "\nTrả lời gọn, nêu file/function cụ thể.";
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Q&A', 'directory' => (string)$cx['root'], 'timeout' => 240]);
            if (empty($r['ok'])) return ['text' => '❌ OpenCode: ' . ($r['error'] ?? 'lỗi')];
            // Guard: Q&A khong duoc doi file (§10)
            $after = self::gitHead((string)$cx['root']);
            $warn = ($before !== '' && $after !== '' && $before !== $after)
                ? "\n\n⚠ OpenCode đã thay đổi file ngoài ý muốn — kiểm tra `git status` trên Tool."
                : '';
            $txt = '📚 ' . mb_substr(trim((string)$r['text']), 0, 3500) . $warn;
            self::logAi($chatId, $txt);
            return ['text' => $txt];
        } catch (Throwable $e) {
            return ['text' => '❌ Lỗi: ' . mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** @return array{text, __buttons?} */
    public static function startPlan(string $chatId, string $userId, string $request, bool $fromCommand): array
    {
        if (get_setting('ai_plan_enabled', '1') !== '1') {
            return ['text' => 'Chế độ Plan đang tắt.'];
        }
        $oc = self::ensureOC($chatId);
        if ($oc !== '') return ['text' => $oc];
        self::reply($chatId, '📋 Đang lập phương án, chờ chút...');
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            $cx = ProjectContextService::build(null, 5000);
            if (empty($cx['ok'])) return ['text' => '❌ ' . ($cx['error'] ?? 'Lỗi context')];
            $prompt = "Lập PHƯƠNG ÁN (KHÔNG CODE, không sửa file):\nYÊU CẦU: $request\nCONTEXT:\n" . $cx['context']
                . "\nOutput: Tóm tắt | Kiến trúc | Files ảnh hưởng | Migration DB (nếu có) | Rủi ro | Test plan.";
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Plan', 'directory' => (string)$cx['root'], 'timeout' => 300]);
            if (empty($r['ok'])) return ['text' => '❌ OpenCode: ' . ($r['error'] ?? 'lỗi')];
            $plan = mb_substr(trim((string)$r['text']), 0, 3000);
            set_setting('ai_plan_text_' . md5($chatId), $plan);
            set_setting('ai_plan_req_' . md5($chatId), mb_substr($request, 0, 1000));
            if (!empty($r['session_id'])) {
                set_setting('ai_plan_session_' . md5($chatId), (string)$r['session_id']);
            }
            self::logAi($chatId, $plan);
            return ['text' => "📋 Phương án:\n\n$plan",
                '__buttons' => [['🛠 Bắt đầu code', 'aidev:startcode:'], ['💬 Hỏi thêm', 'aidev:askmore:'], ['❌ Bỏ', 'aidev:cancel:']]];
        } catch (Throwable $e) {
            return ['text' => '❌ Lỗi: ' . mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** @return array{text, __buttons?} */
    public static function offerDev(string $chatId, string $request, bool $fromCommand): array
    {
        if (get_setting('ai_dev_enabled', '1') !== '1') {
            return ['text' => 'Chế độ Dev Job đang tắt.'];
        }
        set_setting('ai_pending_dev_' . md5($chatId), mb_substr($request, 0, 1000));
        return ['text' => "Tôi có thể phân tích trước cho chắc.\nYêu cầu: " . mb_substr($request, 0, 300),
            '__buttons' => [['📋 Lập kế hoạch', 'aidev:mkplan:'], ['🛠 Code luôn', 'aidev:codego:'], ['❌ Hủy', 'aidev:cancel:']]];
    }

    public static function answerRuntime(string $text): string
    {
        try {
            require_once __DIR__ . '/SystemHealthService.php';
            $o = SystemHealthService::overall();
            $low = mb_strtolower($text);
            $lines = ['🖥 ' . ($o['label'] ?? '')];
            if (str_contains($low, 'telegram') || str_contains($low, 'receiver') || str_contains($low, 'polling')) {
                require_once __DIR__ . '/TelegramSupervisor.php';
                $h = TelegramSupervisor::health();
                $lines[] = 'Telegram: ' . ($h['state'] ?? '?') . ' · worker ' . (!empty($h['worker_alive']) ? 'ALIVE' : 'DEAD')
                    . ' · uptime ' . ($h['uptime'] ?? '—');
            }
            if (str_contains($low, 'kênh') || str_contains($low, 'kenh') || str_contains($low, 'chrome')) {
                try {
                    $tot = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
                    $run = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
                    $lines[] = "Kênh: $run/$tot đang chạy";
                } catch (Throwable $e) {
                }
            }
            if (str_contains($low, 'proxy')) {
                try {
                    $tot = (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
                    $dead = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='dead'")->fetchColumn();
                    $lines[] = 'Proxy: ' . ($tot - $dead) . "/$tot khỏe";
                } catch (Throwable $e) {
                }
            }
            if (str_contains($low, 'job')) {
                try {
                    require_once __DIR__ . '/JobManager.php';
                    $n = (int)db()->query("SELECT COUNT(*) FROM app_jobs WHERE status IN ('QUEUED','RUNNING','PAUSED')")->fetchColumn();
                    $lines[] = "Job active: $n";
                } catch (Throwable $e) {
                }
            }
            return implode("\n", $lines);
        } catch (Throwable $e) {
            return '❌ Không đọc được trạng thái.';
        }
    }

    /** @return array{text, __buttons?} */
    public static function hybridDiagnosis(string $chatId, string $userId, string $role, string $text): array
    {
        $runtime = self::answerRuntime($text);
        // Can OpenCode?
        $needOc = PermissionService::canDev($role) || true; // Q&A cho moi role
        $oc = self::ensureOC($chatId, true);
        if ($oc !== '') {
            return ['text' => "🔎 Runtime hiện tại:\n$runtime\n\n($oc)"];
        }
        self::reply($chatId, '🔎 Đang phân tích project + runtime...');
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            $cx = ProjectContextService::build(null, 4000);
            if (empty($cx['ok'])) return ['text' => "🔎 Runtime:\n$runtime"];
            $prompt = "Chẩn đoán sự cố (CHỈ ĐỌC/phân tích, không sửa file):\nVẤN ĐỀ USER BÁO: $text\n"
                . "RUNTIME THỰC TẾ:\n$runtime\nCONTEXT:\n" . $cx['context']
                . "\nTrả lời: Root cause likely + files liên quan + bước kiểm tra tiếp.";
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Diagnosis', 'directory' => (string)$cx['root'], 'timeout' => 300]);
            if (empty($r['ok'])) return ['text' => "🔎 Runtime:\n$runtime\n\n(OpenCode: " . ($r['error'] ?? 'lỗi') . ')'];
            $diag = mb_substr(trim((string)$r['text']), 0, 3000);
            set_setting('ai_plan_text_' . md5($chatId), $diag);
            set_setting('ai_plan_req_' . md5($chatId), 'Sửa lỗi: ' . mb_substr($text, 0, 500));
            self::logAi($chatId, $diag);
            $buttons = null;
            if (PermissionService::canDev($role)) {
                $buttons = [['🛠 Sửa lỗi', 'aidev:startcode:'], ['📄 Chi tiết đủ', 'aidev:cancel:']];
            }
            return ['text' => "🔎 Chẩn đoán:\n\n$diag", '__buttons' => $buttons];
        } catch (Throwable $e) {
            return ['text' => "🔎 Runtime:\n$runtime"];
        }
    }

    // ================= Callbacks (dev:*) =================

    public static function handleCallback(string $chatId, string $userId, string $role, string $data): bool
    {
        if (!str_starts_with($data, 'aidev:') && !str_starts_with($data, 'dev:')) return false;
        try {
            $parts = explode(':', $data, 3);
            $act = ($parts[0] ?? '') . ':' . ($parts[1] ?? '');
            $arg = $parts[2] ?? '';
            // Callback dat tien (kick job/apply/approve) cung rate limit
            if (in_array($act, ['aidev:mkplan', 'aidev:codego', 'aidev:startcode', 'aidev:apply',
                'aidev:apply2', 'aidev:approve2', 'aidev:plan', 'aidev:qa'], true)) {
                require_once __DIR__ . '/PermissionService.php';
                $rate = PermissionService::rateCheck($chatId);
                if (empty($rate['ok'])) {
                    self::reply($chatId, '⏳ Quá nhiều yêu cầu, thử lại sau 1 phút.');
                    return true;
                }
            }
            switch ($act) {
                case 'aidev:cancel':
                    self::clearPending($chatId);
                    self::reply($chatId, 'Đã hủy.');
                    return true;
                case 'aidev:ocstart': {
                    try {
                        require_once __DIR__ . '/OpenCodeService.php';
                        $r = OpenCodeService::start();
                        self::reply($chatId, !empty($r['ok'])
                            ? '✅ OpenCode đã khởi động.'
                            : '❌ ' . ($r['message'] ?? 'Không khởi động được'));
                    } catch (Throwable $e) {
                        self::reply($chatId, '❌ Lỗi khởi động.');
                    }
                    return true;
                }
                case 'aidev:mkplan': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER.');
                        return true;
                    }
                    $req = (string)get_setting('ai_pending_dev_' . md5($chatId), '');
                    if ($req === '') {
                        self::reply($chatId, 'Hết hạn yêu cầu, gửi lại giúp tôi.');
                        return true;
                    }
                    self::clearPending($chatId);
                    $r = self::startPlan($chatId, $userId, $req, false);
                    if (($r['__buttons'] ?? null)) self::sendWithButtons($chatId, $r['text'], $r['__buttons']);
                    else self::reply($chatId, $r['text']);
                    return true;
                }
                case 'aidev:codego': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER.');
                        return true;
                    }
                    $req = (string)get_setting('ai_pending_dev_' . md5($chatId), '');
                    if ($req === '') {
                        self::reply($chatId, 'Hết hạn yêu cầu, gửi lại giúp tôi.');
                        return true;
                    }
                    self::clearPending($chatId);
                    self::kickDevJob($chatId, $userId, $req, '');
                    return true;
                }
                case 'aidev:startcode': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER.');
                        return true;
                    }
                    $req = (string)get_setting('ai_plan_req_' . md5($chatId), '');
                    $plan = (string)get_setting('ai_plan_text_' . md5($chatId), '');
                    if ($req === '') {
                        self::reply($chatId, 'Hết hạn phương án, yêu cầu lại giúp tôi.');
                        return true;
                    }
                    self::clearPending($chatId);
                    self::kickDevJob($chatId, $userId, $req, $plan);
                    return true;
                }
                case 'aidev:askmore':
                    self::reply($chatId, 'Bạn cứ nhắn câu hỏi thêm, tôi trả lời trong context phương án này.');
                    return true;
                case 'aidev:qa': {
                    $t = (string)get_setting('ai_pending_clarify_' . md5($chatId), '');
                    self::clearPending($chatId);
                    if ($t === '') {
                        self::reply($chatId, 'Hết hạn, gửi lại giúp tôi.');
                        return true;
                    }
                    $r = self::answerQuestion($chatId, $userId, $t, $role, false);
                    self::reply($chatId, $r['text']);
                    return true;
                }
                case 'aidev:plan': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, '⛔ Cần quyền DEVELOPER.');
                        return true;
                    }
                    $t = (string)get_setting('ai_pending_clarify_' . md5($chatId), '');
                    self::clearPending($chatId);
                    if ($t === '') {
                        self::reply($chatId, 'Hết hạn, gửi lại giúp tôi.');
                        return true;
                    }
                    $r = self::startPlan($chatId, $userId, $t, false);
                    if (($r['__buttons'] ?? null)) self::sendWithButtons($chatId, $r['text'], $r['__buttons']);
                    else self::reply($chatId, $r['text']);
                    return true;
                }
                case 'aidev:approve2': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, '⛔ Cần quyền ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $r = DevJobManager::approve($arg, 'telegram:' . $userId, true);
                    self::reply($chatId, empty($r['ok']) ? '❌ ' . ($r['error'] ?? 'Lỗi') : "✅ $arg đã duyệt (high-risk confirmed).");
                    return true;
                }
                case 'aidev:apply': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, '⛔ Cần quyền ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $job = DevJobManager::get($arg);
                    if (!$job) {
                        self::reply($chatId, 'Không thấy job.');
                        return true;
                    }
                    if (!empty($job['has_db_migration'])) {
                        self::sendWithButtons($chatId, "⚠ $arg có DB migration — apply code không rollback DB.",
                            [['Tiếp tục áp dụng', 'aidev:apply2:' . $arg], ['Hủy', 'aidev:cancel:']]);
                        return true;
                    }
                    $r = DevJobManager::apply($arg, 'telegram:' . $userId);
                    self::reply($chatId, empty($r['ok']) ? '❌ ' . ($r['error'] ?? 'Lỗi')
                        : "✅ $arg applied: " . ($r['commit'] ?? ''));
                    return true;
                }
                case 'aidev:apply2': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, '⛔ Cần quyền ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $r = DevJobManager::apply($arg, 'telegram:' . $userId);
                    self::reply($chatId, empty($r['ok']) ? '❌ ' . ($r['error'] ?? 'Lỗi')
                        : "✅ $arg applied: " . ($r['commit'] ?? ''));
                    return true;
                }
                case 'dev:status': {
                    require_once __DIR__ . '/DevJobManager.php';
                    $job = DevJobManager::get($arg);
                    if ($job) {
                        DevJobManager::pollProgress($arg);
                        $job = DevJobManager::get($arg);
                        self::reply($chatId, self::progressText($job));
                    }
                    return true;
                }
                default:
                    return false;
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Tao Dev Job + prepare + coding, bao tien do gon (khong spam token). */
    public static function kickDevJob(string $chatId, string $userId, string $request, string $plan): void
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            require_once __DIR__ . '/DevJobManager.php';
            $p = DevProjectRegistry::primary();
            if (!$p) {
                self::reply($chatId, '❌ Chưa có project.');
                return;
            }
            $job = DevJobManager::create((int)$p['id'], $request, 'TELEGRAM', $userId,
                $plan !== '' ? ['plan_text' => $plan] : []);
            if (!$job) {
                self::reply($chatId, '❌ Không tạo được Dev Job.');
                return;
            }
            $code = (string)$job['job_code'];
            DevJobManager::setActiveForChat($chatId, $code);
            self::reply($chatId, "🛠 $code đã tạo. Đang chuẩn bị worktree cách ly...");
            $pr = DevJobManager::prepare($code, 'telegram:' . $userId);
            if (empty($pr['ok'])) {
                $msg = ($pr['need'] ?? '') === 'DIRTY'
                    ? '⚠ ' . ($pr['message'] ?? 'Main tree dirty')
                    : '❌ ' . ($pr['error'] ?? 'Lỗi prepare');
                self::reply($chatId, $msg . "\nJob: $code");
                return;
            }
            $st = DevJobManager::startCoding($code, 'telegram:' . $userId, $plan);
            if (empty($st['ok'])) {
                self::reply($chatId, '❌ ' . ($st['error'] ?? 'Lỗi') . "\nJob: $code");
                return;
            }
            self::sendWithButtons($chatId,
                "🛠 $code đang code trong worktree cách ly.\nMain project không đổi. Tôi báo khi xong.",
                [['Xem tiến độ', 'dev:status:' . $code]]);
        } catch (Throwable $e) {
            self::reply($chatId, '❌ Lỗi: ' . mb_substr($e->getMessage(), 0, 150));
        }
    }

    /** Spawn tien trinh tra loi async (khong block dispatcher). */
    private static function spawnAnswer(string $chatId, string $userId, string $role,
        string $kind, string $text): void
    {
        try {
            require_once __DIR__ . '/OpenCodeService.php';
            $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
                . 'ytm_ai_q_' . md5($chatId . microtime(true)) . '.txt';
            @file_put_contents($tmp, $text);
            OpenCodeService::spawnPhp(__DIR__ . '/../bin/ai_answer.php', [
                'chat' => $chatId, 'user' => $userId, 'role' => $role,
                'kind' => $kind, 'file' => $tmp]);
        } catch (Throwable $e) {
        }
    }

    /** @return string '' neu OK, message neu can bao */
    private static function ensureOC(string $chatId, bool $quiet = false): string
    {        try {
            require_once __DIR__ . '/OpenCodeService.php';
            $st = OpenCodeService::status();
            if (in_array($st['state'], [OpenCodeService::ST_ONLINE, OpenCodeService::ST_BUSY], true)) return '';
            // Thu autostart
            $ens = OpenCodeService::ensureRunning();
            if (!empty($ens['ok'])) return '';
            if (!$quiet) {
                self::sendWithButtons($chatId, '⚠ OpenCode hiện không hoạt động.',
                    [['Khởi động OpenCode', 'aidev:ocstart:'], ['Chỉ xem Runtime', 'aidev:cancel:']]);
            }
            return 'OpenCode hiện không hoạt động.';
        } catch (Throwable $e) {
            return 'OpenCode hiện không hoạt động.';
        }
    }

    private static function gitHead(string $root): string
    {
        try {
            require_once __DIR__ . '/DevJobManager.php';
            return DevJobManager::headCommit($root);
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function clearPending(string $chatId): void
    {
        foreach (['ai_pending_dev_', 'ai_pending_clarify_', 'ai_plan_text_', 'ai_plan_req_'] as $k) {
            set_setting($k . md5($chatId), '');
        }
    }

    public static function progressText(array $job): string
    {
        $stage = (string)($job['status'] ?? '');
        $elapsed = '';
        if (!empty($job['started_at'])) {
            $s = max(0, time() - strtotime((string)$job['started_at']));
            $elapsed = intdiv($s, 60) . 'm ' . ($s % 60) . 's';
        }
        $pct = ['QUEUED' => 5, 'PREPARING' => 10, 'ANALYZING' => 20, 'CODING' => 55,
            'TESTING' => 80, 'REVIEW_READY' => 100, 'APPROVED' => 100, 'APPLIED' => 100];
        $p = $pct[$stage] ?? 10;
        $bars = str_repeat('█', (int)($p / 10)) . str_repeat('░', 10 - (int)($p / 10));
        return "🛠 " . $job['job_code'] . "\n" . mb_substr((string)$job['request'], 0, 120)
            . "\n$bars $p%\nStage: $stage\nFiles changed: " . ($job['files_changed'] ?? 0)
            . "\nElapsed: $elapsed";
    }

    public static function reply(string $chatId, string $text): void
    {
        if ($text === '') return;
        try {
            require_once __DIR__ . '/TelegramGateway.php';
            require_once __DIR__ . '/ConversationService.php';
            // Telegram gioi han ~4096 ky tu
            foreach (self::splitMessage($text) as $part) {
                TelegramGateway::sendMessage($chatId, $part);
                ConversationService::log(ConversationService::OUT, $chatId, $part, ['type' => 'AI']);
            }
        } catch (Throwable $e) {
        }
    }

    /** @param array[] $buttons [[text, callback_data]] */
    public static function sendWithButtons(string $chatId, string $text, array $buttons): void
    {
        try {
            require_once __DIR__ . '/TelegramGateway.php';
            require_once __DIR__ . '/ConversationService.php';
            $rows = [];
            foreach ($buttons as $b) $rows[] = [$b];
            foreach (self::splitMessage($text) as $i => $part) {
                if ($i === 0) TelegramGateway::sendButtons($chatId, $part, $rows);
                else TelegramGateway::sendMessage($chatId, $part);
                ConversationService::log(ConversationService::OUT, $chatId, $part, ['type' => 'AI']);
            }
        } catch (Throwable $e) {
        }
    }

    /** @return string[] */
    private static function splitMessage(string $text): array
    {
        if (mb_strlen($text) <= 4000) return [$text];
        $parts = [];
        $cur = '';
        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($cur) + mb_strlen($line) + 1 > 4000) {
                $parts[] = $cur;
                $cur = '';
            }
            $cur .= ($cur === '' ? '' : "\n") . $line;
        }
        if ($cur !== '') $parts[] = $cur;
        return $parts;
    }

    private static function logAi(string $chatId, string $text): void
    {
        try {
            require_once __DIR__ . '/ConversationService.php';
            ConversationService::log(ConversationService::OUT, $chatId,
                mb_substr($text, 0, 2000), ['type' => 'AI']);
        } catch (Throwable $e) {
        }
    }
}
