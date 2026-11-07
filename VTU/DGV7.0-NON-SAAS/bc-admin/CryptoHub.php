<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-crypto-func.php");

$vid = $get_logged_admin_details['id'];

// Handle withdrawal approval/rejection
if (isset($_POST['action'])) {
    $ref = mysqli_real_escape_string($connection_server, $_POST['reference']);
    if ($_POST['action'] == 'approve_withdrawal') {
        $q = mysqli_query($connection_server, "SELECT * FROM sas_crypto_withdrawals WHERE reference='$ref' AND vendor_id='$vid' AND status='pending' LIMIT 1");
        if ($r = mysqli_fetch_assoc($q)) {
            $currency = $r['currency_code'];
            $amount = $r['crypto_amount'];
            $address = $r['address'];
            $ngn_amount = (float)$r['ngn_amount'];

            if ($ngn_amount > 0) {
                // Payout Toggle Enforcement
                if (!isServiceEnabled('payout')) {
                    $_SESSION['product_purchase_response'] = "Error: Payout service is currently disabled. Please enable it in Service Control.";
                    header("Location: CryptoHub.php");
                    exit();
                }

                // Check Vendor (Merchant) Balance if using platform keys
                $payhub = getGatewayDetails('payhub', $vid);
                $using_platform_keys = ($payhub['source_table'] ?? '') == 'sas_super_admin_payment_gateways' || ($payhub['vendor_id'] ?? 0) == 0;
                if ($using_platform_keys) {
                    $check_v = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1"));
                    if (($check_v['balance'] ?? 0) < $ngn_amount) {
                        $_SESSION['product_purchase_response'] = "Error: Insufficient platform balance to process this payout.";
                        header("Location: CryptoHub.php");
                        exit();
                    }
                }

                // Handle NGN Payout via PayHub
                // Address field format: "account_number (bank_code)"
                preg_match('/^(\d+)\s*\(([^)]+)\)$/', $address, $matches);
                $acc_no = $matches[1] ?? '';
                $bank_code = $matches[2] ?? '';

                $u_q = mysqli_query($connection_server, "SELECT firstname, lastname FROM sas_users WHERE username='".$r['username']."' AND vendor_id='$vid' LIMIT 1");
                $u_r = mysqli_fetch_assoc($u_q);
                $acc_name = ($u_r['firstname'] ?? '') . ' ' . ($u_r['lastname'] ?? '');

                $res = payhubInitiatePayout($ngn_amount, $bank_code, $acc_no, $acc_name, "Crypto Withdrawal - ".$r['username'], $vid);

                if (($res['status'] ?? '') == 'success') {
                    mysqli_query($connection_server, "UPDATE sas_crypto_withdrawals SET status='completed' WHERE reference='$ref'");
                    logCryptoTransaction($vid, $r['username'], 'withdrawal_ngn', $currency, $amount, 1, $res['data']['reference'] ?? ($res['reference'] ?? 'PO_'.time()), '', $acc_no, ['ngn_value' => $ngn_amount]);
                    $_SESSION['product_purchase_response'] = "NGN Withdrawal approved and processed via PayHub.";
                } else {
                    $_SESSION['product_purchase_response'] = "PayHub Error: " . ($res['message'] ?? 'Unknown');
                }
            } else {
                // Direct cash out via Plisio (Crypto to Crypto)
                $res = plisioCashOut([
                    'psys_cid' => $currency,
                    'amount' => $amount,
                    'address' => $address,
                    'type' => 'cash_out'
                ], $vid);

                if (($res['status'] ?? '') == 'success') {
                    mysqli_query($connection_server, "UPDATE sas_crypto_withdrawals SET status='completed' WHERE reference='$ref'");
                    logCryptoTransaction($vid, $r['username'], 'withdrawal', $currency, $amount, 1, $res['data']['txn_id'], '', $address);
                    $_SESSION['product_purchase_response'] = "Crypto Withdrawal approved and processed via Plisio.";
                } else {
                    $_SESSION['product_purchase_response'] = "Plisio Error: " . ($res['message'] ?? 'Unknown');
                }
            }
        }
    } elseif ($_POST['action'] == 'reject_withdrawal') {
        $reason = mysqli_real_escape_string($connection_server, $_POST['reason'] ?? 'Rejected by Admin');
        $q = mysqli_query($connection_server, "SELECT * FROM sas_crypto_withdrawals WHERE reference='$ref' AND vendor_id='$vid' AND status='pending' LIMIT 1");
        if ($r = mysqli_fetch_assoc($q)) {
            // Refund balance
            updateUserCryptoBalance($vid, $r['username'], $r['currency_code'], $r['crypto_amount'], 'credit');
            mysqli_query($connection_server, "UPDATE sas_crypto_withdrawals SET status='rejected', reject_reason='$reason' WHERE reference='$ref'");
            $_SESSION['product_purchase_response'] = "Withdrawal rejected and funds refunded. Reason: $reason";
        }
    }
    header("Location: CryptoHub.php");
    exit();
}

$sql = "SELECT * FROM sas_crypto_withdrawals WHERE vendor_id='$vid' ORDER BY created_at DESC LIMIT 100";
$withdrawals = mysqli_query($connection_server, $sql);

