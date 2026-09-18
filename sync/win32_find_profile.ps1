###############################################################################
# win32_find_profile.ps1 - Tim MAIN window cua 1 Chrome profile (GON, NHANH).
# Dung: powershell -NoProfile -ExecutionPolicy Bypass -File win32_find_profile.ps1 `
#         -UserDataDir "C:\...\K__nh_1"
# Output: 1 dong JSON {ok, hwnd, pid} hoac {ok:false}. Chi 1 luot EnumWindows
# + 1 luot CIM (khong lay monitor/DPI/clientRect nhu discovery day du).
# Match: PID co cmdline chua user-data-dir + top-level visible +
#        class Chrome_WidgetWin_1, lay cua so dien tich lon nhat.
###############################################################################
param(
    [Parameter(Mandatory = $true)][string]$UserDataDir
)
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8

Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class FF {
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left; public int Top; public int Right; public int Bottom; }
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    public static System.Collections.Generic.List<IntPtr> Windows = new System.Collections.Generic.List<IntPtr>();
    private static bool EnumCb(IntPtr hWnd, IntPtr lParam) { Windows.Add(hWnd); return true; }
    public static EnumWindowsProc Cb = new EnumWindowsProc(EnumCb);
    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetClassNameW(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
}
"@

function Out-Res($hwnd, $procId) {
    (@{ ok = ($hwnd -gt 0); hwnd = [long]$hwnd; pid = [int]$procId } | ConvertTo-Json -Compress)
    exit
}

$like = '*' + $UserDataDir + '*'
$pids = @{}
foreach ($pr in (Get-CimInstance Win32_Process -Filter "Name='chrome.exe'")) {
    if ([string]$pr.CommandLine -like $like) { $pids[[string]$pr.ProcessId] = $true }
}
if ($pids.Count -eq 0) { Out-Res 0 0 }

[FF]::Windows.Clear()
[FF]::EnumWindows([FF]::Cb, [IntPtr]::Zero) | Out-Null
$bestHwnd = 0; $bestPid = 0; $bestArea = 0
foreach ($h in [FF]::Windows) {
    $pidOut = 0
    [FF]::GetWindowThreadProcessId($h, [ref]$pidOut) | Out-Null
    if (-not $pids.ContainsKey([string]$pidOut)) { continue }
    if (-not [FF]::IsWindowVisible($h)) { continue }
    $cb = New-Object Text.StringBuilder 256
    [FF]::GetClassNameW($h, $cb, 256) | Out-Null
    if ($cb.ToString() -ne 'Chrome_WidgetWin_1') { continue }
    $rc = New-Object FF+RECT
    if (-not [FF]::GetWindowRect($h, [ref]$rc)) { continue }
    $area = ($rc.Right - $rc.Left) * ($rc.Bottom - $rc.Top)
    if ($area -gt $bestArea) { $bestArea = $area; $bestHwnd = $h.ToInt64(); $bestPid = $pidOut }
}
Out-Res $bestHwnd $bestPid
