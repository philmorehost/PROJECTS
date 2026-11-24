<?php
/** Receiver boundary: the old unconditional hold is replaced by a gated verified workflow. */
$n=0;function phcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
putenv('BC_PAYHUB_DEPOSITS_ENABLED=0');ob_start();
foreach(['DGV7.0-SAAS'=>['users-payhub.php','vendors-payhub.php'],'DGV7.0-NON-SAAS'=>['users-payhub.php']] as $edition=>$routes){
 foreach($routes as $route){
  // Separate editions define the same shared functions. Execute one edition and check source parity below.
  if($edition==='DGV7.0-SAAS'){
   $_SERVER['REQUEST_METHOD']='POST';http_response_code(200);ob_start();require dirname(__DIR__).'/'.$edition.'/'.$route;$r=json_decode(ob_get_clean(),true);
   phcheck(http_response_code()===503 && ($r['code']??'')==='PAYHUB_DEPOSITS_ROLLOUT_REQUIRED','rollout gate no credit');
   $_SERVER['REQUEST_METHOD']='GET';ob_start();require dirname(__DIR__).'/'.$edition.'/'.$route;$r=json_decode(ob_get_clean(),true);
   phcheck(http_response_code()===405 && ($r['code']??'')==='METHOD_NOT_ALLOWED','POST only');
  }
  $s=file_get_contents(dirname(__DIR__).'/'.$edition.'/'.$route);
  phcheck(strpos($s,'bc_ph_endpoint(')!==false && strpos($s,'LEGACY_SETTLEMENT_HOLD')===false,'verified endpoint replaces unconditional stub');
 }
}
ob_end_clean();
foreach(['bc-payhub-deposits.php','bc-payhub-deposit-endpoint.php'] as $file)phcheck(file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/'.$file)===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/'.$file),'edition parity');
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){$s=file_get_contents(__DIR__.'/../'.$ed.'/func/bc-func.php');phcheck((bool)preg_match('/function processPayhubSuccess\([^\n]+\) \{\s*\/\/[^\n]+\n\s*return false;/',$s),'no legacy browser bypass');}
echo "PASS $n PayHub receiver boundary checks (no financial changes)\n";
