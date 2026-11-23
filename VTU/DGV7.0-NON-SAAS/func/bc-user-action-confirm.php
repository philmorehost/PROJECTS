<?php
/** Standalone presentation only. All mutations stay in Users.php's existing step-up gate. */
/** Called only AFTER authenticated CSRF-checked OTP dispatch. No mutation is authorized here. */
function bc_user_action_otp_ajax($error) {
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') return;
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['status' => $error === '' ? 'success' : 'error', 'message' => $error === '' ? 'OTP SENT' : (string)$error]);
    exit;
}
function bc_user_action_confirmation_url($fields) {
    return 'UserAction.php?' . http_build_query([
        'mode'=>(string)($fields['sec33_user_action'] ?? ''),
        'status'=>(string)($fields['sec33_status'] ?? ''),
        'id'=>max(0, (int)($fields['account-user-id'] ?? 0)),
        'username'=>(string)($fields['account-username'] ?? ''),
        'vid'=>max(0, (int)($fields['vid'] ?? 0))
    ], '', '&', PHP_QUERY_RFC3986);
}
function bc_user_action_confirmation($actor, $super = false) {
    if (!is_array($actor) || empty($actor['id']) || !function_exists('bc_csrf_field')) {
        http_response_code(403); echo 'Sign in again to confirm this action.'; return;
    }
    $mode = (string)($_GET['mode'] ?? '');
    $status = (string)($_GET['status'] ?? '');
    if (!in_array($mode, ['status', 'api', 'log', 'permanent'], true)
        || ($mode === 'status' && !in_array($status, ['1','2','3'], true))
        || ($mode === 'api' && !in_array($status, ['1','2'], true))) {
        http_response_code(400); echo 'Invalid user action. Return to the Users page.'; return;
    }
    $username = (string)($_GET['username'] ?? '');
    $id = max(0, (int)($_GET['id'] ?? 0));
    $vid = max(0, (int)($_GET['vid'] ?? 0));
    $enrolled = ($actor['two_factor_type'] ?? '') === 'totp' && !empty($actor['totp_secret']);
    try{$channels=bc_action_channels($super?'sas_super_admin':'sas_vendors',$actor);}catch(Throwable $e){http_response_code(503);echo 'Security policy unavailable.';return;}
    $enrolled=$enrolled && $channels['google'];
    $factor_required=$channels['google']||$channels['email'];
    $titles = ['status'=>['1'=>'Activate account','2'=>'Block account','3'=>'Archive account'],
        'api'=>['1'=>'Enable API access','2'=>'Disable API access']];
    $title = $titles[$mode][$status] ?? ($mode === 'permanent' ? 'Permanently delete account' : 'Sign in as user');
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=UTF-8');
    }
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $esc($title); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#172033;font:16px/1.5 system-ui,sans-serif;padding:24px}
main{width:100%;max-width:540px;margin:5vh auto;background:white;border:1px solid #cbd5e1;border-radius:18px;padding:28px;box-shadow:0 18px 60px #17203318}
h1{font-size:1.5rem;margin:0 0 12px}p{overflow-wrap:anywhere}label{display:block;font-weight:650;margin:18px 0 6px}
input{display:block;width:100%;min-height:48px;border:1px solid #94a3b8;border-radius:9px;padding:12px;font:inherit;background:#fff;color:#172033}
input[type=hidden]{display:none}button,a{font:inherit}button{min-height:44px;cursor:pointer;border:0;border-radius:9px;padding:10px 16px;background:#4c1d95;color:#fff;font-weight:650}
.email{background:#e2e8f0;color:#172033;margin-top:12px}.actions{display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-top:24px}a{color:#4c1d95}small{display:block;color:#475569;margin-top:12px}
@media(max-width:420px){body{padding:12px}main{margin:16px auto;padding:20px}}
</style></head><body><main data-user-action-version="standalone-1">
<a href="Users.php">← Back to Users</a>
<h1><?php echo $esc($title); ?></h1>
<?php if (!empty($_SESSION['product_purchase_response'])): ?>
<p role="status"><?php echo $esc($_SESSION['product_purchase_response']); unset($_SESSION['product_purchase_response']); ?></p>
<?php endif; ?>
<p>Confirm this action for <strong>@<?php echo $esc($username); ?></strong>. Nothing changes until your verification succeeds.</p>
<?php if ($mode === 'permanent'): ?><p><strong>This permanently removes an archived account and cannot be undone.</strong></p><?php endif; ?>
<form method="post" action="Users.php" autocomplete="off" data-inline-user-otp>
<?php echo bc_csrf_field(); ?>
<input type="hidden" name="sec33_user_action" value="<?php echo $esc($mode); ?>">
<input type="hidden" name="sec33_status" value="<?php echo $esc($status); ?>">
<input type="hidden" name="account-user-id" value="<?php echo $id; ?>">
<input type="hidden" name="account-username" value="<?php echo $esc($username); ?>">
<?php if ($super): ?><input type="hidden" name="vid" value="<?php echo $vid; ?>"><?php endif; ?>
<label for="action-pin">Security PIN</label><input id="action-pin" type="password" name="sec_pin" required>
<label for="action-factor"><?php echo $enrolled ? 'Google Authenticator code' : 'Email OTP'; ?></label>
<input id="action-factor" name="sec_factor" <?php echo $factor_required?'required':''; ?> inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="6-digit code">
<?php if($channels['email']): ?><button class="email" type="submit" name="sec33_send_code" value="1" formnovalidate>Send code</button><?php endif; ?>
<?php if($enrolled && $channels['email']): ?><label>Verification method<select name="factor_method"><option value="auto">Google Authenticator</option><option value="email">Email OTP</option></select></label><?php endif; ?>
<?php if(!$factor_required): ?><small>PIN-only action verification is selected. No second-factor code is required.</small><?php endif; ?>
<small>Verification is checked on the server. PINs and codes are sent only by POST and are never added to the URL.</small>
<p role="status" aria-live="polite" data-otp-status></p>
<div class="actions"><button type="submit"><?php echo $esc($title); ?></button><a href="Users.php">Cancel</a></div>
</form></main><script src="../asset/bc-user-action-otp.js" defer></script></body></html>
<?php
}
