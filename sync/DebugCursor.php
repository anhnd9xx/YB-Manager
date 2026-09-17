<?php
declare(strict_types=1);
/**
 * SyncDebugCursor - Hien thi vi tri target tren CONTROLLED (spec muc 37:
 * Show Sync Cursor, debug coordinate mapping). Ve 1 cham do + label toa do
 * trong page qua Runtime.evaluate (pointer-events:none, khong anh huong input).
 * Bat/tat qua config showCursor. Update throttle o engine (100ms/target).
 */
require_once __DIR__ . '/CdpConnection.php';

class SyncDebugCursor
{
    public const DIV_ID = 'ytmSyncCursor';

    /** Tao dot cursor neu chua co. */
    public static function ensure(SyncCdpConnection $conn): void
    {
        $id = $conn->sendCommand('Runtime.evaluate', ['expression' =>
            '(function(){var d=document.getElementById("' . self::DIV_ID . '");'
            . 'if(d)return "have";'
            . 'd=document.createElement("div");d.id="' . self::DIV_ID . '";'
            . 'd.style.cssText="position:fixed;left:0;top:0;width:18px;height:18px;margin:-9px 0 0 -9px;'
            . 'border:2px solid #ff0000;border-radius:50%;background:rgba(255,0,0,.25);'
            . 'pointer-events:none;z-index:2147483647;";'
            . 'var s=document.createElement("div");s.id="' . self::DIV_ID . 'xy";'
            . 's.style.cssText="position:fixed;left:22px;top:0;font:11px monospace;color:#ff0000;'
            . 'background:rgba(255,255,255,.85);padding:1px 4px;pointer-events:none;z-index:2147483647;";'
            . 'd.appendChild(s);document.documentElement.appendChild(d);return "made";})()',
            'returnByValue' => true]);
        $conn->waitForId($id, 2000);
    }

    /** Di chuyen dot toi (x,y) viewport CSS px + label. */
    public static function move(SyncCdpConnection $conn, float $x, float $y): void
    {
        $conn->sendCommand('Runtime.evaluate', ['expression' =>
            '(function(x,y){var d=document.getElementById("' . self::DIV_ID . '");if(!d)return "no";'
            . 'd.style.transform="translate("+x+"px,"+y+"px)";'
            . 'var s=document.getElementById("' . self::DIV_ID . 'xy");'
            . 'if(s)s.textContent=Math.round(x)+","+Math.round(y);return "ok";})('
            . json_encode(round($x, 1)) . ',' . json_encode(round($y, 1)) . ')',
            'returnByValue' => true]);
        // fire-and-forget (khong cho response de dat 60fps input)
    }

    /** Xoa dot cursor khoi page. */
    public static function clear(SyncCdpConnection $conn): void
    {
        $conn->sendCommand('Runtime.evaluate', ['expression' =>
            '(function(){var d=document.getElementById("' . self::DIV_ID . '");'
            . 'if(d)d.remove();return "ok";})()',
            'returnByValue' => true]);
    }
}
