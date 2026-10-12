<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/response.php';
try {
    require dirname(__DIR__).'/src/config.php';
    require dirname(__DIR__).'/src/mail.php';
    if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Нужен POST-запрос.'],405);
    sessionInit();
    $csrf=$_POST['csrf']??'';
    if(!is_string($csrf)||!hash_equals($_SESSION['csrf'],$csrf))reply(['error'=>'Обновите страницу.'],403);
    $action=$_POST['action']??'';
    if($action==='login'){
        foreach(['address','password'] as $field)if(!is_string($_POST[$field]??null))reply(['error'=>'Введите адрес и пароль.'],400);
        $address=trim($_POST['address']);
        $auth=['address'=>$address,'password'=>$_POST['password']];
        // Only save credentials after the mail server confirms IMAP login.
        mailAuthenticate($auth);
        session_regenerate_id(true);
        unset($_SESSION['mailbox'],$_SESSION['mail_uids']);
        $_SESSION['mail_auth']=$auth;$_SESSION['mail_activity']=time();
        session_write_close();
        reply(['result'=>['connected'=>true,'address'=>$address]]);
    }
    if($action==='logout'){
        unset($_SESSION['mail_auth'],$_SESSION['mail_activity'],$_SESSION['mailbox'],$_SESSION['mail_uids']);
        session_regenerate_id(true);session_write_close();
        reply(['result'=>['connected'=>false]]);
    }

    if($action==='list'){
        session_write_close();$data=inboxList();
        sessionInit();
        $_SESSION['mailbox']=$data['mailbox'];
        $_SESSION['mail_uids']=array_column($data['messages'],'uid');
        session_write_close();reply(['result'=>$data]);
    }
    if($action==='read'){
        $uid=$_POST['uid']??'';
        if(!is_string($uid)||!in_array($uid,$_SESSION['mail_uids']??[],true))reply(['error'=>'Выберите письмо из обновлённого списка.'],400);
        $identity=$_SESSION['mailbox']??'';session_write_close();
        reply(['result'=>inboxRead($uid,$identity)]);
    }
    if($action==='smtp_check'){
        $config=mailConfig();session_write_close();
        reply(['result'=>smtpCheck($config)]);
    }
    if($action==='send'){
        $token=$_POST['send_token']??'';
        if(!is_string($token)||!in_array($token,$_SESSION['send_tokens']??[],true))reply(['error'=>'Обновите страницу перед отправкой.'],400);
        foreach(['recipients','subject','body'] as $field)if(!is_string($_POST[$field]??null))reply(['error'=>'Заполните все поля письма.'],400);
        $recipients=mailRecipients($_POST['recipients']);
        $subject=trim($_POST['subject']);$body=trim($_POST['body']);
        if($subject===''||strlen($subject)>500||preg_match('/[\r\n]/',$subject)||$body===''||strlen($body)>24000)
            reply(['error'=>'Введите тему до 500 байт и текст до 24 КБ. Переносы строки в теме не допускаются.'],400);
        session_write_close();
        reply(['result'=>outgoingSend(mailConfig(),$recipients,$subject,$body,$token)]);
    }
    reply(['error'=>'Неизвестное действие.'],400);
}catch(RuntimeException $e){reply(['error'=>$e->getMessage()],502);}
catch(Throwable $e){
    error_log('Mail error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    reply(['error'=>'Не удалось обработать почтовый запрос. Проверьте настройки и журнал PHP.'],500);
}
