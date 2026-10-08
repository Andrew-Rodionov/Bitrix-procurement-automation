<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!in_array($path,['/','/index.php','/analyze.php'],true)) {
 http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found'; return true;
}
return false;