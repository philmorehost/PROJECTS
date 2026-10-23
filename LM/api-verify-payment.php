<?php
/**
 * Paystack payment verification + licence handout for the success page.
 *
 * Security notes (8.9.0)
 * ----------------------
 *  - A transaction reference is not a secret: it travels in a redirect URL
 *    and leaks through logs and Referer headers. It therefore cannot be the
 *    only thing needed to read a licence.
 *  - The licence key and the auto-login token are only returned when the
 *    caller also proves ownership: the matching customer e-mail must be
 *    supplied, or the request must come from the browser session that
 *    started the checkout.
 *  - Guests still see a friendly "we have e-mailed your key" state; nothing
 *    sensitive is disclosed.
 *  - Calls are throttled, and licence keys are never written to a log.
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once('db.php');

$ref = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
$claimedEmail = strtolower(trim((string) ($_GET['email'] ?? $_POST['email'] ?? '')));

if ($ref === '' || strlen($ref) > 128) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => 'No transaction reference provided'));
    exit;
}

/* One bucket per address, one per reference: hammering a single ref is the
 * enumeration pattern we care about most. */
$ipState = lm_rate_limit(lm_rate_limit_ip('verify-payment'), 30, 300, true);
$refState = lm_rate_limit(lm_rate_limit_ip('verify-payment-ref', $ref), 15, 300, true);
if (!$ipState['allowed'] || !$refState['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int) max($ipState['retry_after'], $refState['retry_after']));
    echo json_encode(array('success' => false, 'message' => 'Too many checks. Please wait a moment.'));
    exit;
}

$settings = lm_settings();
$paystack_secret_key = (string) ($settings['paystack_secret_key'] ?? '');

if ($paystack_secret_key === '') {
    echo json_encode(array('success' => false, 'message' => 'Paystack not configured'));
    exit;
}

/**
 * Is this caller allowed to see the licence for $transaction?
 *
 * @param array  $transaction
 * @param string $claimedEmail
 * @return bool
 */
function lm_verify_payment_owner(array $transaction, $claimedEmail)
{
    if ($claimedEmail !== '' && !empty($transaction['customer_email'])) {
        if (hash_equals(strtolower(trim((string) $transaction['customer_email'])), $claimedEmail)) {
            return true;
        }
    }

    // The browser that started the checkout keeps the reference in its session.
    $sessionRef = (string) ($_SESSION['lm_checkout_ref'] ?? '');
    if ($sessionRef !== '' && hash_equals($sessionRef, (string) ($transaction['transaction_ref'] ?? ''))) {
        return true;
    }

    return false;
}

/**
 * Response for a customer we have positively identified.
 *
 * @param PDO   $pdo
 * @param array $transaction
 * @return array
 */
function buildLicenseResponse(PDO $pdo, array $transaction)
{
    $licenseStmt = $pdo->prepare('SELECT license_key, customer_email FROM licenses WHERE id = ?');
    $licenseStmt->execute(array($transaction['license_id']));
    $license = $licenseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        return array('success' => false, 'message' => 'License record missing');
    }

    $autoLoginToken = $transaction['auto_login_token'] ?? null;
    $tokenCreated = $transaction['token_created_at'] ?? null;
    if (!$autoLoginToken || !$tokenCreated || (time() - strtotime($tokenCreated) > 600)) {
        $autoLoginToken = bin2hex(random_bytes(16));
        $tokenCreated = date('Y-m-d H:i:s');
        $updateStmt = $pdo->prepare('UPDATE transactions SET auto_login_token = ?, token_created_at = ? WHERE id = ?');
        $updateStmt->execute(array($autoLoginToken, $tokenCreated, $transaction['id']));
    }

    return array(
        'success' => true,
        'license_key' => $license['license_key'],
        'customer_email' => $license['customer_email'] ?? '',
        'auto_login_token' => $autoLoginToken,
        'message' => 'License found',
    );
}

/**
 * Response for an unidentified caller: confirm the payment, reveal nothing.
 *
 * @param array $transaction
 * @return array
 */
