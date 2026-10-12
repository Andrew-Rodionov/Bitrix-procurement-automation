<?php
declare(strict_types=1);
function setting(string $name, string $default = ''): string {

 $path=dirname(__DIR__,2).'/.env';
 foreach(is_file($path)?file($path,FILE_IGNORE_NEW_LINES):[] as $line) {
  if(!str_contains($line,'=') || str_starts_with(trim($line),'#')) continue;
  [$k,$v]=explode('=',$line,2);
  if(trim($k)===$name) return trim(trim($v),"\"'")?:$default;
 } return $default;
}
function sessionInit(): void {
 $path=dirname(__DIR__,2).'/.runtime/sessions';
 if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Не удалось создать закрытое хранилище сессий.');
 session_save_path($path);
 session_start(['use_strict_mode'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'Strict',
     'cookie_secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'gc_maxlifetime'=>1800]);
 $_SESSION['csrf']??=bin2hex(random_bytes(24));
 if(isset($_SESSION['mail_auth'])){
     if(time()-($_SESSION['mail_activity']??0)>1800){
         unset($_SESSION['mail_auth'],$_SESSION['mail_activity'],$_SESSION['mailbox'],$_SESSION['mail_uids']);
     }else $_SESSION['mail_activity']=time();
 }
}
