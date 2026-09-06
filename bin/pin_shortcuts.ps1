param([string]$Json, [string]$OutDir)

Add-Type @"
using System;
using System.Runtime.InteropServices;

public static class LnkUtil {
    [StructLayout(LayoutKind.Sequential)]
    public struct PROPERTYKEY { public Guid fmtid; public uint pid; }
    [StructLayout(LayoutKind.Sequential)]
    public struct PROPVARIANT { public ushort vt; public ushort wR1; public ushort wR2; public ushort wR3; public IntPtr data1; public IntPtr data2; public IntPtr data3; public IntPtr data4; }

    [ComImport, Guid("886d8eeb-8cf2-4446-8d02-cdba1dbdcf99"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    public interface IPropertyStore {
        void GetCount(out uint cProps);
        void GetAt(uint iProp, out PROPERTYKEY pkey);
        void GetValue(ref PROPERTYKEY key, out PROPVARIANT pv);
        void SetValue(ref PROPERTYKEY key, ref PROPVARIANT pv);
        void Commit();
    }

    [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
    static extern int SHGetPropertyStoreFromParsingName(string pszPath, IntPtr hwnd, uint flags, ref Guid riid, out IntPtr ppv);
    [DllImport("ole32.dll")]
    static extern int PropVariantClear(ref PROPVARIANT pvar);

    static PROPERTYKEY Key() {
        PROPERTYKEY k; k.fmtid = new Guid("9F4C2855-9F79-4B39-A8D0-E1D42DE1D5F3"); k.pid = 5; return k;
    }

    public static void SetAppId(string path, string appId) {
        Guid iid = new Guid("886d8eeb-8cf2-4446-8d02-cdba1dbdcf99");
        IntPtr ppv;
        int hr = SHGetPropertyStoreFromParsingName(path, IntPtr.Zero, 2, ref iid, out ppv);
        if (hr != 0) throw new Exception("SHGetPropertyStoreFromParsingName hr=0x" + hr.ToString("X8"));
        IPropertyStore st = (IPropertyStore)Marshal.GetObjectForIUnknown(ppv);
        PROPERTYKEY k = Key();
        PROPVARIANT v = new PROPVARIANT();
        v.vt = 31;
        v.data1 = Marshal.StringToCoTaskMemUni(appId);
        try { st.SetValue(ref k, ref v); st.Commit(); }
        finally { PropVariantClear(ref v); Marshal.Release(ppv); }
    }

    public static string GetAppId(string path) {
        Guid iid = new Guid("886d8eeb-8cf2-4446-8d02-cdba1dbdcf99");
        IntPtr ppv;
        int hr = SHGetPropertyStoreFromParsingName(path, IntPtr.Zero, 2, ref iid, out ppv);
        if (hr != 0) return "ERR 0x" + hr.ToString("X8");
        IPropertyStore st = (IPropertyStore)Marshal.GetObjectForIUnknown(ppv);
        PROPERTYKEY k = Key();
        PROPVARIANT v = new PROPVARIANT();
        string r;
        try {
            st.GetValue(ref k, out v);
            r = (v.vt == 31) ? Marshal.PtrToStringUni(v.data1) : ("vt=" + v.vt);
        } finally { PropVariantClear(ref v); Marshal.Release(ppv); }
        return r;
    }
}
"@

$pinDir = Join-Path $env:APPDATA "Microsoft\Internet Explorer\Quick Launch\User Pinned\TaskBar"
$list = Get-Content -LiteralPath $Json -Raw -Encoding UTF8 | ConvertFrom-Json
foreach ($it in $list) {
  $name = $it.name + ".lnk"
  $src = Join-Path $OutDir $name
  $dst = Join-Path $pinDir $name
  Copy-Item -LiteralPath $src -Destination $dst -Force
  Start-Sleep -Milliseconds 1000
  $setOk = $false
  for ($i = 0; $i -lt 25; $i++) {
    try { [LnkUtil]::SetAppId($dst, $it.aumi) | Out-Null; $setOk = $true; break }
    catch { Start-Sleep -Milliseconds 400 }
  }
  $v = "FAIL"
  for ($i = 0; $i -lt 25; $i++) {
    try { $v = [LnkUtil]::GetAppId($dst); if ($v -ne "ERR 0x80070020") { break } } catch { }
    Start-Sleep -Milliseconds 400
  }
  $tag = if ($setOk) { "SET-OK" } else { "SET-RETRIED" }
  Write-Output ("{0,-12} PIN_AUMI={1} ({2})" -f $it.name, $v, $tag)
}
Write-Output ("PIN_DIR=" + $pinDir)