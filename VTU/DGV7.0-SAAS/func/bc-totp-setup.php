<?php
class BcTotpSetupError extends RuntimeException {}
/** Same-origin, session-bound enrollment. Call only after the area's authenticated bootstrap. */
function bc_totp_setup_binding($table, $actor) {
    if (!in_array($table, ['sas_users','sas_vendors','sas_super_admin'], true) || empty($actor['id'])) throw new BcTotpSetupError('Invalid setup identity.');
    return hash('sha256', json_encode([$table,(int)$actor['id'],(int)($actor['vendor_id']??0),(string)($actor['email']??''),session_id()]));
}
function bc_totp_setup_ready($grant, $binding, $now) {
    return is_array($grant) && ($grant['binding']??'')===$binding && (int)($grant['until']??0)>$now;
}
function bc_totp_setup_process($table, $actor, $action, $input) {
    $binding=bc_totp_setup_binding($table,$actor);
    if (!empty($actor['force_password_change'])) throw new BcTotpSetupError('Change your password before setting up your authenticator.');
    if ((isset($actor['status']) && (int)$actor['status']!==1) || !empty($actor['is_blocked'])) throw new BcTotpSetupError('This account cannot complete setup.');
    if (bc_gauth_enrolled($actor)) return ['ok'=>true,'enabled'=>true,'message'=>'Already saved — Google Authenticator is enabled.'];
    if (!in_array($action,['send','verify','begin','confirm'],true)) throw new BcTotpSetupError('Choose a setup step.');
    if (bc_is_rate_limited('totp_setup_'.$action,$table.':'.(int)$actor['id'],$action==='send'?3:10,300)) throw new BcTotpSetupError('Too many attempts. Please wait five minutes.');
    $vendor=$table==='sas_vendors'?(int)$actor['id']:(int)($actor['vendor_id']??0);
    $identity='totp_setup:'.$binding;
    if ($action==='send') {
        if (!filter_var($actor['email']??'',FILTER_VALIDATE_EMAIL)) throw new BcTotpSetupError('A valid account email is required.');
        unset($_SESSION['bc_totp_setup'][$binding]);
        $code=bc_issue_otp('stepup_totp_enrollment',$identity,$vendor,[],600);
        if ($code==='') throw new BcTotpSetupError('Could not issue the email code. Please retry.');
        $mail=bc_otp_email($code,'Set up Google Authenticator');
        $sender=$table==='sas_super_admin'?'sendSuperAdminEmail':'sendVendorEmail';
        if (!function_exists($sender) || $sender((string)$actor['email'],$mail['subject'],$mail['body'])!==true) throw new BcTotpSetupError('Email could not be sent. Check mail settings or contact support, then retry.');
        return ['ok'=>true,'message'=>'Email code requested. Check your inbox and spam folder.'];
    }
    if ($action==='verify') {
        $code=$input['email_code']??'';
        if (!is_string($code) || !preg_match('/^[0-9]{6}$/D',$code) || !bc_verify_otp('stepup_totp_enrollment',$identity,$vendor,$code)) throw new BcTotpSetupError('Email code is incorrect or expired. Request a new code if needed.');
        $_SESSION['bc_totp_setup'][$binding]=['binding'=>$binding,'until'=>time()+600];
        return ['ok'=>true,'verified'=>true,'message'=>'Email verified. Generate your setup key next.'];
    }
    if (!bc_totp_setup_ready($_SESSION['bc_totp_setup'][$binding]??null,$binding,time())) throw new BcTotpSetupError('Verify your email first. Verification lasts ten minutes.');
    if ($action==='begin') {
        // Resume the same pending secret; retries must not rotate the key being scanned.
        $secret=($actor['two_factor_type']??'')==='pending'?bc_stepup_open_secret((string)($actor['totp_secret']??'')):bc_totp_begin_enrolment($table,(int)$actor['id']);
        if ($secret==='') throw new BcTotpSetupError('Could not generate a setup key. Please try again.');
        return ['ok'=>true,'secret'=>$secret,'uri'=>bc_totp_uri($secret,(string)($actor['email']??''),'VTU'),'message'=>'Key generated, not yet saved. Add it to Google Authenticator, then enter the app code and click Save.'];
    }
    if (!bc_totp_confirm_enrolment($table,$actor,(string)($input['totp_code']??''))) throw new BcTotpSetupError('App code did not match. Check your phone time and try a new code.');
    unset($_SESSION['bc_totp_setup'][$binding]);
    if (function_exists('bc_sec_audit')) bc_sec_audit($vendor,$table==='sas_users'?'user':($table==='sas_vendors'?'admin':'spadmin'),(string)($actor['username']??$actor['email']),'totp_enabled',(string)$actor['id'],'Email-verified authenticator enrollment completed');
    if (function_exists('bc_log_security_event')) bc_log_security_event('TOTP_ENABLED','enrollment',(string)($actor['email']??''),'Email-verified authenticator enrollment completed');
    return ['ok'=>true,'enabled'=>true,'message'=>'Saved — Google Authenticator is enabled. Keep the app entry; setup is complete.'];
}
function bc_totp_setup_handle($table,$actor) {
    if (!isset($_POST['totp-setup']) && !isset($_POST['totp-begin']) && !isset($_POST['totp-confirm'])) return;
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    bc_validate_csrf(true);
    try {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new BcTotpSetupError('Use the setup form.');
        if (!isset($_POST['totp-setup'])) throw new BcTotpSetupError('Use the email-verified setup form on this page.');
        if (function_exists('bc_auth_epoch_session_ok') && !bc_auth_epoch_session_ok()) throw new BcTotpSetupError('Your session expired. Sign in again.');
        bc_totp_setup_binding($table,$actor); // Validate trusted table before interpolation.
        $fresh=bc_q_row("SELECT * FROM `$table` WHERE id=? LIMIT 1",[(int)$actor['id']]);
        if (!$fresh || bc_totp_setup_binding($table,$fresh)!==bc_totp_setup_binding($table,$actor)) throw new BcTotpSetupError('Account changed. Sign in again.');
        echo json_encode(bc_totp_setup_process($table,$fresh,(string)($_POST['totp-setup']??''),$_POST));
    } catch (BcTotpSetupError $e) {
        http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Setup is temporarily unavailable. Please retry.']);
    }
    exit;
}
function bc_totp_setup_widget() {
    ?>
    <section class="bc-totp-setup border rounded-3 p-3 my-3" aria-label="Google Authenticator setup">
      <h5>Set up Google Authenticator once</h5>
      <p>1. Verify your email. 2. Add the setup key to the app. 3. Enter the app code and save. This card stays open.</p>
      <form class="bc-totp-flow" method="post">
        <?php echo bc_csrf_field(); ?>
        <button type="button" data-step="send" class="btn btn-outline-primary mb-2">Send email code</button>
        <label class="d-block">Email verification code<input name="email_code" class="form-control mb-2" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label>
        <button type="button" data-step="verify" class="btn btn-primary mb-3">Verify email</button>
        <div data-key-stage hidden>
          <button type="button" data-step="begin" class="btn btn-primary mb-3">Generate / show setup key</button>
          <div data-secret-stage hidden>
            <p>In Google Authenticator choose <strong>Add a code → Enter a setup key → Time based</strong>. Never share this key.</p>
            <div data-qr class="my-3" aria-label="Scan this QR code with Google Authenticator"></div>
            <code data-secret class="d-block p-3 text-break"></code>
            <label class="d-block">Six-digit code from Google Authenticator<input name="totp_code" class="form-control my-2" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label>
            <button type="button" data-step="confirm" class="btn btn-success">Save &amp; enable Google Authenticator</button>
          </div>
        </div>
      </form>
      <p data-setup-status role="status" aria-live="polite" class="mt-3 fw-bold">Not saved yet.</p>
      <a data-continue href="Dashboard.php" class="btn btn-primary" hidden>Continue to dashboard</a>
      <noscript>JavaScript is required for this no-reload setup. Enable it and try again.</noscript>
    </section>
    <script src="../asset/bc-qrcode.js" defer></script>
    <script src="../asset/bc-totp-setup.js" defer></script>
    <?php
}
