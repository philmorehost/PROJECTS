<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-token.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-request-compat.php';
$n=0;function stcheck($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
$_SERVER=[];$row=['id'=>11,'vendor_id'=>7,'password'=>'fixture-password-hash'];$token=bc_app_credential_token($row);
stcheck(bc_app_token_from_request(['api_key'=>$token])==='','reproduce old Android body not recognized when bearer header stripped');
foreach(['get','verify','set','reset'] as $action){$input=bc_app_security_question_input(['action'=>$action,'api_key'=>$token]);stcheck(bc_app_token_from_request($input)===$token,'released Android body carries real session for '.$action);stcheck($input['action']===$action,'action retained');}
foreach(['developer-key',str_repeat('A',43),'sk_live_key','',[],null] as $key)stcheck(!isset(bc_app_security_question_input(['api_key'=>$key])['app_token']),'no developer/legacy API-key authentication');
foreach(['',[],null,'invalid-explicit'] as $explicit){$p=bc_app_security_question_input(['app_token'=>$explicit,'api_key'=>$token]);stcheck($p['app_token']===$explicit,'explicit body credential never replaced');}
$_SERVER['HTTP_AUTHORIZATION']='Bearer invalid-header';$input=bc_app_security_question_input(['api_key'=>$token]);stcheck(!isset($input['app_token']) && bc_app_token_from_request($input)==='invalid-header','invalid bearer cannot be overridden');
unset($_SERVER['HTTP_AUTHORIZATION']);
foreach(['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','HTTP_X_APP_TOKEN'] as $header){foreach(['','Basic invalid'] as $value){$_SERVER[$header]=$value;stcheck(!isset(bc_app_security_question_input(['api_key'=>$token])['app_token']),'even empty or non-bearer explicit header blocks legacy normalization');unset($_SERVER[$header]);}}
$_SERVER['HTTP_X_APP_TOKEN']=$token;stcheck(bc_app_token_from_request([])===$token,'FastCGI-compatible session header');unset($_SERVER['HTTP_X_APP_TOKEN']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION']='Bearer '.$token;stcheck(bc_app_token_from_request([])===$token,'Apache forwarded bearer');$_SERVER=[];
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){$b=__DIR__.'/../'.$ed;
 stcheck(file_get_contents($b.'/func/bc-app-request-compat.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-app-request-compat.php'),'edition parity');
 $route=file_get_contents($b.'/web/api/security-quest.php');stcheck(strpos($route,'bc_app_security_question_input($data)')<strpos($route,'bc_fintech_require_app_account((int)$vendor_id, $data)'),'normalization still followed by mandatory real session validation');
 stcheck(strpos(file_get_contents($b.'/.htaccess'),'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}')!==false,'Apache bearer preservation');
 stcheck(strpos(file_get_contents($b.'/web/.htaccess'),'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}')!==false,$ed.' web/.htaccess Apache bearer preservation');
 stcheck(strpos(file_get_contents($b.'/api/.htaccess'),'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}')!==false,$ed.' api/.htaccess Apache bearer preservation');
}
stcheck(bc_app_normalized_token_from_legacy_body(['api_key'=>$token])===$token,'legacy Android v2_ token in api_key normalized when headers stripped');
stcheck(bc_app_normalized_token_from_legacy_body(['api_key'=>'personal-legacy-key'])==='','raw personal api_key never normalized to app token');
stcheck(bc_app_normalized_token_from_legacy_body(['api_key'=>'sk_live_merchant'])==='','developer api_key never normalized to app token');
$_SERVER['HTTP_AUTHORIZATION']='Bearer bad-token';
stcheck(bc_app_normalized_token_from_legacy_body(['api_key'=>$token])==='','explicit bearer header blocks legacy body normalization');
$_SERVER=[];
echo "PASS $n app session transport checks; normalization is not authorization\n";
