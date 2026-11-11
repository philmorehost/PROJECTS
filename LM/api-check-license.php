<?php
/**
 * Poll a transaction reference and report the licence.
 *
 * 8.9.0: this endpoint used to return the full licence key to anyone who knew
 * (or guessed) a Paystack reference. A reference travels in redirect URLs and
 * logs, so it is not a credential. The key is now only returned to a caller
 * who can prove ownership — the matching customer e-mail, or the browser
 * session that started the checkout. Everyone else gets a masked status.
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

$limit = lm_rate_limit(lm_rate_limit_ip('check-license', $ref), 20, 300, true);
if (!$limit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int) $limit['retry_after']);
    echo json_encode(array('success' => false, 'message' => 'Too many checks. Please wait a moment.'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, license_id, customer_email FROM transactions WHERE transaction_ref = ? LIMIT 1');
    $stmt->execute(array($ref));
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        echo json_encode(array('success' => false, 'message' => 'Processing payment...'));
        exit;
    }

    if (empty($transaction['license_id'])) {
        echo json_encode(array('success' => false, 'message' => 'Processing your license...'));
        exit;
    }

    $license_stmt = $pdo->prepare('SELECT license_key, customer_email FROM licenses WHERE id = ? LIMIT 1');
    $license_stmt->execute(array($transaction['license_id']));
    $license = $license_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license || empty($license['license_key'])) {
        echo json_encode(array('success' => false, 'message' => 'Finalizing your license...'));
        exit;
    }

    $owner = false;
    if ($claimedEmail !== '') {
        $owner = hash_equals(strtolower(trim((string) $license['customer_email'])), $claimedEmail);
    }
    if (!$owner) {
        $sessionRef = (string) ($_SESSION['lm_checkout_ref'] ?? '');
        $owner = $sessionRef !== '' && hash_equals($sessionRef, $ref);
    }

    if ($owner) {
        echo json_encode(array(
            'success' => true,
            'license_key' => $license['license_key'],
            'message' => 'License found!',
        ));
        exit;
    }

    $email = (string) $license['customer_email'];
    $masked = '';
    if (strpos($email, '@') !== false) {
        list($local, $domain) = explode('@', $email, 2);
        $masked = substr($local, 0, 1) . str_repeat('•', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    echo json_encode(array(
        'success' => true,
        'license_key' => null,
        'masked' => true,
        'message' => $masked !== ''
            ? 'Your licence is ready and the key was e-mailed to ' . $masked . '.'
            : 'Your licence is ready and the key was e-mailed to you.',
    ));

} catch (PDOException $e) {
    lm_log('payment', 'License check failed: ' . $e->getMessage());
    echo json_encode(array('success' => false, 'message' => 'Processing payment...'));
}
