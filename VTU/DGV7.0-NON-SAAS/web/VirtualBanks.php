<?php session_start();
include("../func/bc-config.php");

$username = mysqli_real_escape_string($connection_server, $get_logged_user_details["username"]);
$vendor_id = (int)$get_logged_user_details["vendor_id"];

// Check if Virtual Bank is enabled globally or for this vendor
if(!isServiceEnabled('virtual_bank_display')){
    header("Location: Dashboard.php");
    exit();
}

// Master KYC Compliance Check for Virtual Bank Generation (Always required regardless of global force_kyc for security)
$is_kyc_compliant = ($get_logged_user_details['kyc_status'] == 2);

$keys = getGatewayDetails('payhub', $vendor_id);

// Virtual account generation
$registered_virtual_bank_arr_3 = array();
$user_banks_3 = getUserVirtualBank();
if (is_array($user_banks_3)) {
    foreach ($user_banks_3 as $bank_json) {
        $bank_json = json_decode($bank_json, true);
        array_push($registered_virtual_bank_arr_3, $bank_json["bank_code"]);
    }
}

// ── Server-side PayHub Auto-Sync ──────────────────────────────────────────────
// If the user has no PayHub account yet and PayHub is configured, attempt a
// synchronous fetch-and-save before rendering the HTML.  This ensures accounts
// are visible immediately without relying solely on the async JS sync (which can
// be blocked by the shared session throttle).  A 60-second per-page cooldown
// prevents hammering the PayHub API on rapid successive refreshes.
$server_sync_result = null;
$has_payhub_account = in_array("PayHub", $registered_virtual_bank_arr_3);

if (!$has_payhub_account && !empty($keys['secret_key'])) {
    $last_vb_payhub_sync = $_SESSION['last_vb_payhub_sync'] ?? 0;
    if (time() - $last_vb_payhub_sync >= 60) {
        $_SESSION['last_vb_payhub_sync'] = time();
        $server_sync_result = syncPayhubVirtualAccounts($vendor_id, $get_logged_user_details['email'], false, $username);
        // If sync succeeded, refresh the bank list so the HTML shows the new accounts
        if (!empty($server_sync_result['success'])) {
            $registered_virtual_bank_arr_3 = [];
            $user_banks_3 = getUserVirtualBank();
            if (is_array($user_banks_3)) {
                foreach ($user_banks_3 as $bank_json) {
                    $bank_json = json_decode($bank_json, true);
                    $registered_virtual_bank_arr_3[] = $bank_json["bank_code"];
                }
            }
            $has_payhub_account = in_array("PayHub", $registered_virtual_bank_arr_3);
        }
    }
}
// ─────────────────────────────────────────────────────────────────────────────

