<?php
/**
 * tabtitle_keeper.php - Process nên theo doi CDP cua 1 Chrome channel.
 * Khi phat hien target page MOI (tab moi, ctrl+T, click link mo tab moi...)
 * thi tu dong gan tien de tab: "Ten kenh | <tieu de trang>" giong tab dau tien.
 *
 * Cach dung: php -f tabtitle_keeper.php <cdp_port> <ten_kenh>
 * Thoat tu dong khi CDP mat ket noi (Chrome dong)
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$port = (int)($argv[1] ?? 0);
$profileId = (int)($argv[2] ?? 0);
if ($port < 1 || $profileId < 1) exit("usage: tabtitle_keeper.php <cdp_port> <profile_id>\n");
require __DIR__ . '/config.php';

$st = db()->prepare('SELECT name FROM profiles WHERE id=? LIMIT 1');
$st->execute([$profileId]);
$name = trim((string)$st->fetchColumn());
if ($name === '') exit("no name\n");

$nJs = json_encode($name, JSON_UNESCAPED_UNICODE);
$strip = "P + t.split(P).join('').trim()";
$src = "(()=>{const N=$nJs;const P=N+' | ';const A=()=>{const t=document.title;const n=P+t.split(P).join('').trim();if(t!==n)document.title=n;};try{A();new MutationObserver(A).observe(document.documentElement,{subtree:true,childList:true,characterData:true});}catch(e){}setInterval(A,300);})();";
$expr = "(()=>{const N=$nJs;const P=N+' | ';const t=document.title;const n=P+t.split(P).join('').trim();if(t!==n)document.title=n;})()";

$scripted = [];
$giveUp = [];
$downRounds = 0;
while (true) {
    try {
        $deadline = time() + 10;
        $targets = [];
        while (time() < $deadline) {
            $targets = cdp_page_targets($port);
            if (!empty($targets)) break;
            $downRounds++;
            if ($downRounds > 45) exit(0); // Chrome da dong
            sleep(1);
        }
        if (empty($targets)) continue;
        $downRounds = 0;
        $state = [];
        foreach ($targets as $t) $state[$t['id']] = $t;
        foreach ($state as $id => $t) {
            $prefixed = strpos($t['title'], $name . ' | ') === 0;
            if ($prefixed) continue; // da co ten kenh, khong can lam gi
            if (!isset($scripted[$id])) {
                // tab moi / chua tu gan: addScript 1 lan (phu moi doc sau cua tab nay)
                cdp_ws_send($port, $t['webSocketDebuggerUrl'], json_encode(['id' => 1, 'method' => 'Page.addScriptToEvaluateOnNewDocument', 'params' => ['source' => $src]]));
                $scripted[$id] = true;
            }
            $giveUp[$id] = ($giveUp[$id] ?? 0) + 1;
            // tab blank chua san context: evaluate se thanh cong khi context tao xong.
            // sau ~150s khong set duoc (tab dac biet), chi retry thua (1/10) de khoe man hinh
            if ($giveUp[$id] > 600 && $giveUp[$id] % 10 !== 0) continue;
            // Runtime context co the chua san -> retry evaluate cho den khi title co prefix
            cdp_ws_send($port, $t['webSocketDebuggerUrl'], json_encode(['id' => 2, 'method' => 'Runtime.evaluate', 'params' => ['expression' => $expr]]));
        }
    } catch (Throwable $e) {
        // khong de loi lam chet keeper
    }
    usleep(250000);
}