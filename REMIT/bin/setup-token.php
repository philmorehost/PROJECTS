<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
$dir=__DIR__.'/../config';
if(is_file($dir.'/installed.lock')) exit("Installer is locked.\n");
$token=bin2hex(random_bytes(32));
file_put_contents($dir.'/install-token',$token,LOCK_EX);chmod($dir.'/install-token',0600);
echo "Operator setup token: $token\nKeep this private. It is deleted when setup completes.\n";
