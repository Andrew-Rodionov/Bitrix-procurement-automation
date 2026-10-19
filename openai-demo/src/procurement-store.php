<?php
declare(strict_types=1);
require_once __DIR__.'/mail-mime.php';

function procurementRoot():string {
    $path=dirname(__DIR__,2).'/.runtime/procurement';
    if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new RuntimeException('Не удалось открыть хранилище закупок.');
    return $path;
}
function procurementAccount(array $c):string {return hash('sha256',strtolower($c['address']).'|'.$c['imap']);}
function procurementWorkerRunning():bool {
    $lock=fopen(procurementRoot().'/worker.lock','c');
    if(!$lock)return false;
    $available=flock($lock,LOCK_EX|LOCK_NB);
    if($available)flock($lock,LOCK_UN);
    fclose($lock);return !$available;
}
function procurementPath(array $c):string {
    $path=procurementRoot().'/'.procurementAccount($c);
    if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new RuntimeException('Не удалось открыть данные ящика.');
    return $path;
}
function procurementWrite(string $path,array $data):void {
    $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    if(file_put_contents($temp,$json,LOCK_EX)===false)throw new RuntimeException('Не удалось сохранить данные закупки.');
    @chmod($temp,0600);
    // Windows may briefly keep the destination open while another process reads it.
    for($attempt=0;$attempt<8;$attempt++){
        if(@rename($temp,$path))return;
        usleep(50000*($attempt+1));
    }
    @unlink($temp);throw new RuntimeException('Не удалось обновить данные закупки.');
}
function procurementRead(string $path,array $default=[]):array {
    if(!is_file($path))return $default;
    $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Повреждено хранилище закупок.');
    return $data;
}
function procurementLock(array $c,callable $action):array {
    $path=procurementPath($c);$lock=fopen($path.'/account.lock','c');
    if(!$lock)throw new RuntimeException('Не удалось открыть блокировку закупки.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['busy'=>true,'message'=>'Обновление уже выполняется. Результат появится автоматически.'];}
    try{
        $state=procurementRead($path.'/state.json',['campaigns'=>[],'mailbox'=>null,'cursor'=>null,'next_check'=>null,'last_check'=>null,'check_error'=>null]);
        $save=static function()use(&$state,$path):void{procurementWrite($path.'/state.json',$state);};
        return $action($state,$save,$path);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function procurementKey():string {
    $path=procurementRoot().'/key.bin';$lock=fopen(procurementRoot().'/key.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Не удалось открыть ключ фоновой проверки.');
    try{
        if(!is_file($path)){if(file_put_contents($path,random_bytes(32),LOCK_EX)!==32)throw new RuntimeException('Не удалось сохранить ключ.');@chmod($path,0600);}
        $key=file_get_contents($path);
        if(strlen($key)!==32)throw new RuntimeException('Повреждён ключ фоновой проверки.');
        return $key;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function procurementSeal(array $auth):array {
    $iv=random_bytes(12);$tag='';
    $encrypted=openssl_encrypt(json_encode($auth,JSON_THROW_ON_ERROR),'aes-256-gcm',procurementKey(),OPENSSL_RAW_DATA,$iv,$tag);
    if($encrypted===false)throw new RuntimeException('Не удалось защитить данные почты.');
    return ['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($encrypted)];
}
function procurementUnseal(array $envelope):array {
    foreach(['iv','tag','data'] as $key)if(!is_string($envelope[$key]??null)||base64_decode($envelope[$key],true)===false)throw new RuntimeException('Повреждены данные фонового входа.');
    $plain=openssl_decrypt(base64_decode($envelope['data']),'aes-256-gcm',procurementKey(),OPENSSL_RAW_DATA,base64_decode($envelope['iv']),base64_decode($envelope['tag']));
    if($plain===false)throw new RuntimeException('Не удалось открыть данные фонового входа. Войдите в почту заново.');
    return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
}
function procurementConnect(array $c,bool $enabled):array {
    // Refreshing a verified login must not wait for an ongoing model request.
    if($enabled)procurementWrite(procurementPath($c).'/auth.json',procurementSeal(['address'=>$c['address'],'password'=>$c['password']]));
    else procurementDisconnectAccount(procurementAccount($c));
    return ['connected'=>$enabled];
}
function procurementDisconnectAccount(string $account):void {
    if(!preg_match('/^[a-f0-9]{64}$/',$account))throw new RuntimeException('Неверный аккаунт фоновой проверки.');
    $path=procurementRoot().'/'.$account.'/auth.json';
    if(is_file($path)&&!unlink($path))throw new RuntimeException('Не удалось отключить фоновое подключение. Повторите выход.');
}
function procurementSuppliers():array {
    $path=dirname(__DIR__).'/suppliers.txt';
    if(!is_file($path))throw new RuntimeException('Не найден suppliers.txt.');
    $lines=file($path,FILE_IGNORE_NEW_LINES);$addresses=[];
    foreach($lines as $line){
        $line=trim($line,"\xEF\xBB\xBF \t\r\n");
        if($line===''||str_starts_with($line,'#'))continue;
        if(!filter_var($line,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Некорректный адрес в suppliers.txt: '.$line);
        $addresses[strtolower($line)]=$line;
    }
    if(count($addresses)>20)throw new RuntimeException('В прототипе поддерживается до 20 поставщиков в suppliers.txt.');
    return array_values($addresses);
}
function procurementPublic(array $state):array {
    $list=[];
    foreach(array_reverse($state['campaigns'],true) as $id=>$campaign){
        $messages=[];
        foreach($campaign['messages'] as $messageId=>$message){
            $copy=$message;unset($copy['body']);$copy['attachments']=mailVisibleAttachments($copy['attachments']??[]);$copy['id']=$messageId;$messages[]=$copy;
        }
        $copy=$campaign;$copy['id']=$id;$copy['messages']=$messages;
        unset($copy['send_token'],$copy['uid_min']);$list[]=$copy;
    }
    return ['campaigns'=>$list,'last_check'=>$state['last_check'],'next_check'=>$state['next_check'],'check_error'=>$state['check_error'],
        'status_message'=>empty($list)?'Подготовьте и отправьте запрос поставщикам.':(array_sum(array_map(static fn($c)=>count($c['messages']),$list))===0?'Ответов поставщиков ещё нет.':'Ответы поставщиков загружены.')];
}
