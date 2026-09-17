###############################################################################
# win32_control.ps1 - PHASE 1: dieu khien 1 window theo HWND.
# Dung: powershell -NoProfile -ExecutionPolicy Bypass -File win32_control.ps1 `
#         -Hwnd <so> -Action <move|resize|moveresize|front|minimize|restore|show|hide> `
#         [-PosX <x>] [-PosY <y>] [-Width <w>] [-Height <h>]
# LUU Y: ten param dai tuong minh (PosX/Width/Height) de PowerShell khong bind
# nham prefix (VD -H bi nhan thanh -Hwnd).
# Tra ve 1 dong JSON {ok, error?, rect?}. Validate IsWindow truoc moi lenh.
# KHONG co chuc nang dong/kill process (View Window chi restore/hien).
###############################################################################
param(
    [Parameter(Mandatory = $true)][long]$Hwnd,
    [Parameter(Mandatory = $true)][string]$Action,
    [int]$PosX = 0, [int]$PosY = 0, [int]$Width = 0, [int]$Height = 0
)
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8

Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class WC {
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left; public int Top; public int Right; public int Bottom; }
    [DllImport("user32.dll")] public static extern bool IsWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsIconic(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsZoomed(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")] public static extern bool SetWindowPos(IntPtr hWnd, IntPtr hWndInsertAfter, int X, int Y, int cx, int cy, uint uFlags);
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
}
"@

function Out-Result($ok, $err) {
    $r = @{ x = 0; y = 0; w = 0; h = 0 }
    $rc = New-Object WC+RECT
    if ([WC]::GetWindowRect($hWndPtr, [ref]$rc)) {
        $r = @{ x = $rc.Left; y = $rc.Top; w = ($rc.Right - $rc.Left); h = ($rc.Bottom - $rc.Top) }
    }
    (@{ ok = $ok; error = $err; rect = $r } | ConvertTo-Json -Compress)
    exit
}

$hWndPtr = [IntPtr]$Hwnd
if (-not [WC]::IsWindow($hWndPtr)) { Out-Result $false "HWND $Hwnd khong ton tai (cua so da dong?)" }

# SWP flags: giu Z-order + khong kich hoat (tranh cuop focus khi move/resize ngam)
$SWP_NOZORDER = 0x0004; $SWP_NOACTIVATE = 0x0010
# ShowWindow cmds
$SW_RESTORE = 9; $SW_MINIMIZE = 6; $SW_SHOW = 5; $SW_HIDE = 0

# Cua so MAXIMIZED bo qua SetWindowPos (Windows giu nguyen maximized) -> restore
# truoc de resize/move co hieu luc. Chrome hay mo maximized theo state lan truoc.
function Ensure-Restored {
    if ([WC]::IsIconic($hWndPtr) -or [WC]::IsZoomed($hWndPtr)) {
        [WC]::ShowWindow($hWndPtr, $SW_RESTORE) | Out-Null
        Start-Sleep -Milliseconds 150
    }
}

switch ($Action.ToLower()) {
    'move' {
        Ensure-Restored
        if (-not [WC]::SetWindowPos($hWndPtr, [IntPtr]::Zero, $PosX, $PosY, 0, 0, ($SWP_NOZORDER -bor $SWP_NOACTIVATE -bor 0x0001))) {
            Out-Result $false "SetWindowPos(move) that bai"
        }
        Out-Result $true $null
    }
    'resize' {
        if ($Width -le 0 -or $Height -le 0) { Out-Result $false "resize can Width,Height > 0" }
        # Neu dang minimized/maximized thi restore truoc, khong resize se vo hieu
        Ensure-Restored
        if (-not [WC]::SetWindowPos($hWndPtr, [IntPtr]::Zero, 0, 0, $Width, $Height, ($SWP_NOZORDER -bor $SWP_NOACTIVATE -bor 0x0002))) {
            Out-Result $false "SetWindowPos(resize) that bai"
        }
        Out-Result $true $null
    }
    'moveresize' {
        if ($Width -le 0 -or $Height -le 0) { Out-Result $false "moveresize can Width,Height > 0" }
        Ensure-Restored
        if (-not [WC]::SetWindowPos($hWndPtr, [IntPtr]::Zero, $PosX, $PosY, $Width, $Height, ($SWP_NOZORDER -bor $SWP_NOACTIVATE))) {
            Out-Result $false "SetWindowPos(moveresize) that bai"
        }
        Out-Result $true $null
    }
    'front' {
        if ([WC]::IsIconic($hWndPtr)) { [WC]::ShowWindow($hWndPtr, $SW_RESTORE) | Out-Null }
        # SetForegroundWindow co the bi tu choi neu process goi khong co focus;
        # van tra ok neu cua so visible (caller tu quyet dinh thu lai).
        [WC]::SetForegroundWindow($hWndPtr) | Out-Null
        if (-not [WC]::IsWindowVisible($hWndPtr)) { Out-Result $false "cua so khong visible sau front" }
        Out-Result $true $null
    }
    'minimize' {
        if (-not [WC]::ShowWindow($hWndPtr, $SW_MINIMIZE)) { Out-Result $false "minimize that bai" }
        Out-Result $true $null
    }
    'restore' {
        if (-not [WC]::ShowWindow($hWndPtr, $SW_RESTORE)) { Out-Result $false "restore that bai" }
        Out-Result $true $null
    }
    'show' {
        if (-not [WC]::ShowWindow($hWndPtr, $SW_SHOW)) { Out-Result $false "show that bai" }
        Out-Result $true $null
    }
    'hide' {
        if (-not [WC]::ShowWindow($hWndPtr, $SW_HIDE)) { Out-Result $false "hide that bai" }
        Out-Result $true $null
    }
    default { Out-Result $false "Action khong ho tro: $Action" }
}
