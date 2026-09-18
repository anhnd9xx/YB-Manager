###############################################################################
# win32_close.ps1 - Dong window NHẸ NHÀNG qua WM_CLOSE (de Chrome flush profile).
# Dung: powershell -NoProfile -ExecutionPolicy Bypass -File win32_close.ps1 `
#         -Hwnd "123,456"
# Output: 1 dong JSON {ok, results:[{hwnd, ok, error?}]}.
# KHONG kill process (fallback kill do PHP lo). Khong dong trinh duyet ngoai.
###############################################################################
param(
    [Parameter(Mandatory = $true)][string]$Hwnd
)
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$sw = [Diagnostics.Stopwatch]::StartNew()

Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class WC2 {
    [DllImport("user32.dll")] public static extern bool IsWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
}
"@

$WM_CLOSE = 0x0010
$results = @()
foreach ($part in ($Hwnd -split ',')) {
    $id = 0
    if (-not [long]::TryParse($part.Trim(), [ref]$id) -or $id -le 0) { continue }
    $h = [IntPtr]$id
    if (-not [WC2]::IsWindow($h)) {
        $results += @{ hwnd = $id; ok = $false; error = 'HWND khong ton tai' }
        continue
    }
    if ([WC2]::PostMessage($h, $WM_CLOSE, [IntPtr]::Zero, [IntPtr]::Zero)) {
        $results += @{ hwnd = $id; ok = $true; error = $null }
    } else {
        $results += @{ hwnd = $id; ok = $false; error = 'PostMessage that bai' }
    }
}
(@{ ok = $true; ms = $sw.ElapsedMilliseconds; results = @($results) } | ConvertTo-Json -Depth 4 -Compress)
