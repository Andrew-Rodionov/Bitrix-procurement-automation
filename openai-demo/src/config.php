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
 session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict']);
 $_SESSION['csrf']??=bin2hex(random_bytes(24));
}
