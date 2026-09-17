<?php
declare(strict_types=1);
/**
 * SyncCdpConnection - Ket noi WebSocket PERSISTENT toi 1 CDP target (spec: BrowserConnection).
 * Moi profile 1 connection rieng (khong dung chung). Ho tro:
 * connect/disconnect/isConnected/sendCommand/waitForId + doc message lien tuc
 * (xu ly fragmentation, ping->pong, close). Server->client frame KHONG mask.
 */
class SyncCdpConnection
{
    private $fp = null;
    private string $host = '127.0.0.1';
    private int $port = 0;
    private string $path = '/';
    private string $buf = '';       // buffer frame chua xong
    private string $frag = '';      // message dang ghep tu continuation frames
    private int $fragOp = -1;
    private bool $closed = false;
    private int $nextId = 1;
    /** @var array<int, array|null> id -> result/error (cho waitForId) */
    private array $pending = [];
    /** @var string[] message da doc nhung chua tieu thu (waitForId stash vao day) */
    private array $inbox = [];

    public function connect(string $wsUrl): bool
    {
        $this->disconnect();
        $u = parse_url($wsUrl);
        if (!$u || ($u['scheme'] ?? '') !== 'ws' || empty($u['path'])) return false;
        $this->port = (int)($u['port'] ?? 0);
        if ($this->port <= 0) return false;
        $this->path = $u['path'] . (!empty($u['query']) ? '?' . $u['query'] : '');
        $fp = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 3);
        if (!$fp) return false;
        $key = base64_encode(random_bytes(16));
        fwrite($fp, "GET {$this->path} HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($fp, 3);
        $hdr = '';
        while (strpos($hdr, "\r\n\r\n") === false) {
            $c = fread($fp, 4096);
            if ($c === false || $c === '') { fclose($fp); return false; }
            $hdr .= $c;
            if (strlen($hdr) > 16384) { fclose($fp); return false; }
        }
        if (strpos($hdr, ' 101 ') === false) { fclose($fp); return false; }
        $this->fp = $fp;
        $this->closed = false;
        stream_set_blocking($fp, false);
        return true;
    }

    public function disconnect(): void
    {
        if (is_resource($this->fp)) @fclose($this->fp);
        $this->fp = null;
        $this->buf = '';
        $this->frag = '';
        $this->fragOp = -1;
        $this->closed = true;
        $this->pending = [];
    }

    public function isConnected(): bool
    {
        return is_resource($this->fp) && !$this->closed;
    }

    /** Gui 1 frame text (client->server BAT BUOC mask). */
    private function sendFrame(string $payload, int $opcode = 0x1): bool
    {
        if (!$this->isConnected()) return false;
        $len = strlen($payload);
        if ($len < 126) $h = chr(0x80 | $opcode) . chr(0x80 | $len);
        elseif ($len < 65536) $h = chr(0x80 | $opcode) . chr(0x80 | 126) . pack('n', $len);
        else $h = chr(0x80 | $opcode) . chr(0x80 | 127) . pack('J', $len);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
        $n = @fwrite($this->fp, $h . $mask . $masked);
        return $n !== false;
    }

    /** Gui lenh CDP, tra ve id (dang ky cho waitForId). */
    public function sendCommand(string $method, array $params = []): int
    {
        $id = $this->nextId++;
        $msg = ['id' => $id, 'method' => $method];
        if ($params) $msg['params'] = $params;
        $this->pending[$id] = null; // marker dang cho
        if (!$this->sendFrame(json_encode($msg))) {
            unset($this->pending[$id]);
            return 0;
        }
        return $id;
    }

    /**
     * Doc message trong $timeoutMs. Tra ve inbox truoc (khong mat event),
     * roi moi doc socket. Dong thoi nap $pending + tra ping/pong/close.
     */
    public function readMessages(int $timeoutMs = 50): array
    {
        $out = $this->inbox;
        $this->inbox = [];
        foreach ($this->readSocket($timeoutMs) as $text) $out[] = $text;
        foreach ($out as $text) {
            $j = json_decode($text, true);
            if (is_array($j) && isset($j['id']) && array_key_exists((int)$j['id'], $this->pending)) {
                $this->pending[(int)$j['id']] = $j;
            }
        }
        return $out;
    }

