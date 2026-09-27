<?php
declare(strict_types=1);
/**
 * AIDevConsole - Cua ngo AI Dev qua Telegram (Â§1).
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
                    'description' => 'Há»i vá» project YT Manager', 'args_hint' => '<cÃ¢u há»i>',
                    'handler' => [self::class, 'cmdAi']],
                ['name' => 'plan', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Láº­p phÆ°Æ¡ng Ã¡n phÃ¡t triá»ƒn', 'args_hint' => '<yÃªu cáº§u>',
                    'handler' => [self::class, 'cmdPlan']],
                ['name' => 'dev', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Code theo yÃªu cáº§u (worktree + duyá»‡t)', 'args_hint' => '<yÃªu cáº§u>',
                    'handler' => [self::class, 'cmdDev']],
                ['name' => 'devjobs', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Dev Jobs Ä‘ang hoáº¡t Ä‘á»™ng', 'handler' => [self::class, 'cmdDevJobs']],
                ['name' => 'devclose', 'module' => 'AIDEV', 'required_role' => 'DEVELOPER',
                    'description' => 'Káº¿t thÃºc phiÃªn Dev', 'handler' => [self::class, 'cmdDevClose']],
                ['name' => 'aiapprove', 'module' => 'AIDEV', 'required_role' => 'ADMIN',
                    'description' => 'Duyá»‡t Dev Job', 'args_hint' => '<DEV-id>',
                    'handler' => [self::class, 'cmdApprove']],
                ['name' => 'aireject', 'module' => 'AIDEV', 'required_role' => 'ADMIN',
                    'description' => 'Tá»« chá»‘i Dev Job', 'args_hint' => '<DEV-id>',
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
        if ($args === '') return ['text' => "DÃ¹ng: /ai <cÃ¢u há»i vá» project>\nVD: /ai Telegram receiver náº±m á»Ÿ Ä‘Ã¢u?"];
        $chatId = (string)($req['chat_id'] ?? '');
        self::reply($chatId, 'ðŸ“š Äang há»i OpenCode, chá» chÃºt...');
        self::spawnAnswer($chatId, (string)($req['user_id'] ?? ''),
            (string)($req['role'] ?? 'VIEWER'), 'qa', $args);
        return ['text' => ''];
    }

    public static function cmdPlan(array $req): array
    {
        $args = trim((string)($req['args'] ?? ''));
        if ($args === '') return ['text' => "DÃ¹ng: /plan <yÃªu cáº§u>\nVD: /plan thÃªm module Scheduler"];
        if (!PermissionService::canDev((string)($req['role'] ?? ''))) {
            return ['text' => 'â›” Cáº§n quyá»n DEVELOPER Ä‘á»ƒ láº­p phÆ°Æ¡ng Ã¡n.'];
        }
        $chatId = (string)($req['chat_id'] ?? '');
        self::reply($chatId, 'ðŸ“‹ Äang láº­p phÆ°Æ¡ng Ã¡n, tÃ´i nháº¯n khi xong...');
        self::spawnAnswer($chatId, (string)($req['user_id'] ?? ''),
            (string)($req['role'] ?? ''), 'plan', $args);
        return ['text' => ''];
    }

    public static function cmdDev(array $req): array
    {
        $args = trim((string)($req['args'] ?? ''));
        if ($args === '') return ['text' => "DÃ¹ng: /dev <yÃªu cáº§u code>\nVD: /dev thÃªm model ScheduledJob"];
        if (!PermissionService::canDev((string)($req['role'] ?? ''))) {
            return ['text' => 'â›” Cáº§n quyá»n DEVELOPER Ä‘á»ƒ táº¡o Dev Job.'];
        }
        return self::offerDev((string)($req['chat_id'] ?? ''), $args, true);
    }

    public static function cmdDevJobs(array $req): array
    {
        try {
            require_once __DIR__ . '/DevJobManager.php';
            $rows = DevJobManager::activeForProject();
            if (!$rows) return ['text' => 'KhÃ´ng cÃ³ Dev Job Ä‘ang hoáº¡t Ä‘á»™ng.'];
            $lines = [];
            foreach (array_slice($rows, 0, 8) as $j) {
                $lines[] = 'ðŸ›  ' . $j['job_code'] . ' Â· ' . $j['status'] . "\n" . mb_substr((string)$j['request'], 0, 80);
            }
            return ['text' => implode("\n\n", $lines)];
        } catch (Throwable $e) {
            return ['text' => 'Lá»—i táº£i Dev Jobs.'];
        }
    }

    public static function cmdDevClose(array $req): array
    {
        require_once __DIR__ . '/DevJobManager.php';
        DevJobManager::clearActiveForChat((string)($req['chat_id'] ?? ''));
        return ['text' => 'ÄÃ£ káº¿t thÃºc phiÃªn Dev.'];
    }

    public static function cmdApprove(array $req): array
    {
        $code = strtoupper(trim((string)($req['args'] ?? '')));
        if ($code === '') return ['text' => 'DÃ¹ng: /aiapprove <DEV-id>'];
        if (!PermissionService::canApprove((string)($req['role'] ?? ''))) {
            return ['text' => 'â›” Cáº§n quyá»n ADMIN Ä‘á»ƒ duyá»‡t.'];
        }
        require_once __DIR__ . '/DevJobManager.php';
        $r = DevJobManager::approve($code, 'telegram:' . ($req['user_id'] ?? ''));
        if (empty($r['ok']) && ($r['need'] ?? '') === 'CONFIRM2') {
            self::sendWithButtons((string)($req['chat_id'] ?? ''),
                'âš  ' . ($r['message'] ?? 'Rá»§i ro cao') . "\nJob: $code",
                [['Tiáº¿p tá»¥c duyá»‡t', 'aidev:approve2:' . $code], ['Há»§y', 'aidev:cancel:']]);
            return ['text' => ''];
        }
        if (empty($r['ok'])) return ['text' => 'âŒ ' . ($r['error'] ?? 'Lá»—i')];
        // Apply ngay sau approve? Theo flow: approve -> user quyet dinh apply rieng.
        self::sendWithButtons((string)($req['chat_id'] ?? ''),
            "âœ… $code Ä‘Ã£ duyá»‡t.\nÃp dá»¥ng vÃ o main?",
            [['Ãp dá»¥ng', 'aidev:apply:' . $code], ['Äá»ƒ sau', 'aidev:cancel:']]);
        return ['text' => ''];
    }

    public static function cmdReject(array $req): array
    {
        $code = strtoupper(trim((string)($req['args'] ?? '')));
        if ($code === '') return ['text' => 'DÃ¹ng: /aireject <DEV-id>'];
        if (!PermissionService::canApprove((string)($req['role'] ?? ''))) {
            return ['text' => 'â›” Cáº§n quyá»n ADMIN Ä‘á»ƒ tá»« chá»‘i.'];
        }
        require_once __DIR__ . '/DevJobManager.php';
        $r = DevJobManager::reject($code, 'telegram:' . ($req['user_id'] ?? ''));
        return ['text' => empty($r['ok']) ? 'âŒ ' . ($r['error'] ?? 'Lá»—i') : "ÄÃ£ tá»« chá»‘i $code. Main khÃ´ng Ä‘á»•i."];
    }

    // ================= Plain-text dispatch =================

    /**
     * Xu ly text thuong tu user hop le. Tra true neu da xu ly (worker return).
     * Giu tuong thich nguoc (worker cu / test).
     */
    public static function handleText(string $chatId, string $userId, string $role, string $text): bool
    {
        return self::handleTextWithIntent($chatId, $userId, $role, $text, '', [], []);
    }

    /**
     * Ban co intent/entity san (tu AIControlOrchestrator). Khong classify lai.
     */
    public static function handleTextWithIntent(string $chatId, string $userId, string $role,
        string $text, string $intent = '', array $cl = [], array $entity = []): bool
    {
        try {
            require_once __DIR__ . '/AIIntentRouter.php';
            require_once __DIR__ . '/DevJobManager.php';
            require_once __DIR__ . '/AIConversationContext.php';
            // Setup session dang mo -> uu tien pairing, khong AI chen ngang
            try {
                require_once __DIR__ . '/TelegramSetup.php';
                if (TelegramSetup::active() !== null) return false;
            } catch (Throwable $e) {
            }
            if ($intent === '') {
                $ctx = AIConversationContext::get($chatId, $userId);
                $active = $ctx['active_dev_job_id'] ?? '';
                if ($active === '') $active = DevJobManager::activeForChat($chatId);
                $lastDiag = $ctx['last_diagnosis_id'] ?? '';
                if ($lastDiag === '') $lastDiag = (string)get_setting('ai_last_diag_' . md5($chatId), '');
                $cl = AIIntentRouter::classify($text, ['active_dev_job' => $active, 'last_diag' => $lastDiag]);
                $intent = $cl['intent'];
            }
            // Rate limit: AI calls dat (10/phut/chat, chung voi command)
            $rate = PermissionService::rateCheck($chatId);
            if (empty($rate['ok'])) {
                self::reply($chatId, 'â³ QuÃ¡ nhiá»u yÃªu cáº§u, thá»­ láº¡i sau 1 phÃºt.');
                return true;
            }
            // DEV_REQUEST tu DIAG ref: tao job kem diagnosis context
            if ($intent === AIIntentRouter::DEV_REQUEST && !empty($cl['diag'])) {
                if (!PermissionService::canDev($role)) {
                    self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                    return true;
                }
                self::devFromDiag($chatId, $userId, (string)$cl['diag'], $text);
                return true;
            }
            switch ($intent) {
                case AIIntentRouter::DEV_FOLLOWUP: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    $job = DevJobManager::get((string)($cl['dev_job'] ?? $active));
                    if (!$job) {
                        DevJobManager::clearActiveForChat($chatId);
                        return false;
                    }
                    DevJobManager::setActiveForChat($chatId, (string)$job['job_code']);
                    AIConversationContext::syncDevJob($chatId, (string)$job['job_code']);
                    $r = DevJobManager::followup((string)$job['job_code'], $text, 'telegram:' . $userId);
                    self::reply($chatId, empty($r['ok'])
                        ? 'âŒ ' . ($r['error'] ?? 'Lá»—i')
                        : "ðŸ’¬ ÄÃ£ gá»­i vÃ o " . $job['job_code'] . '. AI Ä‘ang xá»­ lÃ½, tÃ´i bÃ¡o khi cÃ³ milestone.');
                    return true;
                }
                case AIIntentRouter::PLAN_REQUEST: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER Ä‘á»ƒ láº­p phÆ°Æ¡ng Ã¡n.');
                        return true;
                    }
                    self::reply($chatId, 'ðŸ“‹ Äang láº­p phÆ°Æ¡ng Ã¡n, tÃ´i nháº¯n khi xong...');
                    self::spawnAnswer($chatId, $userId, $role, 'plan', $text);
                    return true;
                }
                case AIIntentRouter::DEV_REQUEST: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER Ä‘á»ƒ táº¡o Dev Job.');
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
                case AIIntentRouter::HYBRID_DIAGNOSIS:
                case AIIntentRouter::BUG_ANALYSIS: {
                    self::reply($chatId, 'ðŸ”Ž Äang cháº©n Ä‘oÃ¡n (runtime + source + logs)...');
                    self::spawnAnswer($chatId, $userId, $role, 'diag', $text);
                    return true;
                }
                case AIIntentRouter::ARCHITECTURE_QUESTION: {
                    self::reply($chatId, 'ðŸ“š Äang há»i OpenCode, chá» chÃºt...');
                    self::spawnAnswer($chatId, $userId, $role, 'qa', $text);
                    return true;
                }
                case AIIntentRouter::CODE_REVIEW: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    self::reply($chatId, self::reviewActiveJob($chatId, $active));
                    return true;
                }
                case AIIntentRouter::TEST_REQUEST: {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    self::reply($chatId, self::testActiveJob($chatId, $active));
                    return true;
                }
                case AIIntentRouter::PROJECT_QUESTION: {
                    self::reply($chatId, 'ðŸ“š Äang há»i OpenCode, chá» chÃºt...');
                    self::spawnAnswer($chatId, $userId, $role, 'qa', $text);
                    return true;
                }
                case AIIntentRouter::CLARIFY:
                    self::sendWithButtons($chatId,
                        'Báº¡n muá»‘n tÃ´i lÃ m gÃ¬ vá»›i yÃªu cáº§u nÃ y?',
                        [['Há»i vá» project', 'aidev:qa:'], ['Láº­p phÆ°Æ¡ng Ã¡n', 'aidev:plan:'], ['Bá» qua', 'aidev:cancel:']]);
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
            return ['text' => 'Cháº¿ Ä‘á»™ Há»i Ä‘Ã¡p AI Ä‘ang táº¯t.'];
        }
        $oc = self::ensureOC($chatId);
        if ($oc !== '') return ['text' => $oc];
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            require_once __DIR__ . '/DevProjectRegistry.php';
            $cx = ProjectContextService::build(null, 5000);
            if (empty($cx['ok'])) return ['text' => 'âŒ ' . ($cx['error'] ?? 'Lá»—i context')];
            $before = self::gitHead((string)$cx['root']);
            $prompt = "Tráº£ lá»i cÃ¢u há»i vá» project (CHá»ˆ Äá»ŒC, TUYá»†T Äá»I KHÃ”NG sá»­a file, khÃ´ng cháº¡y lá»‡nh destructive):\n"
                . "CÃ‚U Há»ŽI: $question\nCONTEXT:\n" . $cx['context']
                . "\nTráº£ lá»i gá»n, nÃªu file/function cá»¥ thá»ƒ.";
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Q&A', 'directory' => (string)$cx['root'], 'timeout' => 240]);
            if (empty($r['ok'])) return ['text' => 'âŒ OpenCode: ' . ($r['error'] ?? 'lá»—i')];
            // Guard: Q&A khong duoc doi file (Â§10)
            $after = self::gitHead((string)$cx['root']);
            $warn = ($before !== '' && $after !== '' && $before !== $after)
                ? "\n\nâš  OpenCode Ä‘Ã£ thay Ä‘á»•i file ngoÃ i Ã½ muá»‘n â€” kiá»ƒm tra `git status` trÃªn Tool."
                : '';
            $txt = 'ðŸ“š ' . mb_substr(trim((string)$r['text']), 0, 3500) . $warn;
            self::logAi($chatId, $txt);
            return ['text' => $txt];
        } catch (Throwable $e) {
            return ['text' => 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** @return array{text, __buttons?} */
    public static function startPlan(string $chatId, string $userId, string $request, bool $fromCommand): array
    {
        if (get_setting('ai_plan_enabled', '1') !== '1') {
            return ['text' => 'Cháº¿ Ä‘á»™ Plan Ä‘ang táº¯t.'];
        }
        $oc = self::ensureOC($chatId);
        if ($oc !== '') return ['text' => $oc];
        self::reply($chatId, 'ðŸ“‹ Äang láº­p phÆ°Æ¡ng Ã¡n, chá» chÃºt...');
        try {
            require_once __DIR__ . '/ProjectContextService.php';
            require_once __DIR__ . '/OpenCodeGateway.php';
            $cx = ProjectContextService::build(null, 5000);
            if (empty($cx['ok'])) return ['text' => 'âŒ ' . ($cx['error'] ?? 'Lá»—i context')];
            $prompt = "Láº­p PHÆ¯Æ NG ÃN (KHÃ”NG CODE, khÃ´ng sá»­a file):\nYÃŠU Cáº¦U: $request\nCONTEXT:\n" . $cx['context']
                . "\nOutput: TÃ³m táº¯t | Kiáº¿n trÃºc | Files áº£nh hÆ°á»Ÿng | Migration DB (náº¿u cÃ³) | Rá»§i ro | Test plan.";
            $r = OpenCodeGateway::ask($prompt, ['title' => 'Plan', 'directory' => (string)$cx['root'], 'timeout' => 300]);
            if (empty($r['ok'])) return ['text' => 'âŒ OpenCode: ' . ($r['error'] ?? 'lá»—i')];
            $plan = mb_substr(trim((string)$r['text']), 0, 3000);
            set_setting('ai_plan_text_' . md5($chatId), $plan);
            set_setting('ai_plan_req_' . md5($chatId), mb_substr($request, 0, 1000));
            if (!empty($r['session_id'])) {
                set_setting('ai_plan_session_' . md5($chatId), (string)$r['session_id']);
            }
            self::logAi($chatId, $plan);
            return ['text' => "ðŸ“‹ PhÆ°Æ¡ng Ã¡n:\n\n$plan",
                '__buttons' => [['ðŸ›  Báº¯t Ä‘áº§u code', 'aidev:startcode:'], ['ðŸ’¬ Há»i thÃªm', 'aidev:askmore:'], ['âŒ Bá»', 'aidev:cancel:']]];
        } catch (Throwable $e) {
            return ['text' => 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** @return array{text, __buttons?} */
    public static function offerDev(string $chatId, string $request, bool $fromCommand): array
    {
        if (get_setting('ai_dev_enabled', '1') !== '1') {
            return ['text' => 'Cháº¿ Ä‘á»™ Dev Job Ä‘ang táº¯t.'];
        }
        set_setting('ai_pending_dev_' . md5($chatId), mb_substr($request, 0, 1000));
        return ['text' => "TÃ´i cÃ³ thá»ƒ phÃ¢n tÃ­ch trÆ°á»›c cho cháº¯c.\nYÃªu cáº§u: " . mb_substr($request, 0, 300),
            '__buttons' => [['ðŸ“‹ Láº­p káº¿ hoáº¡ch', 'aidev:mkplan:'], ['ðŸ›  Code luÃ´n', 'aidev:codego:'], ['âŒ Há»§y', 'aidev:cancel:']]];
    }

    public static function answerRuntime(string $text): string
    {
        try {
            require_once __DIR__ . '/SystemHealthService.php';
            $o = SystemHealthService::overall();
            $low = mb_strtolower($text);
            $lines = ['ðŸ–¥ ' . ($o['label'] ?? '')];
            if (str_contains($low, 'telegram') || str_contains($low, 'receiver') || str_contains($low, 'polling')) {
                require_once __DIR__ . '/TelegramSupervisor.php';
                $h = TelegramSupervisor::health();
                $lines[] = 'Telegram: ' . ($h['state'] ?? '?') . ' Â· worker ' . (!empty($h['worker_alive']) ? 'ALIVE' : 'DEAD')
                    . ' Â· uptime ' . ($h['uptime'] ?? 'â€”');
            }
            if (str_contains($low, 'kÃªnh') || str_contains($low, 'kenh') || str_contains($low, 'chrome')) {
                try {
                    $tot = (int)db()->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
                    $run = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE status='running'")->fetchColumn();
                    $lines[] = "KÃªnh: $run/$tot Ä‘ang cháº¡y";
                } catch (Throwable $e) {
                }
            }
            if (str_contains($low, 'proxy')) {
                try {
                    $tot = (int)db()->query('SELECT COUNT(*) FROM proxies')->fetchColumn();
                    $dead = (int)db()->query("SELECT COUNT(*) FROM proxies WHERE status='dead'")->fetchColumn();
                    $lines[] = 'Proxy: ' . ($tot - $dead) . "/$tot khá»e";
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
            if (str_contains($low, 'lỗi') || str_contains($low, 'loi') || str_contains($low, 'cảnh báo') || str_contains($low, 'canh bao')) {
                try {
                    require_once __DIR__ . '/AlertManager.php';
                    $c = AlertManager::counts();
                    $lines[] = 'Cảnh báo mở: ' . ($c['total'] ?? 0)
                        . ' (CRITICAL ' . ($c['CRITICAL'] ?? 0) . ')';
                } catch (Throwable $e) {
                }
            }
            if (str_contains($low, 'auto')) {
                try {
                    require_once __DIR__ . '/ActivityScheduler.php';
                    $s = ActivityScheduler::status();
                    $lines[] = 'Auto Activity: ' . ($s['enabled'] ?? 0) . ' kênh bật · '
                        . ($s['running'] ?? 0) . ' đang chạy';
                } catch (Throwable $e) {
                }
            }
            return implode("\n", $lines);
        } catch (Throwable $e) {
            return 'âŒ KhÃ´ng Ä‘á»c Ä‘Æ°á»£c tráº¡ng thÃ¡i.';
        }
    }

    /** @return array{text, __buttons?} */
    public static function hybridDiagnosis(string $chatId, string $userId, string $role, string $text): array
    {
        return self::runDiagnosis($chatId, $userId, $text);
    }

    /**
     * Diagnosis co cau truc + luu DIAG. Tra format ngan (Â§29).
     * @return array{text, __buttons?}
     */
    public static function runDiagnosis(string $chatId, string $userId, string $text): array
    {
        try {
            require_once __DIR__ . '/DevProjectRegistry.php';
            require_once __DIR__ . '/AIDiagnosisEngine.php';
            require_once __DIR__ . '/AIIntentRouter.php';
            $p = DevProjectRegistry::primary();
            if (!$p) return ['text' => 'âŒ ChÆ°a cÃ³ project.'];
            $ent = AIIntentRouter::extractEntities($text);
            $r = AIDiagnosisEngine::diagnose((int)$p['id'], $text, 'TELEGRAM', $userId, $ent);
            if (empty($r['ok'])) {
                if (($r['error'] ?? '') === 'opencode_offline') {
                    return ['text' => 'âš  OpenCode hiá»‡n khÃ´ng hoáº¡t Ä‘á»™ng.',
                        '__buttons' => [['Khá»Ÿi Ä‘á»™ng OpenCode', 'aidev:ocstart:'], ['Chá»‰ xem Runtime', 'aidev:cancel:']]];
                }
                return ['text' => 'âŒ ' . ($r['error'] ?? 'Lá»—i cháº©n Ä‘oÃ¡n')];
            }
            $d = $r['diag'];
            $code = (string)$d['diag_code'];
            set_setting('ai_last_diag_' . md5($chatId), $code);
            AIConversationContext::patch($chatId, $userId, ['last_diagnosis_id' => $code]);
            self::logAi($chatId, "[$code] " . mb_substr((string)($d['cause'] ?? ''), 0, 500));
            $txt = "ðŸ”Ž $code\n\nKáº¿t luáº­n:\n" . mb_substr((string)($d['cause'] ?? 'â€”'), 0, 400)
                . "\n\nConfidence: " . $d['confidence'];
            return ['text' => $txt,
                '__buttons' => [['ðŸ“„ Chi tiáº¿t', 'aidev:diagdetail:' . $code],
                    ['ðŸ›  Sá»­a lá»—i', 'aidev:diagfix:' . $code]]];
        } catch (Throwable $e) {
            return ['text' => 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** Tao DevJob tu DIAG (Â§26): khong bat user giai thich lai. */
    public static function devFromDiag(string $chatId, string $userId, string $diagCode, string $extra = ''): void
    {
        try {
            require_once __DIR__ . '/AIDiagnosisEngine.php';
            require_once __DIR__ . '/DevJobManager.php';
            require_once __DIR__ . '/DevProjectRegistry.php';
            $d = AIDiagnosisEngine::get($diagCode);
            if (!$d) {
                self::reply($chatId, 'KhÃ´ng tháº¥y ' . $diagCode);
                return;
            }
            if (strtoupper((string)($d['confidence'] ?? 'LOW')) === 'LOW') {
                self::reply($chatId, "âš  $diagCode confidence LOW â€” cáº§n thÃªm evidence trÆ°á»›c khi code.\n"
                    . 'Gá»£i Ã½: ' . mb_substr((string)($d['evidence'] ?? ''), 0, 300));
                return;
            }
            $p = DevProjectRegistry::primary();
            $req = 'Sá»­a theo ' . $diagCode . ': ' . mb_substr((string)($d['problem'] ?? ''), 0, 300)
                . "\nRoot cause: " . mb_substr((string)($d['root_cause'] ?? ''), 0, 300)
                . "\nFix Ä‘á» xuáº¥t: " . mb_substr((string)($d['recommended_fix'] ?? ''), 0, 500)
                . ($extra !== '' ? "\nBá»• sung user: " . mb_substr($extra, 0, 300) : '');
            $job = DevJobManager::create((int)$p['id'], $req, 'TELEGRAM', $userId);
            if (!$job) {
                self::reply($chatId, 'âŒ KhÃ´ng táº¡o Ä‘Æ°á»£c Dev Job.');
                return;
            }
            AIDiagnosisEngine::linkJob($diagCode, (string)$job['job_code']);
            AIConversationContext::syncDevJob($chatId, (string)$job['job_code']);
            set_setting('ai_dev_chat_' . $job['job_code'], $chatId);
            self::reply($chatId, "ðŸ›  " . $job['job_code'] . " Ä‘Ã£ táº¡o tá»« $diagCode. Pipeline tá»± cháº¡y: plan â†’ impact â†’ code â†’ test â†’ review.");
        } catch (Throwable $e) {
            self::reply($chatId, 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150));
        }
    }

    private static function reviewActiveJob(string $chatId, string $active): string
    {
        try {
            require_once __DIR__ . '/DevJobManager.php';
            $job = $active !== '' ? DevJobManager::get($active) : null;
            if (!$job || empty($job['worktree_path']) || !is_dir((string)$job['worktree_path'])) {
                return 'ChÆ°a cÃ³ Dev Job Ä‘ang code. VÃ o tab AI Dev Ä‘á»ƒ xem danh sÃ¡ch.';
            }
            require_once __DIR__ . '/AICodeReviewer.php';
            $wt = (string)$job['worktree_path'];
            DevJobManager::stageWorktree($job);
            $d = DevJobManager::git($wt, ['diff', '--name-status',
                (string)$job['base_branch'] . '...' . (string)$job['work_branch']]);
            if (trim((string)($d['out'] ?? '')) === '') return 'ChÆ°a cÃ³ diff nÃ o Ä‘á»ƒ review.';
            $rev = AICodeReviewer::review((int)$job['project_id'], (string)$job['request'],
                (string)($job['planning_summary'] ?? ''), (string)$d['out'],
                (string)($job['test_report'] ?? ''));
            if (empty($rev['ok'])) return 'âŒ ' . ($rev['error'] ?? 'Lá»—i review');
            $t = 'ðŸ”Ž Review ' . $job['job_code'] . ': ' . $rev['verdict'];
            foreach (array_slice($rev['issues'] ?? [], 0, 5) as $is) {
                $t .= "\n- [" . ($is['severity'] ?? '') . '] ' . mb_substr((string)($is['text'] ?? ''), 0, 150);
            }
            return $t;
        } catch (Throwable $e) {
            return 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150);
        }
    }

    private static function testActiveJob(string $chatId, string $active): string
    {
        try {
            require_once __DIR__ . '/DevJobManager.php';
            $job = $active !== '' ? DevJobManager::get($active) : null;
            if (!$job) return 'ChÆ°a cÃ³ Dev Job Ä‘ang hoáº¡t Ä‘á»™ng.';
            $r = DevJobManager::runTests((string)$job['job_code'], 'telegram:' . $chatId);
            $rep = $r['report'] ?? [];
            return !empty($r['ok'])
                ? 'âœ… Tests PASS (lint ' . ($rep['lint'] ?? '?') . ').'
                : 'âŒ Tests FAIL: ' . mb_substr(json_encode($rep), 0, 400);
        } catch (Throwable $e) {
            return 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150);
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
                    self::reply($chatId, 'â³ QuÃ¡ nhiá»u yÃªu cáº§u, thá»­ láº¡i sau 1 phÃºt.');
                    return true;
                }
            }
            switch ($act) {
                case 'aidev:cancel':
                    self::clearPending($chatId);
                    self::reply($chatId, 'ÄÃ£ há»§y.');
                    return true;
                case 'aidev:diagdetail': {
                    require_once __DIR__ . '/AIDiagnosisEngine.php';
                    $d = AIDiagnosisEngine::get($arg);
                    if (!$d) {
                        self::reply($chatId, 'KhÃ´ng tháº¥y ' . $arg);
                        return true;
                    }
                    self::reply($chatId, "ðŸ“„ $arg\n\nVáº¤N Äá»€: " . mb_substr((string)$d['problem'], 0, 400)
                        . "\n\nEVIDENCE: " . mb_substr((string)$d['evidence'], 0, 600)
                        . "\n\nAFFECTED: " . mb_substr((string)$d['affected'], 0, 300)
                        . "\n\nFIX: " . mb_substr((string)$d['recommended_fix'], 0, 600)
                        . "\n\nTEST: " . mb_substr((string)$d['test_plan'], 0, 400));
                    return true;
                }
                case 'aidev:diagfix': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    self::devFromDiag($chatId, $userId, $arg);
                    return true;
                }
                case 'aidev:ocstart': {    try {
                        require_once __DIR__ . '/OpenCodeService.php';
                        $r = OpenCodeService::start();
                        self::reply($chatId, !empty($r['ok'])
                            ? 'âœ… OpenCode Ä‘Ã£ khá»Ÿi Ä‘á»™ng.'
                            : 'âŒ ' . ($r['message'] ?? 'KhÃ´ng khá»Ÿi Ä‘á»™ng Ä‘Æ°á»£c'));
                    } catch (Throwable $e) {
                        self::reply($chatId, 'âŒ Lá»—i khá»Ÿi Ä‘á»™ng.');
                    }
                    return true;
                }
                case 'aidev:mkplan': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    $req = (string)get_setting('ai_pending_dev_' . md5($chatId), '');
                    if ($req === '') {
                        self::reply($chatId, 'Háº¿t háº¡n yÃªu cáº§u, gá»­i láº¡i giÃºp tÃ´i.');
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
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    $req = (string)get_setting('ai_pending_dev_' . md5($chatId), '');
                    if ($req === '') {
                        self::reply($chatId, 'Háº¿t háº¡n yÃªu cáº§u, gá»­i láº¡i giÃºp tÃ´i.');
                        return true;
                    }
                    self::clearPending($chatId);
                    self::kickDevJob($chatId, $userId, $req, '');
                    return true;
                }
                case 'aidev:startcode': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    $req = (string)get_setting('ai_plan_req_' . md5($chatId), '');
                    $plan = (string)get_setting('ai_plan_text_' . md5($chatId), '');
                    if ($req === '') {
                        self::reply($chatId, 'Háº¿t háº¡n phÆ°Æ¡ng Ã¡n, yÃªu cáº§u láº¡i giÃºp tÃ´i.');
                        return true;
                    }
                    self::clearPending($chatId);
                    self::kickDevJob($chatId, $userId, $req, $plan);
                    return true;
                }
                case 'aidev:askmore':
                    self::reply($chatId, 'Báº¡n cá»© nháº¯n cÃ¢u há»i thÃªm, tÃ´i tráº£ lá»i trong context phÆ°Æ¡ng Ã¡n nÃ y.');
                    return true;
                case 'aidev:qa': {
                    $t = (string)get_setting('ai_pending_clarify_' . md5($chatId), '');
                    self::clearPending($chatId);
                    if ($t === '') {
                        self::reply($chatId, 'Háº¿t háº¡n, gá»­i láº¡i giÃºp tÃ´i.');
                        return true;
                    }
                    $r = self::answerQuestion($chatId, $userId, $t, $role, false);
                    self::reply($chatId, $r['text']);
                    return true;
                }
                case 'aidev:plan': {
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    $t = (string)get_setting('ai_pending_clarify_' . md5($chatId), '');
                    self::clearPending($chatId);
                    if ($t === '') {
                        self::reply($chatId, 'Háº¿t háº¡n, gá»­i láº¡i giÃºp tÃ´i.');
                        return true;
                    }
                    $r = self::startPlan($chatId, $userId, $t, false);
                    if (($r['__buttons'] ?? null)) self::sendWithButtons($chatId, $r['text'], $r['__buttons']);
                    else self::reply($chatId, $r['text']);
                    return true;
                }
                case 'aidev:approve2': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $r = DevJobManager::approve($arg, 'telegram:' . $userId, true);
                    self::reply($chatId, empty($r['ok']) ? 'âŒ ' . ($r['error'] ?? 'Lá»—i') : "âœ… $arg Ä‘Ã£ duyá»‡t (high-risk confirmed).");
                    return true;
                }
                case 'aidev:apply': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $job = DevJobManager::get($arg);
                    if (!$job) {
                        self::reply($chatId, 'KhÃ´ng tháº¥y job.');
                        return true;
                    }
                    if (!empty($job['has_db_migration'])) {
                        self::sendWithButtons($chatId, "âš  $arg cÃ³ DB migration â€” apply code khÃ´ng rollback DB.",
                            [['Tiáº¿p tá»¥c Ã¡p dá»¥ng', 'aidev:apply2:' . $arg], ['Há»§y', 'aidev:cancel:']]);
                        return true;
                    }
                    $r = DevJobManager::apply($arg, 'telegram:' . $userId);
                    self::reply($chatId, empty($r['ok']) ? 'âŒ ' . ($r['error'] ?? 'Lá»—i')
                        : "âœ… $arg applied: " . ($r['commit'] ?? ''));
                    return true;
                }
                case 'aidev:apply2': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $r = DevJobManager::apply($arg, 'telegram:' . $userId);
                    self::reply($chatId, empty($r['ok']) ? 'âŒ ' . ($r['error'] ?? 'Lá»—i')
                        : "âœ… $arg applied: " . ($r['commit'] ?? ''));
                    return true;
                }
                case 'aidev:tgapprove': {
                    // Duyet nhanh tu Telegram (ADMIN only, high-risk can CONFIRM2)
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $r = DevJobManager::approve($arg, 'telegram:' . $userId, false);
                    if (!empty($r['ok'])) {
                        self::sendWithButtons($chatId, "âœ… $arg Ä‘Ã£ duyá»‡t. Ãp dá»¥ng vÃ o main?",
                            [['Ãp dá»¥ng', 'aidev:apply:' . $arg], ['Äá»ƒ sau', 'aidev:cancel:']]);
                    } elseif (($r['need'] ?? '') === 'CONFIRM2') {
                        self::sendWithButtons($chatId, 'âš  ' . ($r['message'] ?? 'Rá»§i ro cao') . "\nJob: $arg",
                            [['Tiáº¿p tá»¥c duyá»‡t', 'aidev:approve2:' . $arg], ['Há»§y', 'aidev:cancel:']]);
                    } else {
                        self::reply($chatId, 'âŒ ' . ($r['error'] ?? 'Lá»—i'));
                    }
                    return true;
                }
                case 'aidev:tgject': {
                    if (!PermissionService::canApprove($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n ADMIN.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $r = DevJobManager::reject($arg, 'telegram:' . $userId);
                    self::reply($chatId, !empty($r['ok']) ? "ÄÃ£ tá»« chá»‘i $arg. Main khÃ´ng Ä‘á»•i." : 'âŒ ' . ($r['error'] ?? 'Lá»—i'));
                    return true;
                }
                case 'aidev:tgfix': {
                    // "Cho AI sua": tiep tuc CUNG session (Â§48)
                    if (!PermissionService::canDev($role)) {
                        self::reply($chatId, 'â›” Cáº§n quyá»n DEVELOPER.');
                        return true;
                    }
                    require_once __DIR__ . '/DevJobManager.php';
                    $job = DevJobManager::get($arg);
                    if (!$job) {
                        self::reply($chatId, 'KhÃ´ng tháº¥y job.');
                        return true;
                    }
                    DevJobManager::setActiveForChat($chatId, $arg);
                    AIConversationContext::syncDevJob($chatId, $arg);
                    $r = DevJobManager::followup($arg,
                        'Tests Ä‘ang FAIL. HÃ£y sá»­a lá»—i cho tests pass, rá»“i bÃ¡o cÃ¡o láº¡i structured.',
                        'telegram:' . $userId);
                    self::reply($chatId, !empty($r['ok'])
                        ? "ðŸ”§ ÄÃ£ yÃªu cáº§u AI sá»­a trong cÃ¹ng session $arg."
                        : 'âŒ ' . ($r['error'] ?? 'Lá»—i'));
                    return true;
                }
                case 'dev:status': {
                    require_once __DIR__ . '/DevJobManager.php';
                    $job = DevJobManager::get($arg);
                    if ($job) {
                        // Day pipeline neu job dang tu chay
                        if (in_array($job['status'] ?? '', ['QUEUED', 'ANALYZING', 'CODING', 'TESTING'], true)) {
                            try {
                                require_once __DIR__ . '/DevJobPipeline.php';
                                DevJobPipeline::advance($arg);
                            } catch (Throwable $e) {
                            }
                            $job = DevJobManager::get($arg);
                        } else {
                            DevJobManager::pollProgress($arg);
                            $job = DevJobManager::get($arg);
                        }
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
                self::reply($chatId, 'âŒ ChÆ°a cÃ³ project.');
                return;
            }
            $job = DevJobManager::create((int)$p['id'], $request, 'TELEGRAM', $userId,
                $plan !== '' ? ['plan_text' => mb_substr($plan, 0, 4000)] : []);
            if (!$job) {
                self::reply($chatId, 'âŒ KhÃ´ng táº¡o Ä‘Æ°á»£c Dev Job.');
                return;
            }
            $code = (string)$job['job_code'];
            AIConversationContext::syncDevJob($chatId, $code);
            set_setting('ai_dev_chat_' . $code, $chatId); // milestone notify (Â§21-23)
            self::reply($chatId, "ðŸ›  $code Ä‘Ã£ táº¡o. Pipeline: prepare â†’ plan â†’ impact â†’ code â†’ test â†’ review...");
            try {
                require_once __DIR__ . '/DevJobPipeline.php';
                $adv = DevJobPipeline::advance($code); // QUEUED -> ANALYZING (prepare)
                if (empty($adv['ok'])) {
                    $job2 = DevJobManager::get($code);
                    self::reply($chatId, 'âš  ' . (($job2['error'] ?? null) ?: ($adv['error'] ?? 'Lá»—i prepare')) . "\nJob: $code");
                    return;
                }
            } catch (Throwable $e) {
                self::reply($chatId, 'âŒ Lá»—i pipeline: ' . mb_substr($e->getMessage(), 0, 120));
                return;
            }
            self::sendWithButtons($chatId,
                "ðŸ›  $code Ä‘ang cháº¡y pipeline tá»± Ä‘á»™ng.\nMain project khÃ´ng Ä‘á»•i. TÃ´i bÃ¡o tá»«ng milestone.",
                [['Xem tiáº¿n Ä‘á»™', 'dev:status:' . $code]]);
        } catch (Throwable $e) {
            self::reply($chatId, 'âŒ Lá»—i: ' . mb_substr($e->getMessage(), 0, 150));
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
                self::sendWithButtons($chatId, 'âš  OpenCode hiá»‡n khÃ´ng hoáº¡t Ä‘á»™ng.',
                    [['Khá»Ÿi Ä‘á»™ng OpenCode', 'aidev:ocstart:'], ['Chá»‰ xem Runtime', 'aidev:cancel:']]);
            }
            return 'OpenCode hiá»‡n khÃ´ng hoáº¡t Ä‘á»™ng.';
        } catch (Throwable $e) {
            return 'OpenCode hiá»‡n khÃ´ng hoáº¡t Ä‘á»™ng.';
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
        $bars = str_repeat('â–ˆ', (int)($p / 10)) . str_repeat('â–‘', 10 - (int)($p / 10));
        return "ðŸ›  " . $job['job_code'] . "\n" . mb_substr((string)$job['request'], 0, 120)
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
