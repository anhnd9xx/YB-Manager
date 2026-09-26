<?php
declare(strict_types=1);
/**
 * bin/ai_answer.php - Tra loi AI async (khong block Telegram dispatcher).
 * Usage: php ai_answer.php --chat=ID --user=ID --role=R --kind=qa|plan|hybrid [--text=...]
 * Text doc tu STDIN file tam neu dai (tranh quoting): --file=path
 */
require_once __DIR__ . '/../sync/AIDevConsole.php';

$opts = getopt('', ['chat:', 'user:', 'role:', 'kind:', 'text:', 'file:']);
$chatId = (string)($opts['chat'] ?? '');
$text = (string)($opts['text'] ?? '');
if (!empty($opts['file']) && is_file((string)$opts['file'])) {
    $text = (string)@file_get_contents((string)$opts['file']);
    @unlink((string)$opts['file']);
}
if ($chatId === '' || $text === '') exit(0);
$userId = (string)($opts['user'] ?? '');
$role = (string)($opts['role'] ?? 'VIEWER');
$kind = (string)($opts['kind'] ?? 'qa');

try {
    switch ($kind) {
        case 'plan': {
            $r = AIDevConsole::startPlan($chatId, $userId, $text, false);
            if (($r['__buttons'] ?? null)) AIDevConsole::sendWithButtons($chatId, $r['text'], $r['__buttons']);
            else AIDevConsole::reply($chatId, $r['text']);
            break;
        }
        case 'hybrid': {
            $r = AIDevConsole::hybridDiagnosis($chatId, $userId, $role, $text);
            if (($r['__buttons'] ?? null)) AIDevConsole::sendWithButtons($chatId, $r['text'], $r['__buttons']);
            else AIDevConsole::reply($chatId, $r['text']);
            break;
        }
        default: {
            $r = AIDevConsole::answerQuestion($chatId, $userId, $text, $role, false);
            AIDevConsole::reply($chatId, $r['text']);
            break;
        }
    }
} catch (Throwable $e) {
    AIDevConsole::reply($chatId, '❌ Lỗi xử lý AI.');
}
