<?php
/** Read-only funding instructions. Never creates a checkout, receipt, intent or wallet mutation. */
require_once __DIR__.'/bc-payhub-deposits.php';
require_once __DIR__.'/bc-compliance-policy.php';
function bc_fund_e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function bc_fund_amount($value) {
    if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/D', $value)) return null;
    $parts=explode('.',$value,2);$minor=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    return $minor>=10000 && $minor<=100000000 ? $minor : null;
}
function bc_fund_actor_allowed($actor, $session) {
    return is_array($actor) && is_array($session) && is_string($session['user_session']??null) && ($session['user_session']??'')!=='' && ($actor['username']??null)===$session['user_session'] && (int)($actor['id']??0)>0 && (int)($actor['vendor_id']??0)>0 && (int)($actor['status']??0)===1 && empty($actor['is_blocked']) && empty($actor['force_password_change']);
}
function bc_fund_validate_post($actor, $session, $post) {
    if (!bc_fund_actor_allowed($actor,$session)) throw new RuntimeException('Your account cannot request funding instructions. Sign in again or contact support.',403);
    $token=$post['_csrf_token']??null;$expected=$session['_csrf_token']??null;
    if (!is_string($token)||!is_string($expected)||$expected===''||!hash_equals($expected,$token)) throw new RuntimeException('Security validation failed. Refresh the page and try again.',403);
    if (($post['gateway']??null)!=='payhub') throw new RuntimeException('Choose an available funding method. Card checkout is currently unavailable.',422);
    $amount=bc_fund_amount($post['amount']??null);
    if ($amount===null) throw new RuntimeException('Enter an amount from NGN 100.00 to NGN 1,000,000.00, with at most two decimal places.',422);
    if (!bc_kyc_approval_current($actor)) throw new RuntimeException('Complete your current KYC approval before using virtual-account funding.',403);
    return $amount;
}
function bc_fund_instructions($db, $actor) {
    if (getenv('BC_PAYHUB_DEPOSITS_ENABLED')!=='1') throw new RuntimeException('Automatic PayHub crediting is not yet enabled. Do not transfer funds until support confirms funding is available.');
    $vid=(int)$actor['vendor_id'];$u=(string)$actor['username'];
    if (!isServiceEnabled('payhub',$vid) || !isServiceEnabled('virtual_bank_display',$vid)) throw new RuntimeException('PayHub funding is unavailable for your vendor. Contact support.');
    // Check schema read-only; do not start transactions or run migrations on this presentation page.
    bc_ph_storage_ready($db);
    $q=bc_ph_query($db,"SELECT option_value FROM sas_super_admin_options WHERE option_name='payhub_base_url' LIMIT 2");$options=$q->fetch_all(MYSQLI_ASSOC);
    $base=$options[0]['option_value']??'https://merchant.payhub.com.ng/';
    if(count($options)>1 || !is_string($base) || !preg_match('~\Ahttps://merchant\.payhub\.com\.ng/?\z~D',$base)) throw new RuntimeException('provider_endpoint_requires_review');
    $q=bc_ph_query($db,'SELECT status,is_blocked FROM sas_vendors WHERE id=?',[(string)$vid]);$tenant=$q->fetch_assoc();
    if (!$tenant || (int)$tenant['status']!==1 || !empty($tenant['is_blocked'])) throw new RuntimeException('Funding is unavailable for this vendor. Contact support.');
    $q=bc_ph_query($db,"SELECT * FROM sas_user_banks WHERE vendor_id=? AND username=? AND LOWER(TRIM(gateway_name))='payhub' AND status=1 LIMIT 2",[(string)$vid,$u]);$banks=$q->fetch_all(MYSQLI_ASSOC);
    if (count($banks)!==1 || !preg_match('/\A[0-9]{10}\z/D',(string)$banks[0]['account_number'])) throw new RuntimeException('A unique approved PayHub account is not available. Contact support; do not reuse old bank details.');
    $bank=$banks[0];$bound=bc_ph_binding($db,(string)$bank['account_number']);
    if ($bound['target']!=='user' || (int)$bound['vendor_id']!==$vid || ($bound['username']??null)!==$u) throw new RuntimeException('Bank ownership requires review. Do not transfer funds.');
    bc_ph_keys($db,$bound); // Reject disabled/ambiguous/test/fallback credentials; never render them.
    return ['bank_name'=>(string)$bank['bank_name'],'account_name'=>(string)$bank['account_name'],'account_number'=>(string)$bank['account_number']];
}
function bc_fund_public_error($e) {
    $messages=["Your account cannot request funding instructions. Sign in again or contact support.", "Security validation failed. Refresh the page and try again.", "Choose an available funding method. Card checkout is currently unavailable.", "Enter an amount from NGN 100.00 to NGN 1,000,000.00, with at most two decimal places.", "Complete your current KYC approval before using virtual-account funding.", "Automatic PayHub crediting is not yet enabled. Do not transfer funds until support confirms funding is available.", "PayHub funding is unavailable for your vendor. Contact support.", "Funding setup requires operator verification. Do not transfer funds yet.", "Funding is unavailable for this vendor. Contact support.", "A unique approved PayHub account is not available. Contact support; do not reuse old bank details.", "Bank ownership requires review. Do not transfer funds."];
    return in_array($e->getMessage(),$messages,true) ? $e->getMessage() : 'Funding configuration is unavailable. Contact support; do not transfer funds yet.';
}
function bc_fund_render($model) {
    $error=$model['error']??'';$bank=$model['bank']??null;$available=!empty($model['available']);
    ?>
    <section class="section dashboard" aria-labelledby="fund-heading"><div class="row justify-content-center"><div class="col-lg-8"><div class="card shadow-sm border-0 p-4">
    <h1 id="fund-heading" class="h4 fw-bold">Fund your wallet</h1>
    <p>Use your approved PayHub virtual account for a bank transfer. Card checkout and other legacy gateways are currently unavailable.</p>
    <?php if($error!==''): ?><div class="alert alert-warning" role="alert" tabindex="-1" data-fund-error><?php echo bc_fund_e($error); ?></div><?php endif; ?>
    <?php if(!empty($model['retry'])): ?><p class="alert alert-info">Opening this page does not retry a previous payment. If you already paid, contact support with the original payment reference before paying again.</p><?php endif; ?>
    <form method="post" action="/web/Fund.php" data-wallet-funding>
    <input type="hidden" name="_csrf_token" value="<?php echo bc_fund_e($model['csrf']??''); ?>">
    <fieldset class="mb-3"><legend class="h6">Payment method</legend>
    <label class="d-block p-3 border rounded"><input type="radio" name="gateway" value="payhub" required <?php echo $available?'checked':'disabled'; ?>> PayHub bank transfer<?php echo $available?'':' — currently unavailable'; ?></label>
    </fieldset>
    <label for="fund-amount" class="form-label">Amount you plan to transfer (NGN)</label>
    <input id="fund-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" maxlength="10" required pattern="(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?" value="<?php echo bc_fund_e($model['amount']??''); ?>" class="form-control mb-2" aria-describedby="fund-help fund-status">
    <p id="fund-help" class="small text-muted">NGN 100.00–1,000,000.00. Provider fees are deducted from the verified payment. This form does not charge your bank or reserve an amount.</p>
    <button id="fundProceedBtn" type="submit" class="btn btn-primary w-100 py-3" <?php echo $available?'':'disabled'; ?>>PAY NOW — VIEW BANK DETAILS</button>
    <p id="fund-status" role="status" aria-live="polite" class="mt-2"><?php echo $available?'Choose an amount, then view your bank transfer instructions.':'Funding is temporarily unavailable. Do not send a new payment; contact support for existing payments.'; ?></p>
    <noscript><p>You can submit this form without JavaScript. Bank details appear below after server validation.</p></noscript>
    </form>
    <?php if(is_array($bank)): ?><section class="alert alert-info mt-4" aria-labelledby="fund-bank-title" tabindex="-1" data-fund-bank>
    <h2 id="fund-bank-title" class="h5">Your bank transfer instructions</h2>
    <p>Planned transfer: <strong>NGN <?php echo bc_fund_e($model['amount']); ?></strong>. Make the transfer using your bank app. Clicking Pay Now has not sent money.</p>
    <dl><dt>Bank</dt><dd><?php echo bc_fund_e($bank['bank_name']); ?></dd><dt>Account name</dt><dd><?php echo bc_fund_e($bank['account_name']); ?></dd><dt>Account number</dt><dd class="fs-4 fw-bold" style="overflow-wrap:anywhere"><?php echo bc_fund_e($bank['account_number']); ?></dd></dl>
    <p class="mb-0">Check these details before transferring. Your wallet is credited only after a verified live payment settles, less provider fees. If you already paid, do not pay again because a confirmation is delayed; contact support with the original reference. This page is not a payment receipt.</p>
    </section><?php endif; ?>
    <p class="small mt-3"><a href="/web/KYCVerification.php">Review KYC</a> · <a href="/web/Transactions.php">Check transaction history</a></p>
    </div></div></div></section>
    <?php
}
