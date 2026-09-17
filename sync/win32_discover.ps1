###############################################################################
# win32_discover.ps1 - PHASE 1: Window Discovery cho module Synchronize.
# Liet ke process chrome.exe (PID + user-data-dir) va moi top-level window
# thuoc cac process do: HWND, title, class, rect, clientRect, DPI, monitor.
# Output: 1 dong JSON (Compress) ra stdout. Khong can quyen admin.
# Chay: powershell -NoProfile -ExecutionPolicy Bypass -File win32_discover.ps1
###############################################################################
$ErrorActionPreference = 'SilentlyContinue'
# Ep stdout UTF-8 (tieu de window co tieng Viet) de PHP json_decode duoc
[Console]::OutputEncoding = [Text.Encoding]::UTF8

Add-Type @"
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;

public static class W32 {
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left; public int Top; public int Right; public int Bottom; }

    [StructLayout(LayoutKind.Sequential)]
    public struct POINT { public int X; public int Y; }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct MONITORINFOEX {
        public int cbSize;
        public RECT rcMonitor;
        public RECT rcWork;
        public uint dwFlags;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 32)]
        public string szDevice;
    }

    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    public delegate bool MonitorEnumProc(IntPtr hMonitor, IntPtr hdc, ref RECT lprc, IntPtr lParam);

    // Danh sach thu thap qua callback (static de PowerShell doc duoc sau Enum)
    public static List<IntPtr> Windows = new List<IntPtr>();
    public static List<IntPtr> Monitors = new List<IntPtr>();

    private static bool EnumWinCb(IntPtr hWnd, IntPtr lParam) { Windows.Add(hWnd); return true; }
    private static bool EnumMonCb(IntPtr hM, IntPtr hdc, ref RECT r, IntPtr l) { Monitors.Add(hM); return true; }
    public static EnumWindowsProc WinCb = new EnumWindowsProc(EnumWinCb);
    public static MonitorEnumProc MonCb = new MonitorEnumProc(EnumMonCb);

    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")] public static extern bool IsWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool IsIconic(IntPtr hWnd);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetWindowTextW(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetClassNameW(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")] public static extern bool GetClientRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")] public static extern bool ClientToScreen(IntPtr hWnd, ref POINT lpPoint);
    [DllImport("user32.dll")] public static extern uint GetDpiForWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern IntPtr MonitorFromWindow(IntPtr hWnd, uint dwFlags);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern bool GetMonitorInfoW(IntPtr hMonitor, ref MONITORINFOEX lpmi);
    [DllImport("user32.dll")] public static extern bool EnumDisplayMonitors(IntPtr hdc, IntPtr lprcClip, MonitorEnumProc lpfnEnum, IntPtr dwData);
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    // Shcore.dll (Win 8.1+): DPI that su cua monitor
    [DllImport("Shcore.dll")] public static extern int GetDpiForMonitor(IntPtr hMonitor, int dpiType, out uint dpiX, out uint dpiY);
}
"@

function Get-Monitors {
    [W32]::Monitors.Clear()
    [W32]::EnumDisplayMonitors([IntPtr]::Zero, [IntPtr]::Zero, [W32]::MonCb, [IntPtr]::Zero) | Out-Null
    $list = @()
    $i = 0
    foreach ($hM in [W32]::Monitors) {
        $i++
        $mi = New-Object W32+MONITORINFOEX
        $mi.cbSize = [Runtime.InteropServices.Marshal]::SizeOf($mi)
        $ok = [W32]::GetMonitorInfoW($hM, [ref]$mi)
        $dpiX = 96; $dpiY = 96
        try {
            $x = 0; $y = 0
            if ([W32]::GetDpiForMonitor($hM, 0, [ref]$x, [ref]$y) -eq 0) { $dpiX = $x; $dpiY = $y }
        } catch {}
        $list += [ordered]@{
            id = $i
            handle = $hM.ToInt64()
            name = if ($ok) { $mi.szDevice } else { "DISPLAY$i" }
            resolution = if ($ok) { @{ w = ($mi.rcMonitor.Right - $mi.rcMonitor.Left); h = ($mi.rcMonitor.Bottom - $mi.rcMonitor.Top) } } else { @{ w = 0; h = 0 } }
            workArea = if ($ok) { @{ x = $mi.rcWork.Left; y = $mi.rcWork.Top; w = ($mi.rcWork.Right - $mi.rcWork.Left); h = ($mi.rcWork.Bottom - $mi.rcWork.Top) } } else { @{ x = 0; y = 0; w = 0; h = 0 } }
            dpi = @{ x = $dpiX; y = $dpiY }
            primary = if ($ok) { ($mi.dwFlags -band 1) -eq 1 } else { $false }
        }
    }
    return $list
}

