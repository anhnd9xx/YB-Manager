<?php
declare(strict_types=1);
// MonitorRegistry unit tests — FAKE registry, khong can monitor that.
// CASE A: 1 monitor · B: 2 · C: 3 · D: saved missing · E: negative coords · F: mixed DPI.
// Chay: php tests/backend/test_monitor_registry.php
require __DIR__ . '/../../sync/MonitorRegistry.php';

$pass = 0;
$fail = 0;
function t($name, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS $name" . ($extra !== '' ? " :: $extra" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FAIL $name" . ($extra !== '' ? " :: $extra" : '') . PHP_EOL;
    }
}
function mon(string $dev, bool $primary, int $x, int $y, int $w, int $h, int $dpi = 96): array {
    return ['device_name' => $dev, 'is_primary' => $primary,
        'work_area' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h],
        'bounds' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h + 40],
        'dpi_x' => $dpi, 'dpi_y' => $dpi];
}

// CASE A: 1 monitor — moi pref deu ve primary, khong fatal
$a1 = [mon('\\\\.\\DISPLAY1', true, 0, 0, 1920, 1040)];
$r = MonitorRegistry::resolve('', 'LAST', '\\\\.\\DISPLAY3', 0, $a1);
t('A_stale_pref_fallback_primary', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY1'
    && $r['method'] === MonitorRegistry::M_PRIMARY_FALLBACK && $r['remapped'] === true, $r['method']);

// CASE B: 2 monitors — exact + stale DISPLAY3 -> primary
$a2 = [mon('\\\\.\\DISPLAY1', true, 0, 0, 1920, 1040),
       mon('\\\\.\\DISPLAY2', false, 1920, 0, 2560, 1400, 120)];
$r = MonitorRegistry::resolve('\\\\.\\DISPLAY2', 'LAST', '', 0, $a2);
t('B_explicit_wins', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY2'
    && $r['method'] === MonitorRegistry::M_EXACT && !$r['remapped'], $r['method']);
$r = MonitorRegistry::resolve('', 'LAST', '\\\\.\\DISPLAY3', 0, $a2);
t('B_missing_pref_primary', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY1'
    && $r['method'] === MonitorRegistry::M_PRIMARY_FALLBACK, $r['method']);
$r = MonitorRegistry::resolve('', 'SECONDARY', '', 0, $a2);
t('B_secondary_mode', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY2'
    && $r['method'] === MonitorRegistry::M_SECONDARY, $r['method']);

// CASE C: 3 monitors — saved DISPLAY2 exact, khong remap
$a3 = [mon('\\\\.\\DISPLAY1', true, 0, 0, 1920, 1040),
       mon('\\\\.\\DISPLAY2', false, 1920, 0, 1920, 1040),
       mon('\\\\.\\DISPLAY3', false, 3840, 0, 1920, 1040)];
$r = MonitorRegistry::resolve('', 'LAST', '\\\\.\\DISPLAY2', 0, $a3);
t('C_saved_exact', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY2'
    && $r['method'] === MonitorRegistry::M_PROFILE && !$r['remapped'], $r['method']);

// CASE D: registry rong -> EMPTY, khong crash
$r = MonitorRegistry::resolve('', 'LAST', '\\\\.\\DISPLAY1', 0, []);
t('D_empty_registry', $r['monitor'] === null && $r['method'] === 'EMPTY_REGISTRY', $r['method']);
$r = MonitorRegistry::resolveSaved('\\\\.\\DISPLAY9', [], null);
t('D_pure_empty', $r['monitor'] === null, $r['method']);

// CASE E: negative coords — monitor trai primary (x<0) match dung, khong clamp
$an = [mon('\\\\.\\DISPLAY2', false, -1920, 0, 1920, 1040),
       mon('\\\\.\\DISPLAY1', true, 0, 0, 1920, 1040)];
$m = MonitorRegistry::matchPoint(-500, 300, $an);
t('E_negative_match', ($m['device_name'] ?? '') === '\\\\.\\DISPLAY2', $m['device_name'] ?? '?');
$m = MonitorRegistry::matchPoint(100, 100, $an);
t('E_primary_match', ($m['device_name'] ?? '') === '\\\\.\\DISPLAY1', $m['device_name'] ?? '?');
$m = MonitorRegistry::matchPoint(-5000, -5000, $an); // ngoai xa -> nearest
t('E_nearest_fallback', $m !== null, $m['device_name'] ?? '?');

// CASE F: mixed DPI — resolve giu DPI rieng tung monitor (khong reuse primary)
$ad = [mon('\\\\.\\DISPLAY1', true, 0, 0, 1920, 1040, 96),
       mon('\\\\.\\DISPLAY2', false, 1920, 0, 2560, 1400, 144)];
$r = MonitorRegistry::resolve('', 'LAST', '\\\\.\\DISPLAY2', 0, $ad);
t('F_dpi_kept', ($r['monitor']['dpi_x'] ?? 0) === 144, 'dpi=' . ($r['monitor']['dpi_x'] ?? '?'));

// resolveSaved: current-window win khi saved stale
$r = MonitorRegistry::resolveSaved('\\\\.\\DISPLAY9', $a3, '\\\\.\\DISPLAY3');
t('saved_current_window', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY3'
    && $r['method'] === MonitorRegistry::M_CURRENT_WINDOW && $r['remapped'], $r['method']);
// single monitor + SECONDARY -> primary fallback (khong null)
$r = MonitorRegistry::resolve('', 'SECONDARY', '', 0, $a1);
t('secondary_single_machines', ($r['monitor']['device_name'] ?? '') === '\\\\.\\DISPLAY1'
    && $r['method'] === MonitorRegistry::M_PRIMARY_FALLBACK, $r['method']);

echo "TOTAL: $pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
