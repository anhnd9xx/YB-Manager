<?php
declare(strict_types=1);
/**
 * SmartContextBuilder - Context 8 layers co budget + trace (khong dump repo).
 * Layers: request → symbols → deps → rules → db/api → runtime → logs → history.
 */
require_once __DIR__ . '/../config.php';

class SmartContextBuilder
{
    public const BUDGET = 6000;

    /**
     * @param array{runtime?, logs?, history?} $opts
     * @return array{ok, context?, trace?, error?}
     */
    public static function build(int $projectId, string $question, string $intent, array $opts = []): array
    {
        try {
            require_once __DIR__ . '/ProjectBrainService.php';
            require_once __DIR__ . '/ProjectKnowledgeService.php';
            require_once __DIR__ . '/ProjectContextService.php';
            require_once __DIR__ . '/DevProjectRegistry.php';
            $p = DevProjectRegistry::get($projectId);
            if (!$p) return ['ok' => false, 'error' => 'Chưa có project'];
            $budget = self::BUDGET;
            $parts = [];
            $trace = ['question' => mb_substr($question, 0, 200), 'symbols' => [], 'rules' => 0,
                'runtime' => false, 'logs' => 0, 'history' => 0];
            // L1: request (ngan)
            $parts[] = 'REQUEST: ' . mb_substr($question, 0, 500);
            $budget -= 500;
            // L2: symbols tu terms
            $terms = self::extractTerms($question);
            $syms = [];
            foreach (array_slice($terms, 0, 4) as $t) {
                foreach (ProjectBrainService::search($projectId, $t, 5) as $s) {
                    $syms[$s['symbol_type'] . ':' . $s['symbol_name']] = $s;
                }
            }
            $symText = [];
            foreach (array_slice($syms, 0, 10) as $s) {
                $trace['symbols'][] = $s['symbol_name'];
                $sig = trim((string)($s['signature'] ?? ''));
                $symText[] = $s['symbol_type'] . ' ' . $s['symbol_name']
                    . ' @' . $s['file_path'] . ':' . $s['start_line']
                    . ($sig !== '' ? ' — ' . mb_substr($sig, 0, 120) : '');
            }
            if ($symText) {
                $t = "SYMBOLS:\n" . implode("\n", $symText);
                $parts[] = mb_substr($t, 0, (int)($budget * 0.3));
                $budget -= min((int)($budget * 0.3), mb_strlen($t));
            }
            // L3: deps 1-hop cua symbol dau
            if ($syms) {
                require_once __DIR__ . '/ProjectBrainService.php';
                $first = reset($syms);
                $rel = ProjectBrainService::related($projectId, (string)$first['symbol_name'], 8);
                $dt = [];
                if ($rel['callers']) $dt[] = 'callers: ' . implode(',', array_slice($rel['callers'], 0, 8));
                if ($rel['callees']) $dt[] = 'uses: ' . implode(',', array_slice($rel['callees'], 0, 8));
                if ($dt) {
                    $parts[] = 'DEPENDENCIES of ' . $first['symbol_name'] . ': ' . implode(' | ', $dt);
                    $budget -= 300;
                }
            }
            // L4: rules + knowledge lien quan (luon co invariants co ban)
            $kn = ProjectKnowledgeService::relevant($projectId, $question, 6);
            if (count($kn) < 2) {
                foreach (ProjectKnowledgeService::list($projectId, 'INVARIANT', 3) as $r2) {
                    $kn[] = $r2;
                }
            }
            $trace['rules'] = count($kn);
            if ($kn) {
                $kt = [];
                foreach ($kn as $k) {
                    $kt[] = '[' . $k['ktype'] . '] ' . $k['title'] . ': ' . mb_substr((string)$k['body'], 0, 160);
                }
                $t = "RULES:\n" . implode("\n", $kt);
                $parts[] = mb_substr($t, 0, (int)($budget * 0.25));
                $budget -= min((int)($budget * 0.25), mb_strlen($t));
            }
            // L5: db/api refs gon (schema tables lien quan theo tu khoa)
            if ($budget > 400 && preg_match('/(database|bảng|table|migration|api|endpoint)/i', $question)) {
                $parts[] = 'DATABASE: ' . ProjectContextService::schemaRefs();
                $budget -= 400;
            }
            // L6: runtime (diagnosis/runtime intents)
            if (!empty($opts['runtime']) && $budget > 500) {
                require_once __DIR__ . '/RuntimeContextService.php';
                $parts[] = RuntimeContextService::toText(RuntimeContextService::snapshot($projectId));
                $trace['runtime'] = true;
                $budget -= 600;
            }
            // L7: logs (diagnosis/bug)
            if (!empty($opts['logs']) && $budget > 500) {
                require_once __DIR__ . '/AILogContextService.php';
                $rows = AILogContextService::query([
                    'module' => self::guessModule($question),
                    'entity' => implode(' ', array_slice($terms, 0, 3)),
                    'hours' => 6, 'limit' => 25,
                ], (string)$p['root_path']);
                $trace['logs'] = count($rows);
                if ($rows) {
                    $parts[] = 'LOGS:' . "\n" . mb_substr(AILogContextService::toText($rows), 0, (int)($budget * 0.3));
                    $budget -= 600;
                }
            }
            // L8: git history cua files lien quan
            if (!empty($opts['history']) && $syms && $budget > 300) {
                require_once __DIR__ . '/DevJobManager.php';
                $root = realpath((string)$p['root_path']);
                $hists = [];
                $n = 0;
                foreach (array_slice($syms, 0, 3) as $s) {
                    if ($root === false) break;
                    $r = DevJobManager::git($root, ['log', '--oneline', '-n', '3', '--', (string)$s['file_path']]);
                    if (!empty($r['ok']) && trim((string)$r['out']) !== '') {
                        $hists[] = $s['file_path'] . ': ' . str_replace("\n", ' / ', trim((string)$r['out']));
                        $trace['history']++;
                        if (++$n >= 3) break;
                    }
                }
                if ($hists) $parts[] = 'HISTORY:' . "\n" . implode("\n", $hists);
            }
            $ctx = ProjectContextService::redactSecrets(implode("\n\n", $parts));
            if (mb_strlen($ctx) > self::BUDGET) $ctx = mb_substr($ctx, 0, self::BUDGET) . "\n…(rút gọn)";
            return ['ok' => true, 'context' => $ctx, 'trace' => $trace];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /** Trich terms: CamelCase tokens + tu dai. */
    public static function extractTerms(string $q): array
    {
        $terms = [];
        if (preg_match_all('/[A-Z][A-Za-z0-9_]{3,}/', $q, $m)) {
            foreach ($m[0] as $t) $terms[] = $t;
        }
        foreach (preg_split('/\s+/u', mb_strtolower($q)) as $w) {
            $w = trim($w, " \t\n\r\0\x0B?,.:!\"'()[]");
            if (mb_strlen($w) > 4 && !in_array($w, $terms, true)) $terms[] = $w;
        }
        // Alias map: tu thuong -> module/symbol
        $alias = ['nuôi mail' => 'Activity', 'nuoi mail' => 'activity', 'auto' => 'Activity',
            'đóng tất cả' => 'ChromeBatchManager', 'dong tat ca' => 'ChromeBatchManager',
            'polling' => 'TelegramPolling', 'receiver' => 'TelegramSupervisor',
            'đánh giá' => 'ChannelEvaluationManager', 'danh gia' => 'ChannelEvaluationManager',
            'kênh' => 'profiles', 'kenh' => 'profiles', 'proxy' => 'proxy',
            'lịch' => 'Scheduler', 'lich' => 'Scheduler', 'thông báo' => 'Notification',
            'thong bao' => 'Notification', 'sức khỏe' => 'Health', 'suc khoe' => 'Health'];
        $low = mb_strtolower($q);
        foreach ($alias as $k => $v) {
            if (str_contains($low, $k) && !in_array($v, $terms, true)) $terms[] = $v;
        }
        return array_slice(array_values(array_unique($terms)), 0, 10);
    }

    private static function guessModule(string $q): string
    {
        $low = mb_strtolower($q);
        foreach (['telegram' => 'telegram_polling', 'activity' => 'activity',
            'proxy' => 'proxy', 'evaluation' => 'evaluation', 'job' => 'job_worker',
            'chrome' => 'chrome', 'notify' => 'notify_worker'] as $k => $v) {
            if (str_contains($low, $k)) return $v;
        }
        return '';
    }
}