$sql_tx = "SELECT * FROM sas_crypto_transactions WHERE vendor_id='$vid' ORDER BY created_at DESC LIMIT 100";
$transactions = mysqli_query($connection_server, $sql_tx);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Crypto | Vendor Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle px-4 pt-4">
      <h1>CRYPTO HUB MANAGEMENT</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Crypto</li>
        </ol>
      </nav>
    </div>

    <section class="section p-4">
        <div class="row g-4">
            <!-- Payout Settings -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 p-4 text-center mb-4">
                    <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle p-3 d-inline-block mb-3">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <h5 class="fw-bold">Security Settings</h5>
                    <p class="small text-muted">Manage if crypto withdrawals require manual approval and set swap fees.</p>
                    <div class="d-grid gap-2">
                        <a href="AccountSettings.php" class="btn btn-primary rounded-pill">Account Settings</a>
                        <a href="PaymentGateway.php" class="btn btn-outline-primary rounded-pill">Gateway Configuration</a>
                        <a href="CryptoInvoices.php" class="btn btn-info rounded-pill">Invoice History</a>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 p-4 bg-dark text-white">
                    <h6 class="fw-bold mb-3"><i class="bi bi-gear-wide-connected me-2 text-info"></i>Automation (Cron)</h6>
                    <p class="small opacity-75">To ensure crypto deposits reflect automatically, set up a Cron Job in your cPanel to run every 2-5 minutes.</p>
                    <div class="bg-white bg-opacity-10 p-3 rounded mb-3 text-break border border-white border-opacity-10" style="font-family: 'Courier New', Courier, monospace; font-size: 0.8rem;">
                        php <?php echo realpath(__DIR__ . '/../func/crypto-cron.php'); ?>
                    </div>
                    <button class="btn btn-info btn-sm w-100 rounded-pill fw-bold" onclick="copyToClipboard('php <?php echo realpath(__DIR__ . '/../func/crypto-cron.php'); ?>')">
                        <i class="bi bi-clipboard me-1"></i> Copy Cron Path
                    </button>
                </div>
                <script>
                function copyToClipboard(text) {
                    navigator.clipboard.writeText(text).then(() => {
                        Swal.fire({
                            title: 'Copied!',
                            text: 'Cron path copied to clipboard.',
                            icon: 'success',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    });
                }
                </script>
            </div>

            <!-- Pending Withdrawals -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">Pending Crypto Withdrawals</h6>
                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 rounded-pill">Requires Action</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover small mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4 border-0">User</th>
                                        <th class="border-0">Amount</th>
                                        <th class="border-0">Destination</th>
                                        <th class="pe-4 border-0">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $has_pending = false;
                                    if($withdrawals):
                                        mysqli_data_seek($withdrawals, 0);
                                        while($row = mysqli_fetch_assoc($withdrawals)):
                                            if($row['status'] != 'pending') continue;
                                            $has_pending = true;
                                            $is_ngn = ((float)$row['ngn_amount'] > 0);
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold">@<?php echo $row['username']; ?></div>
                                            <div class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo $row['crypto_amount']; ?> <?php echo $row['currency_code']; ?></div>
                                            <?php if($is_ngn) echo "<div class='text-success fw-bold' style='font-size:0.75rem;'>₦".number_format($row['ngn_amount'], 2)."</div>"; ?>
                                        </td>
                                        <td>
                                            <div class="text-truncate" style="max-width: 150px;">
                                                <code class="text-primary" title="<?php echo $row['address']; ?>"><?php echo $row['address']; ?></code>
                                            </div>
                                            <?php if($is_ngn) echo "<span class='badge bg-info bg-opacity-10 text-info' style='font-size:0.6rem;'>Bank Payout</span>"; ?>
                                        </td>
                                        <td class="pe-4">
                                            <form method="post" class="d-flex flex-column gap-1">
                                                <input type="hidden" name="reference" value="<?php echo $row['reference']; ?>">
                                                <div class="d-flex gap-1">
                                                    <button type="submit" name="action" value="approve_withdrawal" class="btn btn-success btn-sm rounded-pill px-3" style="font-size:0.7rem;">Approve</button>
                                                    <button type="submit" name="action" value="reject_withdrawal" class="btn btn-outline-danger btn-sm rounded-pill px-3" style="font-size:0.7rem;" onclick="return confirmRejection(this)">Reject</button>
                                                </div>
                                                <input type="text" name="reason" class="form-control form-control-sm mt-1" placeholder="Reason for rejection" style="font-size:0.65rem;">
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; endif; ?>
                                    <?php if(!$has_pending): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                                        No pending withdrawal requests
                                    </td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Logs -->
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="fw-bold mb-0">Audit Log: Crypto Activity</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover small mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4 border-0">Context</th>
                                        <th class="border-0">Operation</th>
                                        <th class="border-0">Asset & Amount</th>
                                        <th class="pe-4 border-0">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($transactions):
                                        mysqli_data_seek($transactions, 0);
                                        while($row = mysqli_fetch_assoc($transactions)):
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold">@<?php echo $row['username']; ?></div>
                                            <div class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                        </td>
                                        <td class="text-uppercase fw-bold text-muted" style="font-size:0.7rem;"><?php echo str_replace('_', ' ', $row['type']); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $row['currency_code'])) . ".png"; ?>" style="width:16px; height:16px; margin-right:6px;">
                                                <span class="fw-bold"><?php echo (float)$row['amount']; ?> <?php echo ($row['currency_code'] == 'USDT_TRX') ? 'USDT' : $row['currency_code']; ?></span>
                                            </div>
                                        </td>
                                        <td class="pe-4">
                                            <?php
                                                $s = $row['status'];
                                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                                $txt = ($s == 1) ? 'Success' : (($s == 2) ? 'Pending' : 'Failed');
                                                echo "<span class='badge bg-$c bg-opacity-10 text-$c rounded-pill' style='font-size:0.7rem;'>$txt</span>";
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
    <script>
    function confirmRejection(btn) {
        const form = btn.closest('form');
        const reason = form.querySelector('input[name="reason"]').value.trim();
        if (!reason) {
            Swal.fire('Required', 'Please provide a reason for rejection.', 'warning');
            return false;
        }
        return confirm('Are you sure you want to reject this withdrawal?');
    }
    </script>
</body>
</html>