<?php
declare(strict_types=1);
require_once __DIR__.'/mail-mime.php';
require_once dirname(__DIR__).'/lib/PHPMailer/src/Exception.php';
require_once dirname(__DIR__).'/lib/PHPMailer/src/PHPMailer.php';
require_once dirname(__DIR__).'/lib/PHPMailer/src/SMTP.php';

function mailConfig(?array $auth=null):array {
    foreach(['MAIL_IMAP_HOST','MAIL_SMTP_HOST'] as $name)
        if(setting($name)==='')throw new RuntimeException('Заполните '.$name.' в корневом .env.');
    $auth??=$_SESSION['mail_auth']??null;
    if(!$auth)throw new RuntimeException('Сначала войдите в почту помощника через форму на странице.');
    $address=$auth['address']??'';$username=$address;$password=$auth['password']??'';
    if(!is_string($address)||!filter_var($address,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Введите полный адрес почтового ящика.');
    if(!is_string($username)||trim($username)===''||preg_match('/[\r\n\x00]/',$username))throw new RuntimeException('Введите корректный логин.');
    if(!is_string($password)||$password===''||strlen($password)>4096)throw new RuntimeException('Введите пароль почтового подключения.');
    $domain=setting('MAIL_DOMAIN');
    if($domain!=='' && strcasecmp(substr(strrchr($address,'@'),1),$domain)!==0)
        throw new RuntimeException('Для этого подключения нужен ящик @'.$domain.'.');
    $c=['address'=>$address,'username'=>$username,'password'=>$password,
        'imap'=>setting('MAIL_IMAP_HOST'),'smtp'=>setting('MAIL_SMTP_HOST'),
        'imap_port'=>(int)setting('MAIL_IMAP_PORT','993'),'smtp_port'=>(int)setting('MAIL_SMTP_PORT','465'),
        'folder'=>setting('MAIL_FOLDER','INBOX'),'smtp_security'=>setting('MAIL_SMTP_SECURITY','ssl')];
    foreach(['imap','smtp'] as $key)if(!preg_match('/^[a-zA-Z0-9.-]+$/',$c[$key]))throw new RuntimeException('Некорректный адрес почтового сервера.');
    foreach(['imap_port','smtp_port'] as $key)if($c[$key]<1||$c[$key]>65535)throw new RuntimeException('Некорректный порт почтового сервера.');
    if(!in_array($c['smtp_security'],['ssl','tls'],true))throw new RuntimeException('MAIL_SMTP_SECURITY: ssl или tls.');
    if($c['folder']!== 'INBOX')throw new RuntimeException('В этом этапе поддерживается MAIL_FOLDER=INBOX.');
    return $c;
}
function mailAuthenticate(array $auth,?callable $verify=null):void {
    $config=mailConfig($auth);
    $verify??=static function(array $config):void { imapRequest($config,'','NOOP'); };
    $verify($config);
}

function mailCa():string {return setting('MAIL_CA_BUNDLE',ini_get('curl.cainfo')?:'');}
function imapRequest(array $c,string $suffix='',?string $command=null,$handle=null):string {
    $ch=$handle??curl_init();
    curl_reset($ch);
    $body='';
    $options=[CURLOPT_URL=>'imaps://'.$c['imap'].':'.$c['imap_port'].'/INBOX'.$suffix,
        CURLOPT_USERNAME=>$c['username'],CURLOPT_PASSWORD=>$c['password'],
        CURLOPT_LOGIN_OPTIONS=>'AUTH=LOGIN',
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body):int{
            if(strlen($body)+strlen($chunk)>5*1024*1024)return 0;
            $body.=$chunk;return strlen($chunk);
        }];
    if(defined('CURLSSLOPT_NATIVE_CA')&&PHP_OS_FAMILY==='Windows')$options[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA;
    if(mailCa()!=='')$options[CURLOPT_CAINFO]=mailCa();
    if($command!==null)$options[CURLOPT_CUSTOMREQUEST]=$command;
    curl_setopt_array($ch,$options);$ok=curl_exec($ch);$errno=curl_errno($ch);
    if($handle===null)curl_close($ch);
    if($ok===false)throw new RuntimeException(match($errno){
        67=>'Сервер IMAP отклонил адрес или пароль (cURL 67). Вход выполняется полным адресом ящика через LOGIN. Проверьте пароль и разрешение IMAP у администратора почты.',
        60=>'Не доверен сертификат почтового сервера. Проверьте MAIL_CA_BUNDLE.',
        23=>'Письмо слишком большое: поддерживается до 5 МБ.',
        default=>'Не удалось подключиться к почте (cURL '.$errno.'). Проверьте настройки и соединение.'
    });
    return $body;
}
function mailboxIdentity(array $c,$ch):string {
    $status=imapRequest($c,'','STATUS INBOX (UIDVALIDITY)',$ch);
    if(!preg_match('/UIDVALIDITY\s+(\d+)/i',$status,$m))throw new RuntimeException('Не удалось определить идентификатор папки.');
    return hash('sha256',$c['imap'].'|'.$c['username'].'|'.$c['folder'].'|'.$m[1]);
}
function inboxList():array {
    $c=mailConfig();$ch=curl_init();
    try{
        $identity=mailboxIdentity($c,$ch);
        $raw=imapRequest($c,'','UID SEARCH ALL',$ch);
        $uids=[];
        if(preg_match('/\* SEARCH([^\r\n]*)/',$raw,$m))$uids=array_filter(preg_split('/\s+/',trim($m[1])),fn($id)=>ctype_digit($id));
        $uids=array_reverse(array_values($uids));$count=count($uids);$messages=[];
        foreach(array_slice($uids,0,20) as $uid){
            $headers=mailHeaders(imapRequest($c,';UID='.$uid.';SECTION=HEADER',null,$ch));
            $messages[]=['uid'=>$uid,'subject'=>mailDecodeHeader($headers['subject']??'(без темы)'),
                'from'=>mailDecodeHeader($headers['from']??''),'date'=>$headers['date']??''];
        }
        return ['mailbox'=>$identity,'messages'=>$messages,'total'=>$count,'address'=>$c['address']];
    }finally{curl_close($ch);}
}
function inboxRead(string $uid,string $identity):array {
    if(!ctype_digit($uid)||$uid==='0')throw new RuntimeException('Неверный идентификатор письма.');
    $c=mailConfig();$ch=curl_init();
    try{
        if(!hash_equals(mailboxIdentity($c,$ch),$identity))throw new RuntimeException('Папка или почтовый аккаунт изменились. Обновите список писем.');
        return mailMessage(imapRequest($c,';UID='.$uid,null,$ch));
    }finally{curl_close($ch);}
}
function mailRecipients(string $input):array {
    $parts=preg_split('/[;,\s]+/',trim($input));$addresses=[];
    foreach($parts as $address){
        if($address==='')continue;
        if(!filter_var($address,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Некорректный адрес получателя: '.$address);
        $addresses[strtolower($address)]=$address;
    }
    if(!$addresses||count($addresses)>20)throw new RuntimeException('Укажите от 1 до 20 адресов получателей.');
    return array_values($addresses);
}
class MailDeliveryException extends RuntimeException {
    public function __construct(public readonly string $deliveryStatus,string $message){parent::__construct($message);}
}
function smtpErrorText(Throwable $e,array $c):string {
    $text=$e->getMessage();
    foreach(array_unique([$c['password'],base64_encode($c['password']),base64_encode("\0".$c['username']."\0".$c['password'])]) as $secret)
        if($secret!=='')$text=str_replace($secret,'[скрыто]',$text);
    $text=preg_replace('/[\r\n\t]+/',' ',strip_tags($text));
    return substr($text,0,1200);
}
function smtpClient(array $c):PHPMailer\PHPMailer\PHPMailer {
    $mailer=new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();$mailer->Host=$c['smtp'];$mailer->Port=$c['smtp_port'];
    $mailer->SMTPSecure=$c['smtp_security']==='ssl'?PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->SMTPAuth=true;$mailer->AuthType='LOGIN';
    $mailer->Username=$c['username'];$mailer->Password=$c['password'];$mailer->Timeout=25;
    $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false];
    if(mailCa()!=='')$ssl['cafile']=mailCa();
    $mailer->SMTPOptions=['ssl'=>$ssl];$mailer->CharSet='UTF-8';
    $mailer->setFrom($c['address'],setting('MAIL_SENDER_NAME','Отдел закупок'));
    return $mailer;
}
function smtpCheck(array $c):array {
    $mailer=smtpClient($c);
    try{
        if(!$mailer->smtpConnect())throw new RuntimeException($mailer->ErrorInfo?:'Соединение не установлено.');
        return ['message'=>'SMTP: соединение и вход успешны. Письмо не отправлялось. Приём конкретного адресата и доставка проверяются только при отправке.'];
    }catch(Throwable $e){throw new RuntimeException('Ошибка подключения или входа SMTP: '.smtpErrorText($e,$c));}
    finally{$mailer->smtpClose();}
}
function smtpDeliver(array $c,string $recipient,string $subject,string $body):string {
    $mailer=smtpClient($c);
    try{
        $mailer->addAddress($recipient);$mailer->Subject=$subject;$mailer->Body=$body;
        if(!$mailer->preSend())throw new RuntimeException($mailer->ErrorInfo?:'Не удалось подготовить письмо.');
        if(!$mailer->smtpConnect())throw new RuntimeException($mailer->ErrorInfo?:'Не удалось подключиться к SMTP.');
    }catch(Throwable $e){
        $mailer->smtpClose();
        throw new MailDeliveryException('failed','Письмо не отправлено: ошибка подготовки, подключения или входа SMTP. '.smtpErrorText($e,$c));
    }
    try{
        if(!$mailer->postSend())throw new RuntimeException($mailer->ErrorInfo?:'Нет подтверждения SMTP.');
        return $mailer->getLastMessageID();
    }catch(Throwable $e){
        $error=$mailer->getSMTPInstance()->getError();
        $code=(int)($error['smtp_code']??0);
        $status=$code>=400&&$code<=599?'failed':'unknown';
        throw new MailDeliveryException($status,($status==='failed'?'SMTP отклонил письмо. ':'Не удалось подтвердить результат отправки. ').smtpErrorText($e,$c));
    }finally{$mailer->smtpClose();}
}

function outgoingSend(array $c,array $recipients,string $subject,string $body,string $token,?callable $deliver=null,?string $directory=null):array {
    if(!preg_match('/^[a-f0-9]{48}$/',$token))throw new RuntimeException('Недействительный идентификатор отправки.');
    $directory??=dirname(__DIR__,2).'/.runtime/mail';
    if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Не удалось открыть журнал отправки.');
    $path=$directory.'/'.hash('sha256',$c['address'].'|'.$token).'.json';
    $f=fopen($path,'c+');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Не удалось заблокировать отправку.');
    $fingerprint=hash('sha256',json_encode([$c['address'],$recipients,$subject,$body]));
    try{
        $stored=stream_get_contents($f);$record=$stored!==''?json_decode($stored,true,512,JSON_THROW_ON_ERROR):null;
        if($record!==null){
            if(!hash_equals($record['fingerprint'],$fingerprint))throw new RuntimeException('Эта отправка уже использована. Создайте новое письмо.');
            return ['deliveries'=>$record['deliveries'],'repeated'=>true];
        }
        $record=['fingerprint'=>$fingerprint,'created_at'=>gmdate('c'),'subject'=>$subject,'body'=>$body,'deliveries'=>array_map(static fn($address)=>['address'=>$address,'status'=>'not_sent','message_id'=>null],$recipients)];
        $save=static function()use($f,&$record):void{
            rewind($f);ftruncate($f,0);fwrite($f,json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($f);
        };
        $deliver??='smtpDeliver';
        foreach($recipients as $index=>$address){
            $record['deliveries'][$index]['status']='unknown';$save();
            try{
                $record['deliveries'][$index]['message_id']=$deliver($c,$address,$subject,$body);
                $record['deliveries'][$index]['status']='sent';
            }catch(Throwable $e){
                $record['deliveries'][$index]['status']=$e instanceof MailDeliveryException?$e->deliveryStatus:'unknown';
                $record['deliveries'][$index]['error']=$e instanceof MailDeliveryException?$e->getMessage():'Внутренний сбой отправки. Подтверждение от SMTP не получено.';
            }
            $save();
        }
        return ['deliveries'=>$record['deliveries'],'repeated'=>false];
    }finally{flock($f,LOCK_UN);fclose($f);}
}
