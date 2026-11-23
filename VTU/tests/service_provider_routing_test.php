<?php
$settings=[];$accessible=['klasha','flutterwave','platnova'];$verify='';$writes=0;$limited=false;$demo=true;
function getVendorOption($id,$key,$default=''){return $GLOBALS['settings'][$id.':'.$key]??$default;}
function setVendorOption($id,$key,$value){$GLOBALS['writes']++;$GLOBALS['settings'][$id.':'.$key]=$value;}
function bc_fintech_api_accessible_providers($id){return $id===7?$GLOBALS['accessible']:[];}
function bc_validate_csrf(){if(($_POST['_csrf_token']??'')!=='token')throw new RuntimeException('csrf');}
function bc_is_rate_limited($a,$b,$c,$d){return $GLOBALS['limited'];}
function bc_demo_edits_allowed($db){return $GLOBALS['demo'];}
function bc_q_row($sql,$params){return $GLOBALS['fresh'];}
function bc_stepup_admin_verify($actor,$data,$context){return $GLOBALS['verify'];}
require __DIR__.'/../DGV7.0-SAAS/func/bc-paid-security.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-service-provider-routing.php';
$n=0;function sr_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$settings['7:fintech_active_provider']='klasha';
sr_check(bc_service_route_provider(7,'intl_transfer')==='klasha','legacy default only when no independent setting');
sr_check(bc_service_route_provider(7,'tuition')==='platnova','independent tuition default');
sr_check(bc_service_route_provider(7,'pos_request')==='flutterwave','independent POS default');
sr_check(bc_service_route_provider(8,'intl_transfer')==='','tenant cannot use another entitlement');
foreach(['bybit','bogus','flutterwave',''] as $provider){$settings['7:fintech_route_tuition']=$provider;sr_check(bc_service_route_provider(7,'tuition')==='','bad unsupported or disabled tuition never falls back');}unset($settings['7:fintech_route_tuition']);
$settings['7:fintech_route_intl_transfer']='flutterwave';sr_check(bc_service_route_provider(7,'intl_transfer')==='flutterwave','explicit route independent of active Klasha');
$accessible=['klasha','platnova'];sr_check(bc_service_route_provider(7,'intl_transfer')==='','revoked explicit route never silently reroutes');$accessible[]='flutterwave';
$services=bc_service_route_services(7,'intl_transfer','flutterwave');sr_check(isset($services['global_transfer'])&&!isset($services['virtual_card'])&&!isset($services['pos_request']),'only selected page service catalogue');
sr_check(bc_service_route_services(7,'intl_transfer','klasha')===[],'posted alternative cannot choose provider');
$_SERVER['REQUEST_METHOD']='POST';$_SESSION=['admin_session'=>'owner@example.test'];$_POST=['_csrf_token'=>'token'];
$fresh=['id'=>7,'email'=>'owner@example.test','status'=>1,'is_blocked'=>0,'force_password_change'=>0];$input=['route_service'=>'tuition','route_provider'=>'platnova'];
$verify='bad factor';sr_check(bc_service_route_save($fresh,$input)==='bad factor'&&$writes===0,'verification before write');$verify='';
sr_check(bc_service_route_save($fresh,$input)===''&&$settings['7:fintech_route_tuition']==='platnova','verified independent route persisted');
sr_check($settings['7:fintech_active_provider']==='klasha','global default untouched');
$input['route_provider']='flutterwave';sr_check(bc_service_route_save($fresh,$input)!==''&&$writes===1,'unsupported service provider refused');
$input['route_provider']='';sr_check(bc_service_route_save($fresh,$input)===''&&bc_service_route_provider(7,'tuition')==='','explicit disabled saved');
$limited=true;sr_check(bc_service_route_save($fresh,$input)!=='','throttle');$limited=false;
$demo=false;sr_check(bc_service_route_save($fresh,$input)!=='','demo lock');$demo=true;
$_SESSION['spadmin_vendor_auth']=true;sr_check(bc_service_route_save($fresh,$input)!=='','impersonation denied');unset($_SESSION['spadmin_vendor_auth']);
$fresh['is_blocked']=1;sr_check(bc_service_route_save($fresh,$input)!=='','fresh blocked actor refused');$fresh['is_blocked']=0;
$_POST['_csrf_token']='wrong';try{bc_service_route_save($fresh,$input);throw new LogicException('csrf accepted');}catch(RuntimeException $e){sr_check($e->getMessage()==='csrf','csrf enforced');}
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed.'/';sr_check(file_get_contents($root.'func/bc-service-provider-routing.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-service-provider-routing.php'),'edition parity');
 foreach(['IntlTransfer.php','Tuition.php','POSRequest.php'] as $page){$s=file_get_contents($root.'web/'.$page);sr_check(strpos($s,'bc_service_route_provider(')!==false&&strpos($s,'route_snapshot')!==false&&strpos($s,'bc_validate_csrf();')!==false,'request route and stale form/CSRF guards');}
}
echo "$n independent request-route checks passed; no provider calls or settlement\n";
