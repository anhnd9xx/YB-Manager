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

// --- Chống dính fingerprint giữa các kênh: bơm polyfill JS BỀN theo target vào từng trang
// (addScriptToEvaluateOnNewDocument áp cho mọi tab mới của kênh, không reset khi session CDP detach).
// hardwareConcurrency/deviceMemory/timezone khác nhau từng kênh; ngôn ngữ đã do --accept-lang bền.
$fp   = channel_fingerprint($profileId);
$hwC  = (int)$fp['hardwareConcurrency'];
$dm   = (int)$fp['deviceMemory'];
$tzId = $fp['timezoneId'];
$tzOff= (int)$fp['tzOffset'];
// CDP không có Emulation cho deviceMemory/hwConcurrency/timezone bền trong Chrome branded ->
// defineProperty trên prototype để mọi trang mới (và trang hiện tại khi evaluate) thấy giá trị riêng.
$tzLabels = [
    'Asia/Ho_Chi_Minh'      => 'Indochina Time',
    'Asia/Bangkok'          => 'Indochina Time',
    'Asia/Singapore'        => 'Singapore Standard Time',
    'Asia/Manila'           => 'Philippine Standard Time',
    'Asia/Jakarta'          => 'Western Indonesia Time',
];
$tzLabel = $tzLabels[$tzId] ?? 'Indochina Time';
$poly = "(()=>{"
    . "try{Object.defineProperty(Navigator.prototype,'deviceMemory',{get:()=>$dm,configurable:true});}"
    . "catch(e){try{Object.defineProperty(navigator,'deviceMemory',{get:()=>$dm,configurable:true});}catch(_){}}"
    . "try{Object.defineProperty(Navigator.prototype,'hardwareConcurrency',{get:()=>$hwC,configurable:true});}"
    . "catch(e){try{Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>$hwC,configurable:true});}catch(_){}}"
    . "try{Date.prototype.getTimezoneOffset=function(){return $tzOff;};}catch(e){}"
    // Intl: wrap constructor de ghi nho timeZone do SITE chi dinh (neu co) -> giu dung nhu that,
    // neu site khong chi dinh thi dung timezone cua kenh. resolvedOptions tra timeZone cua kenh.
    . "try{const __Ctor=Intl.DateTimeFormat;const __rd=__Ctor.prototype.resolvedOptions;"
    . "const __Fake=function(...a){const inst=new __Ctor(...a);const o=(a&&a[1]&&typeof a[1]==='object')?a[1]:null;"
    . "try{Object.defineProperty(inst,'__fpTz',{value:(o&&typeof o.timeZone==='string')?o.timeZone:'',configurable:true});}catch(_){}return inst;};"
    . "__Fake.prototype=__Ctor.prototype;Object.setPrototypeOf(__Fake,__Ctor);Intl.DateTimeFormat=__Fake;"
    . "__Ctor.prototype.resolvedOptions=function(){const r=__rd.call(this);if(!this.__fpTz)r.timeZone='$tzId';return r;};}catch(e){}"
    // Date.toString/toTimeString: bo label timezone THAT (vd Indochina Time that) -> label cua kenh,
    // tranh lai suong khi Intl da doi nhung chuoi toString van lo that.
    . "try{const __ts=Date.prototype.toString,__tts=Date.prototype.toTimeString,__zl='('+'$tzLabel'+')';"
    . "const __fix=function(s){const i=s.lastIndexOf('(');return i>=0?s.slice(0,i)+__zl:s;};"
    . "Date.prototype.toString=function(){return __fix(__ts.call(this));};"
    . "Date.prototype.toTimeString=function(){return __fix(__tts.call(this));};}catch(e){}"
    . "})();";
$src  .= ';' . $poly;
$expr .= ';' . $poly;

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