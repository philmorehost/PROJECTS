<?php
require_once __DIR__.'/../func/bc-secure-session.php';
bc_secure_session_start();
require __DIR__.'/../func/bc-config.php';
require_once __DIR__.'/../func/bc-wallet-funding-page.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);exit('Method not allowed.');}
if(!bc_fund_actor_allowed($get_logged_user_details??null,$_SESSION)){http_response_code(403);exit('Please sign in with an active customer account.');}
$model=['available'=>false,'error'=>'','bank'=>null,'amount'=>'','retry'=>isset($_GET['retry_ref'])];
$prefill=$_GET['amount']??'';
if(bc_fund_amount($prefill)!==null)$model['amount']=$prefill; // Editable hint, never payment evidence.
try {
    if(!bc_kyc_approval_current($get_logged_user_details))throw new RuntimeException('Complete your current KYC approval before using virtual-account funding.');
    $instructions=bc_fund_instructions($connection_server,$get_logged_user_details);
    $model['available']=true;
} catch(Throwable $e) { $model['error']=bc_fund_public_error($e); }
if($method==='POST'){
    try {
        $amount=bc_fund_validate_post($get_logged_user_details,$_SESSION,$_POST);
        $model['amount']=bc_ph_decimal($amount);
        // Recheck availability, scope and configuration on every POST. No browser authority.
        $model['bank']=bc_fund_instructions($connection_server,$get_logged_user_details);
        $model['available']=true;$model['error']='';
    } catch(Throwable $e) {
        http_response_code(in_array($e->getCode(),[403,422],true)?$e->getCode():503);$model['error']=bc_fund_public_error($e);$model['bank']=null;
        $posted=$_POST['amount']??'';
        if(is_string($posted) && strlen($posted)<=32)$model['amount']=$posted;
    }
}
$model['csrf']=bc_generate_csrf_token();
require_once __DIR__.'/../func/bc-plate-app.php';
bc_app_head('Fund Wallet | '.(string)($get_all_site_details['site_title']??'Wallet'));
bc_app_sidebar();bc_app_topbar(['title'=>'FUND WALLET','crumb'=>'Home / Fund Wallet']);
bc_fund_render($model);
?>
<script src="/jsfile/bc-wallet-funding.js?v=1" defer></script>
<?php bc_app_foot(); ?>
