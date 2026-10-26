<?php
session_start();
include("../func/bc-spadmin-config.php");
include_once("../func/bc-func.php");
include_once("../func/whmp-func.php");

if (empty($_SESSION['domain_request_csrf'])) $_SESSION['domain_request_csrf'] = bin2hex(random_bytes(32));
$admin_id = (int)($get_logged_spadmin_details['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    $request_id = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = (string)$_POST['request_action'];
    $note = trim(substr(strip_tags((string)($_POST['decision_note'] ?? '')), 0, 500));

    if (!hash_equals($_SESSION['domain_request_csrf'], $token) || !$request_id || !in_array($action, ['approve', 'reject'], true)) {
        $_SESSION['page_alert'] = 'Invalid review request. Refresh the page and try again.';
        header('Location: DomainChangeRequests.php');
        exit;
    }

    // Atomically claim a pending request so two administrators cannot approve it simultaneously.
    $claim = mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='processing' WHERE id='" . (int)$request_id . "' AND status='pending'");
    if (!$claim || mysqli_affected_rows($connection_server) !== 1) {
        $_SESSION['page_alert'] = 'This request has already been reviewed or is currently being processed.';
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $rq = mysqli_query($connection_server, "SELECT r.*, v.email AS vendor_email, v.firstname, v.lastname, v.website_url, v.app_base_url, v.balance FROM sas_vendor_domain_requests r JOIN sas_vendors v ON v.id=r.vendor_id WHERE r.id='" . (int)$request_id . "' LIMIT 1");
    $request = $rq ? mysqli_fetch_assoc($rq) : null;
    if (!$request) {
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='pending' WHERE id='" . (int)$request_id . "' AND status='processing'");
        $_SESSION['page_alert'] = 'The vendor or domain request could not be found.';
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $requested_domain = strtolower(trim($request['requested_domain']));
    $vendor_id = (int)$request['vendor_id'];
    $request_id_sql = (int)$request_id;
    $decision_note = '';

    if ($action === 'reject') {
        $decision_note = $note !== '' ? $note : 'Request rejected by super-admin.';
        $decision_esc = mysqli_real_escape_string($connection_server, $decision_note);
        $ok = mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='rejected', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = $ok ? 'Domain request rejected. The vendor can see the decision in the portal.' : 'Could not save the rejection. Please retry.';
        header('Location: DomainChangeRequests.php');
        exit;
    }

    if (!bc_is_valid_domain_name($requested_domain) || bc_is_temporary_vendor_host($requested_domain)) {
        $decision_note = 'The requested domain is invalid or reserved for temporary vendor access.';
        $decision_esc = mysqli_real_escape_string($connection_server, $decision_note);
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='rejected', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = $decision_note;
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $expected_current = strtolower(bc_normalize_vendor_host($request['current_domain']));
    $live_current = strtolower(bc_normalize_vendor_host($request['app_base_url'] ?: $request['website_url']));
    if ($request['request_type'] === 'change' && !hash_equals($expected_current, $live_current)) {
        $decision_note = 'The vendor domain changed after this request was submitted. Ask the vendor to submit a new request.';
        $decision_esc = mysqli_real_escape_string($connection_server, $decision_note);
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='rejected', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = $decision_note;
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $domain_esc = mysqli_real_escape_string($connection_server, $requested_domain);
    $duplicate_q = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id<>'$vendor_id' AND (LOWER(website_url)='$domain_esc' OR LOWER(app_base_url)='$domain_esc') LIMIT 1");
    if ($duplicate_q && mysqli_num_rows($duplicate_q) > 0) {
        $decision_note = 'This domain is already assigned to another vendor account.';
        $decision_esc = mysqli_real_escape_string($connection_server, $decision_note);
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='rejected', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = $decision_note;
        header('Location: DomainChangeRequests.php');
        exit;
    }

    if ($request['request_type'] === 'change') {
        $update = mysqli_query($connection_server, "UPDATE sas_vendors SET website_url='$domain_esc', app_base_url='$domain_esc' WHERE id='$vendor_id'");
        if (!$update) {
            mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='pending' WHERE id='$request_id_sql' AND status='processing'");
            $_SESSION['page_alert'] = 'Could not update the vendor domain. Request restored to pending; please retry.';
            header('Location: DomainChangeRequests.php');
            exit;
        }
        // Keep cPanel addon-domain configuration aligned with the approved host where it is configured.
        include_once(__DIR__ . '/../func/cpanel-func.php');
        if ($live_current !== '' && !bc_is_temporary_vendor_host($live_current)) cpanel_remove_addon_domain($live_current);
        cpanel_add_addon_domain($requested_domain);
        $decision_note = $note !== '' ? $note : 'Existing-domain change approved.';
        $decision_esc = mysqli_real_escape_string($connection_server, $decision_note);
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='approved', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = 'Domain change approved and applied to the vendor account.';
        header('Location: DomainChangeRequests.php');
        exit;
    }

    // Registration request: use only WHMP, recheck availability, and debit the accepted NGN quote
    // only after approval. A failed WHMP request is refunded and returned to pending for retry.
    $quote = (float)$request['quoted_price'];
    if ($quote <= 0 || !whmp_is_configured()) {
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='pending' WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = 'A positive WHMP quote and active WHMP configuration are required. Request restored to pending; no charge was made.';
        header('Location: DomainChangeRequests.php');
        exit;
    }
    $availability = whmp_check_domain_availability($requested_domain);
    if (($availability['status'] ?? '') !== 'available') {
        $decision_note = ($availability['status'] ?? '') === 'registered' ? 'Domain is no longer available in WHMP.' : 'WHMP could not verify domain availability: ' . ($availability['message'] ?? 'unknown error');
        $decision_esc = mysqli_real_escape_string($connection_server, substr($decision_note, 0, 500));
        if (($availability['status'] ?? '') === 'registered') {
            mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='rejected', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
        } else {
            mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='pending', decision_note='$decision_esc' WHERE id='$request_id_sql' AND status='processing'");
        }
        $_SESSION['page_alert'] = $decision_note;
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $debit = mysqli_query($connection_server, "UPDATE sas_vendors SET balance=balance-" . number_format($quote, 2, '.', '') . " WHERE id='$vendor_id' AND balance >= " . number_format($quote, 2, '.', ''));
    if (!$debit || mysqli_affected_rows($connection_server) !== 1) {
        mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='pending' WHERE id='$request_id_sql' AND status='processing'");
        $_SESSION['page_alert'] = 'The vendor has insufficient balance for the quoted registration fee. Request restored to pending; no charge was made.';
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $registration = whmp_register_domain($requested_domain);
    if (($registration['status'] ?? '') !== 'success') {
        $refund = mysqli_query($connection_server, "UPDATE sas_vendors SET balance=balance+" . number_format($quote, 2, '.', '') . " WHERE id='$vendor_id'");
        $failure = substr((string)($registration['message'] ?? 'WHMP registration failed.'), 0, 500);
        $failure_esc = mysqli_real_escape_string($connection_server, $failure);
        if ($refund && mysqli_affected_rows($connection_server) === 1) {
            mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='pending', decision_note='WHMP registration failed; wallet refunded: $failure_esc' WHERE id='$request_id_sql' AND status='processing'");
            $_SESSION['page_alert'] = 'WHMP registration failed and the vendor wallet was refunded. Request restored to pending: ' . $failure;
        } else {
            // Keep the request locked to prevent a second debit while a failed refund is reconciled.
            error_log('[DomainChangeRequests] Failed to refund vendor ' . $vendor_id . ' after WHMP registration error for ' . $requested_domain . '.');
            $_SESSION['page_alert'] = 'WHMP registration failed, but the wallet refund could not be confirmed. The request is locked for manual reconciliation; do not retry it yet.';
        }
        header('Location: DomainChangeRequests.php');
        exit;
    }

    $expiry = date('Y-m-d', strtotime('+1 year'));
    $vendor_update = mysqli_query($connection_server, "UPDATE sas_vendors SET website_url='$domain_esc', app_base_url='$domain_esc', domain_expiry_date='$expiry' WHERE id='$vendor_id'");
    $decision_note = $vendor_update ? ($note !== '' ? $note : 'WHMP domain registration approved and assigned.') : 'WHMP registered the domain and charged the quoted fee, but the vendor account update failed. Manual account repair is required; do not retry registration.';
    $decision_esc = mysqli_real_escape_string($connection_server, $decision_note);
    mysqli_query($connection_server, "UPDATE sas_vendor_domain_requests SET status='approved', decision_note='$decision_esc', reviewed_by='$admin_id', reviewed_at=NOW() WHERE id='$request_id_sql' AND status='processing'");
    if ($vendor_update) {
        include_once(__DIR__ . '/../func/cpanel-func.php');
        cpanel_add_addon_domain($requested_domain);
        $_SESSION['page_alert'] = 'WHMP registered and assigned ' . $requested_domain . '; ₦' . number_format($quote, 2) . ' was charged to the vendor wallet.';
    } else {
        error_log('[DomainChangeRequests] WHMP registered ' . $requested_domain . ' for vendor ' . $vendor_id . ' but the vendor update failed.');
        $_SESSION['page_alert'] = $decision_note;
    }
    header('Location: DomainChangeRequests.php');
    exit;
}

$pending_result = mysqli_query($connection_server, "SELECT r.*, v.firstname, v.lastname, v.email, v.website_url, v.app_base_url, v.balance FROM sas_vendor_domain_requests r JOIN sas_vendors v ON v.id=r.vendor_id WHERE r.status='pending' ORDER BY r.created_at ASC");
$history_result = mysqli_query($connection_server, "SELECT r.*, v.firstname, v.lastname, v.email FROM sas_vendor_domain_requests r JOIN sas_vendors v ON v.id=r.vendor_id WHERE r.status IN ('approved','rejected','processing') ORDER BY r.reviewed_at DESC, r.created_at DESC LIMIT 100");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Domain Change Requests | Super Admin</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        body { background: #f6f9ff; }
        .card { border: 0; border-radius: 12px; box-shadow: 0 0.125rem .25rem rgba(0,0,0,.075); }
        .table td { vertical-align: middle; }
        .domain { font-family: monospace; font-weight: 600; word-break: break-word; }
        .decision-note { min-width: 180px; }
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>
<div class="pagetitle">
    <h1>Domain Requests</h1>
    <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item">Manage Vendors</li><li class="breadcrumb-item active">Domain Requests</li></ol></nav>
</div>
<section class="section">
    <?php if (isset($_SESSION['page_alert'])): $alert = (string)$_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($alert, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header bg-white border-0 py-3"><h5 class="mb-0 fw-bold">Pending review</h5><small class="text-muted">Existing-domain changes have no registration charge. New registrations use WHMP and are charged only after approval.</small></div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Vendor</th><th>Request</th><th>Current domain</th><th>Requested domain</th><th>Quote / balance</th><th>Submitted</th><th>Vendor note</th><th>Decision</th></tr></thead>
                <tbody>
                <?php if ($pending_result && mysqli_num_rows($pending_result) > 0): while ($row = mysqli_fetch_assoc($pending_result)): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(trim($row['firstname'] . ' ' . $row['lastname']), ENT_QUOTES, 'UTF-8'); ?></strong><br><small><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                        <td><span class="badge <?php echo $row['request_type'] === 'registration' ? 'text-bg-primary' : 'text-bg-info'; ?>"><?php echo htmlspecialchars(ucfirst($row['request_type'])); ?></span></td>
                        <td class="domain"><?php echo htmlspecialchars($row['current_domain'] ?: $row['app_base_url'] ?: $row['website_url'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="domain"><?php echo htmlspecialchars($row['requested_domain'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php if ($row['request_type'] === 'registration'): ?>₦<?php echo number_format((float)$row['quoted_price'], 2); ?><br><small class="text-muted">Balance ₦<?php echo number_format((float)$row['balance'], 2); ?></small><?php else: ?><span class="text-muted">No fee</span><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['vendor_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <form method="post" class="d-grid gap-2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['domain_request_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="text" name="decision_note" maxlength="500" class="form-control form-control-sm decision-note" placeholder="Optional note to vendor">
                                <div class="d-flex gap-2">
                                    <button type="submit" name="request_action" value="approve" class="btn btn-success btn-sm" onclick="return confirm('Approve this domain request?<?php echo $row['request_type'] === 'registration' ? ' The quoted amount will be charged and WHMP will register the domain.' : ' The vendor domain will be updated.'; ?>');"><i class="bi bi-check2 me-1"></i>Approve</button>
                                    <button type="submit" name="request_action" value="reject" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject this domain request?');"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; else: ?><tr><td colspan="8" class="text-center text-muted py-4">No domain requests are awaiting review.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header bg-white border-0 py-3"><h5 class="mb-0 fw-bold">Recent decisions and processing</h5></div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-hover"><thead><tr><th>Vendor</th><th>Type</th><th>Requested domain</th><th>Status</th><th>Decision note</th><th>Reviewed</th></tr></thead><tbody>
            <?php if ($history_result && mysqli_num_rows($history_result) > 0): while ($row = mysqli_fetch_assoc($history_result)): ?>
                <tr><td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname'] . ' (' . $row['email'] . ')', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(ucfirst($row['request_type']), ENT_QUOTES, 'UTF-8'); ?></td><td class="domain"><?php echo htmlspecialchars($row['requested_domain'], ENT_QUOTES, 'UTF-8'); ?></td><td><span class="badge <?php echo $row['status'] === 'approved' ? 'text-bg-success' : ($row['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning'); ?>"><?php echo htmlspecialchars(ucfirst($row['status']), ENT_QUOTES, 'UTF-8'); ?></span></td><td><?php echo htmlspecialchars($row['decision_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($row['reviewed_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <?php endwhile; else: ?><tr><td colspan="6" class="text-center text-muted py-4">No recent decisions.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
</section>
<script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
