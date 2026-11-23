<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-credential-binding.php';
$n=0;function cb_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
$row=['id'=>11,'vendor_id'=>7,'password'=>'synthetic-password-hash','security_pin'=>'synthetic-pin-hash','transaction_pin'=>'9362','email'=>'fixture@example.test','google_id'=>'subject'];
$token=bc_app_credential_token($row);
cb_check(is_string($token)&&strlen($token)<200&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$token),'opaque transport-compatible token');
cb_check(bc_app_credential_token_valid($token,$row),'current credential binding');
cb_check($token!==bc_app_credential_token($row),'fresh randomness');
foreach(['id'=>12,'vendor_id'=>8,'password'=>'reset-hash','security_pin'=>'reset-pin','transaction_pin'=>'5678','email'=>'changed@example.test','google_id'=>'changed-subject'] as $field=>$value){$changed=$row;$changed[$field]=$value;cb_check(!bc_app_credential_token_valid($token,$changed),'changed credential revokes '.$field);}
$changed=$row;$changed['balance']='2000';$changed['account_level']=2;cb_check(bc_app_credential_token_valid($token,$changed),'unrelated balance/pricing does not revoke');
cb_check(!bc_app_credential_token_valid(str_repeat('A',43),$row),'old random token requires sign in');
cb_check(!bc_app_credential_token_valid(substr($token,0,-1).($token[-1]==='a'?'b':'a'),$row),'tampered binding');
cb_check(bc_app_credential_token(['id'=>11,'vendor_id'=>7])===null,'missing password fails closed');
cb_check(!bc_app_credential_token_valid($token,['id'=>11,'vendor_id'=>7]),'missing lookup credentials fail closed');
foreach(['SAAS','NON-SAAS'] as $ed){$s=file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/func/bc-app-token.php');cb_check(strpos($s,'bc_app_credential_token($row)')!==false,'issuance bound to authoritative row');cb_check(strpos($s,'bc_app_credential_token_valid(trim($token),$user)')>strpos($s,"\$user = bc_app_token_user_row"),'lookup after authoritative row');cb_check(strpos($s,"bc_app_token_hash(trim(\$token))")!==false,'persisted hash still required');}
echo "$n app credential binding checks passed\n";
