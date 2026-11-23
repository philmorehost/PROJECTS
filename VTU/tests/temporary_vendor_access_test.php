<?php
$src=file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-func.php');
$start=strpos($src,'function bc_temporary_vendor_admin_session_is_valid()');
$end=strpos($src,'function bc_render_temporary_vendor_block_page',$start);
eval(substr($src,$start,$end-$start));
$connection_server=(object)[];$row=[];$query='';$days='3';
function bc_is_temporary_vendor_host(){return true;}
function bc_temporary_vendor_domain(){return 'setup.example.test';}
function bc_normalize_vendor_host($host){return $host;}
function bc_is_valid_vendor_access_hash($value){return preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
function getSuperAdminOption($key,$default){global $days;return $days;}
function mysqli_real_escape_string($db,$value){return addslashes($value);}
function mysqli_query($db,$sql){global $query;$query=$sql;return true;}
function mysqli_fetch_assoc($query){global $row;return $row;}
$n=0;function temp_check($value,$label){global $n;if(!$value)throw new RuntimeException($label);$n++;}
$token=str_repeat('a',64);
$base=['id'=>7,'email'=>'vendor@example.test','status'=>1,'is_blocked'=>0,'reg_date'=>gmdate('Y-m-d H:i:s',time()-60),'access_hash'=>$token];
$_SESSION=['tmp_vendor_host'=>'setup.example.test','tmp_vendor_override'=>7,'admin_session'=>'vendor@example.test','tmp_vendor_token_digest'=>hash('sha256',$token)];
$row=$base;temp_check(bc_temporary_vendor_admin_session_is_valid(),'valid recent session accepted');
temp_check(strpos($query,"id='7' AND email='vendor@example.test'")!==false,'session tenant and actor bound');
foreach([
 ['status'=>2],['is_blocked'=>1],['access_hash'=>str_repeat('b',64)],
 ['reg_date'=>'invalid'],['reg_date'=>gmdate('Y-m-d H:i:s',time()+86400)],
 ['reg_date'=>gmdate('Y-m-d H:i:s',time()-3*86400)]
] as $change){$row=array_merge($base,$change);temp_check(!bc_temporary_vendor_admin_session_is_valid(),'denied blocked, expired, invalid, future or revoked token');}
$row=$base;$row['reg_date']=gmdate('Y-m-d H:i:s',time()-2*86400);$days='1';temp_check(!bc_temporary_vendor_admin_session_is_valid(),'configured day reduction applies immediately');
$days='3';unset($_SESSION['tmp_vendor_token_digest']);temp_check(!bc_temporary_vendor_admin_session_is_valid(),'legacy session must reauthenticate with token');
$portal=file_get_contents(__DIR__.'/../DGV7.0-SAAS/VendorOrderPortal.php');
temp_check(strpos($portal,"header('Referrer-Policy: no-referrer')")!==false,'bearer URL not leaked through referrer');
temp_check(strpos($portal,"hash_equals(\$_SESSION['domain_csrf'], \$_POST['domain_csrf'])")!==false,'portal login CSRF checked');
temp_check(strpos($portal,'Your secure portal link (keep private)')!==false,'portal token link displayed');
echo "$n temporary vendor access checks passed\n";
