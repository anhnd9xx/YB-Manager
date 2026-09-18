###############################################################################
# win32_apply_layout.ps1 - Apply NHIEU window rect trong 1 batch (B5-B6).
# Dung: powershell -NoProfile -ExecutionPolicy Bypass -File win32_apply_layout.ps1 `
#         -InputFile <json_file>
# Input JSON: [{hwnd, x, y, w, h}, ...]
# Output: 1 dong JSON {ok, ms, results:[{hwnd, ok, error?, rect?}]}.
# Cach lam: restore (minimized/maximized) truoc, roi 1 luot DeferWindowPos duy nhat
# (BeginDeferWindowPos/DeferWindowPos/EndDeferWindowPos) voi SWP_NOACTIVATE
# (khong cuop focus) + SWP_NOZORDER. Loi 1 window khong lan (ghi nhan tung cai).
# Neu batch hong giua chung -> fallback SetWindowPos tung cai (van trong 1 process).
# KHONG co chuc nang dong/kill process.
###############################################################################
param(
    [Parameter(Mandatory = $true)][string]$InputFile
)
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$sw = [Diagnostics.Stopwatch]::StartNew()

Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class DL {
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left; public int Top; public int Right; public int Bottom; }
    [DllImport("user32.dll")] public static extern bool IsWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsIconic(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsZoomed(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")] public static extern IntPtr BeginDeferWindowPos(int nNumWindows);
    [DllImport("user32.dll")] public static extern IntPtr DeferWindowPos(IntPtr hWinPosInfo, IntPtr hWnd, IntPtr hWndInsertAfter, int x, int y, int cx, int cy, uint uFlags);
    [DllImport("user32.dll")] public static extern bool EndDeferWindowPos(IntPtr hWinPosInfo);
    [DllImport("user32.dll")] public static extern bool SetWindowPos(IntPtr hWnd, IntPtr hWndInsertAfter, int X, int Y, int cx, int cy, uint uFlags);
}
"@

function Get-Rect($h) {
    $rc = New-Object DL+RECT
    if ([DL]::GetWindowRect($h, [ref]$rc)) {
        return @{ x = $rc.Left; y = $rc.Top; w = ($rc.Right - $rc.Left); h = ($rc.Bottom - $rc.Top) }
    }
    return $null
}

$items = @()
try {
    $raw = [IO.File]::ReadAllText($InputFile)
    # LUU Y PS5.1: ConvertFrom-Json tra collection KHONG enumerate qua pipeline/@();
    # gan bien truoc roi moi foreach (foreach tu enumerate dung).
    $parsed = ConvertFrom-Json -InputObject $raw
    if ($parsed -is [array]) { $items = $parsed }
    elseif ($null -ne $parsed) { $items = @($parsed) }
} catch {
    (@{ ok = $false; error = 'Khong doc duoc input JSON'; ms = $sw.ElapsedMilliseconds; results = @() } | ConvertTo-Json -Depth 4 -Compress)
    exit
}

$SW_RESTORE = 9
$FLAGS = 0x0004 -bor 0x0010  # SWP_NOZORDER + SWP_NOACTIVATE (khong kich hoat, khong cuop focus)
$valid = @()
$results = @()

foreach ($it in $items) {
    $hwnd = [long]($it.hwnd)
    $h = [IntPtr]$hwnd
    if (-not [DL]::IsWindow($h)) {
        $results += @{ hwnd = $hwnd; ok = $false; error = 'HWND khong ton tai (cua so da dong?)'; rect = $null }
        continue
    }
    $w = [int]($it.w)
    $hh = [int]($it.h)
    if ($w -le 0 -or $hh -le 0) {
        $results += @{ hwnd = $hwnd; ok = $false; error = 'Kich thuoc phai > 0'; rect = (Get-Rect $h) }
        continue
    }
    # Maximized/minimized bo qua SetWindowPos -> restore truoc (giong win32_control)
    if ([DL]::IsIconic($h) -or [DL]::IsZoomed($h)) {
        [DL]::ShowWindow($h, $SW_RESTORE) | Out-Null
    }
    $valid += @{ hwnd = $hwnd; h = $h; x = [int]($it.x); y = [int]($it.y); w = $w; hgt = $hh }
}
# Cho restore xong truoc khi batch (1 lan duy nhat, khong sleep tung window)
if ($valid.Count -gt 0) { Start-Sleep -Milliseconds 120 }

$batched = @{}
if ($valid.Count -gt 0) {
    $hdwp = [DL]::BeginDeferWindowPos($valid.Count)
    $batchOk = ($hdwp -ne [IntPtr]::Zero)
    if ($batchOk) {
        foreach ($v in $valid) {
            $hdwp = [DL]::DeferWindowPos($hdwp, $v.h, [IntPtr]::Zero, $v.x, $v.y, $v.w, $v.hgt, $FLAGS)
            if ($hdwp -eq [IntPtr]::Zero) { $batchOk = $false; break }
        }
    }
    if ($batchOk -and $hdwp -ne [IntPtr]::Zero) {
        $done = [DL]::EndDeferWindowPos($hdwp)
        if ($done) {
            foreach ($v in $valid) { $batched[[string]$v.hwnd] = $true }
        } else {
            $batchOk = $false
        }
    } else {
        $batchOk = $false
    }
    # Fallback: SetWindowPos tung cai chua xong (van trong 1 process)
    if (-not $batchOk) {
        if ($hdwp -ne $null -and $hdwp -ne [IntPtr]::Zero) {
            try { [DL]::EndDeferWindowPos($hdwp) | Out-Null } catch {}
        }
        foreach ($v in $valid) {
            if ($batched.ContainsKey([string]$v.hwnd)) { continue }
            $ok1 = [DL]::SetWindowPos($v.h, [IntPtr]::Zero, $v.x, $v.y, $v.w, $v.hgt, $FLAGS)
            if ($ok1) { $batched[[string]$v.hwnd] = $true }
            else {
                $results += @{ hwnd = $v.hwnd; ok = $false; error = 'SetWindowPos that bai'; rect = (Get-Rect $v.h) }
            }
        }
    }
}
foreach ($v in $valid) {
    if ($batched.ContainsKey([string]$v.hwnd)) {
        $results += @{ hwnd = $v.hwnd; ok = $true; error = $null; rect = (Get-Rect $v.h) }
    } elseif (-not ($results | Where-Object { $_.hwnd -eq $v.hwnd })) {
        $results += @{ hwnd = $v.hwnd; ok = $false; error = 'DeferWindowPos that bai'; rect = (Get-Rect $v.h) }
    }
}

(@{ ok = $true; ms = $sw.ElapsedMilliseconds; results = @($results) } | ConvertTo-Json -Depth 5 -Compress)
