<?php
declare(strict_types=1);
/**
 * OpenCodeGateway - Client DUY NHAT noi voi OpenCode server (§7).
 * Server mode (REST 127.0.0.1) uu tien; CLI adapter fallback khi server chet.
 * Khong module nao tu subprocess opencode.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/OpenCodeService.php';

class OpenCodeGateway
{
    /** GET tho. @return array{ok, data?, http?, error?} */
    public static function get(string $path, int $timeout = 10): array
    {
        return self::req('GET', $path, null, $timeout);
    }

    /** POST JSON. @return array{ok, data?, http?, error?} */
    public static function post(string $path, $body, int $timeout = 30): array
    {
        return self::req('POST', $path, $body, $timeout);
    }

    /** DELETE. */
    public static function del(string $path, int $timeout = 10): array
    {
        return self::req('DELETE', $path, null, $timeout);
    }

    private static function req(string $method, string $path, $body, int $timeout): array
    {
        $url = OpenCodeService::baseUrl() . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        // Basic auth: user opencode + server password (§5)
        $auth = 'opencode:' . OpenCodeService::password();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(2, $timeout),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERPWD => $auth,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($resp)) {
            return ['ok' => false, 'http' => 0, 'error' => 'oc_unreachable'];
        }
        if ($http === 401) return ['ok' => false, 'http' => 401, 'error' => 'oc_unauthorized'];
        if ($http === 404) return ['ok' => false, 'http' => 404, 'error' => 'oc_not_found'];
        if ($http >= 400) {
            return ['ok' => false, 'http' => $http,
                'error' => 'oc_error_' . $http, 'raw' => mb_substr($resp, 0, 300)];
        }
        // 204 No Content (wait idle)
        if ($http === 204 || $resp === '') return ['ok' => true, 'http' => $http, 'data' => null];
        $j = json_decode($resp, true);
        if (!is_array($j)) return ['ok' => false, 'http' => $http, 'error' => 'oc_bad_response'];
        return ['ok' => true, 'http' => $http, 'data' => $j['data'] ?? $j];
    }

    // ---------- Concept API (§7) ----------

    /** @return array{ok, session?, error?} */
    public static function createSession(string $title = '', string $directory = '', string $model = ''): array
    {
        $body = [];
        if ($title !== '') $body['title'] = mb_substr($title, 0, 120);
        if ($directory !== '') $body['location'] = ['directory' => $directory];
        if ($model !== '') $body['model'] = ['modelID' => $model];
        $r = self::post('/api/session', $body ?: (object)[], 15);
        if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'oc_error'];
        return ['ok' => true, 'session' => $r['data']];
    }

    /** @return array{ok, message?, error?} */
    public static function prompt(string $sessionId, string $text, array $opts = []): array
    {
        $body = ['prompt' => ['text' => $text]];
        if (!empty($opts['agent'])) $body['agent'] = $opts['agent'];
        if (!empty($opts['model'])) $body['model'] = $opts['model'];
        if (isset($opts['resume'])) $body['resume'] = (bool)$opts['resume'];
        $r = self::post('/api/session/' . $sessionId . '/prompt', $body, 60);
        if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'oc_error'];
        return ['ok' => true, 'message' => $r['data']];
    }

    /** Cho den idle: 204=idle, 503=busy. @return array{idle} */
    public static function waitIdle(string $sessionId, int $timeoutSec = 120): array
    {
        $t0 = microtime(true);
        while ((microtime(true) - $t0) < $timeoutSec) {
            $r = self::post('/api/session/' . $sessionId . '/wait', (object)[], 30);
            if (!empty($r['ok']) && ($r['http'] ?? 0) === 204) return ['idle' => true];
            if (!empty($r['ok'])) return ['idle' => true]; // 200 bat thuong -> coi nhu xong
            if (($r['http'] ?? 0) === 404) return ['idle' => false];
            sleep(5); // busy/timeout -> cho, khong hammer server
        }
        return ['idle' => false];
    }

    /** @return array[] pending permission requests cua session */
    public static function permissions(string $sessionId): array
    {
        $r = self::get('/api/session/' . $sessionId . '/permission', 10);
        if (empty($r['ok'])) return [];
        $d = $r['data'];
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) return $d['data'];
        return is_array($d) ? $d : [];
    }

    /** Tra loi permission request: allow|deny|ask. */
    public static function replyPermission(string $sessionId, string $requestId, string $decision): array
    {
        $r = self::post('/api/session/' . $sessionId . '/permission/' . $requestId . '/reply',
            ['decision' => $decision], 10);
        return ['ok' => !empty($r['ok'])];
    }

    /** @return array[] messages (moi nhat truoc) */
    public static function messages(string $sessionId, int $limit = 20): array
    {
        $r = self::get('/api/session/' . $sessionId . '/message?limit=' . max(1, min(50, $limit)), 20);
        if (empty($r['ok']) || !is_array($r['data'] ?? null)) return [];
        $d = $r['data'];
        if (isset($d['data']) && is_array($d['data'])) return $d['data'];
        return is_array($d) ? $d : [];
    }

    /** Trich text assistant moi nhat + tokens/cost. */
    public static function lastAssistantText(string $sessionId): array
    {
        $out = ['text' => '', 'tokens' => null, 'cost' => null, 'model' => ''];
        foreach (self::messages($sessionId, 10) as $m) {
            if (($m['type'] ?? '') !== 'assistant') continue;
            $txt = '';
            foreach ((array)($m['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'text' && isset($c['text'])) $txt .= (string)$c['text'];
            }
            $out['text'] = trim($txt);
            $out['tokens'] = $m['tokens'] ?? null;
            $out['cost'] = $m['cost'] ?? null;
            $out['model'] = $m['model']['id'] ?? ($m['model'] ?? '');
            break;
        }
        return $out;
    }

    /** @return array{ok} */
    public static function interrupt(string $sessionId): array
    {
        $r = self::post('/api/session/' . $sessionId . '/interrupt', (object)[], 15);
        return ['ok' => !empty($r['ok'])];
    }

    /** @return array|null session info */
    public static function getSession(string $sessionId): ?array
    {
        $r = self::get('/api/session/' . $sessionId, 15);
        if (empty($r['ok']) || !is_array($r['data'] ?? null)) return null;
        return $r['data'];
    }

    /** @return array{ok} */
    public static function health(): array
    {
        return OpenCodeService::health(4);
    }

    /**
     * Q&A dong bo: tao session (neu can) + prompt + doi + tra text.
     * @return array{ok, text?, session_id?, tokens?, cost?, model?, error?}
     */
    public static function ask(string $question, array $opts = []): array
    {
        $sid = (string)($opts['session_id'] ?? '');
        $created = false;
        if ($sid === '') {
            $c = self::createSession(
                mb_substr((string)($opts['title'] ?? 'Q&A'), 0, 120),
                (string)($opts['directory'] ?? ''),
                (string)($opts['model'] ?? ''));
            if (empty($c['ok'])) return ['ok' => false, 'error' => $c['error'] ?? 'oc_error'];
            $sid = (string)($c['session']['id'] ?? '');
            $created = true;
        }
        if ($sid === '') return ['ok' => false, 'error' => 'oc_no_session'];
        $p = self::prompt($sid, $question);
        if (empty($p['ok'])) return ['ok' => false, 'error' => $p['error'] ?? 'oc_error', 'session_id' => $sid];
        $w = self::waitIdle($sid, (int)($opts['timeout'] ?? 300));
        if (empty($w['idle'])) return ['ok' => false, 'error' => 'oc_timeout', 'session_id' => $sid];
        $a = self::lastAssistantText($sid);
        if ($a['text'] === '') return ['ok' => false, 'error' => 'oc_empty', 'session_id' => $sid];
        return ['ok' => true, 'text' => $a['text'], 'session_id' => $sid,
            'tokens' => $a['tokens'], 'cost' => $a['cost'], 'model' => $a['model'],
            'created_session' => $created];
    }

    /**
     * CLI fallback: `opencode run` 1-shot (khong session luu).
     * Dung khi server chet va can cau tra loi gap.
     */
    public static function cliAsk(string $question, string $directory = '', int $timeoutSec = 300): array
    {
        $bin = OpenCodeService::binary();
        if ($bin === '') return ['ok' => false, 'error' => 'oc_no_binary'];
        $dir = $directory !== '' ? $directory : OpenCodeService::primaryRoot();
        // Ghi prompt ra file tam (tranh quoting hell), chay headless json
        $tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ytm_oc_q_' . md5(microtime(true)) . '.txt';
        @file_put_contents($tmp, $question);
        try {
            $ps = '$in = Get-Content -Raw \'' . str_replace("'", "''", $tmp) . '\'; '
                . '& \'' . str_replace("'", "''", $bin) . '\' run --format json --dir \'' . str_replace("'", "''", $dir) . '\' $in '
                . '2>$null | Select-Object -Last 50';
            $descriptors = [1 => ['pipe', 'w']];
            $proc = proc_open('powershell -NoProfile -Command "' . $ps . '"', $descriptors, $pipes);
            if (!is_resource($proc)) return ['ok' => false, 'error' => 'oc_spawn_fail'];
            stream_set_blocking($pipes[1], false);
            $out = '';
            $t0 = microtime(true);
            while ((microtime(true) - $t0) < $timeoutSec) {
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && $chunk !== '') $out .= $chunk;
                $st = proc_get_status($proc);
                if (empty($st['running'])) break;
                usleep(500000);
            }
            proc_terminate($proc);
            proc_close($proc);
            // Parse json events: lay text cuoi
            $text = '';
            foreach (preg_split('/\r?\n/', $out) as $line) {
                $j = json_decode(trim($line), true);
                if (is_array($j) && isset($j['text'])) $text .= $j['text'];
            }
            $text = trim($text) !== '' ? trim($text) : trim($out);
            if ($text === '') return ['ok' => false, 'error' => 'oc_empty'];
            return ['ok' => true, 'text' => mb_substr($text, 0, 8000)];
        } finally {
            @unlink($tmp);
        }
    }
}
