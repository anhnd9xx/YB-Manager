<?php
declare(strict_types=1);
/**
 * TelegramSetup - One-field pairing flow (§2, §5-§11, §23-§25).
 * Token -> getMe -> webhook check -> setup session (TTL 5') ->
 * doi private message MOI (offset tai thoi diem start, §8) ->
 * candidate -> inline confirm (verify callback user, §10) ->
 * persist ADMIN + welcome.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TelegramConfig.php';
require_once __DIR__ . '/TelegramProvider.php';
require_once __DIR__ . '/TelegramGateway.php';
require_once __DIR__ . '/SyncLogger.php';

class TelegramSetup
{
    public const TTL_SEC = 300;

    public const ST_WAITING = 'WAITING_MESSAGE';
    public const ST_CONFIRM = 'WAITING_CONFIRM';
    public const ST_DONE = 'DONE';
    public const ST_EXPIRED = 'EXPIRED';
    public const ST_CANCELLED = 'CANCELLED';

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS tg_setup_sessions (
                session_id VARCHAR(32) PRIMARY KEY,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                offset_start BIGINT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'WAITING_MESSAGE',
                candidate_chat_id VARCHAR(64) NULL,
                candidate_user_id VARCHAR(64) NULL,
                candidate_username VARCHAR(100) NULL,
                candidate_display VARCHAR(190) NULL,
                candidate_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
        }
    }

    public static function validFormat(string $token): bool
    {
        return (bool)preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', trim($token));
    }

    /**
     * Buoc 1: validate + getMe + webhook check + tao session.
     * @return array{ok, error?, bot?, webhook_active?, session_id?, bot_username?}
     */
    public static function start(string $token, bool $forceTakeover = false): array
    {
        self::ensureTable();
        $token = trim($token);
        if (!self::validFormat($token)) {
            return ['ok' => false, 'error' => 'Bot Token không đúng định dạng.'];
        }
        $me = TelegramProvider::getMeVia($token);
        if (empty($me['ok'])) {
            return ['ok' => false, 'error' => 'Bot Token không hợp lệ hoặc không thể kết nối Telegram.'];
        }
        // Webhook check (§17): khong delete am tham
        $wh = TelegramProvider::webhookInfoVia($token);
        if (!empty($wh['active']) && !$forceTakeover) {
            return ['ok' => false, 'error' => 'webhook_active',
                'webhook_url' => $wh['url'] ?? '',
                'bot' => $me['bot']];
        }
        if (!empty($wh['active']) && $forceTakeover) {
            TelegramProvider::deleteWebhookVia($token);
        }
        // Luu token + bot info (getMe success moi luu §3)
        TelegramConfig::saveToken($token);
        TelegramConfig::set('bot_id', (string)($me['bot']['id'] ?? ''));
        TelegramConfig::set('bot_username', (string)($me['bot']['username'] ?? ''));
        TelegramConfig::set('bot_first_name', (string)($me['bot']['first_name'] ?? ''));
        TelegramConfig::setStatus('CONNECTING');
        try {
            // Dev log §1 (khong token)
            SyncLogger::info('telegram', '[TELEGRAM] getMe OK bot_id=' . ($me['bot']['id'] ?? '?')
                . ' username=@' . ($me['bot']['username'] ?? '?'));
        } catch (Throwable $e) {
        }
        // Session moi: huy session cu dang mo
        try {
            db()->exec("UPDATE tg_setup_sessions SET status='CANCELLED' WHERE status IN ('WAITING_MESSAGE','WAITING_CONFIRM')");
        } catch (Throwable $e) {
        }
        $sid = 'ts_' . substr(md5(microtime(true) . mt_rand()), 0, 12);
        $exp = date('Y-m-d H:i:s', time() + self::TTL_SEC);
        // Offset hien tai: chi nhan update MOI sau luc nay (§8)
        $offset = self::currentOffset($token);
        try {
            db()->prepare('INSERT INTO tg_setup_sessions (session_id, expires_at, offset_start, status)
                VALUES (?,?,?,?)')
                ->execute([$sid, $exp, $offset, self::ST_WAITING]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Không tạo được setup session.'];
        }
        // Dam bao polling worker chay de lang nghe
        try {
            require_once __DIR__ . '/TelegramPollingCtl.php';
            TelegramPollingCtl::ensureRunning();
        } catch (Throwable $e) {
        }
        try {
            SyncLogger::info('telegram', '[Setup] session ' . $sid . ' started, offset=' . $offset);
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'session_id' => $sid, 'expires_at' => $exp, 'bot' => $me['bot']];
    }

    /** Probe server de lay max update hien tai (khong skip unprocessed §11).
     *  Offset share giu nguyen — worker van nhan binh thuong; loc bang DATE. */
    private static function currentOffset(string $token): int
    {
        try {
            $ch = curl_init(TelegramProvider::API . $token . '/getUpdates');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['offset' => 0, 'limit' => 1, 'timeout' => 0],
                CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
            $body = curl_exec($ch);
            curl_close($ch);
            $j = is_string($body) ? json_decode($body, true) : null;
            $max = 0;
            if (is_array($j) && !empty($j['ok'])) {
                foreach ((array)($j['result'] ?? []) as $u) {
                    $max = max($max, (int)($u['update_id'] ?? 0));
                }
            }
            // Sanity: file offset lech server qua xa (>100k) la nhiem/corrupt -> reset
            require_once __DIR__ . '/TelegramOffset.php';
            $cur = TelegramOffset::get();
            if ($cur > $max + 100000) {
                TelegramOffset::set($max + 1);
                try {
                    SyncLogger::warn('telegram', '[Setup] offset reset (corrupt?) cur=' . $cur . ' server_max=' . $max);
                } catch (Throwable $e) {
                }
                return $max + 1;
            }
            return $cur > 0 ? $cur : $max + 1;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function active(): ?array
    {
        self::ensureTable();
        try {
            $st = db()->query("SELECT * FROM tg_setup_sessions
                WHERE status IN ('WAITING_MESSAGE','WAITING_CONFIRM') ORDER BY created_at DESC LIMIT 1");
            $r = $st->fetch();
            if (!$r) return null;
            if (strtotime((string)$r['expires_at']) < time()) {
                db()->prepare("UPDATE tg_setup_sessions SET status='EXPIRED' WHERE session_id=?")
                    ->execute([$r['session_id']]);
                TelegramConfig::setStatus('ERROR', 'Setup hết hạn.');
                return null;
            }
            return $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function cancel(string $sessionId): void
    {
        try {
            self::ensureTable();
            db()->prepare("UPDATE tg_setup_sessions SET status='CANCELLED' WHERE session_id=?")
                ->execute([$sessionId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Diagnostic tong hop (§2, §43): token/bot/webhook/polling/update/pairing.
     * Khong bao gio kem token.
     */
    public static function diagnostics(): array
    {
        $d = ['token' => false, 'bot' => null, 'webhook' => ['active' => false],
            'polling' => ['state' => 'STOPPED'], 'offset' => 0,
            'session' => null, 'probe' => null];
        try {
            $token = TelegramConfig::token();
            $d['token'] = $token !== '';
            if ($token !== '') {
                $d['bot'] = ['username' => TelegramConfig::get('bot_username', ''),
                    'id' => TelegramConfig::get('bot_id', '')];
                $wh = TelegramProvider::webhookInfoVia($token);
                $d['webhook'] = $wh;
            }
            require_once __DIR__ . '/TelegramGateway.php';
            $d['polling'] = TelegramGateway::connectionState();
            require_once __DIR__ . '/TelegramOffset.php';
            $d['offset'] = TelegramOffset::get();
            $d['session'] = self::active();
        } catch (Throwable $e) {
        }
        return $d;
    }

    /**
     * Dev probe: 1 getUpdates truc tiep (HTTP status/ok/count/latest) — khong token ra ngoai.
     * Chi dung khi worker KHONG listening (tranh 409 second poller §25, §38).
     */
    public static function probeOnce(int $timeout = 8): array
    {
        $out = ['http' => 0, 'ok' => false, 'update_count' => 0, 'latest_update_id' => 0, 'error' => null];
        try {
            $token = TelegramConfig::token();
            if ($token === '') {
                $out['error'] = 'no_token';
                return $out;
            }
            require_once __DIR__ . '/TelegramGateway.php';
            $gw = TelegramGateway::connectionState();
            if (!empty($gw['listening'])) {
                $out['error'] = 'worker_listening';
                return $out;
            }
            require_once __DIR__ . '/TelegramOffset.php';
            $ch = curl_init(TelegramProvider::API . $token . '/getUpdates');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['offset' => TelegramOffset::get(), 'limit' => 10,
                    'timeout' => max(1, min(15, $timeout)),
                    'allowed_updates' => json_encode(['message', 'callback_query'])],
                CURLOPT_TIMEOUT => $timeout + 8, CURLOPT_CONNECTTIMEOUT => 6]);
            $body = curl_exec($ch);
            $out['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0) {
                $out['error'] = 'NETWORK_ERROR';
                return $out;
            }
            $j = is_string($body) ? json_decode($body, true) : null;
            if (!is_array($j) || empty($j['ok'])) {
                $d = strtolower((string)($j['description'] ?? ''));
                $out['error'] = str_contains($d, 'unauthorized') ? 'TELEGRAM_401'
                    : (str_contains($d, 'conflict') ? 'TELEGRAM_409' : 'JSON_PARSE_ERROR');
                return $out;
            }
            $res = (array)($j['result'] ?? []);
            $out['ok'] = true;
            $out['update_count'] = count($res);
            foreach ($res as $u) $out['latest_update_id'] = max($out['latest_update_id'], (int)($u['update_id'] ?? 0));
            // Xu ly ngay cac update lay duoc (setup hoac route), ack sau
            foreach ($res as $u) {
                try {
                    require_once __DIR__ . '/CommandRouter.php';
                    $uid = (int)($u['update_id'] ?? 0);
                    if ($uid > 0 && CommandRouter::seenUpdate($uid)) continue;
                    // Tai su dung handler cua worker (khong duplicate code):
                    // chi setup-branch + route text don gian
                    self::handleProbed($u);
                    if ($uid > 0) {
                        require_once __DIR__ . '/TelegramOffset.php';
                        TelegramOffset::set($uid + 1);
                    }
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
            $out['error'] = 'worker_error';
        }
        return $out;
    }

    /** Xu ly 1 update tu probe (giong worker, rut gon). */
    private static function handleProbed(array $u): void
    {
        $msg = $u['message'] ?? null;
        if (!is_array($msg)) return;
        $chatId = (string)($msg['chat']['id'] ?? '');
        $userId = (string)($msg['from']['id'] ?? '');
        $text = trim((string)($msg['text'] ?? ''));
        if ($chatId === '' || $text === '') return;
        require_once __DIR__ . '/ConversationService.php';
        $isCmd = str_starts_with($text, '/');
        $testMode = get_setting('tg_chat_test_mode', '1') !== '0';
        $cmdMode = get_setting('tg_chat_command_mode', '1') === '1';
        $msgType = $isCmd ? ConversationService::T_COMMAND : ConversationService::T_TEXT;
        if (($testMode || !$cmdMode) && $isCmd && stripos($text, '/pair') !== 0) {
            $msgType = ConversationService::T_TEXT;
        }
        ConversationService::log(ConversationService::IN, $chatId, $text,
            ['user_id' => $userId, 'type' => $msgType]);
        require_once __DIR__ . '/PermissionService.php';
        $chk = PermissionService::check($chatId, $userId);
        if (empty($chk['ok']) && ($msg['chat']['type'] ?? '') === 'private') {
            $s = self::onPrivateMessage($msg, (int)($u['update_id'] ?? 0));
            if (!empty($s['asked_confirm']) || self::active() !== null) return;
        }
        if ($msgType === ConversationService::T_TEXT && $isCmd) return;
        require_once __DIR__ . '/CommandRouter.php';
        $reply = CommandRouter::route($text, 'TELEGRAM', $chatId, $userId);
        if (($reply['text'] ?? '') !== '') {
            require_once __DIR__ . '/TelegramGateway.php';
            TelegramGateway::sendMessage($chatId, (string)$reply['text']);
        }
    }

    /**
     * Nhan private message MOI trong setup window -> candidate (§5, §9, §25).
     * Loc bang DATE (message.date >= session.created_at - 60s), KHONG skip
     * offset (§8 vs §11: khong bo message chua xu ly; old that su bi loai boi date).
     * Bat ky text nao (/start, hello, ...) deu duoc (§12).
     * @return array{paired:bool, asked_confirm:bool}
     */
    public static function onPrivateMessage(array $msg, int $updateId): array
    {
        $sess = self::active();
        if (!$sess) return ['paired' => false, 'asked_confirm' => false];
        $chat = $msg['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'private') return ['paired' => false, 'asked_confirm' => false];
        // Date gate: chi message moi hon session (tru tolerance) — old that bi loai
        $msgDate = (int)($msg['date'] ?? 0);
        $started = strtotime((string)($sess['created_at'] ?? 'now'));
        if ($msgDate > 0 && $msgDate < $started - 60) {
            return ['paired' => false, 'asked_confirm' => false];
        }
        $chatId = (string)($chat['id'] ?? '');
        $from = $msg['from'] ?? [];
        $userId = (string)($from['id'] ?? '');
        if ($chatId === '' || $userId === '') return ['paired' => false, 'asked_confirm' => false];
        $username = (string)($from['username'] ?? '');
        $display = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        if ($display === '') $display = $username !== '' ? '@' . $username : 'Telegram user';
        try {
            $count = (int)($sess['candidate_count'] ?? 0) + 1;
            if (($sess['status'] ?? '') === self::ST_WAITING) {
                // Candidate dau tien -> gui confirm (§9)
                db()->prepare("UPDATE tg_setup_sessions SET status='WAITING_CONFIRM',
                        candidate_chat_id=?, candidate_user_id=?, candidate_username=?,
                        candidate_display=?, candidate_count=? WHERE session_id=?")
                    ->execute([$chatId, $userId, $username, mb_substr($display, 0, 190), $count, $sess['session_id']]);
                $text = "🔐 <b>YT Manager</b>\n\nPhát hiện yêu cầu kết nối với Tool.\n"
                    . "Người yêu cầu: " . htmlspecialchars($display, ENT_QUOTES, 'UTF-8')
                    . ($username !== '' ? ' (@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ')' : '')
                    . "\n\nXác nhận ghép nối?";
                TelegramGateway::sendButtons($chatId, $text,
                    [[['✅ Xác nhận kết nối', 'setup:confirm:' . $sess['session_id']],
                      ['❌ Từ chối', 'setup:reject:' . $sess['session_id']]]]);
                return ['paired' => false, 'asked_confirm' => true];
            }
            // Da co candidate dang cho -> dem them, khong auto-admin (§25)
            db()->prepare('UPDATE tg_setup_sessions SET candidate_count=? WHERE session_id=?')
                ->execute([$count, $sess['session_id']]);
            return ['paired' => false, 'asked_confirm' => false];
        } catch (Throwable $e) {
            return ['paired' => false, 'asked_confirm' => false];
        }
    }

    /** Callback confirm: verify user == candidate (§10). */
    public static function confirm(string $sessionId, string $chatId, string $userId): array
    {
        self::ensureTable();
        try {
            $st = db()->prepare("SELECT * FROM tg_setup_sessions WHERE session_id=? AND status='WAITING_CONFIRM'");
            $st->execute([$sessionId]);
            $sess = $st->fetch();
            if (!$sess) return ['ok' => false, 'text' => '⌛ Yêu cầu đã hết hạn.'];
            if ((string)$sess['candidate_user_id'] !== $userId
                || (string)$sess['candidate_chat_id'] !== $chatId) {
                return ['ok' => false, 'text' => '⛔ Chỉ người yêu cầu mới được xác nhận.'];
            }
            db()->prepare("UPDATE tg_setup_sessions SET status='DONE' WHERE session_id=?")
                ->execute([$sessionId]);
            // Persist qua service chung (single path) (§15)
            require_once __DIR__ . '/TelegramConfigService.php';
            TelegramConfigService::save_pairing($chatId, $userId,
                (string)($sess['candidate_username'] ?? ''),
                (string)($sess['candidate_display'] ?? ''), 'telegram');
            $welcome = "✅ <b>YT Manager đã kết nối thành công.</b>\n\nBạn có thể dùng:\n\n/status\n/help\n/jobs\n\nTool đã sẵn sàng nhận báo cáo và lệnh.";
            TelegramGateway::sendMessage($chatId, $welcome);
            try {
                SyncLogger::info('telegram', '[Setup] paired chat=' . $chatId . ' user=' . $userId);
            } catch (Throwable $e) {
            }
            return ['ok' => true, 'text' => 'Đã ghép nối.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => '❌ Lỗi ghép nối.'];
        }
    }

    public static function reject(string $sessionId, string $chatId, string $userId): array
    {
        try {
            self::ensureTable();
            $st = db()->prepare("SELECT * FROM tg_setup_sessions WHERE session_id=? AND status='WAITING_CONFIRM'");
            $st->execute([$sessionId]);
            $sess = $st->fetch();
            if (!$sess) return ['ok' => false, 'text' => '⌛ Yêu cầu đã hết hạn.'];
            if ((string)$sess['candidate_user_id'] !== $userId) {
                return ['ok' => false, 'text' => '⛔ Chỉ người yêu cầu mới được từ chối.'];
            }
            // Discard candidate, tiep tuc cho (§10)
            db()->prepare("UPDATE tg_setup_sessions SET status='WAITING_MESSAGE',
                    candidate_chat_id=NULL, candidate_user_id=NULL WHERE session_id=?")
                ->execute([$sessionId]);
            return ['ok' => true, 'text' => 'Đã từ chối. Đang chờ yêu cầu khác...'];
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => '❌ Lỗi.'];
        }
    }

    /**
     * Tool-side confirm (§21 "Dung chat nay"): ADMIN local UI xac nhan candidate.
     * Chi khi session WAITING_CONFIRM. Log confirmed_by=tool-ui.
     */
    public static function confirmFromTool(string $sessionId): array
    {
        try {
            self::ensureTable();
            $st = db()->prepare("SELECT * FROM tg_setup_sessions WHERE session_id=? AND status='WAITING_CONFIRM'");
            $st->execute([$sessionId]);
            $sess = $st->fetch();
            if (!$sess) return ['ok' => false, 'text' => '⌛ Yêu cầu đã hết hạn.'];
            if (empty($sess['candidate_chat_id'])) {
                return ['ok' => false, 'text' => 'Chưa có candidate.'];
            }
            db()->prepare("UPDATE tg_setup_sessions SET status='DONE' WHERE session_id=?")
                ->execute([$sessionId]);
            require_once __DIR__ . '/TelegramConfigService.php';
            TelegramConfigService::save_pairing((string)$sess['candidate_chat_id'],
                (string)($sess['candidate_user_id'] ?? ''),
                (string)($sess['candidate_username'] ?? ''),
                (string)($sess['candidate_display'] ?? ''), 'tool-ui');
            require_once __DIR__ . '/TelegramGateway.php';
            TelegramGateway::sendMessage((string)$sess['candidate_chat_id'],
                "✅ <b>YT Manager đã kết nối thành công.</b>\n\nBạn có thể dùng:\n\n/status\n/help\n/jobs");
            return ['ok' => true, 'text' => 'Đã ghép nối.'];
        } catch (Throwable $e) {
            try {
                SyncLogger::error('telegram', 'tool confirm loi', null, $e);
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'text' => 'Không gửi được tin nhắn Telegram.'];
        }
    }
}
