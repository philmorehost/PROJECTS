<?php
/** SaaS subscription enforcement. Never change status: renewal restores access immediately. */
function bc_vendor_subscription_expired($vendor, $now = null) {
    $date = trim((string)($vendor['expiry_date'] ?? ''));
    // NULL/zero dates are the legacy unlimited subscription representation.
    if ($date === '' || $date === '0000-00-00') return false;
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Africa/Lagos'));
    if (!$expiry || $expiry->format('Y-m-d') !== $date) return true;
    return ($now ?? time()) >= $expiry->modify('+1 day')->getTimestamp();
}

function bc_vendor_expiry_route($script) {
    // Use the executing script, never a query string or substring in REQUEST_URI.
    if (strpos($script, '/bc-spadmin/') === 0) return 'platform';
    // Verified asynchronous settlements must finish even while the storefront is offline.
    $settlements = array('/vendors-paystack.php', '/vendors-flutterwave.php', '/vendors-monnify.php',
        '/vendors-payhub.php', '/vendors-payvessel.php');
    if (in_array($script, $settlements, true)) return 'settlement';
    if (in_array($script, array('/bc-admin/Login.php', '/bc-admin/PasswordRecovery.php',
        '/bc-admin/RenewSubscription.php', '/bc-admin/SecuritySetup.php', '/web/SecuritySetup.php', '/web/logout.php', '/bc-admin/admin-logout.php', '/admin-logout.php'), true)) return 'recovery';
    return strpos($script, '/bc-admin/') === 0 ? 'admin' : 'public';
}

function bc_vendor_expiry_response($json = false) {
    http_response_code(503);
    header('Cache-Control: no-store, private, max-age=0');
    header('Retry-After: 3600');
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 'failed', 'code' => 'VENDOR_SUBSCRIPTION_EXPIRED',
            'desc' => 'This website subscription has expired. The owner must renew to restore service.'));
    } else {
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/bc-vendor-expired-view.php';
    }
    exit;
}

function bc_enforce_vendor_expiry($db) {
    if (PHP_SAPI === 'cli' || !$db) return;
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $route = bc_vendor_expiry_route($script);
    if ($route === 'platform' || $route === 'settlement') return;
    $id = (int)resolveVendorID();
    if ($id < 1) return;
    // Intentionally fresh, not the landing-page/session vendor cache.
    $q = mysqli_query($db, "SELECT expiry_date FROM sas_vendors WHERE id=$id LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row || !bc_vendor_subscription_expired($row)) return;
    $GLOBALS['bc_vendor_is_expired'] = true;
    if ($route === 'recovery') return;
    $json = strpos($script, '/api/') !== false || strpos($script, '/guest-api/') !== false
        || $script === '/api.php' || isset($_SERVER['HTTP_X_APP_SOURCE'])
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0;
    if ($route === 'admin' && !$json) {
        header('Cache-Control: no-store');
        header('Location: /bc-admin/RenewSubscription.php');
        exit;
    }
    bc_vendor_expiry_response($json);
}
