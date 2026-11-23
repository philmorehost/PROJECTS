<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-service-request-idempotency.php';
$n=0;function si($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
si(bc_svc_canonical_payload(['z'=>['b'=>2,'a'=>1],'a'=>3])===['a'=>3,'z'=>['a'=>1,'b'=>2]],'nested map keys sorted');
si(bc_svc_canonical_payload([2,1])===[2,1],'list ordering preserved');
$key=bc_svc_form_key(null);si(strlen($key)===64&&ctype_xdigit($key),'random form key');si(bc_svc_form_key($key)===$key,'posted key preserved for retry');si(bc_svc_form_key(null)!==$key,'new form is distinct intent');
foreach([null,[],42,'short',str_repeat('Z',64)] as $bad)si(bc_svc_request_once(7,['id'=>2,'vendor_id'=>7],'esim','platnova',[],$bad)['code']==='invalid_key','malformed keys refuse');
si(bc_svc_request_once(8,['id'=>2,'vendor_id'=>7],'esim','platnova',[],$key)['code']==='restricted','tenant mismatch refused');
foreach(['SAAS','NON-SAAS'] as $edition){$root=__DIR__.'/../DGV7.0-'.$edition.'/';foreach(['IntlTransfer','Tuition','POSRequest','ESIM'] as $page){$s=file_get_contents($root.'web/'.$page.'.php');si(strpos($s,'bc_svc_request_once(')!==false&&strpos($s,'bc_svc_request_create(')===false,'all public intake uses durable wrapper');si(strpos($s,'name="request_key"')!==false&&strpos($s,'bc_csrf_field()')!==false&&strpos($s,'bc_validate_csrf();')!==false,'key and CSRF on all forms');}si(file_get_contents($root.'func/bc-service-request-idempotency.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-service-request-idempotency.php'),'edition parity');}
echo "$n service request idempotency boundary checks passed\n";
