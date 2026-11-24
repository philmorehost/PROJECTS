<?php
require_once __DIR__.'/bc-security-question-policy.php';
function bc_question_policy_handle($platform,$actor) {
    if(!isset($_POST['save-question-policy']) && !isset($_POST['send-question-code']))return;
    bc_validate_csrf();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    $reply=function($ok,$message){echo json_encode(['status'=>$ok?'success':'error','message'=>$message]);exit;};
    global $connection_server;
    $table=$platform?'sas_super_admin':'sas_vendors';$id=(int)$actor['id'];
    $fresh=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1",[$id]);
    if(!$fresh || (int)$fresh['status']!==1 || !empty($fresh['is_blocked']) || !empty($fresh['force_password_change']))$reply(false,'Account restricted.');
    if(!$platform && function_exists('bc_demo_guard_edit'))bc_demo_guard_edit($connection_server);
    if(isset($_POST['send-question-code'])){
        if(bc_gauth_enrolled($fresh))$reply(false,'Use your enrolled authenticator to verify a security-policy change.');
        $err=bc_stepup_send_email_otp($platform?0:$id,(string)$fresh['email'],(string)$fresh['email'],'security_question_policy','Change security question policy');
        $reply($err==='',$err===''?'SENT':$err);
    }
    if(!is_string($_POST['current_password']??null) || !bc_verify_password($_POST['current_password'],$fresh['password']))$reply(false,'Current password is incorrect.');
    $factor=is_string($_POST['otp_code']??null)?$_POST['otp_code']:'';
    $input=['pin'=>is_string($_POST['pin']??null)?$_POST['pin']:'','totp_code'=>$factor,'otp_code'=>$factor];
    $error=$platform?bc_stepup_spadmin_verify($fresh,$input,'security_question_policy'):bc_stepup_admin_verify($fresh,$input,'security_question_policy');
    if($error!=='')$reply(false,$error);
    $inherit=false;
    $value=json_encode(['web'=>isset($_POST['question_web']),'app'=>isset($_POST['question_app'])]);
    if((!isset($_POST['question_web']) || !isset($_POST['question_app'])) && !isset($_POST['ack_question_off']))$reply(false,'Acknowledge the security risk before disabling this gate.');
    $begun=false;
    try{
        $store=$platform?'sas_super_admin_options':'sas_settings';
        foreach([$table,$store] as $t){$q=mysqli_query($connection_server,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'");$r=$q?mysqli_fetch_assoc($q):null;if(!$r || strtoupper($r['ENGINE'])!=='INNODB')throw new RuntimeException('schema');}
        $q=mysqli_query($connection_server,'SELECT @@session.autocommit AS auto_commit');$tx=$q?mysqli_fetch_assoc($q):null;
        if(!$tx || (int)$tx['auto_commit']!==1 || mysqli_query($connection_server,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)throw new RuntimeException('outer_transaction');
        if(!mysqli_begin_transaction($connection_server))throw new RuntimeException('transaction');$begun=true;
        $locked=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1 FOR UPDATE",[$id]);
        if(!$locked || (int)$locked['status']!==1 || !empty($locked['is_blocked']) || !empty($locked['force_password_change']))throw new RuntimeException('restricted');
        foreach(['password','security_pin','totp_secret','two_factor_type'] as $key)if(($locked[$key]??'')!==($fresh[$key]??''))throw new RuntimeException('identity_changed');
        $where=$platform?"option_name='security_question_policy'":"vendor_id=$id AND setting_name='security_question_policy'";
        $q=mysqli_query($connection_server,"SELECT * FROM $store WHERE $where LIMIT 2 FOR UPDATE");if(!$q || mysqli_num_rows($q)>1)throw new RuntimeException('duplicate_policy');
        $safe=mysqli_real_escape_string($connection_server,$value);
        if($platform)$sql="INSERT INTO sas_super_admin_options (option_name,option_value) VALUES ('security_question_policy','$safe') ON DUPLICATE KEY UPDATE option_value='$safe'";
        else $sql="INSERT INTO sas_settings (vendor_id,setting_name,setting_value) VALUES ($id,'security_question_policy','$safe') ON DUPLICATE KEY UPDATE setting_value='$safe'";
        if(!mysqli_query($connection_server,$sql))throw new RuntimeException('save');
        $q=mysqli_query($connection_server,"SELECT * FROM $store WHERE $where LIMIT 2");$r=$q?mysqli_fetch_assoc($q):null;
        if(!$r || mysqli_num_rows($q)!==1 || $r[$platform?'option_value':'setting_value']!==$value)throw new RuntimeException('unconfirmed');
        if(!mysqli_commit($connection_server))throw new RuntimeException('commit');
    }catch(Throwable $e){if($begun)mysqli_rollback($connection_server);$reply(false,'Policy save could not be confirmed. Refresh before retrying.');}
    unset($_SESSION['bc_gauth_unlock'],$_SESSION['super_admin_options_cache']);
    if(function_exists('bc_sec_audit'))bc_sec_audit($platform?0:$id,$platform?'spadmin':'admin',(string)$fresh['email'],'security_question_policy_changed',$platform?'platform':'tenant',$inherit?'Inherit platform defaults':$value);
    $reply(true,'Security question policy saved. Enrolments, login/recovery checks, forced-reset requirements and financial holds are unchanged. Refresh before another change.');
}
function bc_question_policy_form($actor) {
 $p=bc_security_question_policy((int)$actor['id']); ?>
 <section class="border rounded p-3 my-4"><h5>Security question — Web / App</h5>
 <p>Controls customer security-question setup and the login/idle question screen. Default: ON for both. Does not disable password, PIN, OTP, KYC restrictions or financial holds. Saved answers are retained.</p>
 <form method="post" data-channel-policy><?php echo bc_csrf_field(); ?>
 <label class="d-block"><input type="checkbox" name="question_web" <?php echo $p['web']?'checked':''; ?>> Web security question ON</label>
 <label class="d-block"><input type="checkbox" name="question_app" <?php echo $p['app']?'checked':''; ?>> App security question ON</label>
 <label class="d-block text-danger"><input type="checkbox" name="ack_question_off"> I understand that disabling the question gate reduces shared-device protection.</label>
 <label class="d-block">Current password<input class="form-control" type="password" name="current_password" required></label>
 <label class="d-block">Security PIN<input class="form-control" type="password" name="pin" required></label>
 <label class="d-block">Authenticator / email OTP<input class="form-control" name="otp_code" pattern="[0-9]{6}" required></label>
 <?php if(!bc_gauth_enrolled($actor)): ?><button type="button" class="btn btn-outline-primary" data-security-send="send-question-code">Send code</button><?php endif; ?>
 <p data-wallet-status role="status"></p><button class="btn btn-primary" name="save-question-policy" value="1">Save security question policy</button>
 </form></section><?php
}
