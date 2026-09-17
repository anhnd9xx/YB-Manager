<?php
declare(strict_types=1);
/**
 * SyncCapture - Gan capture script + Runtime binding vao 1 CDP target (PHASE 3).
 * Dung Page.addScriptToEvaluateOnNewDocument (song qua navigation) + Runtime.addBinding.
 * Binding name: __ytmSyncEvt. Da arm roi thi bo qua (theo target id).
 */
require_once __DIR__ . '/CdpConnection.php';

class SyncCapture
{
    public const BINDING = '__ytmSyncEvt';
    /** @var array<string,true> target id da arm */
    private array $armed = [];

    /** Doc capture.js, thay __FPS__. */
    public static function script(int $fps = 60): string
    {
        if ($fps !== 30 && $fps !== 120) $fps = 60;
        $js = (string)@file_get_contents(__DIR__ . '/capture.js');
        return str_replace('__FPS__', (string)$fps, $js);
    }

    /**
     * Arm 1 target (can WS connection + target id). Tra ve true neu ca 2 lenh OK.
     * Idempotent theo target id (goi lai khong inject trung).
     */
    public function arm(SyncCdpConnection $conn, string $targetId, int $fps = 60): bool
    {
        if (isset($this->armed[$targetId])) return true;
        $id1 = $conn->sendCommand('Runtime.addBinding', ['name' => self::BINDING]);
        $r1 = $conn->waitForId($id1, 3000);
        if ($r1 === null || isset($r1['error'])) return false;
        $id2 = $conn->sendCommand('Page.addScriptToEvaluateOnNewDocument', ['source' => self::script($fps)]);
        $r2 = $conn->waitForId($id2, 3000);
        if ($r2 === null || isset($r2['error'])) return false;
        $this->armed[$targetId] = true;
        return true;
    }

    public function forget(string $targetId): void
    {
        unset($this->armed[$targetId]);
    }

    public function reset(): void
    {
        $this->armed = [];
    }
}
