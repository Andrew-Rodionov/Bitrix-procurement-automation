$ErrorActionPreference='Stop'
$taskRoot=Split-Path $PSScriptRoot -Parent
$taskPhp=Join-Path $taskRoot '.runtime/php/php.exe'
if(!(Test-Path $taskPhp)){$taskPhp=(Get-Command php -ErrorAction Stop).Source}
$taskWorker=$null
$taskWorkerState=& $taskPhp (Join-Path $PSScriptRoot 'worker.php') --status
if($taskWorkerState -ne 'running'){
    $taskWorker=Start-Process -FilePath $taskPhp -ArgumentList @(('"'+(Join-Path $PSScriptRoot 'worker.php')+'"'),'--loop') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $taskRoot '.runtime/worker.out.log') -RedirectStandardError (Join-Path $taskRoot '.runtime/worker.err.log') -PassThru
    $taskWorker.Id | Set-Content (Join-Path $taskRoot '.runtime/worker.pid')
}
try{& $taskPhp -S 127.0.0.1:8088 -t (Join-Path $PSScriptRoot 'public') (Join-Path $PSScriptRoot 'router.php')}
finally{if($taskWorker -and !$taskWorker.HasExited){Stop-Process -Id $taskWorker.Id -ErrorAction SilentlyContinue}}
