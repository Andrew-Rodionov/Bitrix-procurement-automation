$ErrorActionPreference='Stop'
$taskRoot=Split-Path $PSScriptRoot -Parent
$taskPhp=Join-Path $taskRoot '.runtime/php/php.exe'
if(!(Test-Path $taskPhp)){$taskPhp=(Get-Command php -ErrorAction Stop).Source}
& $taskPhp -S 127.0.0.1:8088 -t (Join-Path $PSScriptRoot 'public') (Join-Path $PSScriptRoot 'router.php')
