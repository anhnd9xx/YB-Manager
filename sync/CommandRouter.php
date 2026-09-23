<?php
declare(strict_types=1);
/**
 * CommandRouter - Phan tich + dieu huong command (§8-§10).
 * Parser output CommandRequest (slash + NL co ban) — parser KHONG goi service (§35-37).
 * Flow: RECEIVED -> VALIDATING -> (permission/role/rate/lock) ->
 *   WAITING_CONFIRMATION? -> QUEUED/RUNNING (job) hoac SUCCESS (tra loi ngay).
 * KHONG eval/exec/subprocess raw text (§11, §57).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/CommandRegistry.php';
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/ConfirmationService.php';
require_once __DIR__ . '/JobManager.php';
require_once __DIR__ . '/SyncLogger.php';

/** Parser interface (§35): Slash + NL deu output CommandRequest array. */
interface IntentParser
{
    /** @return array{command:string, args:string}|null */
    public function parse(string $text): ?array;
}

class SlashCommandParser implements IntentParser
{
    public function parse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;
        if ($text[0] === '/') $text = substr($text, 1);
        // cat @botname suffix
        $parts = preg_split('/\s+/', $text, 2);
        $cmd = strtolower(preg_replace('/@.+$/', '', $parts[0] ?? ''));
        if ($cmd === '') return null;
        return ['command' => $cmd, 'args' => trim($parts[1] ?? '')];
    }
}

/** NL co ban tieng Viet (§36): chi parse intent, khong execute. */
class BasicNlParser implements IntentParser
{
    public function parse(string $text): ?array
    {
        $t = mb_strtolower(trim($text));
        if (preg_match('/đánh giá kênh (\d+)\s*(?:đến|den|-)\\s*(\d+)/u', $t, $m)) {
            return ['command' => 'evaluation.run', 'args' => $m[1] . '-' . $m[2]];
        }
        if (preg_match('/đánh giá kênh (\d+)/u', $t, $m)) {
            return ['command' => 'evaluation.run', 'args' => $m[1]];
        }
        if (preg_match('/(?:mở|mo) kênh (\d+)/u', $t, $m)) {
            return ['command' => 'browser.start', 'args' => $m[1]];
        }
        if (preg_match('/(?:đóng|dong) kênh (\d+)/u', $t, $m)) {
            return ['command' => 'browser.stop', 'args' => $m[1]];
        }
        if (preg_match('/trạng thái|trang thai/u', $t)) {
            return ['command' => 'status', 'args' => ''];
        }
        return null;
    }
}

