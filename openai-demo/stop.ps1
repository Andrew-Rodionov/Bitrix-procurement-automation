param(
    [switch]$Detach,
    [Parameter(Mandatory=$true)][ValidateRange(1,2147483647)][int]$ServerPid,
    [ValidateRange(0,2147483647)][int]$WorkerPid=0,
    [Parameter(Mandatory=$true)][string]$PhpPath
)
$ErrorActionPreference='Stop'
$taskRoot=Split-Path $PSScriptRoot -Parent
if($Detach){
    # Start a separate helper so PHP can return its response before being stopped.
    $taskArguments=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',('"'+$PSCommandPath+'"'),'-ServerPid',[string]$ServerPid,'-WorkerPid',[string]$WorkerPid,'-PhpPath',('"'+$PhpPath+'"'))
    Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -ArgumentList $taskArguments -WindowStyle Hidden
    exit 0
}
$resultPath=Join-Path $taskRoot '.runtime/shutdown-result.json'
function Get-ProjectPhp([int]$TaskId){
    if($TaskId -le 0){return $null}
    try{$taskProcess=[Diagnostics.Process]::GetProcessById($TaskId)}
    catch [ArgumentException]{return $null}
    $expected=[IO.Path]::GetFullPath($PhpPath)
    if(![string]::Equals($taskProcess.MainModule.FileName,$expected,[StringComparison]::OrdinalIgnoreCase)){
        throw 'The process is not the PHP executable used by this site.'
    }
    return $taskProcess
}
function Stop-ProjectPhp($TaskProcess,$StartedAt){
    if(!$TaskProcess){return}
    $TaskProcess.Refresh()
    if(!$TaskProcess.HasExited -and $TaskProcess.StartTime.ToUniversalTime().Ticks -eq $StartedAt){
        $TaskProcess.Kill()
        if(!$TaskProcess.WaitForExit(5000)){throw 'The process did not stop.'}
    }
}
try{
    $taskServer=Get-ProjectPhp $ServerPid
    if(!$taskServer){throw 'The local server has already stopped.'}
    $taskServerStart=$taskServer.StartTime.ToUniversalTime().Ticks
    $taskWorker=if($WorkerPid -ne $ServerPid){Get-ProjectPhp $WorkerPid}else{$null}
    $taskWorkerStart=if($taskWorker){$taskWorker.StartTime.ToUniversalTime().Ticks}else{0}
    # Allow the HTTP response to reach the browser before closing the listener.
    Start-Sleep -Milliseconds 1500
    Stop-ProjectPhp $taskWorker $taskWorkerStart
    Stop-ProjectPhp $taskServer $taskServerStart
    @{status='stopped';port=8088;server_pid=$ServerPid;worker_pid=$WorkerPid;finished_at=[DateTime]::UtcNow.ToString('o')} |
        ConvertTo-Json -Compress | Set-Content -LiteralPath $resultPath -Encoding UTF8
}catch{
    @{status='error';message=$_.Exception.Message;server_pid=$ServerPid;worker_pid=$WorkerPid} |
        ConvertTo-Json -Compress | Set-Content -LiteralPath $resultPath -Encoding UTF8
    exit 1
}
