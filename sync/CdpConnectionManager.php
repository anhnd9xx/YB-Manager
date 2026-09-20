<?php
declare(strict_types=1);
/**
 * CdpConnectionManager - 1 WebSocket CDP BROWSER-LEVEL / profile / batch.
 *
 * Van de cu: moi tab = 1 HTTP /json/new + 1 GET /json/list + 1 WS connect/disconnect
 *   + sleep 300ms -> mo 20 tabs mat ~6-10s, UI refresh 20 lan.
 * Moi: 1 ket noi WS duy nhat toi browser endpoint trong ca batch, dieu khien
 *   Target.createTarget / Target.closeTarget / Target.activateTarget / Target.getTargets
 *   tren cung socket. Khong reconnect tung tab, khong /json/list tung tab,
 *   khong doi website load, khong sleep giua cac tab.
 *
 * Luu y PHP stateless: persistent o day = trong pham vi 1 batch operation
 * (1 HTTP request mo 20 tabs van chi 1 WS). Khong the giu socket qua nhieu
 * request khac nhau ma khong co daemon.
 */
require_once __DIR__ . '/CdpConnection.php';
require_once __DIR__ . '/SyncLogger.php';

class CdpConnectionManager
{
    public const COMMAND_TIMEOUT_MS = 2000;

    private SyncCdpConnection $conn;
    private int $port;
    private bool $ok = false;

    private function __construct(int $port)
    {
        $this->port = $port;
        $this->conn = new SyncCdpConnection();
    }

    /** Mo 1 WS browser-level cho $port. Tra ve null neu khong ket noi duoc. */
    public static function forPort(int $port): ?self
    {
        if ($port <= 0 || !cdp_reachable($port)) return null;
        $ws = self::browserWs($port);
        if ($ws === null) return null;
        $m = new self($port);
        if (!$m->conn->connect($ws)) return null;
        $m->ok = true;
        return $m;
    }

    /** Lay browser webSocketDebuggerUrl tu /json/version (1 HTTP duy nhat/batch). */
    private static function browserWs(int $port): ?string
    {
        $r = cdp_http($port, 'GET', '/json/version', 1500);
        if ($r === null || $r['body'] === '') return null;
        $j = json_decode($r['body'], true);
        $ws = is_array($j) ? (string)($j['webSocketDebuggerUrl'] ?? '') : '';
        return $ws !== '' ? $ws : null;
    }

    public function isOk(): bool
    {
        return $this->ok && $this->conn->isConnected();
    }

    public function disconnect(): void
    {
        try {
            $this->conn->disconnect();
        } catch (Throwable $e) {
        }
        $this->ok = false;
    }

    /** Gui lenh CDP dong bo, timeout 2s. Tra ve result array hoac null. */
    public function cmd(string $method, array $params = [], int $timeoutMs = self::COMMAND_TIMEOUT_MS): ?array
    {
        if (!$this->isOk()) return null;
        $id = $this->conn->sendCommand($method, $params);
        if ($id <= 0) return null;
        $r = $this->conn->waitForId($id, $timeoutMs);
        if (!is_array($r) || isset($r['error'])) return null;
        return $r['result'] ?? [];
    }

    /** Snapshot targets 1 lan (thay N lan /json/list). */
    public function getTargets(): ?array
    {
        $res = $this->cmd('Target.getTargets', [], 3000);
        if ($res === null) return null;
        return $res['targetInfos'] ?? [];
    }

    /** Tao 1 target, tra ve targetId hoac null. KHONG doi load. */
    public function createTarget(string $url): ?string
    {
        $res = $this->cmd('Target.createTarget', ['url' => $url]);
        $tid = is_array($res) ? (string)($res['targetId'] ?? '') : '';
        return $tid !== '' ? $tid : null;
    }

    public function closeTarget(string $targetId): bool
    {
        $res = $this->cmd('Target.closeTarget', ['targetId' => $targetId]);
        return $res !== null;
    }

    public function activateTarget(string $targetId): bool
    {
        $res = $this->cmd('Target.activateTarget', ['targetId' => $targetId]);
        return $res !== null;
    }
}
