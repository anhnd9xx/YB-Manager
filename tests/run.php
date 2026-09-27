<?php
declare(strict_types=1);
// Chay toan bo Auto Activity tests: backend PHP + frontend Node.
// Dung: php tests/run.php (exit 0 = tat ca PASS)
$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$details = [];

function run_case($label, $cmd, &$pass, &$fail, &$details) {
    $out = [];
    $code = 0;
    exec('cd ' . escapeshellarg($GLOBALS['root']) . ' && ' . $cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    if (preg_match_all('/^(PASS|FAIL) /m', $text, $m)) {
        foreach ($m[1] as $r) {
            if ($r === 'PASS') $pass++;
            else $fail++;
        }
    } elseif ($code !== 0) {
        $fail++;
        $text .= "\n(exit=$code)";
    } else {
        // khong co dong PASS/FAIL nhung exit 0
        $pass++;
    }
    $details[] = "=== $label ===\n$text";
}

$php = PHP_BINARY;
run_case('backend bulk', $php . ' tests/backend/test_bulk_assign.php', $pass, $fail, $details);
run_case('backend scheduler', $php . ' tests/backend/test_scheduler.php', $pass, $fail, $details);
run_case('frontend', 'node tests/frontend/run.js', $pass, $fail, $details);

echo implode("\n\n", $details) . "\n\n";
echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
