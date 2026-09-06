$lib = Join-Path $PSScriptRoot "badge_lib.ps1"
. $lib

# Chỉ duy nhất 1 keeper chạy: giữ file-lock toàn bộ vòng đời; keeper thứ hai thoát ngay.
$lockPath = Join-Path $PSScriptRoot "overlay_keeper.lock"
try {
    $fs = New-Object System.IO.FileStream($lockPath, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
} catch {
    exit 0
}

$script:cache = @{}
while ($true) {
    try {
        $null = Refresh-All $script:cache $null
    } catch { }
    Start-Sleep -Milliseconds 2500
}