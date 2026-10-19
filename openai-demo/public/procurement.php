<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/response.php';
set_time_limit(600);
try{
    require dirname(__DIR__).'/src/config.php';
    require dirname(__DIR__).'/src/procurement.php';
    if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Нужен POST-запрос.'],405);
    sessionInit();
    if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))reply(['error'=>'Обновите страницу.'],403);
    if(!isset($_SESSION['mail_auth']))reply(['error'=>'Сеанс истёк. Войдите в почту снова.'],401);
    $c=mailConfig();session_write_close();
    $action=$_POST['action']??'';
    if($action==='status'){
        $state=procurementRead(procurementPath($c).'/state.json',['campaigns'=>[],'last_check'=>null,'next_check'=>null,'check_error'=>null]);
        $data=procurementPublic($state);$data['suppliers']=procurementSuppliers();
        $data['worker_running']=procurementWorkerRunning();
        $data['background_enabled']=is_file(procurementPath($c).'/auth.json');
        reply(['result'=>$data]);
    }
    if($action==='compose'){
        if(!is_string($_POST['brief']??null))reply(['error'=>'Введите тезисы закупки.'],400);
        reply(['result'=>procurementDraft($c,trim($_POST['brief']))]);
    }
    if($action==='send'){
        foreach(['id','suppliers','subject','body'] as $key)if(!is_string($_POST[$key]??null))reply(['error'=>'Заполните письмо и выберите поставщиков.'],400);
        $suppliers=json_decode($_POST['suppliers'],true);
        if(!is_array($suppliers)||!array_is_list($suppliers)||array_filter($suppliers,static fn($s)=>!is_string($s)))reply(['error'=>'Некорректный список поставщиков.'],400);
        reply(['result'=>procurementSend($c,$_POST['id'],$suppliers,$_POST['subject'],$_POST['body'])]);
    }
    if($action==='refresh')reply(['result'=>procurementScan($c)]);
    if($action==='process')reply(['result'=>procurementProcess($c)]);
    if($action==='retry')reply(['result'=>procurementRetry($c)]);
    if($action==='original'){
        if(!is_string($_POST['id']??null)||!is_string($_POST['message_id']??null))reply(['error'=>'Выберите письмо.'],400);
        $state=procurementRead(procurementPath($c).'/state.json');
        $message=$state['campaigns'][$_POST['id']]['messages'][$_POST['message_id']]??null;
        if(!$message)reply(['error'=>'Письмо не найдено.'],404);
        $message['attachments']=mailVisibleAttachments($message['attachments']??[]);
        reply(['result'=>$message]);
    }
    reply(['error'=>'Неизвестное действие.'],400);
}catch(RuntimeException $e){reply(['error'=>$e->getMessage()],502);}
catch(Throwable $e){
    error_log('Procurement error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    reply(['error'=>'Не удалось выполнить операцию закупки. Проверьте журнал PHP.'],500);
}