function buildMaskedResponse(array $transaction)
{
    $email = (string) ($transaction['customer_email'] ?? '');
    $masked = '';
    if ($email !== '' && strpos($email, '@') !== false) {
        list($local, $domain) = explode('@', $email, 2);
        $masked = substr($local, 0, 1) . str_repeat('•', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    return array(
        'success' => true,
        'license_key' => null,
        'auto_login_token' => null,
        'customer_email' => $masked,
        'masked' => true,
        'message' => $masked !== ''
            ? 'Your licence is ready and the key has been e-mailed to ' . $masked . '.'
            : 'Your licence is ready and the key has been e-mailed to you.',
    );
}

/* -----------------------------------------------------------------------
 * 1. Already processed: answer from the database.
 * -------------------------------------------------------------------- */
try {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE transaction_ref = ? LIMIT 1');
    $stmt->execute(array($ref));
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transaction && !empty($transaction['license_id'])) {
        echo json_encode(lm_verify_payment_owner($transaction, $claimedEmail)
            ? buildLicenseResponse($pdo, $transaction)
            : buildMaskedResponse($transaction));
        exit;
    }
} catch (PDOException $e) {
    lm_log('payment', 'Transaction lookup failed: ' . $e->getMessage());
}

/* -----------------------------------------------------------------------
 * 2. Ask Paystack.
 * -------------------------------------------------------------------- */
$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://api.paystack.co/transaction/verify/' . rawurlencode($ref),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => array(
        'Authorization: Bearer ' . $paystack_secret_key,
        'Content-Type: application/json',
    ),
));

$response = curl_exec($curl);
$curl_error = curl_error($curl);
$http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

lm_log('payment', 'Paystack verify ref=' . substr($ref, 0, 12) . '… http=' . $http_code . ($curl_error !== '' ? ' error=' . $curl_error : ''));

if ($curl_error !== '') {
    echo json_encode(array('success' => false, 'message' => 'Could not verify payment (connection error)'));
    exit;
}
if (empty($response)) {
    echo json_encode(array('success' => false, 'message' => 'Could not verify payment (no response)'));
    exit;
}

$response_data = json_decode($response, true);
if (!is_array($response_data) || !isset($response_data['status'])) {
    lm_log('payment', 'Unexpected Paystack response for ref ' . substr($ref, 0, 12) . '…');
    echo json_encode(array('success' => false, 'message' => 'Invalid payment response'));
    exit;
}

if (!$response_data['status']) {
    echo json_encode(array('success' => false, 'message' => 'Payment not confirmed yet'));
    exit;
}

if (($response_data['data']['status'] ?? '') !== 'success') {
    echo json_encode(array('success' => false, 'message' => 'Payment not confirmed yet'));
    exit;
}

/* -----------------------------------------------------------------------
 * 3. Payment is genuinely confirmed: create the licence once.
 * -------------------------------------------------------------------- */