    /** Doc that tu socket (noi bo). */
    private function readSocket(int $timeoutMs = 50): array
    {
        $out = [];
        if (!$this->isConnected()) return $out;
        $deadline = microtime(true) + max(0, $timeoutMs) / 1000;
        do {
            $r = [$this->fp];
            $w = null;
            $e = null;
            $wait = (int)max(0, ($deadline - microtime(true)) * 1000000);
            $n = @stream_select($r, $w, $e, 0, min($wait, 200000));
            if ($n === false) { $this->closed = true; break; }
            if ($n === 0) break;
            $chunk = @fread($this->fp, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($this->fp)) $this->closed = true;
                break;
            }
            $this->buf .= $chunk;
            foreach ($this->parseBuffer() as $m) {
                if ($m['op'] === 0x8) { $this->closed = true; break 2; }
                if ($m['op'] === 0x9) { $this->sendFrame($m['data'], 0xA); continue; } // ping -> pong
                if ($m['op'] === 0x1 || $m['op'] === 0x2 || $m['op'] === 0x0) $out[] = $m['data'];
            }
        } while (microtime(true) < $deadline);
        return $out;
    }

    /** Parse buffer thanh frames hoan chinh (xu ly fragmentation). */
    private function parseBuffer(): array
    {
        $msgs = [];
        while (strlen($this->buf) >= 2) {
            $b0 = ord($this->buf[0]);
            $b1 = ord($this->buf[1]);
            $fin = ($b0 & 0x80) !== 0;
            $op = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;
            $off = 2;
            if ($len === 126) {
                if (strlen($this->buf) < 4) break;
                $len = unpack('n', substr($this->buf, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                if (strlen($this->buf) < 10) break;
                $len = unpack('J', substr($this->buf, 2, 8))[1];
                $off = 10;
            }
            $maskLen = $masked ? 4 : 0;
            if (strlen($this->buf) < $off + $maskLen + $len) break; // chua du du lieu
            $data = substr($this->buf, $off + $maskLen, $len);
            if ($masked) {
                $mk = substr($this->buf, $off, 4);
                $um = '';
                for ($i = 0; $i < $len; $i++) $um .= $data[$i] ^ $mk[$i % 4];
                $data = $um;
            }
            $this->buf = substr($this->buf, $off + $maskLen + $len);
            if ($op === 0x0) { // continuation
                if ($this->fragOp >= 0) $this->frag .= $data;
                if ($fin && $this->fragOp >= 0) {
                    $msgs[] = ['op' => $this->fragOp, 'data' => $this->frag];
                    $this->frag = '';
                    $this->fragOp = -1;
                }
            } elseif ($fin) {
                $msgs[] = ['op' => $op, 'data' => $data];
            } else { // bat dau fragmented message
                $this->frag = $data;
                $this->fragOp = $op;
            }
        }
        return $msgs;
    }

    /**
     * Cho ket qua lenh $id toi da $timeoutMs. Message khong phai response
     * (vidu bindingCalled) duoc stash vao inbox, lan readMessages sau tra ve
     * (khong mat event khi cho response). Tra ve response array hoac null.
     */
    public function waitForId(int $id, int $timeoutMs = 3000): ?array
    {
        if ($id <= 0) return null;
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (microtime(true) < $deadline) {
            foreach ($this->readSocket(100) as $text) {
                $j = json_decode($text, true);
                if (is_array($j) && isset($j['id']) && (int)$j['id'] === $id) {
                    unset($this->pending[$id]);
                    return $j;
                }
                if (is_array($j) && isset($j['id']) && array_key_exists((int)$j['id'], $this->pending)) {
                    $this->pending[(int)$j['id']] = $j;
                    continue;
                }
                $this->inbox[] = $text; // giu lai cho readMessages sau
            }
            if (array_key_exists($id, $this->pending) && $this->pending[$id] !== null) {
                $r = $this->pending[$id];
                unset($this->pending[$id]);
                return $r;
            }
            if (!$this->isConnected()) break;
        }
        unset($this->pending[$id]);
        return null;
    }
}
