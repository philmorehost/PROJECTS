<?php
/** Explicit tenant/recipient resolution. Never use the request host to choose a funding recipient. */
require_once __DIR__.'/bc-incident-wallet.php';
function bc_admin_wallet_request($role,$actor,$input) {
    $text=function($key,$default='')use($input){$v=$input[$key]??$default;if(!is_string($v))throw new RuntimeException('Invalid request field.');return trim($v);};
    if(!in_array($role,['admin','spadmin'],true) || empty($actor['id']))throw new RuntimeException('Invalid authority.');
    $type=$text('type');if(!in_array($type,['credit','debit','refund'],true))throw new RuntimeException('Choose credit, debit or refund.');
    $amount=bc_incident_decimal($text('amount'));if($amount===null)throw new RuntimeException('Enter a positive amount, without commas or signs.');
    $target=$role==='admin'?'user':$text('target','vendor');
    if(!in_array($target,['user','vendor'],true))throw new RuntimeException('Invalid target.');
    $identifier=strtolower($text($role==='admin'?'user':'identifier'));
    if($identifier==='' || strlen($identifier)>225)throw new RuntimeException('Enter the recipient.');
    $vid=$role==='admin'?(int)$actor['id']:($target==='user'?(int)$text('vid','0'):0);
    if($target==='user' && $vid<=0)throw new RuntimeException('Choose the recipient vendor.');
    $original=$text('original_reference');
    if($type==='refund' && ($original==='' || !preg_match('/\A[A-Za-z0-9_.:-]{1,225}\z/D',$original)))throw new RuntimeException('Refund requires the original debit reference.');
    return ['type'=>$type,'amount'=>$amount,'target'=>$target,'identifier'=>$identifier,'vendor_id'=>$vid,'original'=>$type==='refund'?$original:''];
}
function bc_admin_wallet_apply($db,$request,$reference) {
    $esc=function($s)use($db){return mysqli_real_escape_string($db,$s);};
    $identifier=$esc($request['identifier']);$vid=(int)$request['vendor_id'];
    if($request['target']==='vendor'){
        // The form accepts an EMAIL, whereas chargeOtherVendor expects a numeric ID.
        $q=mysqli_query($db,"SELECT * FROM sas_vendors WHERE email='$identifier' LIMIT 2");
    }else $q=mysqli_query($db,"SELECT * FROM sas_users WHERE vendor_id=$vid AND username='$identifier' LIMIT 2");
    if(!$q || mysqli_num_rows($q)!==1)return ['ok'=>false,'message'=>'Recipient not uniquely found in the selected scope.'];
    $row=mysqli_fetch_assoc($q);
    if((int)$row['status']!==1 || !empty($row['is_blocked']))return ['ok'=>false,'message'=>'Recipient account is restricted.'];
    $is_vendor=$request['target']==='vendor';if($is_vendor)$vid=(int)$row['id'];
    if(!$is_vendor){
        $q=mysqli_query($db,"SELECT status,is_blocked FROM sas_vendors WHERE id=$vid LIMIT 1");$tenant=$q?mysqli_fetch_assoc($q):null;
        if(!$tenant || (int)$tenant['status']!==1 || !empty($tenant['is_blocked']))return ['ok'=>false,'message'=>'Recipient vendor is restricted.'];
    }
    $type=$request['type'];$amount=$request['amount'];$direction=$type==='debit'?'debit':'credit';
    $label=$type==='refund'?'Refund':'Wallet '.ucfirst($type);
    $description=$type==='refund'?"Refund for Ref:'".$request['original']."'":'Verified administrative wallet '.$type;
    $username=$is_vendor?'':$row['username'];
    $actor=$is_vendor?'vendor':'user:'.strtolower($username);
    $canonical=$type==='refund'?'refund:'.$request['original']:'reference:'.$reference;
    $key=hash('sha256',json_encode([$vid,$actor,$direction,$canonical]));
    $fingerprint=hash('sha256',json_encode([$vid,$actor,$direction,$amount,$type==='refund'?$request['original']:$canonical]));
    $recorded=function()use($db,$key,$fingerprint){
        $q=mysqli_query($db,"SELECT payload_hash,reference FROM sas_wallet_mutation_events WHERE event_key='$key' LIMIT 1");
        $event=$q?mysqli_fetch_assoc($q):null;
        return $event && hash_equals($fingerprint,(string)$event['payload_hash'])?(string)$event['reference']:null;
    };
    $old=$recorded();
    $result=$old!==null?'success':bc_incident_wallet_post($vid,$username,$direction,'admin_wallet',$label,$reference,'',$amount,$amount,$description,'WEB','ADMIN',1,$is_vendor);
    $actual=$recorded();
    if($result!=='success' && $actual===null)return ['ok'=>false,'message'=>(string)($GLOBALS['bc_charge_failure_reason']??'Wallet update refused. Check the source debit for refunds; historical debits without a durable event require reconciliation, not a new credit.')];
    $actual=$actual??$reference;
    return ['ok'=>true,'message'=>'Wallet '.$type.' recorded. Reference: '.$actual,'reference'=>$actual,'vendor_id'=>$vid];
}
function bc_admin_wallet_handle($role,$actor) {
    if(!isset($_POST['share-fund']) && !isset($_POST['send_stepup_code']))return;
    bc_validate_csrf();$table=$role==='spadmin'?'sas_super_admin':'sas_vendors';
    $reply=function($ok,$message){header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode(['status'=>$ok?'success':'error','message'=>$message]);exit;};
    if((int)($actor['status']??0)!==1 || !empty($actor['is_blocked']) || !empty($actor['force_password_change']))$reply(false,'Account restricted.');
    if(isset($_POST['send_stepup_code'])){
        try{$p=bc_action_channels($table,$actor);if(!$p['email'])$reply(false,'Email OTP is disabled by your action verification policy.');}catch(Throwable $e){$reply(false,'Security policy unavailable.');}
        $error=bc_stepup_send_email_otp($role==='spadmin'?0:(int)$actor['id'],(string)$actor['email'],(string)$actor['email'],'share_fund','Administrative wallet adjustment');
        $reply($error==='',$error===''?'SENT':$error);
    }
    try{
        $request=bc_admin_wallet_request($role,$actor,$_POST);
        $nonce=$_POST['wallet_request']??'';
        if(!is_string($nonce) || !isset($_SESSION['admin_wallet_requests'][$nonce]) || !preg_match('/\A[a-f0-9]{32}\z/D',$nonce))throw new RuntimeException('Refresh the form to start a new wallet request.');
        $hash=hash('sha256',json_encode([$role,(int)$actor['id'],$request]));$old=$_SESSION['admin_wallet_requests'][$nonce];
        if($old!=='' && !hash_equals($old,$hash))throw new RuntimeException('This request was already bound to different details. Refresh for a new adjustment.');
        $_SESSION['admin_wallet_requests'][$nonce]=$hash;
        $input=['pin'=>(string)($_POST['pin']??$_POST['security_pin']??''),'otp_code'=>(string)($_POST['otp_code']??''),'totp_code'=>(string)($_POST['otp_code']??''),'factor_method'=>(string)($_POST['factor_method']??'auto')];
        $error=$role==='spadmin'?bc_stepup_spadmin_verify($actor,$input,'share_fund'):bc_stepup_admin_verify($actor,$input,'share_fund');
        if($error!=='')$reply(false,$error);
        global $connection_server;
        $result=bc_admin_wallet_apply($connection_server,$request,'ADM_'.$nonce);
        if($result['ok'] && function_exists('bc_sec_audit'))bc_sec_audit($role==='spadmin'?0:(int)$actor['id'],$role,(string)$actor['email'],'wallet_'.$request['type'],$request['identifier'],$result['message']);
        $reply($result['ok'],$result['message']);
    }catch(Throwable $e){$reply(false,'Unable to complete adjustment: '.(preg_match('/\A[A-Za-z ,.\-]+\z/D',$e->getMessage())?$e->getMessage():'Check the request and database migration.'));}
}
function bc_admin_wallet_fields() {
    $nonce=bin2hex(random_bytes(16));
    if(!isset($_SESSION['admin_wallet_requests']))$_SESSION['admin_wallet_requests']=[];
    if(count($_SESSION['admin_wallet_requests'])>=64)array_shift($_SESSION['admin_wallet_requests']);
    $_SESSION['admin_wallet_requests'][$nonce]='';
    echo '<input type="hidden" name="wallet_request" value="'.$nonce.'"><label class="d-block my-2">Original debit reference (required only for Refund)<input name="original_reference" class="form-control" maxlength="225"></label><label class="d-block my-2">Verification method<select name="factor_method" class="form-select"><option value="auto">Use my policy default</option><option value="google">Google Authenticator</option><option value="email">Email OTP</option></select></label><p data-wallet-status role="status" aria-live="polite"></p>';
}
