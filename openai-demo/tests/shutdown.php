<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/shutdown.php';
function check(bool $ok,string $label):void {if(!$ok)throw new LogicException($label);echo "PASS: $label\n";}
check(shutdownRequestAllowed('cli-server','127.0.0.1',8088),'local development server allowed');
check(shutdownRequestAllowed('cli-server','::1',8088),'IPv6 localhost allowed');
check(!shutdownRequestAllowed('cli-server','192.168.1.1',8088),'remote address rejected');
check(!shutdownRequestAllowed('fpm-fcgi','127.0.0.1',8088),'deployed PHP process cannot be stopped');
check(!shutdownRequestAllowed('cli','127.0.0.1',8088),'unrelated PHP CLI process rejected');
check(!shutdownRequestAllowed('cli-server','127.0.0.1',8090),'different port rejected');
