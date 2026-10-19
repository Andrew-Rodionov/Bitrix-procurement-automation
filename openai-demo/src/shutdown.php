<?php
declare(strict_types=1);

function shutdownRequestAllowed(string $sapi,string $address,int $port):bool {
    return $sapi==='cli-server'&&in_array($address,['127.0.0.1','::1','::ffff:127.0.0.1'],true)&&$port===8088;
}
function shutdownLaunch(int $serverPid,int $workerPid):void {
    if(PHP_OS_FAMILY!=='Windows'||!function_exists('proc_open'))throw new RuntimeException('Остановка доступна в локальном запуске Windows.');
    $powershell=rtrim(getenv('SystemRoot')?:'C:\\Windows','\\/').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if(!is_file($powershell))throw new RuntimeException('Не найден PowerShell для остановки проекта.');
    $root=dirname(__DIR__,2);
    $process=proc_open([$powershell,'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden',
        '-File',dirname(__DIR__).'/stop.ps1','-Detach','-ServerPid',(string)$serverPid,'-WorkerPid',(string)$workerPid,'-PhpPath',PHP_BINARY],
        [0=>['file','NUL','r'],1=>['file',$root.'/.runtime/shutdown.out.log','a'],2=>['file',$root.'/.runtime/shutdown.err.log','a']],$pipes,$root,null,
        ['bypass_shell'=>true,'create_process_group'=>true]);
    if(!is_resource($process))throw new RuntimeException('Не удалось запустить остановку проекта.');
    if(proc_close($process)!==0)throw new RuntimeException('Не удалось запустить остановку проекта. Подробности в журнале shutdown.err.log.');
}
