<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/src/config.php';
require __DIR__.'/src/procurement.php';
ini_set('display_errors','0');set_time_limit(0);
if(in_array('--status',$argv,true)){echo procurementWorkerRunning()?'running':'stopped';exit;}
$workerLock=fopen(procurementRoot().'/worker.lock','c');
if(!$workerLock||!flock($workerLock,LOCK_EX|LOCK_NB))exit;
$loop=in_array('--loop',$argv,true);
do{
    procurementWrite(procurementRoot().'/worker.json',['tick'=>time(),'pid'=>getmypid()]);
    foreach(glob(procurementRoot().'/*/auth.json') as $authPath){
        try{
            $auth=procurementUnseal(procurementRead($authPath));$c=mailConfig($auth);
            // An account path can never redirect processing to another account.
            if(dirname($authPath)!==procurementPath($c))continue;
            $state=procurementRead(dirname($authPath).'/state.json',['campaigns'=>[]]);
            $active=array_filter($state['campaigns'],static fn($campaign)=>$campaign['status']!=='draft');
            if(!$active)continue;
            if(($state['next_check']??0)<=time())procurementScan($c);
            if(is_file($authPath))procurementProcess($c);
        }catch(Throwable $e){
            // Credentials, letter text and raw provider messages are never written to the worker log.
            error_log('Procurement worker: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
        }
    }
    if($loop)sleep(15);
}while($loop);
