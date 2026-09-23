###############################################################################
# dpapi.ps1 - Windows DPAPI LocalMachine protect/unprotect cho Bot Token.
# Dung: powershell -File dpapi.ps1 -Mode protect|unprotect -InFile <path> -OutFile <path>
#   protect:   Doc RAW string (UTF8, trim) tu InFile -> base64 ciphertext vao OutFile
#   unprotect: Doc base64 tu InFile -> RAW string (UTF8) vao OutFile
# LocalMachine scope: Apache + CLI khac user van giai ma duoc tren cung may.
# Key khong luu DB/code (§22). Khong in secret ra stdout.
###############################################################################
param(
    [Parameter(Mandatory = $true)][string]$Mode,
    [Parameter(Mandatory = $true)][string]$InFile,
    [Parameter(Mandatory = $true)][string]$OutFile
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security
$scope = [Security.Cryptography.DataProtectionScope]::LocalMachine
# Ghi KHONG BOM (UTF8Encoding $false) de PHP so sanh byte chinh xac
$utf8 = New-Object Text.UTF8Encoding $false
try {
    if ($Mode -eq 'protect') {
        $raw = [IO.File]::ReadAllText($InFile, [Text.Encoding]::UTF8).Trim()
        if ($raw -eq '') { exit 2 }
        $bytes = [Text.Encoding]::UTF8.GetBytes($raw)
        $enc = [Security.Cryptography.ProtectedData]::Protect($bytes, $null, $scope)
        [IO.File]::WriteAllText($OutFile, [Convert]::ToBase64String($enc), $utf8)
    } elseif ($Mode -eq 'unprotect') {
        $c = [IO.File]::ReadAllText($InFile).Trim()
        if ($c -eq '') { exit 2 }
        $bytes = [Convert]::FromBase64String($c)
        $dec = [Security.Cryptography.ProtectedData]::Unprotect($bytes, $null, $scope)
        [IO.File]::WriteAllText($OutFile, [Text.Encoding]::UTF8.GetString($dec), $utf8)
    } else {
        exit 2
    }
    exit 0
} catch {
    exit 1
}