if(isset($_POST['notify-deposit'])){
    $bank_name = mysqli_real_escape_string($connection_server, $_POST['bank_name']);
    $amount = (float)$_POST['amount'];
    $desc = mysqli_real_escape_string($connection_server, $_POST['description']);
    $ref = "MDEP-".substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);

    // Insert into submitted_payments
    mysqli_query($connection_server, "INSERT INTO sas_submitted_payments (vendor_id, username, reference, amount, discounted_amount, description, mode, api_website, status) VALUES ('$vendor_id', '$username', '$ref', '$amount', '$amount', 'Manual Deposit: $bank_name. $desc', 'Manual', 'WEB', 2)");

    $_SESSION["product_purchase_response"] = "Deposit notification sent. Please wait for admin approval.";
    header("Location: VirtualBanks.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Virtual Bank Accounts | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .bank-card { border-radius: 15px; border: none; box-shadow: 0 5px 20px rgba(0,0,0,0.05); transition: 0.3s; background: #fff; overflow: hidden; }
        .bank-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #eee; font-weight: 700; color: #4154f1; }
        .bank-body { padding: 20px; }
        .acc-no { font-size: 1.5rem; font-weight: 800; letter-spacing: 1px; color: #333; }
        .copy-btn { cursor: pointer; color: #4154f1; transition: 0.2s; }
        .copy-btn:hover { color: #2a3eb1; }
    </style>
</head>
<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
        <h1>VIRTUAL BANK ACCOUNTS</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Virtual Banks</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <?php if(!$is_kyc_compliant && $get_logged_user_details["kyc_status"] != 1 && isKYCEnforced()): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between p-4" style="background: linear-gradient(45deg, #fff3cd, #fff8e1);">
            <div class="d-flex align-items-center">
                <div class="bg-warning bg-opacity-25 text-warning rounded-circle p-3 me-3 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-1 text-dark">Identity Verification Required</h6>
                    <p class="small mb-0 text-muted">Some bank accounts are restricted. Complete your KYC to unlock all virtual bank options.</p>
                </div>
            </div>
            <a href="KYCVerification.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm border-0 text-dark">Start KYC Now</a>
        </div>
        <?php endif; ?>

        <?php if($get_logged_user_details["kyc_status"] == 1): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center p-4">
            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3 me-3">
                <i class="bi bi-clock-history fs-3"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1">Verification in Progress</h6>
                <p class="small mb-0 text-muted">Your identity documents are currently being reviewed. We will notify you once approved.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="alert alert-info d-flex align-items-center justify-content-between">
                    <div>
                        <i class="bi bi-info-circle me-2"></i>
                        Funds sent to these accounts will be automatically credited to your wallet.
                    </div>
                    <button id="syncBtn" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="triggerManualSync()">
                        <i class="bi bi-arrow-repeat me-1"></i> RE-SYNC ACCOUNTS
                    </button>
                </div>

                <div class="row g-4">
                    <?php
                    $ussd_codes = [
                        "FIRST BANK" => "*894*Amount*{acc}#",
                        "FIDELITY BANK" => "*770*{acc}*Amount#",
                        "GTBANK" => "*737*2*Amount*{acc}#",
                        "ACCESS BANK" => "*901*Amount*{acc}#",
                        "PALMPAY" => "",
                        "OPAY" => "",
                        "WEMA BANK" => "*945*Amount*{acc}#",
                        "GLOBUS BANK" => "*989*Amount*{acc}#",
                        "VFD MICROFINANCE BANK" => "*5037*Amount*{acc}#",
                        "STERLING BANK" => "*822*Amount*{acc}#",
                        "PROVIDUS BANK" => "*601*Amount*{acc}#"
                    ];

                    $get_banks = mysqli_query($connection_server, "SELECT * FROM sas_user_banks WHERE vendor_id='$vendor_id' AND username='$username' AND (status = 1 OR status IS NULL)");
                    if(mysqli_num_rows($get_banks) > 0){
                        while($bank = mysqli_fetch_assoc($get_banks)){
                            // Ensure disabled gateways do not display
                            if (!empty($bank['gateway_name']) && !isServiceEnabled($bank['gateway_name'], $vendor_id)) {
                                continue;
                            }
                            $bank_name_upper = strtoupper($bank['bank_name']);
                            $ussd_info = "";
                            foreach($ussd_codes as $name => $code) {
                                if(stripos($bank_name_upper, $name) !== false) {
                                    $formatted_ussd = str_replace("{acc}", $bank['account_number'], $code);
                                    $ussd_info = "<div class='mt-2 p-2 bg-light rounded small'>
                                        <span class='text-muted'>USSD Transfer:</span><br>
                                        <strong class='text-primary'>$formatted_ussd</strong>
                                        <i class='bi bi-clipboard ms-1 copy-btn' onclick='copyToClipboard(\"$formatted_ussd\")' title='Copy USSD'></i>
                                    </div>";
                                    break;
                                }
                            }
                            ?>
                            <div class="col-md-6">
                                <div class="bank-card">
                                    <div class="bank-header d-flex justify-content-between">
                                        <span><?php echo $bank_name_upper; ?></span>
                                        <i class="bi bi-bank"></i>
                                    </div>
                                    <div class="bank-body">
                                        <div class="text-muted small">Account Number:</div>
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="acc-no" id="acc_<?php echo $bank['account_number']; ?>"><?php echo $bank['account_number']; ?></span>
                                            <i class="bi bi-clipboard copy-btn fs-4" onclick="copyToClipboard('<?php echo $bank['account_number']; ?>')"></i>
                                        </div>
                                        <div class="text-muted small">Account Name:</div>
                                        <div class="fw-bold mb-2"><?php echo $bank['account_name']; ?></div>
                                        <?php echo $ussd_info; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    }else{
                        // Determine the right message depending on whether the server-side PayHub sync just ran
                        $no_acct_heading   = "No virtual accounts found";
                        $no_acct_detail    = "Please contact support or ensure your KYC is complete.";
                        $should_auto_reload = false;

                        if ($server_sync_result !== null) {
                            if (!empty($server_sync_result['success'])) {
                                // Sync succeeded but accounts still not found — force a reload
                                $no_acct_heading = "Accounts detected — refreshing…";
                                $no_acct_detail  = "Your account details were just retrieved. The page will reload automatically.";
                                $should_auto_reload = true;
                            } else {
                                $no_acct_heading = "Account retrieval in progress";
                                $no_acct_detail  = "We attempted to fetch your PayHub virtual account but received: \""
                                    . htmlspecialchars(strip_tags($server_sync_result['message']))
                                    . "\". Please click RE-SYNC ACCOUNTS to try again, or contact support.";
                            }
                        } elseif (!empty($keys['secret_key'])) {
                            $no_acct_heading = "Checking for your accounts…";
                            $no_acct_detail  = "We are looking up your virtual bank account via PayHub. If nothing appears after a few seconds, click RE-SYNC ACCOUNTS.";
                        }
                        ?>
                        <div class="col-12">
                            <div class="card p-5 text-center">
                                <i class="bi bi-bank2 fs-1 text-primary mb-3 opacity-50"></i>
                                <h5><?php echo $no_acct_heading; ?></h5>
                                <p class="text-muted"><?php echo $no_acct_detail; ?></p>
                                <?php if(!empty($keys['secret_key'])): ?>
                                <button class="btn btn-outline-primary rounded-pill px-4 mt-2" onclick="triggerManualSync()">
                                    <i class="bi bi-arrow-repeat me-1"></i> RE-SYNC ACCOUNTS
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>

                <div class="card mt-5 p-4 bank-card">
                    <h5 class="fw-bold mb-4">Manual Bank Deposit</h5>
                    <p class="text-muted small">If you made a transfer to our official bank account, please notify us here.</p>
                    <form method="post">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Bank You Paid To</label>
                                <select name="bank_name" class="form-select" required>
                                    <option value="">Select Bank</option>
                                    <?php
                                    $get_admin_banks = mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='$vendor_id'");
                                    while($ab = mysqli_fetch_assoc($get_admin_banks)){
                                        echo "<option value='{$ab['bank_name']}'>{$ab['bank_name']} - {$ab['account_number']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Amount Paid</label>
                                <input type="number" name="amount" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Additional Info (Date, Sender Name, etc.)</label>
                                <textarea name="description" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="notify-deposit" class="btn btn-primary px-5 rounded-pill">Notify Admin</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card p-4 bank-card">
                    <h6 class="fw-bold mb-3">Admin Bank Details</h6>
                    <?php
                    $get_admin_banks = mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='$vendor_id'");
                    if(mysqli_num_rows($get_admin_banks) > 0){
                        while($ab = mysqli_fetch_assoc($get_admin_banks)){
                            echo "<div class='mb-3 border-bottom pb-2'>
                                <div class='fw-bold text-primary'>{$ab['bank_name']}</div>
                                <div>Account: <strong>{$ab['account_number']}</strong></div>
                                <div class='small text-muted'>{$ab['account_name']}</div>
                            </div>";
                        }
                    }else{
                        echo "<p class='small text-muted'>No admin bank details configured.</p>";
                    }
                    ?>
                </div>

                <div class="card p-4 bank-card mt-4">
                    <h6 class="fw-bold mb-3">Recent Notifications</h6>
                    <div class="table-responsive">
                        <table class="table table-sm small">
                            <thead><tr><th>Ref</th><th>Amount</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php
                                $get_recent = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='$vendor_id' AND username='$username' ORDER BY date DESC LIMIT 5");
                                while($rp = mysqli_fetch_assoc($get_recent)){
                                    $st = ($rp['status'] == 1) ? 'text-success' : (($rp['status'] == 3) ? 'text-danger' : 'text-warning');
                                    $st_t = ($rp['status'] == 1) ? 'Approved' : (($rp['status'] == 3) ? 'Declined' : 'Pending');
                                    echo "<tr><td>{$rp['reference']}</td><td>N".number_format($rp['amount'])."</td><td class='$st'>$st_t</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        function copyToClipboardFallback(text) {
            var textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.opacity = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            var successful = false;
            try {
                successful = document.execCommand('copy');
            } catch (err) {
                console.error('Fallback copy failed', err);
            }
            document.body.removeChild(textArea);
            return successful;
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    alert('Account number copied!');
                }).catch(err => {
                    if (copyToClipboardFallback(text)) {
                        alert('Account number copied!');
                    } else {
                        alert('Failed to copy: ' + err);
                    }
                });
            } else {
                if (copyToClipboardFallback(text)) {
                    alert('Account number copied!');
                } else {
                    alert('Failed to copy: Clipboard API not supported');
                }
            }
        }
    </script>

    <?php include("../func/bc-footer.php"); ?>
    <script>
        function triggerManualSync() {
            const btn = document.getElementById('syncBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>SYNCING...';
            btn.disabled = true;

            // manual=1 fully bypasses throttle in ajax-sync-accounts.php
            fetch('ajax-sync-accounts.php?manual=1&source=virtual_banks')
                .then(response => response.json())
                .then(data => {
                    console.log('Sync result:', data);
                    let messages = [];
                    if(data.payhub)    messages.push("PayHub: "    + data.payhub.message);
                    if(data.paystack)  messages.push("Paystack: "  + data.paystack.message);
                    if(data.monnify)   messages.push("Monnify: "   + data.monnify.message);
                    if(data.payvessel) messages.push("Payvessel: " + data.payvessel.message);

                    const anySuccess = ['payhub','paystack','monnify','payvessel']
                        .some(gw => data[gw] && data[gw].status === 'success');

                    Swal.fire({
                        title: anySuccess ? 'Accounts Found!' : 'Sync Result',
                        html: '<div class="text-start small">' + (messages.length ? messages.join('<br>') : 'No updates at this time.') + '</div>',
                        icon: anySuccess ? 'success' : 'info'
                    }).then(() => {
                        window.location.reload();
                    });
                })
                .catch(error => {
                    console.error('Sync error:', error);
                    Swal.fire('Error', 'Failed to communicate with sync server.', 'error');
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }

        // Background sync on page load — uses a dedicated source key so it runs
        // independently of the dashboard throttle (DG6.8 Enhancement).
        // Only trigger if the server-side sync did NOT already succeed.
        <?php if(empty($server_sync_result['success'])): ?>
        fetch('ajax-sync-accounts.php?source=virtual_banks')
            .then(response => response.json())
            .then(data => {
                // Reload when any gateway returns success so new accounts appear immediately
                const hasNew = ['payhub', 'paystack', 'monnify', 'payvessel']
                    .some(gw => data[gw] && data[gw].status === 'success');
                if (hasNew) {
                    setTimeout(() => { window.location.reload(); }, 1500);
                }
            })
            .catch(err => console.warn('Background sync failed:', err));
        <?php endif; ?>
        <?php if(!empty($should_auto_reload)): ?>
        // Server-side sync just found accounts — reload after a short delay
        setTimeout(function() { window.location.reload(); }, 1500);
        <?php endif; ?>
    </script>
</body>
</html>
