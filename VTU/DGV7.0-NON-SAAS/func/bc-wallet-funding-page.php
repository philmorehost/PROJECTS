<?php
/** Server-bound wallet funding controller & renderer (DVA + server-verified gateways). */
require_once __DIR__ . '/bc-payhub-deposits.php';
require_once __DIR__ . '/bc-compliance-policy.php';
require_once __DIR__ . '/bc-payment-gateway-settlement.php';

function bc_fund_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bc_fund_amount($value) {
    if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/D', $value)) return null;
    $parts = explode('.', $value, 2);
    $minor = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    return $minor >= 10000 && $minor <= 100000000 ? $minor : null;
}

function bc_fund_actor_allowed($actor, $session) {
    return is_array($actor)
        && is_array($session)
        && is_string($session['user_session'] ?? null)
        && ($session['user_session'] ?? '') !== ''
        && ($actor['username'] ?? null) === $session['user_session']
        && (int)($actor['id'] ?? 0) > 0
        && (int)($actor['vendor_id'] ?? 0) > 0
        && (int)($actor['status'] ?? 0) === 1
        && empty($actor['is_blocked'])
        && empty($actor['force_password_change']);
}

function bc_fund_validate_post($actor, $session, $post, array $allowed_gateways = ['payhub'], bool $require_kyc = true) {
    if (!bc_fund_actor_allowed($actor, $session)) {
        throw new RuntimeException('Your account cannot request funding instructions. Sign in again or contact support.', 403);
    }
    $token = $post['_csrf_token'] ?? null;
    $expected = $session['_csrf_token'] ?? null;
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Security validation failed. Refresh the page and try again.', 403);
    }
    $gateway = $post['gateway'] ?? null;
    if (!is_string($gateway) || !in_array($gateway, $allowed_gateways, true)) {
        throw new RuntimeException('Choose an available funding method. Card checkout is currently unavailable.', 422);
    }
    $amount = bc_fund_amount($post['amount'] ?? null);
    if ($amount === null) {
        throw new RuntimeException('Enter an amount from NGN 100.00 to NGN 1,000,000.00, with at most two decimal places.', 422);
    }
    if ($require_kyc && !bc_kyc_approval_current($actor)) {
        throw new RuntimeException('Complete your current KYC approval before using virtual-account funding.', 403);
    }
    return $amount;
}

function bc_fund_instructions($db, $actor) {
    if (getenv('BC_PAYHUB_DEPOSITS_ENABLED') !== '1') {
        throw new RuntimeException('Automatic PayHub crediting is not yet enabled. Do not transfer funds until support confirms funding is available.');
    }
    $vid = (int)$actor['vendor_id'];
    $u = (string)$actor['username'];
    if (!isServiceEnabled('payhub', $vid) || !isServiceEnabled('virtual_bank_display', $vid)) {
        throw new RuntimeException('PayHub funding is unavailable for your vendor. Contact support.');
    }
    bc_ph_storage_ready($db);
    $q = bc_ph_query($db, "SELECT option_value FROM sas_super_admin_options WHERE option_name='payhub_base_url' LIMIT 2");
    $options = $q->fetch_all(MYSQLI_ASSOC);
    $base = $options[0]['option_value'] ?? 'https://merchant.payhub.com.ng/';
    if (count($options) > 1 || !is_string($base) || !preg_match('~\Ahttps://merchant\.payhub\.com\.ng/?\z~D', $base)) {
        throw new RuntimeException('provider_endpoint_requires_review');
    }
    $q = bc_ph_query($db, 'SELECT status,is_blocked FROM sas_vendors WHERE id=?', [(string)$vid]);
    $tenant = $q->fetch_assoc();
    if (!$tenant || (int)$tenant['status'] !== 1 || !empty($tenant['is_blocked'])) {
        throw new RuntimeException('Funding is unavailable for this vendor. Contact support.');
    }
    $q = bc_ph_query($db, "SELECT * FROM sas_user_banks WHERE vendor_id=? AND username=? AND LOWER(TRIM(gateway_name))='payhub' AND status=1 LIMIT 2", [(string)$vid, $u]);
    $banks = $q->fetch_all(MYSQLI_ASSOC);
    if (count($banks) !== 1 || !preg_match('/\A[0-9]{10}\z/D', (string)$banks[0]['account_number'])) {
        throw new RuntimeException('A unique approved PayHub account is not available. Contact support; do not reuse old bank details.');
    }
    $bank = $banks[0];
    $bound = bc_ph_binding($db, (string)$bank['account_number']);
    if ($bound['target'] !== 'user' || (int)$bound['vendor_id'] !== $vid || ($bound['username'] ?? null) !== $u) {
        throw new RuntimeException('Bank ownership requires review. Do not transfer funds.');
    }
    bc_ph_keys($db, $bound);
    return ['bank_name' => (string)$bank['bank_name'], 'account_name' => (string)$bank['account_name'], 'account_number' => (string)$bank['account_number']];
}

