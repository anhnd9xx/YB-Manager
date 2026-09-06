# Khoi dong lai overlay keeper: dung keeper cu, xoa lock, ap dung badge ngay, chay keeper moi.
$keeper = Join-Path $PSScriptRoot "overlay_keeper.ps1"
# truoc khi chay: linh nay khong chua chuoi overlay_keeper.ps1 trong dong lenh cua no
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.CommandLine -match 'overlay_keeper\.ps1' -and $_.CommandLine -notmatch 'restart_overlay' } | ForEach-Object {
    Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
}
Start-Sleep -Milliseconds 800
Remove-Item (Join-Path $PSScriptRoot "overlay_keeper.lock") -Force -ErrorAction SilentlyContinue
& powershell -NoProfile -Sta -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "apply_overlays_once.ps1")
Start-Process -FilePath 'powershell' -ArgumentList @('-NoProfile','-Sta','-ExecutionPolicy','Bypass','-File',$keeper) -WindowStyle Hidden
Write-Output "overlay keeper restarted"