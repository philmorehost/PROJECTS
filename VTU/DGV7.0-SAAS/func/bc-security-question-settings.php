<?php
require_once __DIR__.'/bc-security-question-policy.php';
function bc_question_policy_handle($platform,$actor) {
    if(!isset($_POST['save-question-policy']) && !isset($_POST['send-question-code']))return;
    @ini_set('display_errors', '0');@ini_set('html_errors', '0');
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    bc_validate_csrf();
    $reply=function($ok,$message){echo json_encode(['status'=>$ok?'success':'error','message'=>$message]);exit;};
    global $connection_server;
    $table=$platform?'sas_super_admin':'sas_vendors';$id=(int)$actor['id'];
    try{
        $fresh=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1",[$id]);
    }catch(Throwable $e){$reply(false,'Could not load account details. Please try again.');}
    if(!$fresh || (int)$fresh['status']!==1 || !empty($fresh['is_blocked']) || !empty($fresh['force_password_change']))$reply(false,'Account restricted.');
    if(!$platform && function_exists('bc_demo_edits_allowed') && !bc_demo_edits_allowed($connection_server))$reply(false,'Demo Mode is ON — website settings are read-only. Enter the Super Admin Demo security lock key (top of this page) to make changes.');
    if(!$platform && function_exists('bc_demo_guard_edit'))bc_demo_guard_edit($connection_server);
    if(isset($_POST['send-question-code'])){
        try{
            $err=bc_stepup_send_email_otp($platform?0:$id,(string)$fresh['email'],(string)$fresh['email'],'security_question_policy','Change security question policy');
        }catch(Throwable $e){$err='Could not send the verification code. Please try again.';}
        $reply($err==='',$err===''?'SENT':$err);
    }
    if(!is_string($_POST['current_password']??null) || !bc_verify_password($_POST['current_password'],$fresh['password']))$reply(false,'Current password is incorrect.');
    $factor=is_string($_POST['otp_code']??null)?$_POST['otp_code']:'';
    $method=is_string($_POST['factor_method']??null)?$_POST['factor_method']:'';
    $input=['pin'=>is_string($_POST['pin']??null)?$_POST['pin']:'','totp_code'=>$factor,'otp_code'=>$factor,'factor_method'=>$method];
    $error=$platform?bc_stepup_spadmin_verify($fresh,$input,'security_question_policy'):bc_stepup_admin_verify($fresh,$input,'security_question_policy');
    if($error!=='')$reply(false,$error);
    $inherit=false;
    $value=json_encode(['web'=>isset($_POST['question_web']),'app'=>isset($_POST['question_app'])]);
    if((!isset($_POST['question_web']) || !isset($_POST['question_app'])) && !isset($_POST['ack_question_off']))$reply(false,'Acknowledge the security risk before disabling this gate.');
    $begun=false;
    try{
        $store=$platform?'sas_super_admin_options':'sas_settings';
        $q=mysqli_query($connection_server,'SELECT @@session.autocommit AS auto_commit');$tx=$q?mysqli_fetch_assoc($q):null;
        if(!$tx || (int)$tx['auto_commit']!==1)throw new RuntimeException('outer_transaction');
        foreach([$table,$store] as $t){
            $q=mysqli_query($connection_server,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'");
            $r=$q?mysqli_fetch_assoc($q):null;
            if($r && strtoupper($r['ENGINE'])!=='INNODB'){
                @mysqli_query($connection_server,"ALTER TABLE `$t` ENGINE=InnoDB");
                $q=mysqli_query($connection_server,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'");
                $r=$q?mysqli_fetch_assoc($q):null;
            }
            if(!$r || strtoupper($r['ENGINE'])!=='INNODB')throw new RuntimeException('schema');
        }
        $where=$platform?"option_name='security_question_policy'":"vendor_id=$id AND setting_name='security_question_policy'";
        if(!$platform){
            $q_cnt=mysqli_query($connection_server,"SELECT COUNT(*) AS cnt FROM sas_settings WHERE $where");
            $r_cnt=$q_cnt?mysqli_fetch_assoc($q_cnt):null;
            if($r_cnt && (int)$r_cnt['cnt']>1){
                $q_id_col=mysqli_query($connection_server,"SHOW COLUMNS FROM sas_settings LIKE 'id'");
                if($q_id_col && mysqli_num_rows($q_id_col)===1){
                    $q_dup=mysqli_query($connection_server,"SELECT id FROM sas_settings WHERE $where ORDER BY id DESC LIMIT 1");
                    $keep=$q_dup?mysqli_fetch_assoc($q_dup):null;
                    $keep_id=(int)($keep['id']??0);
                    if($keep_id>0)mysqli_query($connection_server,"DELETE FROM sas_settings WHERE $where AND id<>$keep_id");
                }else{
                    mysqli_query($connection_server,"DELETE FROM sas_settings WHERE $where");
                }
            }
        }
        if(mysqli_query($connection_server,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)throw new RuntimeException('outer_transaction');
        if(!mysqli_begin_transaction($connection_server))throw new RuntimeException('transaction');$begun=true;
        $locked=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1 FOR UPDATE",[$id]);
        if(!$locked || (int)$locked['status']!==1 || !empty($locked['is_blocked']) || !empty($locked['force_password_change']))throw new RuntimeException('restricted');
        foreach(['password','security_pin','totp_secret','two_factor_type'] as $key)if(($locked[$key]??'')!==($fresh[$key]??''))throw new RuntimeException('identity_changed');
        $q=mysqli_query($connection_server,"SELECT * FROM $store WHERE $where LIMIT 2 FOR UPDATE");if(!$q || mysqli_num_rows($q)>1)throw new RuntimeException('duplicate_policy');
        $exists=mysqli_num_rows($q)===1;
        $safe=mysqli_real_escape_string($connection_server,$value);
        if($exists){
            $col=$platform?'option_value':'setting_value';
            $sql="UPDATE $store SET $col='$safe' WHERE $where";
        }else{
            if($platform)$sql="INSERT INTO sas_super_admin_options (option_name,option_value) VALUES ('security_question_policy','$safe')";
            else $sql="INSERT INTO sas_settings (vendor_id,setting_name,setting_value) VALUES ($id,'security_question_policy','$safe')";
        }
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
 <label class="d-block">Current password<input class="form-control" type="password" name="current_password" autocomplete="current-password" required></label>
 <label class="d-block">Security PIN<input class="form-control" type="password" name="pin" autocomplete="off" required></label>
 <?php if(bc_gauth_enrolled($actor)): ?>
 <label class="d-block">Verification method<select class="form-select" name="factor_method"><option value="google">Google Authenticator code</option><option value="email">Email OTP</option></select></label>
 <label class="d-block">Authenticator / email OTP<input class="form-control" name="otp_code" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required></label>
 <?php else: ?>
 <input type="hidden" name="factor_method" value="email">
 <label class="d-block">Email OTP<input class="form-control" name="otp_code" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required></label>
 <?php endif; ?>
 <button type="button" class="btn btn-outline-primary my-2" data-security-send="send-question-code">Send code</button>
 <p data-wallet-status role="status"></p><button class="btn btn-primary" name="save-question-policy" value="1">Save security question policy</button>
 </form></section><?php
}
