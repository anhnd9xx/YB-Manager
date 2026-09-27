<?php
declare(strict_types=1);
/**
 * AIControlOrchestrator - Diem vao duy nhat cho moi Telegram message (AI Control).
 * Thu tu: callback/button > slash command > pairing > dev followup > NL intent > chat.
 * Plain text KHONG BAO GIO di CommandRouter (chi slash moi di).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SyncLogger.php';

class AIControlOrchestrator
{
    /**
     * Slash command -> CommandRouter (giuu nguyen flow cu).
     * @return array reply{ text, buttons?, ... }
     */
    public static function handle_command(string $text, string $chatId, string $userId): array
    {
        try {
            require_once __DIR__ . '/CommandRouter.php';
            return CommandRouter::route($text, 'TELEGRAM', $chatId, $userId);
        } catch (Throwable $e) {
            return ['text' => '❌ Lỗi thực hiện lệnh.'];
        }
    }

    /**
     * Plain text: route by intent. Tra ['handled'=>bool,'reply'=>?].
     * reply != null -> worker gui qua send_reply (TOOL_ACTION/HELP).
     */
    public static function handle_plain_text(string $chatId, string $userId, string $role,
        string $text): array
    {
        try {
            require_once __DIR__ . '/AIIntentRouter.php';
            require_once __DIR__ . '/AIConversationContext.php';
            require_once __DIR__ . '/EntityResolver.php';
            require_once __DIR__ . '/AIDevConsole.php';
            // Pairing uu tien: de worker xu ly setup
            try {
                require_once __DIR__ . '/TelegramSetup.php';
                if (TelegramSetup::active() !== null) return ['handled' => false];
            } catch (Throwable $e) {
            }
            $ctx = AIConversationContext::get($chatId, $userId);
            $cl = AIIntentRouter::classify($text, [
                'active_dev_job' => $ctx['active_dev_job_id'] ?? '',
                'last_diag' => $ctx['last_diagnosis_id'] ?? '',
            ]);
            $intent = $cl['intent'];
            $entity = EntityResolver::resolve($text, $ctx);
            try {
                require_once __DIR__ . '/TelegramMessageRouter.php';
                TelegramMessageRouter::trace(0, 'AI_CONTROL', $intent . ' conf=' . ($cl['confidence'] ?? '?'));
            } catch (Throwable $e) {
            }
            // Priority 4: DEV_FOLLOWUP truoc FAQ
            if ($intent === AIIntentRouter::DEV_FOLLOWUP) {
                $r = AIDevConsole::handleTextWithIntent($chatId, $userId, $role, $text, $intent, $cl, $entity);
                if ($r) AIConversationContext::touch($chatId, $userId, $intent, $entity);
                return ['handled' => $r];
            }
            // Priority 5: FAQ (static + dynamic runtime). Miss -> AI tiep (§16).
            try {
                require_once __DIR__ . '/TelegramFAQService.php';
                $faqHit = TelegramFAQService::match($text, $entity);
                if ($faqHit) {
                    $ans = TelegramFAQService::answer($faqHit);
                    AIDevConsole::reply($chatId, (string)($ans['text'] ?? ''));
                    AIConversationContext::touch($chatId, $userId, 'FAQ', $entity);
                    return ['handled' => true];
                }
            } catch (Throwable $e) {
            }
            switch ($intent) {
                case AIIntentRouter::GENERAL_CHAT:
                    AIDevConsole::reply($chatId, self::greeting());
                    AIConversationContext::touch($chatId, $userId, $intent, $entity);
                    return ['handled' => true];
                case AIIntentRouter::HELP: {
                    require_once __DIR__ . '/CommandRouter.php';
                    $reply = CommandRouter::route('/help', 'TELEGRAM', $chatId, $userId);
                    AIConversationContext::touch($chatId, $userId, $intent, $entity);
                    return ['handled' => true, 'reply' => $reply];
                }
                case AIIntentRouter::RUNTIME_QUERY:
                case AIIntentRouter::RUNTIME_QUESTION: {
                    $t = self::answerRuntimeEntity($entity, $text);
                    AIDevConsole::reply($chatId, $t);
                    AIConversationContext::touch($chatId, $userId, AIIntentRouter::RUNTIME_QUERY, $entity);
                    return ['handled' => true];
                }
                case AIIntentRouter::TOOL_ACTION: {
                    require_once __DIR__ . '/CommandRouter.php';
                    $reply = CommandRouter::route($text, 'TELEGRAM', $chatId, $userId);
                    if (!empty($reply['not_a_command'])) {
                        // Pattern nhan dien duoc y dinh hanh dong nhung chua co command tuong ung
                        AIDevConsole::reply($chatId, "Tôi hiểu bạn muốn thao tác, nhưng chưa có lệnh tương ứng.\n"
                            . "Thử /help để xem lệnh có sẵn, hoặc mô tả để tạo Dev Job.");
                        AIConversationContext::touch($chatId, $userId, $intent, $entity);
                        return ['handled' => true];
                    }
                    AIConversationContext::touch($chatId, $userId, $intent, $entity);
                    return ['handled' => true, 'reply' => $reply];
                }
                case AIIntentRouter::JOB_QUERY: {
                    $t = self::answerJob($entity, $ctx);
                    if (!empty($t['job_id'])) {
                        AIConversationContext::patch($chatId, $userId, ['last_job_id' => $t['job_id']]);
                    }
                    AIDevConsole::reply($chatId, $t['text']);
                    AIConversationContext::touch($chatId, $userId, $intent, $entity);
                    return ['handled' => true];
                }
                default:
                    // Phan con lai (PLAN/DEV/DIAG/PROJECT...) -> AIDevConsole (co context san)
                    $r = AIDevConsole::handleTextWithIntent($chatId, $userId, $role, $text, $intent, $cl, $entity);
                    if ($r) AIConversationContext::touch($chatId, $userId, $intent, $entity);
                    return ['handled' => $r];
            }
        } catch (Throwable $e) {
            return ['handled' => false];
        }
    }

    /**
     * Callback/button: aidev/dev -> console; setup -> Setup; con lai -> flow cu.
     * @return bool true neu da xu ly
     */
    public static function handle_callback(string $chatId, string $userId, string $role,
        string $data): bool
    {
        try {
            require_once __DIR__ . '/AIDevConsole.php';
            if (AIDevConsole::handleCallback($chatId, $userId, $role, $data)) return true;
        } catch (Throwable $e) {
        }
        return false; // worker xu ly tiep (setup/confirm/cmd)
    }

    public static function greeting(): string
    {
        return "Chào bạn. YT Manager đang hoạt động.\n\nBạn có thể hỏi:\n"
            . "• trạng thái hệ thống\n• trạng thái kênh\n• hỏi về source/project\n"
            . "• chẩn đoán lỗi\n• yêu cầu thực hiện tác vụ\n• yêu cầu AI Dev sửa code";
    }

    // ================= Runtime answers (data that) =================

    public static function answerRuntimeEntity(array $entity, string $text): string
    {
        $type = (string)($entity['type'] ?? 'NONE');
        if ($type === 'CHANNEL') return self::channelDetail($entity);
        if ($type === 'PROXY') return self::proxyDetail($entity);
        if ($type === 'MODULE' && ($entity['name'] ?? '') === 'TELEGRAM') return self::telegramDetail();
        try {
            require_once __DIR__ . '/AIDevConsole.php';
            return AIDevConsole::answerRuntime($text);
        } catch (Throwable $e) {
            return '❌ Không đọc được trạng thái.';
        }
    }

    public static function channelDetail(array $entity): string
    {
        require_once __DIR__ . '/RuntimeQueryService.php';
        return RuntimeQueryService::channelStatus((int)($entity['id'] ?? 0));
    }

    public static function proxyDetail(array $entity): string
    {
        $id = (int)($entity['id'] ?? 0);
        if ($id <= 0) {
            require_once __DIR__ . '/RuntimeQueryService.php';
            return RuntimeQueryService::proxySummary();
        }
        require_once __DIR__ . '/EntityResolver.php';
        $p = EntityResolver::proxyById($id);
        if (!$p) return "Không thấy proxy #$id.";
        return "Proxy #$id " . ($p['name'] ?? '') . "\nTrạng thái: " . strtoupper((string)($p['status'] ?? '?'));
    }

    public static function telegramDetail(): string
    {
        require_once __DIR__ . '/RuntimeQueryService.php';
        return RuntimeQueryService::telegramStatus();
    }

    /** @return array{text, job_id?} */
    public static function answerJob(array $entity, array $ctx): array
    {
        try {
            require_once __DIR__ . '/JobManager.php';
            $name = (string)($entity['name'] ?? '');
            if (($entity['type'] ?? '') === 'JOB' && $name !== '' && empty($entity['query'])) {
                $j = JobManager::get($name);
                if (!$j) return ['text' => "Không thấy job $name."];
                return ['text' => self::jobText($j), 'job_id' => $name];
            }
            if (!empty($ctx['last_job_id']) && (($entity['query'] ?? '') === 'last' || $name === '')) {
                $j = JobManager::get((string)$ctx['last_job_id']);
                if ($j) return ['text' => self::jobText($j), 'job_id' => (string)$ctx['last_job_id']];
            }
            $rows = JobManager::active(5);
            if (!$rows) return ['text' => 'Không có job nào đang chạy.'];
            $lines = ['Jobs đang chạy:'];
            foreach ($rows as $j) {
                $lines[] = '• ' . $j['job_id'] . ' · ' . ($j['status'] ?? '')
                    . ' · ' . ($j['progress_done'] ?? 0) . '/' . ($j['progress_total'] ?? 0);
            }
            return ['text' => implode("\n", $lines), 'job_id' => (string)($rows[0]['job_id'] ?? '')];
        } catch (Throwable $e) {
            return ['text' => '❌ Không đọc được jobs.'];
        }
    }

    private static function jobText(array $j): string
    {
        return 'JOB ' . ($j['job_id'] ?? '') . ' · ' . ($j['status'] ?? '') . "\n"
            . 'Tiến độ: ' . ($j['progress_done'] ?? 0) . '/' . ($j['progress_total'] ?? 0) . "\n"
            . 'OK: ' . ($j['success_count'] ?? 0) . ' · lỗi: ' . ($j['failed_count'] ?? 0)
            . (!empty($j['error']) ? "\nLỗi: " . mb_substr((string)$j['error'], 0, 150) : '');
    }
}
