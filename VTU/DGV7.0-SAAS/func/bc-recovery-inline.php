<?php
/** Email recovery only; does not authorize login, reset completion or Google identity. */
function bc_recovery_ajax(){return strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest';}
function bc_recovery_reply($ok,$message){
    if(!bc_recovery_ajax())return;
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    $html='';
    if($ok)$html=bc_csrf_field().'<p role="status">Code sent. Enter it below. It expires in 10 minutes.</p><label>Recovery code<input class="pk-auth__input" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label><label>New password<input class="pk-auth__input" name="pass" type="password" autocomplete="new-password" required></label><label>Confirm new password<input class="pk-auth__input" name="confirm-pass" type="password" autocomplete="new-password" required></label><button class="pk-auth__btn" name="verify-code" type="submit">Reset password</button><button class="pk-auth__btn pk-auth__btn--ghost" name="reset-recovery" type="submit" formnovalidate>Restart recovery</button><button class="pk-auth__btn pk-auth__btn--ghost" type="button" disabled>SENT</button>';
    echo json_encode(['status'=>$ok?'success':'error','message'=>$message,'form_html'=>$html]);exit;
}
function bc_recovery_post_guard(){
    $actions=array_intersect(['send-code','verify-code','reset-recovery'],array_keys($_POST));
    if(!$actions)return;
    bc_validate_csrf(bc_recovery_ajax());
    if(count($actions)!==1){http_response_code(400);bc_recovery_reply(false,'Choose one recovery action.');exit('Invalid recovery action.');}
}
function bc_recovery_mail_state($role,$tenant,$sent){
    unset($_SESSION[$role.'-recovery-google']);
    if(!$sent){unset($_SESSION[$role.'-recovery-username'],$_SESSION[$role.'-recovery-code'],$_SESSION['recovery_challenges'][$role]);return;}
    $_SESSION['recovery_challenges'][$role]=['tenant'=>(int)$tenant,'identity'=>(string)$_SESSION[$role.'-recovery-username'],'expires'=>time()+600,'tries'=>0];
}
function bc_recovery_code_valid($role,$tenant,$code){
    $challenge=$_SESSION['recovery_challenges'][$role]??null;
    if(!$challenge||$challenge['expires']<time()||$challenge['tenant']!==(int)$tenant||$challenge['identity']!==($_SESSION[$role.'-recovery-username']??null)||$challenge['tries']>=5)return false;
    $_SESSION['recovery_challenges'][$role]['tries']++;
    return is_string($code)&&preg_match('/\A[0-9]{6}\z/D',$code) && hash_equals((string)($_SESSION[$role.'-recovery-code']??''),$code);
}
function bc_recovery_consume($role){unset($_SESSION['recovery_challenges'][$role],$_SESSION[$role.'-recovery-code'],$_SESSION[$role.'-recovery-google']);}
