<?php
$root=__DIR__.'/../cryptocurrency-idea';require $root.'/php-bridge/lib.php';
$n=0;function cb_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
cb_check(mz_user_can_trade(11)===false,'placeholder never authorizes KYC');
foreach(['api.php','callback.php'] as $file){$s=file_get_contents($root.'/php-bridge/'.$file);cb_check(strpos($s,"require __DIR__ . '/prototype-hold.php';")<strpos($s,"require __DIR__ . '/lib.php';"),'hold precedes dependencies');}
$hold=file_get_contents($root.'/php-bridge/prototype-hold.php');cb_check(strpos($hold,'http_response_code(503)')!==false&&strpos($hold,'CRYPTO_PROTOTYPE_HOLD')!==false&&strpos($hold,'exit;')!==false,'explicit fail closed response');
foreach(['php-bridge/config.php','widget/crypto-widget.html','service/package.json','service/src/config.js','service/src/server.js'] as $file)cb_check(stripos(file_get_contents($root.'/'.$file),'mzeevtu')===false,'no hardcoded demo application brand '.$file);
foreach(['SAAS','NON-SAAS'] as $ed){$dir=__DIR__.'/../DGV7.0-'.$ed;foreach(['func/bc-connect.php','func/bc-tables.php','index-bc-style-template-home.php','web/CryptoHub.php'] as $file){$s=file_get_contents($dir.'/'.$file);cb_check(strpos($s,'cryptocurrency-idea')===false,'no prototype import or seed '.$ed.'/'.$file);}}
if(PHP_SAPI==='cli'&&function_exists('proc_open'))foreach(['api.php','callback.php'] as $file){
 $code='register_shutdown_function(function(){fwrite(STDERR,"HTTP=".http_response_code());});require '.var_export($root.'/php-bridge/'.$file,true).';';
 $p=proc_open([PHP_BINARY,'-n','-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($p))throw new RuntimeException('child unavailable');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$json=json_decode($out,true);
 cb_check($exit===0&&($json['code']??'')==='CRYPTO_PROTOTYPE_HOLD'&&$err==='HTTP=503','native endpoint refuses before DB/network '.$file);
}
echo "$n crypto prototype boundary checks passed\n";
