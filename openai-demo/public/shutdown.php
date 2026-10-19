<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/response.php';
try{
    require dirname(__DIR__).'/src/config.php';
    require dirname(__DIR__).'/src/procurement-store.php';
    require dirname(__DIR__).'/src/shutdown.php';
    if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Нужен POST-запрос.'],405);
    if(!shutdownRequestAllowed(PHP_SAPI,$_SERVER['REMOTE_ADDR']??'',(int)($_SERVER['SERVER_PORT']??0)))
        reply(['error'=>'Завершение доступно только для локального проекта на порту 8088.'],403);
    sessionInit();
    if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))reply(['error'=>'Обновите страницу.'],403);
    $worker=procurementWorkerRunning()?procurementRead(procurementRoot().'/worker.json'):[];
    $workerPid=(int)($worker['pid']??0);
    session_write_close();
    shutdownLaunch(getmypid(),$workerPid);
    header('Connection: close');
    reply(['result'=>['message'=>'Завершаем работу сайта и фоновой проверки почты…']]);
}catch(RuntimeException $e){reply(['error'=>$e->getMessage()],500);}
catch(Throwable $e){
    error_log('Shutdown error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    reply(['error'=>'Не удалось завершить работу проекта. Проверьте журнал PHP.'],500);
}
