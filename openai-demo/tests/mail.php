<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/config.php';
require dirname(__DIR__).'/src/mail.php';
function test(bool $ok,string $name):void{if(!$ok)throw new RuntimeException("FAIL: $name");echo "PASS: $name\n";}
test(mailRecipients("a@example.org; b@example.org\na@example.org")===['a@example.org','b@example.org'],'recipients split and deduplicated');
foreach(['bad-address','a@example.org,Bcc: attacker@example.org'] as $bad){try{mailRecipients($bad);throw new LogicException('Not rejected');}catch(RuntimeException $e){test(true,'invalid recipient rejected');}}
$text='Цена 12500 рублей';
test(mailDecodeHeader('Re: Предложение [ЗКП-тест]')==='Re: Предложение [ЗКП-тест]','literal SMTPUTF8 subject preserved');
$encoded=base64_encode($text);
$r=mailMessage("Subject: =?UTF-8?B?".base64_encode('Предложение')."?=\r\nFrom: supplier@example.org\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$encoded);
test($r['subject']==='Предложение'&&$r['body']===$text,'UTF-8 header and base64 body');
$cp=iconv('UTF-8','Windows-1251','Добрый день');
$r=mailMessage("Content-Type: text/plain; charset=windows-1251\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($cp));
test($r['body']==='Добрый день','Windows-1251 quoted printable');
$raw="Content-Type: multipart/mixed; boundary=outer\r\n\r\n--outer\r\nContent-Type: multipart/alternative; boundary=inner\r\n\r\n--inner\r\nContent-Type: text/plain\r\n\r\nPlain offer\r\n--inner\r\nContent-Type: text/html\r\n\r\n<p>HTML offer</p>\r\n--inner--\r\n\r\n--outer\r\nContent-Type: application/pdf; name=offer.pdf\r\nContent-Disposition: attachment; filename=offer.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('PDF attachment not text')."\r\n--outer--\r\n";
$r=mailMessage($raw);
test($r['body']==='Plain offer'&&$r['attachments']===['offer.pdf'],'multipart plain preferred and PDF excluded');
$raw="Content-Type: multipart/related; boundary=pic\r\n\r\n--pic\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nЦена 100 рублей\r\n--pic\r\nContent-Type: image/png; name=47fc6cea.a1744c3f.png\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\ninvalid image data, deliberately not decoded\r\n--pic--\r\n";
$r=mailMessage($raw);
test($r['body']==='Цена 100 рублей'&&$r['attachments']===[],'inline PNG ignored without decoding or listing');
$r=mailMessage("Content-Type: application/octet-stream; name=PHOTO.JPG\r\nContent-Disposition: attachment; filename=PHOTO.JPG\r\nContent-Transfer-Encoding: base64\r\n\r\ninvalid");
test($r['body']===''&&$r['attachments']===[],'photo with generic MIME ignored by filename');
test(mailVisibleAttachments(['47fc6cea.a1744c3f.png','photo.JPEG','offer.pdf','Вложение (image/png)'])===['offer.pdf'],'old image metadata hidden while document warning retained');
$r=mailMessage("Content-Type: text/html; charset=UTF-8\r\n\r\n<p>Offer &amp; price</p><script>bad()</script><img src='https://example.org/track'>");
test($r['body']==='Offer & price','HTML converted to inert text');
$dir=dirname(__DIR__,2).'/.runtime/mail-test-'.bin2hex(random_bytes(6));
$c=['address'=>'assistant@example.org'];$token=bin2hex(random_bytes(24));$calls=[];
$transport=function($config,$address,$subject,$body)use(&$calls){$calls[]=$address;if($address==='b@example.org')throw new RuntimeException('Disconnect');return '<test@example.org>';};
$r=outgoingSend($c,['a@example.org','b@example.org'],'Subject','Body',$token,$transport,$dir);
test(count($calls)===2&&$r['deliveries'][0]['status']==='sent'&&$r['deliveries'][1]['status']==='unknown','partial delivery recorded separately');
$r=outgoingSend($c,['a@example.org','b@example.org'],'Subject','Body',$token,$transport,$dir);
test(count($calls)===2&&$r['repeated'],'same submission never sent twice');
try{outgoingSend($c,['a@example.org'],'Changed','Body',$token,$transport,$dir);throw new LogicException('Not rejected');}catch(RuntimeException $e){test(true,'token cannot be reused for changed message');}
foreach(glob($dir.'/*.json') as $path)unlink($path);rmdir($dir);

$auth=['address'=>'test@citology.ru','password'=>'test-only-password'];
$_SESSION=[];
try{mailConfig();throw new LogicException('Expected login');}catch(RuntimeException $e){test(true,'no env credential fallback');}
$candidate=mailConfig($auth);
test($candidate['address']===$auth['address']&&$candidate['username']==='test@citology.ru','mailbox address used for login');
$checks=0;
mailAuthenticate($auth,function($c)use(&$checks){$checks++;test($c['password']==='test-only-password','password supplied only to transport');});
test($checks===1&&!isset($_SESSION['mail_auth']),'authentication does not save before endpoint success');
try{mailAuthenticate($auth,function(){throw new RuntimeException('Login rejected');});throw new LogicException('Expected rejection');}
catch(RuntimeException $e){test(!isset($_SESSION['mail_auth']),'rejected login leaves no session credential');}
try{mailConfig(['address'=>'test@other-domain.invalid','password'=>'test']);throw new LogicException('Expected domain rejection');}
catch(RuntimeException $e){test(true,'wrong helper domain rejected');}
$_SESSION['mail_auth']=$auth;
test(mailConfig()['username']==='test@citology.ru','logged-in session used');
unset($_SESSION['mail_auth']);
try{mailConfig();throw new LogicException('Expected logout');}catch(RuntimeException $e){test(true,'logout removes credential access');}

$dir=dirname(__DIR__,2).'/.runtime/smtp-test-'.bin2hex(random_bytes(6));
$testConfig=['address'=>'assistant@example.org','username'=>'assistant@example.org','password'=>'secret-test-only'];
$r=outgoingSend($testConfig,['supplier@example.org'],'Test','Test',bin2hex(random_bytes(24)),
    function(){throw new MailDeliveryException('failed','SMTP: Could not authenticate.');},$dir);
test($r['deliveries'][0]['status']==='failed'&&str_contains($r['deliveries'][0]['error'],'authenticate'),'SMTP rejection retains reason and failed state');
$diagnostic=smtpErrorText(new RuntimeException('secret-test-only '.base64_encode('secret-test-only')),$testConfig);
test(!str_contains($diagnostic,'secret-test-only')&&!str_contains($diagnostic,base64_encode('secret-test-only')),'SMTP diagnostic redacts password');
foreach(glob($dir.'/*.json') as $path)unlink($path);rmdir($dir);
