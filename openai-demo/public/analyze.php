<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/response.php';
try {
    require dirname(__DIR__).'/src/config.php';
    require dirname(__DIR__).'/src/client.php';
    if($_SERVER['REQUEST_METHOD']!=='POST') reply(['error'=>'Нужен POST-запрос.'],405);
    sessionInit();
    if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) reply(['error'=>'Обновите страницу.'],403);
    session_write_close();
    $letter=$_POST['letter']??'';
    if(!is_string($letter)) reply(['error'=>'Письмо должно быть текстом.'],400);
    $letter=trim($letter);
    if($letter==='' || strlen($letter)>24000) reply(['error'=>'Введите письмо до 24 КБ.'],400);
    reply(analyzeOffer($letter));
} catch (RuntimeException $e) {
    reply(['error'=>$e->getMessage()],502);
} catch (Throwable $e) {
    // Log only the error type and location, never message contents that may include request data.
    error_log('Analysis error: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    reply(['error'=>'Ошибка обработки на сервере. Подробности доступны в журнале PHP.'],500);
}