try {
    $payment_data = $response_data['data'];
    $customer_email = (string) ($payment_data['customer']['email'] ?? '');
    $domain = (string) ($payment_data['metadata']['domain'] ?? 'N/A');
    $amount = ((float) $payment_data['amount']) / 100;
    $currency = (string) ($payment_data['currency'] ?? '');

    if (!filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(array('success' => false, 'message' => 'Payment confirmed but the e-mail address is missing. Please contact support.'));
        exit;
    }

    // Re-check inside a transaction: the webhook may have won the race.
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE transaction_ref = ? LIMIT 1');
    $stmt->execute(array($ref));
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($trans && !empty($trans['license_id'])) {
        echo json_encode(lm_verify_payment_owner($trans, $claimedEmail)
            ? buildLicenseResponse($pdo, $trans)
            : buildMaskedResponse($trans));
        exit;
    }

    $pdo->beginTransaction();

    $new_license_key = 'VALID-' . strtoupper(bin2hex(random_bytes(8)));
    $max_domain_changes = (int) ($settings['max_domain_changes'] ?? 3);

    $licenseInsert = getLicenseInsertDefinition($pdo);
    $licenseParams = $licenseInsert['params']($new_license_key, $domain, $customer_email, 'active', $max_domain_changes);
    $stmt = $pdo->prepare($licenseInsert['sql']);
    $stmt->execute($licenseParams);
    $license_id = (int) $pdo->lastInsertId();

    $auto_login_token = bin2hex(random_bytes(16));
    $token_created_at = gmdate('Y-m-d H:i:s');

    if ($trans) {
        $stmt = $pdo->prepare('UPDATE transactions SET license_id = ?, amount = ?, currency = ?, status = ?, auto_login_token = ?, token_created_at = ? WHERE id = ?');
        $stmt->execute(array($license_id, $amount, $currency, 'success', $auto_login_token, $token_created_at, $trans['id']));
        $transaction_id = (int) $trans['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO transactions (license_id, transaction_ref, amount, currency, status, auto_login_token, token_created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(array($license_id, $ref, $amount, $currency, 'success', $auto_login_token, $token_created_at));
        $transaction_id = (int) $pdo->lastInsertId();
    }

    $pdo->commit();

    // Never log the key itself.
    lm_log('payment', 'Licence #' . $license_id . ' created for ref ' . substr($ref, 0, 12) . '…');

    send_license_email($customer_email, $new_license_key, $domain, $settings);

    $fresh = array(
        'id' => $transaction_id,
        'license_id' => $license_id,
        'transaction_ref' => $ref,
        'customer_email' => $customer_email,
        'auto_login_token' => $auto_login_token,
        'token_created_at' => $token_created_at,
    );

    echo json_encode(lm_verify_payment_owner($fresh, $claimedEmail)
        ? array(
            'success' => true,
            'license_key' => $new_license_key,
            'customer_email' => $customer_email,
            'auto_login_token' => $auto_login_token,
            'message' => 'License created and email sent',
        )
        : buildMaskedResponse($fresh));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    lm_log('payment', 'Could not create licence for ref ' . substr($ref, 0, 12) . '…: ' . $e->getMessage());
    echo json_encode(array('success' => false, 'message' => 'Could not finish setting up the licence. Please contact support with your payment reference.'));
}

/**
 * Send the licence e-mail, falling back to the retry queue.
 *
 * @param string $email
 * @param string $license_key
 * @param string $domain
 * @param array  $settings
 * @return void
 */
function send_license_email($email, $license_key, $domain, $settings)
{
    require_once __DIR__ . '/includes/mailer.php';

    $template_file = __DIR__ . '/email_template.html';
    $template = is_file($template_file)
        ? (string) file_get_contents($template_file)
        : get_default_email_template();

    $site_name = $settings['site_name'] ?? 'License Manager';
    $body = str_replace(
        array('{license_key}', '{domain}', '{site_name}', '{year}', '{site_url}'),
        array(
            htmlspecialchars($license_key, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'),
            gmdate('Y'),
            htmlspecialchars(lm_site_url(), ENT_QUOTES, 'UTF-8'),
        ),
        $template
    );

    $alt = "Thank you for your purchase!\n\n"
        . "Your license key: {$license_key}\n"
        . "Domain: {$domain}\n\n"
        . "Paste the key into your script's licence settings to activate it.\n\n"
        . $site_name;

    $subject = 'Your License Key - ' . $site_name;

    $result = lm_send_mail($email, $subject, $body, $alt, array(
        'bcc' => $settings['admin_email'] ?? '',
    ));

    if ($result['ok']) {
        lm_log('mail', 'Licence e-mail sent to ' . $email . ' via ' . $result['transport']);
        return;
    }

    lm_log('mail', 'Licence e-mail failed for ' . $email . ': ' . $result['error']);

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        lm_queue_email($GLOBALS['pdo'], $email, $subject, $body, $alt, array('priority' => 1));
    }
}

/**
 * Fallback template used when email_template.html is missing.
 *
 * @return string
 */
function get_default_email_template()
{
    return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .license-box { background: #fff; border: 2px dashed #d1d5db; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .license-key { font-size: 18px; font-weight: bold; font-family: monospace; color: #3b82f6; }
    </style>
</head>
<body>
    <div class="container">
        <h1>{site_name}</h1>
        <p>Thank you for your purchase!</p>
        <div class="license-box">
            <p>Your license key</p>
            <div class="license-key">{license_key}</div>
        </div>
        <p>Domain: {domain}</p>
        <p>Paste the key into your script's licence settings to activate it.</p>
        <p>&copy; {year} {site_name}</p>
    </div>
</body>
</html>
HTML;
}
