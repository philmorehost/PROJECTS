<?php
/** Independent request-intake routes; catalogue membership is not settlement capability. */
require_once __DIR__.'/bc-fintech-services.php';
function bc_service_route_catalogue(){return [
 'intl_transfer'=>['label'=>'International transfer requests','page'=>'IntlTransfer.php','default'=>''],
 'tuition'=>['label'=>'Tuition requests','page'=>'Tuition.php','default'=>'platnova'],
 'pos_request'=>['label'=>'POS requests','page'=>'POSRequest.php','default'=>'flutterwave'],
];}
function bc_service_route_choices($vendor,$service){
 $definition=bc_service_route_catalogue()[$service]??null;if(!$definition||(int)$vendor<=0)return [];
 $accessible=bc_fintech_api_accessible_providers((int)$vendor);$choices=[];
 foreach(bc_svc_provider_catalogue() as $slug=>$provider){
  if(!in_array($slug,$accessible,true))continue;
  foreach($provider['services'] as $entry)if($entry['page']===$definition['page']){$choices[$slug]=$provider['label'];break;}
 }
 return $choices;
}
function bc_service_route_provider($vendor,$service){
 $definition=bc_service_route_catalogue()[$service]??null;if(!$definition)return '';
 // A deliberately disabled/invalid/revoked saved route NEVER falls back to another provider.
 $saved=getVendorOption((int)$vendor,'fintech_route_'.$service,null);
 if($saved===null)$saved=$definition['default']!==''?$definition['default']:bc_svc_active_provider((int)$vendor);
 if(!is_string($saved))return '';
 return isset(bc_service_route_choices($vendor,$service)[$saved])?$saved:'';
}
function bc_service_route_services($vendor,$service,$provider){
 if($provider===''||bc_service_route_provider($vendor,$service)!==$provider)return [];
 $page=bc_service_route_catalogue()[$service]['page']??'';
 return array_filter(bc_svc_provider_services($vendor,$provider),fn($entry)=>$entry['page']===$page);
}
/** Session actor is supplied by authenticated admin bootstrap, never posted identity. */
function bc_service_route_save($actor,$input){
 global $connection_server;
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return 'Use POST.';
 bc_validate_csrf();
 if(isset($_SESSION['spadmin_vendor_auth']))return 'Sign in as the account owner.';
 $id=(int)($actor['id']??0);if($id<=0)return 'Account unavailable.';
 if(bc_is_rate_limited('service_provider_route',(string)$id,5,300))return 'Too many attempts. Please wait.';
 $fresh=bc_q_row('SELECT * FROM sas_vendors WHERE id=? LIMIT 1',[$id]);
 if(!$fresh||!is_string($_SESSION['admin_session']??null)||!hash_equals((string)$fresh['email'],$_SESSION['admin_session']))return 'Account unavailable.';
 if(!bc_demo_edits_allowed($connection_server))return 'Demo settings are read-only.';
 $service=$input['route_service']??null;$provider=$input['route_provider']??null;
 if(!is_string($service)||!isset(bc_service_route_catalogue()[$service])||!is_string($provider))return 'Invalid service route.';
 if($provider!==''&&!isset(bc_service_route_choices($id,$service)[$provider]))return 'Provider access or service support is unavailable.';
 $error=bc_paid_verify($fresh,'admin','service_provider_route',$input);if($error!=='')return $error;
 // Recheck entitlement after factor validation. This changes only future request intake.
 if($provider!==''&&!isset(bc_service_route_choices($id,$service)[$provider]))return 'Provider access changed. Refresh before retrying.';
 try{setVendorOption($id,'fintech_route_'.$service,$provider);
  if(getVendorOption($id,'fintech_route_'.$service,null)!==$provider)return 'Route could not be confirmed. Refresh before retrying.';
 }catch(Throwable $e){return 'Route could not be confirmed. Refresh before retrying.';}
 return '';
}
