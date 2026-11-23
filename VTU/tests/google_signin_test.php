<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-google-signin.php';
$n=0;function gs_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach(['SAAS','NON-SAAS'] as $i=>$ed){
 $root=__DIR__.'/../DGV7.0-'.$ed;$s=file_get_contents($root.'/func/bc-func.php');
 $a=strpos($s,'function bc_google_payload_valid(');$b=strpos($s,'function generateOTP(',$a);
 $name='google_payload_fixture_'.$i;eval(str_replace('bc_google_payload_valid',$name,substr($s,$a,$b-$a)));
 $p=['sub'=>'subject','email'=>'fixture@example.test','iss'=>'https://accounts.google.com','aud'=>'client','email_verified'=>'true','exp'=>(string)(time()+600)];
 gs_check($name($p,'client'),'valid tokeninfo payload');
 foreach(['sub'=>null,'email'=>['bad'],'iss'=>'evil','aud'=>['client'],'email_verified'=>'false','exp'=>null,'azp'=>'other'] as $key=>$value){$bad=$p;$bad[$key]=$value;gs_check(!$name($bad,'client'),'invalid '.$key);}
 foreach([time()-1,time(),'', 'not-a-time', [],true] as $exp){$bad=$p;$bad['exp']=$exp;gs_check(!$name($bad,'client'),'invalid expiry');}
 $bad=$p;unset($bad['exp']);gs_check(!$name($bad,'client'),'missing expiry');gs_check(!$name($p,'wrong'),'wrong audience');gs_check(!$name($p,''),'disabled oauth client');
 gs_check(strpos($s,'rawurlencode($id_token)')!==false&&strpos($s,'CURLINFO_HTTP_CODE')!==false&&strpos($s,'CURLOPT_SSL_VERIFYHOST, 2')!==false,'transport fail closed');
 foreach(['web','bc-admin','bc-spadmin'] as $area){$path=$root.'/'.$area.'/Login.php';if(!file_exists($path))continue;$page=file_get_contents($path);gs_check(strpos($page,'bc_google_signin_consume(')!==false&&strpos($page,'data-nonce=')!==false,'nonce wired');gs_check(strpos($page,'bc_google_payload_valid(')!==false,'strict validation wired');}
}
$_SESSION=[];$scope='user:7';$nonce=bc_google_signin_nonce($scope);
gs_check(strlen($nonce)===64,'random nonce');gs_check(bc_google_signin_nonce($scope)===$nonce,'same render stable');
gs_check(!bc_google_signin_consume('vendor:7',['nonce'=>$nonce]),'role isolation');
gs_check(!bc_google_signin_consume('user:8',['nonce'=>$nonce]),'tenant isolation');
gs_check(bc_google_signin_consume($scope,['nonce'=>$nonce]),'valid nonce');gs_check(!bc_google_signin_consume($scope,['nonce'=>$nonce]),'replay denied');
$nonce=bc_google_signin_nonce($scope);$_SESSION['google_signin_nonces'][$scope]['expires']=time()-1;gs_check(!bc_google_signin_consume($scope,['nonce'=>$nonce]),'expired nonce');
$nonce=bc_google_signin_nonce($scope);gs_check(!bc_google_signin_consume($scope,['nonce'=>[]]),'malformed nonce');gs_check(!bc_google_signin_consume($scope,['nonce'=>$nonce]),'failed attempt consumes nonce');
echo "$n Google sign-in checks passed\n";