function bc_fund_public_error($e) {
    $messages = [
        "Your account cannot request funding instructions. Sign in again or contact support.",
        "Security validation failed. Refresh the page and try again.",
        "Choose an available funding method. Card checkout is currently unavailable.",
        "Enter an amount from NGN 100.00 to NGN 1,000,000.00, with at most two decimal places.",
        "Complete your current KYC approval before using virtual-account funding.",
        "Automatic PayHub crediting is not yet enabled. Do not transfer funds until support confirms funding is available.",
        "PayHub funding is unavailable for your vendor. Contact support.",
        "Funding setup requires operator verification. Do not transfer funds yet.",
        "Funding is unavailable for this vendor. Contact support.",
        "A unique approved PayHub account is not available. Contact support; do not reuse old bank details.",
        "Bank ownership requires review. Do not transfer funds.",
    ];
    return in_array($e->getMessage(), $messages, true) ? $e->getMessage() : 'Funding configuration is unavailable. Contact support; do not transfer funds yet.';
}

function bc_fund_render($model) {
    $error = $model['error'] ?? '';
    $success = $model['success'] ?? '';
    $bank = $model['bank'] ?? null;
    $available = !empty($model['available']);
    $gateways = is_array($model['gateways'] ?? null) ? $model['gateways'] : [];
    $dva_available = !empty($model['dva_available']);
    $selected = (string)($model['selected_gateway'] ?? '');
    $inline_beewave = is_array($model['inline_beewave'] ?? null) ? $model['inline_beewave'] : null;

    $has_options = !empty($gateways) || $dva_available || $available;
    if ($selected === '') {
        if (!empty($gateways)) {
            $first = reset($gateways);
            $selected = (string)($first['gateway_name'] ?? '');
        } elseif ($dva_available) {
            $selected = 'payhub_dva';
        } elseif ($available) {
            $selected = 'payhub';
        }
    }
    ?>
    <section class="section dashboard" aria-labelledby="fund-heading"><div class="row justify-content-center"><div class="col-lg-8"><div class="card shadow-sm border-0 p-4">
    <h1 id="fund-heading" class="h4 fw-bold">Fund your wallet</h1>
    <p class="text-muted">Choose an enabled payment gateway or bank transfer method below. Wallet credits happen only after server verification.</p>
    <?php if ($success !== ''): ?><div class="alert alert-success" role="alert" tabindex="-1" data-fund-success><?php echo bc_fund_e($success); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-warning" role="alert" tabindex="-1" data-fund-error><?php echo bc_fund_e($error); ?></div><?php endif; ?>
    <?php if (!empty($model['retry'])): ?><p class="alert alert-info">Opening this page does not retry a previous payment. If you already paid, contact support with the original payment reference before paying again.</p><?php endif; ?>
    <?php if ($inline_beewave !== null): ?>
    <div class="alert alert-info mb-3" data-beewave-checkout="<?php echo bc_fund_e(json_encode($inline_beewave)); ?>">
        <h2 class="h6 fw-bold mb-1">Complete BeeWave Payment</h2>
        <p class="mb-2 small">Reference: <strong><?php echo bc_fund_e($inline_beewave['reference'] ?? ''); ?></strong> · Amount: <strong>NGN <?php echo bc_fund_e(number_format((float)($inline_beewave['charge_amount'] ?? 0), 2)); ?></strong></p>
        <button type="button" id="beewaveLaunchBtn" class="btn btn-primary btn-sm">Open BeeWave Checkout</button>
    </div>
    <?php endif; ?>
    <form method="post" action="/web/Fund.php" data-wallet-funding>
    <input type="hidden" name="_csrf_token" value="<?php echo bc_fund_e($model['csrf'] ?? ''); ?>">
    <fieldset class="mb-3"><legend class="h6">Payment method</legend>
    <?php if (!empty($gateways)): ?>
        <?php foreach ($gateways as $gw): ?>
            <?php
                $gw_name = (string)($gw['gateway_name'] ?? '');
                $gw_label = (string)($gw['label'] ?? ucfirst($gw_name));
                $gw_markup = isset($gw['markup_percent']) ? (float)$gw['markup_percent'] : (float)($gw['percentage'] ?? 0.5);
                $gw_fee_label = (string)($gw['gateway_fee_label'] ?? '');
                $gw_rule = is_array($gw['gateway_fee_rule'] ?? null) ? $gw['gateway_fee_rule'] : [];
                $fee_cfg = array_merge($gw_rule, [
                    'gateway_name' => $gw_name,
                    'markup_percent' => $gw_markup,
                    'gateway_fee_label' => $gw_fee_label,
                    'fee_mode' => (string)($gw['fee_mode'] ?? 'customer_pays'),
                ]);
                $markup_text = rtrim(rtrim(number_format($gw_markup, 2), '0'), '.') . '% markup';
                $fee_note = $gw_fee_label !== ''
                    ? (' · Gateway: ' . $gw_fee_label . ' + ' . $markup_text)
                    : (' · Service fee: ' . $markup_text);
            ?>
            <label class="d-block p-3 border rounded mb-2">
                <input type="radio" name="gateway" value="<?php echo bc_fund_e($gw_name); ?>" data-fee-config="<?php echo bc_fund_e(json_encode($fee_cfg)); ?>" required <?php echo $selected === $gw_name ? 'checked' : ''; ?>>
                <strong><?php echo bc_fund_e($gw_label); ?></strong>
                <div class="small text-muted mt-1"><?php echo bc_fund_e(ltrim($fee_note, ' ·')); ?></div>
            </label>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($dva_available): ?>
        <label class="d-block p-3 border rounded mb-2">
            <input type="radio" name="gateway" value="payhub_dva" required <?php echo $selected === 'payhub_dva' ? 'checked' : ''; ?>>
            <strong>PayHub bank transfer</strong> <span class="small text-muted">· Dedicated Virtual Account</span>
        </label>
    <?php elseif (empty($gateways)): ?>
        <label class="d-block p-3 border rounded"><input type="radio" name="gateway" value="payhub" required <?php echo $available ? 'checked' : 'disabled'; ?>> PayHub bank transfer<?php echo $available ? '' : ' — currently unavailable'; ?></label>
    <?php endif; ?>
    </fieldset>
    <label for="fund-amount" class="form-label">Amount to credit wallet (NGN)</label>
    <input id="fund-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" maxlength="10" required pattern="(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?" value="<?php echo bc_fund_e($model['amount'] ?? ''); ?>" class="form-control mb-2" aria-describedby="fund-help fund-status">
    <div data-fee-preview class="border rounded bg-light p-3 mb-3 small" style="display:none;" aria-live="polite">
        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Amount Credited to Wallet</span><strong data-fee-credit>NGN 0.00</strong></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Gateway Fee (<span data-fee-gw-label>Auto</span>)</span><span data-fee-gw>NGN 0.00</span></div>
        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Service Markup (<span data-fee-markup-pct>0.5%</span>)</span><span data-fee-markup>NGN 0.00</span></div>
        <hr class="my-2">
        <div class="d-flex justify-content-between"><span class="fw-bold">Total You Will Pay</span><strong class="text-primary" data-fee-total>NGN 0.00</strong></div>
        <div class="text-muted mt-1" data-fee-note style="display:none;"></div>
    </div>
    <p id="fund-help" class="small text-muted">NGN 100.00–1,000,000.00. Exact gateway fee, service markup, and wallet credit are verified by the server.</p>
    <button id="fundProceedBtn" type="submit" class="btn btn-primary w-100 py-3" <?php echo $has_options ? '' : 'disabled'; ?>>PAY NOW — PROCEED</button>
    <p id="fund-status" role="status" aria-live="polite" class="mt-2"><?php echo $has_options ? 'Choose a payment method and amount to continue.' : 'Funding is temporarily unavailable. Do not send a new payment; contact support for existing payments.'; ?></p>
    <noscript><p>You can submit this form without JavaScript. Payment instructions or hosted checkout will open after server validation.</p></noscript>
    </form>
    <?php if (is_array($bank)): ?><section class="alert alert-info mt-4" aria-labelledby="fund-bank-title" tabindex="-1" data-fund-bank>
    <h2 id="fund-bank-title" class="h5">Your bank transfer instructions</h2>
    <p>Planned transfer: <strong>NGN <?php echo bc_fund_e($model['amount']); ?></strong>. Make the transfer using your bank app. Clicking Proceed has not sent money.</p>
    <dl><dt>Bank</dt><dd><?php echo bc_fund_e($bank['bank_name']); ?></dd><dt>Account name</dt><dd><?php echo bc_fund_e($bank['account_name']); ?></dd><dt>Account number</dt><dd class="fs-4 fw-bold" style="overflow-wrap:anywhere"><?php echo bc_fund_e($bank['account_number']); ?></dd></dl>
    <p class="mb-0">Check these details before transferring. Your wallet is credited only after a verified live payment settles, less provider fees. If you already paid, do not pay again because a confirmation is delayed; contact support with the original reference. This page is not a payment receipt.</p>
    </section><?php endif; ?>
    <p class="small mt-3"><a href="/web/VirtualBanks.php">Virtual Bank Accounts</a> · <a href="/web/KYCVerification.php">Review KYC</a> · <a href="/web/Transactions.php">Check transaction history</a></p>
    </div></div></div></section>
    <?php
}
