<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

$id = $_POST['id'] ?? null;
action:
$action = $_POST['action'] ?? null;
if (!$id || !$action) {
    header('Location: licenses.php');
    exit();
}

try {
    // fetch request
    $stmt = $pdo->prepare("SELECT * FROM license_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        header('Location: licenses.php');
        exit();
    }

    if ($action === 'decline') {
        $u = $pdo->prepare("UPDATE license_requests SET status = 'declined', processed_at = NOW() WHERE id = ?");
        $u->execute([$id]);
        header('Location: licenses.php');
        exit();
    }

    // Approve: create license and mark request approved
    $pdo->beginTransaction();
    $new_license_key = 'MAN-' . strtoupper(bin2hex(random_bytes(6)));
    $max_domain_changes = $settings['max_domain_changes'] ?? 3;

    // create license
    $licenseInsert = getLicenseInsertDefinition($pdo);
    $params = $licenseInsert['params']($new_license_key, $req['domain'], $req['customer_email'], 'active', $max_domain_changes);
    $stmtInsert = $pdo->prepare($licenseInsert['sql']);
    $stmtInsert->execute($params);
    $license_id = $pdo->lastInsertId();

    // update request
    $u = $pdo->prepare("UPDATE license_requests SET status = 'approved', processed_at = NOW(), license_id = ? WHERE id = ?");
    $u->execute([$license_id, $id]);

    // create a transaction record for admin-created license
    $t = $pdo->prepare("INSERT INTO transactions (license_id, transaction_ref, amount, currency, status) VALUES (?, ?, 0.00, 'NGN', 'manual')");
    $t->execute([$license_id, 'MANREQ-' . strtoupper(bin2hex(random_bytes(6)))]);

    $pdo->commit();

    // Notify customer & admin via email queue
    $settings = json_decode(file_get_contents('../settings.json'), true);
    try {
        $subject = 'Your license request has been approved';
        $body = "Hello,<br><br>Your license request ({$req['request_ref']}) has been approved. Your license key is: <strong>{$new_license_key}</strong><br><br>Domain: {$req['domain']}<br><br>Thank you.";
        
        $q = $pdo->prepare("INSERT INTO email_queue (recipient_email, subject, body, status) VALUES (?, ?, ?, 'pending')");
        $q->execute([$req['customer_email'], $subject, $body]);
        
        // Also notify admin
        if (!empty($settings['admin_email'])) {
            $admin_subject = 'License Request Approved: ' . $req['request_ref'];
            $admin_body = "The license request for {$req['customer_email']} ({$req['domain']}) has been approved and license key {$new_license_key} was generated.";
            $q->execute([$settings['admin_email'], $admin_subject, $admin_body]);
        }
    } catch (Exception $e) {
        error_log('Failed to queue approval email: ' . $e->getMessage());
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error approving request: ' . $e->getMessage());
}

header('Location: licenses.php');
exit();
