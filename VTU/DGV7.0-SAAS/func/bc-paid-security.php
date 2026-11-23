<?php
/** Purpose-bound paid settings UI; OTP requests never mutate settings or reload forms. */
function bc_paid_verify($actor,$role,$context,$input) {
    if(!is_array($actor) || empty($actor['id']) || (int)($actor['status']??0)!==1 || !empty($actor['is_blocked']) || !empty($actor['force_password_change']))return 'This account is restricted.';
    if($role==='spadmin' && !bc_gauth_enrolled($actor))return 'Enrol Google Authenticator before changing gateway prices.';
    $factor=is_string($input['paid_factor']??null)?$input['paid_factor']:'';
    $data=['pin'=>is_string($input['pin']??null)?$input['pin']:'','totp_code'=>$factor,'otp_code'=>$factor,'factor_method'=>(string)($input['factor_method']??'auto')];
    $fn=['user'=>'bc_stepup_user_verify','admin'=>'bc_stepup_admin_verify','spadmin'=>'bc_stepup_spadmin_verify'][$role]??'';
    if(!$fn || !function_exists($fn))return 'Verification is unavailable.';
    $error=$fn($actor,$data,$context);
    if($error==='')$GLOBALS['bc_paid_action_verified']=['role'=>$role,'actor'=>$actor];
    return $error;
}
function bc_paid_otp_dispatch($actor,$role,$allowed) {
    if(!isset($_POST['paid_send_otp']))return;
    bc_validate_csrf();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    $context=is_string($_POST['paid_send_otp'])?$_POST['paid_send_otp']:'';$error='Invalid confirmation request.';
    try{$channels=bc_action_channels($role==='spadmin'?'sas_super_admin':($role==='user'?'sas_users':'sas_vendors'),$actor);}catch(Throwable $e){$channels=['email'=>false];}
    if(in_array($context,$allowed,true) && !empty($channels['email'])){
        $vid=$role==='spadmin'?0:($role==='user'?(int)$actor['vendor_id']:(int)$actor['id']);
        $identity=$role==='user'?(string)$actor['username']:(string)$actor['email'];
        $error=bc_stepup_send_email_otp($vid,$identity,(string)$actor['email'],$context,'Confirm paid account or gateway settings');
    }
    echo json_encode(['status'=>$error===''?'success':'error','message'=>$error===''?'OTP SENT':$error]);exit;
}
function bc_paid_fields($actor,$context) {
    $e=function($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');};
    $table=$context==='paid_gateway_prices'?'sas_super_admin':($context==='paid_level_self'?'sas_users':'sas_vendors');
    try{$channels=bc_action_channels($table,$actor);}catch(Throwable $e){echo '<p>Security policy unavailable.</p>';return;}
    $totp=bc_gauth_enrolled($actor)&&$channels['google'];$required=$channels['google']||$channels['email'];
    echo bc_csrf_field().'<fieldset class="border rounded p-3 my-3"><legend class="float-none w-auto fs-6">Confirm action</legend><label class="d-block">Security PIN<input type="password" class="form-control mb-2" name="pin" required autocomplete="off"></label><label class="d-block">'.($totp?'Google Authenticator code / enabled email OTP':'Email OTP (if enabled)').'<input class="form-control" name="paid_factor" pattern="[0-9]{6}" inputmode="numeric" '.($required?'required':'').' autocomplete="one-time-code"></label>';
    if($totp && $channels['email'])echo '<select name="factor_method" class="form-select"><option value="auto">Google Authenticator</option><option value="email">Email OTP</option></select>';
    if($channels['email'])echo '<button type="button" class="btn btn-outline-secondary mt-2" data-paid-otp="'.$e($context).'">Send email OTP</button>';
    if(!$required)echo '<p>PIN-only action verification is selected.</p>';
    echo '<p role="status" aria-live="polite" data-paid-status></p></fieldset>';
}
function bc_paid_reply($ok,$message) {
    if(strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='xmlhttprequest')return;
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    echo json_encode(['status'=>$ok?'success':'error','message'=>$message]);exit;
}
function bc_paid_otp_script() { ?>
<script>
document.addEventListener('submit',async function(event){
 const form=event.target;if(!form.matches('[data-paid-save]'))return;
 event.preventDefault();if(form.dataset.busy==='1')return;
 const button=event.submitter;if(!button)return;
 const status=form.querySelector('[data-paid-status]'),data=new FormData(form);data.set(button.name,button.value||'1');
 form.dataset.busy='1';button.disabled=true;status.textContent='Saving…';
 try{const res=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
 const json=await res.json();status.textContent=json.message||'Could not confirm the result. Refresh before retrying.';
 if(json.status==='success'){
   for(const input of form.querySelectorAll('input[type=password],input[name=paid_factor]'))input.value='';
   if(button.name==='buy-fintech-api'){button.textContent='Access confirmed — refresh to configure';button.dataset.completed='1';}
   if(['upgrade-user','upgrade-level'].includes(button.name)){button.textContent='Pricing level saved — refresh before another change';button.dataset.completed='1';}
 }
 }catch(error){status.textContent='Could not confirm the result. Refresh before retrying; do not assume the purchase failed.';}
 finally{form.dataset.busy='0';button.disabled=button.dataset.completed==='1';}
});
document.addEventListener('click',async function(event){
 const button=event.target.closest('[data-paid-otp]');if(!button||button.disabled)return;
 const form=button.closest('form'),status=form.querySelector('[data-paid-status]');
 button.disabled=true;status.textContent='Sending…';
 try{const data=new FormData(form);data.set('paid_send_otp',button.dataset.paidOtp);
 // Send no PIN, factor or credential fields to the OTP handler.
 for(const key of Array.from(data.keys()))if(!['_csrf_token','paid_send_otp'].includes(key))data.delete(key);
 const res=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
 const json=await res.json();status.textContent=json.message||'Could not send code. Try again.';
 if(json.status==='success'){button.textContent='SENT';if(form.elements && form.elements.factor_method)form.elements.factor_method.value='email';}
 }catch(error){status.textContent='Could not send code. Try again.';}finally{button.disabled=false;}
});
</script>
<?php }