$monitors = Get-Monitors
# Map handle -> monitor id de gan window vao monitor
$monMap = @{}
foreach ($m in $monitors) { $monMap[[string]$m.handle] = $m.id }

# 1) Process chrome.exe + user-data-dir (1 luot CIM duy nhat)
$procs = @()
foreach ($pr in (Get-CimInstance Win32_Process -Filter "Name='chrome.exe'")) {
    $cmd = [string]$pr.CommandLine
    if ($cmd -notmatch '--user-data-dir=') { continue }
    $dir = $null
    # Chrome co 2 dang quote: "--user-data-dir=C:\...\" (bao ca --) va --user-data-dir="C:\..." (chi value)
    if ($cmd -match '"--user-data-dir=([^"]+)"') { $dir = $Matches[1] }
    elseif ($cmd -match "--user-data-dir=`"([^`"]+)`"") { $dir = $Matches[1] }
    elseif ($cmd -match '--user-data-dir=([^\s]+)') { $dir = $Matches[1] }
    $isMain = $cmd -notmatch '--type='
    $procs += [ordered]@{ pid = [int]$pr.ProcessId; userDataDir = $dir; isMain = $isMain }
}

# 2) Enum top-level windows, gan vao process chrome
[W32]::Windows.Clear()
[W32]::EnumWindows([W32]::WinCb, [IntPtr]::Zero) | Out-Null
$byPid = @{}
foreach ($h in [W32]::Windows) {
    $pidOut = 0
    [W32]::GetWindowThreadProcessId($h, [ref]$pidOut) | Out-Null
    if ($pidOut -le 0) { continue }
    $k = [string]$pidOut
    if (-not $byPid.ContainsKey($k)) { $byPid[$k] = @() }
    $byPid[$k] += $h
}

$chromePids = @{}
foreach ($p in $procs) { $chromePids[[string]$p.pid] = $true }

$wins = @()
foreach ($pk in $byPid.Keys) {
    if (-not $chromePids.ContainsKey($pk)) { continue }
    foreach ($h in $byPid[$pk]) {
        if (-not [W32]::IsWindow($h)) { continue }
        $visible = [W32]::IsWindowVisible($h)
        $sb = New-Object Text.StringBuilder 512
        [W32]::GetWindowTextW($h, $sb, 512) | Out-Null
        $cb = New-Object Text.StringBuilder 256
        [W32]::GetClassNameW($h, $cb, 256) | Out-Null
        $rc = New-Object W32+RECT
        $hasRect = [W32]::GetWindowRect($h, [ref]$rc)
        $cr = New-Object W32+RECT
        $hasClient = [W32]::GetClientRect($h, [ref]$cr)
        $clientOrigin = @{ x = 0; y = 0 }
        if ($hasClient) {
            $pt = New-Object W32+POINT
            $pt.X = $cr.Left; $pt.Y = $cr.Top
            if ([W32]::ClientToScreen($h, [ref]$pt)) { $clientOrigin = @{ x = $pt.X; y = $pt.Y } }
        }
        $dpi = 96
        try { $d = [W32]::GetDpiForWindow($h); if ($d -gt 0) { $dpi = [int]$d } } catch {}
        $monId = $null
        try {
            $hM = [W32]::MonitorFromWindow($h, 2)
            $mk = [string]$hM.ToInt64()
            if ($monMap.ContainsKey($mk)) { $monId = $monMap[$mk] }
        } catch {}
        $w = if ($hasRect) { ($rc.Right - $rc.Left) } else { 0 }
        $hh = if ($hasRect) { ($rc.Bottom - $rc.Top) } else { 0 }
        $wins += [ordered]@{
            hwnd = $h.ToInt64()
            pid = [int]$pk
            title = $sb.ToString()
            class = $cb.ToString()
            visible = [bool]$visible
            minimized = [bool][W32]::IsIconic($h)
            rect = if ($hasRect) { @{ x = $rc.Left; y = $rc.Top; w = $w; h = $hh } } else { $null }
            clientRect = if ($hasClient) { @{ x = $clientOrigin.x; y = $clientOrigin.y; w = ($cr.Right - $cr.Left); h = ($cr.Bottom - $cr.Top) } } else { $null }
            dpi = $dpi
            dpiScale = [math]::Round($dpi / 96, 4)
            monitorId = $monId
            area = ($w * $hh)
        }
    }
}

$result = [ordered]@{ processes = $procs; windows = $wins; monitors = $monitors }
try { $result['foregroundHwnd'] = [W32]::GetForegroundWindow().ToInt64() } catch { $result['foregroundHwnd'] = 0 }
$result | ConvertTo-Json -Depth 6 -Compress