class CommandRouter
{
    // Command status (§9)
    public const ST_RECEIVED = 'RECEIVED';
    public const ST_VALIDATING = 'VALIDATING';
    public const ST_WAIT_CONFIRM = 'WAITING_CONFIRMATION';
    public const ST_QUEUED = 'QUEUED';
    public const ST_RUNNING = 'RUNNING';
    public const ST_SUCCESS = 'SUCCESS';
    public const ST_PARTIAL = 'PARTIAL';
    public const ST_FAILED = 'FAILED';
    public const ST_CANCELLED = 'CANCELLED';
    public const ST_REJECTED = 'REJECTED';

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS app_commands (
                command_id VARCHAR(64) PRIMARY KEY,
                source VARCHAR(20) NOT NULL DEFAULT 'TELEGRAM',
                chat_id VARCHAR(64) NULL,
                user_id VARCHAR(64) NULL,
                raw_text VARCHAR(1000) NULL,
                command_name VARCHAR(60) NOT NULL,
                arguments VARCHAR(500) NULL,
                role VARCHAR(15) NULL,
                confirmation_required TINYINT(1) NOT NULL DEFAULT 0,
                confirmed TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(25) NOT NULL DEFAULT 'RECEIVED',
                job_id VARCHAR(32) NULL,
                result TEXT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                KEY idx_cmd_chat (chat_id, requested_at),
                KEY idx_cmd_job (job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS tg_updates (
                update_id BIGINT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    public static function newId(): string
    {
        return 'CMD-' . date('His') . '-' . strtoupper(substr(md5(microtime(true) . mt_rand()), 0, 6));
    }

    /** Idempotency update (§23, §54): da xu ly update nay? */
    public static function seenUpdate(int $updateId): bool
    {
        self::ensureTable();
        try {
            $st = db()->prepare('INSERT IGNORE INTO tg_updates (update_id) VALUES (?)');
            $st->execute([$updateId]);
            return $st->rowCount() === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Parse target an toan (§20): "32" | "1,3,5" | "1-10" | "all" | "selected"(UI only).
     * @return array{ok, ids?:int[], error?}
     */
    public static function parseTargets(string $args, bool $allowAll = false): array
    {
        $args = strtolower(trim($args));
        if ($args === '') return ['ok' => false, 'error' => 'missing_targets'];
        if ($args === 'all') {
            if (!$allowAll) return ['ok' => false, 'error' => 'use_explicit_all_command'];
            return ['ok' => true, 'ids' => 'all'];
        }
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $args) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            // Khong parse arbitrary expression: chi so va dau gach don
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $a = (int)$m[1];
                $b = (int)$m[2];
                if ($a <= 0 || $b <= 0 || abs($b - $a) > 200) return ['ok' => false, 'error' => 'invalid_range'];
                for ($i = min($a, $b); $i <= max($a, $b); $i++) $ids[] = $i;
            } elseif (preg_match('/^\d+$/', $part)) {
                $ids[] = (int)$part;
            } else {
                return ['ok' => false, 'error' => 'invalid_target:' . substr($part, 0, 20)];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0 && $i < 1000000)));
        if (!$ids) return ['ok' => false, 'error' => 'missing_targets'];
        if (count($ids) > 200) return ['ok' => false, 'error' => 'too_many_targets'];
        return ['ok' => true, 'ids' => $ids];
    }

    /**
     * Route 1 raw text tu source. Tra ve reply {text, buttons?, command_id, job_id?}.
     */
    public static function route(string $rawText, string $source, string $chatId, string $userId = ''): array
    {
        self::ensureTable();
        // Parser: slash truoc, NL sau (§35)
        $parsed = (new SlashCommandParser())->parse($rawText);
        $usedNl = false;
        if ($parsed !== null) {
            $cmd = CommandRegistry::find($parsed['command']);
            if ($cmd === null) {
                $parsed = (new BasicNlParser())->parse($rawText);
                $usedNl = $parsed !== null;
            }
        } else {
            $parsed = (new BasicNlParser())->parse($rawText);
            $usedNl = $parsed !== null;
        }
        if ($parsed === null) {
            return ['text' => "❓ Không hiểu lệnh. Gõ /help để xem danh sách.", 'command_id' => null];
        }
        $cmd = CommandRegistry::find($parsed['command']);
        if ($cmd === null || empty($cmd['enabled'])) {
            return ['text' => "❓ Không có lệnh /" . $parsed['command'] . ". Gõ /help.", 'command_id' => null];
        }
        // Permission (Telegram source; UI source = ADMIN local) (§6-§7)
        $role = PermissionService::ADMIN;
        if ($source === 'TELEGRAM') {
            $chk = PermissionService::check($chatId, $userId);
            if (empty($chk['ok'])) {
                self::audit(null, $source, $chatId, $userId, $rawText, $cmd['name'], self::ST_REJECTED, null);
                return ['text' => "⛔ Bạn không có quyền điều khiển hệ thống.", 'command_id' => null];
            }
            $role = $chk['role'];
            $rate = PermissionService::rateCheck($chatId);
            if (empty($rate['ok'])) {
                return ['text' => "⏳ Quá nhiều lệnh, thử lại sau 1 phút.", 'command_id' => null];
            }
        }
        if (!PermissionService::can($role, $cmd['required_role'])) {
            self::audit(null, $source, $chatId, $userId, $rawText, $cmd['name'], self::ST_REJECTED, null);
            return ['text' => "⛔ Bạn không có quyền thực hiện thao tác này.", 'command_id' => null];
        }
        // Luu command (audit §31)
        $commandId = self::newId();
        try {
            db()->prepare('INSERT INTO app_commands (command_id, source, chat_id, user_id, raw_text,
                    command_name, arguments, role, confirmation_required, status, requested_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$commandId, $source, $chatId, $userId, mb_substr($rawText, 0, 1000),
                    $cmd['name'], mb_substr($parsed['args'], 0, 500), $role,
                    !empty($cmd['confirmation']) ? 1 : 0, self::ST_VALIDATING]);
        } catch (Throwable $e) {
        }
        // Duplicate lock: cung command dang chay? (§33) — chi cho job-commands
        // Confirmation (§21)
        if (!empty($cmd['confirmation'])) {
            require_once __DIR__ . '/ConfirmationService.php';
            $summary = $cmd['description'] . ' ' . trim($parsed['args']);
            $cf = ConfirmationService::create($commandId, $chatId, $userId, $summary);
            self::setStatus($commandId, self::ST_WAIT_CONFIRM);
            return ['text' => "⚠️ " . $summary . "?\nXác nhận trong 60 giây.",
                'buttons' => [[['✅ Xác nhận', 'confirm:' . $cf['id']], ['❌ Hủy', 'cancel:' . $cf['id']]]],
                'command_id' => $commandId, 'confirmation_id' => $cf['id']];
        }
        return self::dispatch($commandId, $cmd, $parsed['args'], $source, $chatId, $userId, $role);
    }

