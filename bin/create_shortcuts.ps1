param([string]$Json, [string]$OutDir)
$list = Get-Content -LiteralPath $Json -Raw -Encoding UTF8 | ConvertFrom-Json
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$php = "C:\xampp\php\php.exe"
$chrome = "C:\Program Files\Google\Chrome\Application\chrome.exe"
$script = "C:\xampp\htdocs\yt-manager\bin\open_channel.php"
foreach ($it in $list) {
  $lnk = Join-Path $OutDir ($it.name + ".lnk")
  $ws = New-Object -ComObject WScript.Shell
  $sc = $ws.CreateShortcut($lnk)
  $sc.TargetPath = $php
  $sc.Arguments = "`"-f`" `"$script`" $($it.id)"
  $sc.IconLocation = "$chrome,0"
  $sc.Description = "Mo kenh: $($it.name)"
  $sc.Save()
  [System.Runtime.InteropServices.Marshal]::ReleaseComObject($sc) | Out-Null
  [System.Runtime.InteropServices.Marshal]::ReleaseComObject($ws) | Out-Null
}