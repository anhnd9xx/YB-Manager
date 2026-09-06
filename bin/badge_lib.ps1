Add-Type -AssemblyName System.Drawing
Add-Type @"
using System;
using System.Runtime.InteropServices;

public static class TaskbarBadge {
    [ComImport, Guid("ea1afb91-9e28-4b86-90e9-9e9f8a5eefaf"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    public interface ITaskbarList3 {
        void HrInit();
        void AddTab(IntPtr hwnd);
        void DeleteTab(IntPtr hwnd);
        void ActivateTab(IntPtr hwnd);
        void SetActiveAlt(IntPtr hwnd);
        void MarkFullscreenWindow(IntPtr hwnd, [MarshalAs(UnmanagedType.Bool)] bool fullscreen);
        void SetProgressValue(IntPtr hwnd, ulong completed, ulong total);
        void SetProgressState(IntPtr hwnd, int state);
        void RegisterTab(IntPtr hwndTab, IntPtr hwndMDI);
        void UnregisterTab(IntPtr hwndTab);
        void SetTabOrder(IntPtr hwndTab, IntPtr hwndInsertBefore);
        void SetTabActive(IntPtr hwndTab, IntPtr hwndMDI, int flags);
        void ThumbBarAddButtons(IntPtr hwnd, uint cButtons, IntPtr pButton);
        void ThumbBarUpdateButtons(IntPtr hwnd, uint cButtons, IntPtr pButton);
        void ThumbBarSetImageList(IntPtr hwnd, IntPtr himl);
        void SetOverlayIcon(IntPtr hwnd, IntPtr hIcon, [MarshalAs(UnmanagedType.LPWStr)] string description);
        void SetThumbnailTooltip(IntPtr hwnd, [MarshalAs(UnmanagedType.LPWStr)] string tip);
        void SetThumbnailClip(IntPtr hwnd, IntPtr prc);
    }

    [DllImport("ole32.dll")]
    static extern int CoCreateInstance(ref Guid rclsid, IntPtr pUnkOuter, uint dwClsContext, ref Guid riid, out IntPtr ppv);
    [DllImport("user32.dll")]
    static extern bool DestroyIcon(IntPtr hIcon);

    static ITaskbarList3 GetTaskbar() {
        Guid cls = new Guid("56FDF344-FD6D-11d0-958A-006097C9A090");
        Guid unk = new Guid("00000000-0000-0000-C000-000000000046");
        IntPtr pp;
        int hr = CoCreateInstance(ref cls, IntPtr.Zero, 1, ref unk, out pp);
        if (hr != 0) throw new Exception("CoCreateInstance hr=0x" + hr.ToString("X8"));
        return (ITaskbarList3)Marshal.GetObjectForIUnknown(pp);
    }

    public static void SetBadge(IntPtr hwnd, IntPtr hIcon, string desc) {
        if (hwnd == IntPtr.Zero) throw new Exception("hwnd=0");
        ITaskbarList3 tb = GetTaskbar();
        tb.HrInit();
        tb.SetOverlayIcon(hwnd, hIcon, desc);
    }

    public static void DestroyIconSafe(IntPtr hIcon) {
        if (hIcon != IntPtr.Zero) DestroyIcon(hIcon);
    }
}
"@

function New-BadgeIcon([string]$text) {
    $bmp  = New-Object System.Drawing.Bitmap 16,16
    $g    = [System.Drawing.Graphics]::FromImage($bmp)
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit

    $rect = New-Object System.Drawing.Rectangle 0,0,16,16
    $brushBg = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 225, 57, 53))
    $penBg   = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(255, 255, 255, 255)), 1

    $ellipse = New-Object System.Drawing.RectangleF 0.5,0.5,15,15
    $g.FillEllipse($brushBg, $ellipse)
    $g.DrawEllipse($penBg, $ellipse)

    $font  = New-Object System.Drawing.Font("Segoe UI", 6.5, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $rectF = New-Object System.Drawing.RectangleF 0,0,16,16
    $g.DrawString($text, $font, [System.Drawing.Brushes]::White, $rectF, $sf)

    $hicon = $bmp.GetHicon()
    $g.Dispose(); $bmp.Dispose()
    $brushBg.Dispose(); $penBg.Dispose(); $font.Dispose(); $sf.Dispose()
    return $hicon
}

function Apply-Badge([IntPtr]$hwnd, [string]$text, [string]$desc) {
    if ($hwnd -eq [IntPtr]::Zero) { return }
    $h = New-BadgeIcon $text
    try { [TaskbarBadge]::SetBadge($hwnd, $h, $desc) }
    finally { [TaskbarBadge]::DestroyIconSafe($h) }
}

function Refresh-All([System.Collections.Hashtable]$cache, [scriptblock]$lastText) {
    $procs = @(Get-Process chrome -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowTitle })
    $n = 0
    foreach ($p in $procs) {
        $cmd = (Get-CimInstance Win32_Process -Filter "ProcessId=$($p.Id)").CommandLine
        if ($cmd -match 'K__nh_([0-9]+)') {
            $idx = $Matches[1]
            $text = "K" + $idx
            $key = $p.Id
            $hwndNow = $p.MainWindowHandle
            $hwndLast = $cache[$key]
            if ($null -ne $lastText) { & $lastText $idx }
            if ($hwndLast -ne $hwndNow) {
                Apply-Badge $hwndNow $text ("Kenh " + $idx)
                $cache[$key] = $hwndNow
                $n++
            }
        }
    }
    return $n
}