    /** Dispatch sau validate/confirm: goi handler registry. */
    public static function dispatch(string $commandId, array $cmd, string $args,
        string $source, string $chatId, string $userId, string $role): array
    {
        require_once __DIR__ . '/TelegramCommands.php';
        $handler = $cmd['handler'] ?? null;
        if (!is_callable($handler)) {
            self::setStatus($commandId, self::ST_FAILED, 'no_handler');
            return ['text' => "❌ Lệnh chưa hỗ trợ.", 'command_id' => $commandId];
        }
        self::setStatus($commandId, self::ST_RUNNING);
        try {
            $res = $handler(['command_id' => $commandId, 'command' => $cmd, 'args' => $args,
                'source' => $source, 'chat_id' => $chatId, 'user_id' => $userId, 'role' => $role]);
            $res['command_id'] = $commandId;
            self::setStatus($commandId,
                !empty($res['job_id']) ? self::ST_QUEUED : (empty($res['error']) ? self::ST_SUCCESS : self::ST_FAILED),
                $res['text'] ?? null, $res['job_id'] ?? null);
            return $res;
        } catch (Throwable $e) {
            self::setStatus($commandId, self::ST_FAILED, mb_substr($e->getMessage(), 0, 200));
            return ['text' => "❌ Lỗi thực hiện lệnh.", 'command_id' => $commandId];
        }
    }

    /** Confirm tu callback/UI: chay command da confirm. */
    public static function confirm(string $confirmationId, string $chatId, string $userId): array
    {
        require_once __DIR__ . '/ConfirmationService.php';
        $cf = ConfirmationService::get($confirmationId);
        if ($cf === null) {
            return ['text' => "⌛ Xác nhận đã hết hạn hoặc không tồn tại."];
        }
        if ((string)$cf['chat_id'] !== $chatId) {
            return ['text' => "⛔ Xác nhận này không thuộc về bạn."];
        }
        ConfirmationService::settle($confirmationId, 'CONFIRMED');
        try {
            $st = db()->prepare('SELECT * FROM app_commands WHERE command_id=?');
            $st->execute([$cf['command_id']]);
            $row = $st->fetch();
            if (!$row) return ['text' => "❌ Không tìm thấy lệnh."];
            db()->prepare('UPDATE app_commands SET confirmed=1 WHERE command_id=?')->execute([$cf['command_id']]);
            $cmd = CommandRegistry::find($row['command_name']);
            if ($cmd === null) return ['text' => "❌ Lệnh không còn tồn tại."];
            return self::dispatch($cf['command_id'], $cmd, (string)($row['arguments'] ?? ''),
                (string)$row['source'], $chatId, $userId, (string)($row['role'] ?? PermissionService::VIEWER));
        } catch (Throwable $e) {
            return ['text' => "❌ Lỗi xác nhận."];
        }
    }

    public static function cancelConfirm(string $confirmationId, string $chatId): array
    {
        require_once __DIR__ . '/ConfirmationService.php';
        $cf = ConfirmationService::get($confirmationId);
        if ($cf === null) return ['text' => "⌛ Xác nhận đã hết hạn."];
        if ((string)$cf['chat_id'] !== $chatId) return ['text' => "⛔ Không thuộc về bạn."];
        ConfirmationService::settle($confirmationId, 'CANCELLED');
        self::setStatus($cf['command_id'], self::ST_CANCELLED, 'cancelled by user');
        return ['text' => "❌ Đã hủy."];
    }

    public static function setStatus(string $commandId, string $status, ?string $result = null, ?string $jobId = null): void
    {
        try {
            self::ensureTable();
            $sets = 'status=?';
            $params = [$status];
            if ($result !== null) {
                $sets .= ', result=?';
                $params[] = mb_substr($result, 0, 2000);
            }
            if ($jobId !== null) {
                $sets .= ', job_id=?';
                $params[] = $jobId;
            }
            if (in_array($status, [self::ST_SUCCESS, self::ST_FAILED, self::ST_CANCELLED], true)) {
                $sets .= ', finished_at=NOW()';
            }
            $params[] = $commandId;
            db()->prepare("UPDATE app_commands SET $sets WHERE command_id=?")->execute($params);
        } catch (Throwable $e) {
        }
    }

    private static function audit(?string $commandId, string $source, string $chatId, string $userId,
        string $raw, string $name, string $status, ?string $jobId): void
    {
        try {
            self::ensureTable();
            db()->prepare('INSERT INTO app_commands (command_id, source, chat_id, user_id, raw_text,
                    command_name, arguments, role, status, job_id, requested_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$commandId ?? self::newId(), $source, $chatId, $userId,
                    mb_substr($raw, 0, 1000), $name, '', '', $status, $jobId]);
        } catch (Throwable $e) {
        }
    }

    /** Audit log doc cho UI (§31). */
    public static function auditLog(int $limit = 100): array
    {
        self::ensureTable();
        $limit = max(1, min(200, $limit));
        try {
            return db()->query('SELECT command_id, source, chat_id, command_name, arguments, role,
                    status, job_id, requested_at, finished_at FROM app_commands
                ORDER BY requested_at DESC LIMIT ' . $limit)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}
