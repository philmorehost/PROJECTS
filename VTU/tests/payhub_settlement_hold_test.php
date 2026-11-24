<?php
/** Offline diagnosis: execute held receivers without DB/network with no application bootstrap. */
$n=0;
function phcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
// Buffer until every endpoint has set its headers (also works with native CLI PHP).
ob_start();
foreach(['DGV7.0-SAAS'=>['users-payhub.php','vendors-payhub.php'],'DGV7.0-NON-SAAS'=>['users-payhub.php']] as $edition=>$routes){
    foreach($routes as $route){
        $path=dirname(__DIR__).'/'.$edition.'/'.$route;
        http_response_code(200);ob_start();require $path;$body=ob_get_clean();
        $reply=json_decode($body,true);
        phcheck(http_response_code()===503,"$edition/$route returns deliberate 503");
        phcheck(($reply['code']??'')==='LEGACY_SETTLEMENT_HOLD','exact incident code');
        phcheck(($reply['status']??'')==='pending','not a credited acknowledgement');
        phcheck(strpos($reply['message']??'','No funds were credited or refunded.')!==false,'explicit no-mutation message');
        $source=file_get_contents($path);
        phcheck(strpos($source,'bc-connect')===false && strpos($source,'processPayhubSuccess')===false && strpos($source,'mysqli_')===false,'held receiver does not bootstrap settlement');
    }
}
ob_end_clean();
echo "PASS $n PayHub hold diagnosis checks (offline; no financial changes)\n";
