<?php
declare(strict_types=1);
ini_set('display_errors','0');
set_time_limit(300);
ob_start();
$apiResponseSent=false;
$apiEmergencyMemory=str_repeat('x',65536);
function reply(array $data,int $code=200): never {
    global $apiResponseSent;
    try { $body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { $code=500;$body='{"error":"Не удалось сформировать ответ сервера."}'; }
    $apiResponseSent=true;
    while(ob_get_level()>0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}
register_shutdown_function(static function():void {
    global $apiResponseSent,$apiEmergencyMemory;
    $apiEmergencyMemory=null;
    if($apiResponseSent) return;
    $last=error_get_last();
    $timeout=$last && str_contains($last['message'],'Maximum execution time');
    reply(['error'=>$timeout
        ? 'Сервер превысил время ожидания модели. Попробуйте более короткое письмо.'
        : 'Обработка прервалась на сервере. Подробности доступны в журнале PHP.'],500);
});
