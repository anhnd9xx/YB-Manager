$lib = Join-Path $PSScriptRoot "badge_lib.ps1"
. $lib
$cache = @{}
Refresh-All $cache $null