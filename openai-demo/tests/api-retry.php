<?php
require dirname(__DIR__).'/src/client.php';
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";}
$count=0;$calls=0;
$r=apiWithRetry(function()use(&$calls){if(++$calls===1)throw new ApiHttpException(500,'test');return ['ok'=>true];},1,$count,fn()=>null);
check($count===2&&$r['ok'],'500 followed by success, two attempts');
$count=0;
try{apiWithRetry(fn()=>throw new ApiHttpException(503,'test'),10,$count,fn()=>null);}catch(ApiHttpException $e){}
check($count===2,'retry bounded even for larger requested limit');
foreach([400,401,403,404,429] as $status){
$count=0;try{apiWithRetry(fn()=>throw new ApiHttpException($status,'test'),1,$count,fn()=>null);}catch(ApiHttpException $e){}
check($count===1,"no retry for $status");
}
$count=0;try{apiWithRetry(fn()=>throw new RuntimeException('timeout'),1,$count,fn()=>null);}catch(RuntimeException $e){}
check($count===1,'ambiguous network failure not retried');
