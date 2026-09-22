###############################################################################
# win32_dpi.ps1 - Set process DPI awareness (Per-Monitor V2) cho tien trinh goi.
# Goij tu PHP de Win32 rect/monitor tra physical pixel nhat quan multi-DPI.
# Best-effort: loi thi thoat 1, caller bo qua.
###############################################################################
$ErrorActionPreference = 'SilentlyContinue'
Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class DpiAw2 {
    [DllImport("user32.dll")] public static extern bool SetProcessDpiAwarenessContext(int v);
}
"@
try { [DpiAw2]::SetProcessDpiAwarenessContext(-4) | Out-Null } catch {}
exit 0
