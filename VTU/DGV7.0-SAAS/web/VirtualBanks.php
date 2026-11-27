<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-config.php");
require_once(__DIR__ . "/../func/bc-payment-gateway-settlement.php");

$username = mysqli_real_escape_string($connection_server, $get_logged_user_details["username"]);
$vendor_id = (int)$get_logged_user_details["vendor_id"];

// Check if Virtual Bank is enabled globally or for this vendor
if(!isServiceEnabled('virtual_bank_display', $vendor_id)){
    header("Location: Dashboard.php");
    exit();
}

// Master KYC Compliance Check for Virtual Bank Generation (Always required regardless of global force_kyc for security)
$is_kyc_compliant = ($get_logged_user_details['kyc_status'] == 2);

$payhub_enabled = bc_pg_is_gateway_enabled($connection_server, $vendor_id, 'payhub');
$keys = $payhub_enabled ? getGatewayDetails('payhub', $vendor_id) : [];

// Virtual account generation
$registered_virtual_bank_arr_3 = array();
$user_banks_3 = getUserVirtualBank();
if (is_array($user_banks_3)) {
    foreach ($user_banks_3 as $bank_json) {
        $bank_json = json_decode($bank_json, true);
        array_push($registered_virtual_bank_arr_3, $bank_json["bank_code"]);
    }
}

$server_sync_result = null;
$has_payhub_account = in_array("PayHub", $registered_virtual_bank_arr_3);

if ($payhub_enabled && !$has_payhub_account && !empty($keys['secret_key'])) {
    $last_vb_payhub_sync = $_SESSION['last_vb_payhub_sync'] ?? 0;
    if (time() - $last_vb_payhub_sync >= 60) {
        $_SESSION['last_vb_payhub_sync'] = time();
        $server_sync_result = syncPayhubVirtualAccounts($vendor_id, $get_logged_user_details['email'], false, $username);
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

$manual_funding_enabled = isServiceEnabled('manual_funding', $vendor_id);

if(isset($_POST['notify-deposit'])){
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    if (!$manual_funding_enabled) {
        $_SESSION["product_purchase_response"] = "Manual bank funding is currently disabled.";
        header("Location: VirtualBanks.php");
        exit();
    }
    $bank_name = mysqli_real_escape_string($connection_server, trim(strip_tags((string)($_POST['bank_name'] ?? ''))));
    $amount = (float)($_POST['amount'] ?? 0);
    $desc = mysqli_real_escape_string($connection_server, trim(strip_tags((string)($_POST['description'] ?? ''))));
    if ($bank_name === '' || !is_finite($amount) || $amount < 100) {
        $_SESSION["product_purchase_response"] = "Enter a valid bank and amount (minimum NGN 100).";
        header("Location: VirtualBanks.php");
        exit();
    }
    $ref = "MDEP-".substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);

    // Insert into submitted_payments
    mysqli_query($connection_server, "INSERT INTO sas_submitted_payments (vendor_id, username, reference, amount, discounted_amount, description, mode, api_website, status) VALUES ('$vendor_id', '$username', '$ref', '$amount', '$amount', 'Manual Deposit: $bank_name. $desc', 'Manual', 'WEB', 2)");

    $_SESSION["product_purchase_response"] = "Deposit notification sent. Please wait for admin approval.";
    header("Location: VirtualBanks.php");
    exit();
}
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Virtual Bank Accounts | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "VIRTUAL BANK ACCOUNTS", "crumb" => "Home / Virtual Banks")); ?>

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
                    $visible_banks = [];
                    if ($get_banks) {
                        while ($bank = mysqli_fetch_assoc($get_banks)) {
                            if (!bc_pg_bank_account_allowed($connection_server, $vendor_id, $bank)) {
                                continue;
                            }
                            $visible_banks[] = $bank;
                        }
                    }
                    if(!empty($visible_banks)){
                        foreach($visible_banks as $bank){
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
                                        <span><?php echo htmlspecialchars($bank_name_upper, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <i class="bi bi-bank"></i>
                                    </div>
                                    <div class="bank-body">
                                        <div class="text-muted small">Account Number:</div>
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="acc-no" id="acc_<?php echo htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <i class="bi bi-clipboard copy-btn fs-4" onclick="copyToClipboard('<?php echo htmlspecialchars($bank['account_number'], ENT_QUOTES, 'UTF-8'); ?>')"></i>
                                        </div>
                                        <div class="text-muted small">Account Name:</div>
                                        <div class="fw-bold mb-2"><?php echo htmlspecialchars($bank['account_name'], ENT_QUOTES, 'UTF-8'); ?></div>
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
                                $no_acct_detail  = "We attempted to fetch your Aggregator (Provider) virtual account but received: \""
                                    . htmlspecialchars(function_exists('bc_mask_provider_names') ? bc_mask_provider_names(strip_tags($server_sync_result['message'])) : strip_tags($server_sync_result['message']))
                                    . "\". Please click RE-SYNC ACCOUNTS to try again, or contact support.";
                            }
                        } elseif (!empty($keys['secret_key'])) {
                            $no_acct_heading = "Checking for your accounts…";
                            $no_acct_detail  = "We are looking up your virtual bank account via Aggregator (Provider). If nothing appears after a few seconds, click RE-SYNC ACCOUNTS.";
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

                <?php if ($manual_funding_enabled): ?>
                <div class="card mt-5 p-4 bank-card">
                    <h5 class="fw-bold mb-4">Manual Bank Deposit</h5>
                    <p class="text-muted small">If you made a transfer to our official bank account, please notify us here.</p>
                    <form method="post">
                        <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Bank You Paid To</label>
                                <select name="bank_name" class="form-select" required>
                                    <option value="">Select Bank</option>
                                    <?php
                                    $get_admin_banks = mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='$vendor_id'");
                                    while($ab = mysqli_fetch_assoc($get_admin_banks)){
                                        echo "<option value='".htmlspecialchars($ab['bank_name'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($ab['bank_name'].' - '.$ab['account_number'], ENT_QUOTES, 'UTF-8')."</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Amount Paid</label>
                                <input type="number" name="amount" min="100" step="0.01" class="form-control" required>
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
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <?php if ($manual_funding_enabled): ?>
                <div class="card p-4 bank-card">
                    <h6 class="fw-bold mb-3">Admin Bank Details</h6>
                    <?php
                    $get_admin_banks = mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='$vendor_id'");
                    if(mysqli_num_rows($get_admin_banks) > 0){
                        while($ab = mysqli_fetch_assoc($get_admin_banks)){
                            echo "<div class='mb-3 border-bottom pb-2'>
                                <div class='fw-bold text-primary'>".htmlspecialchars($ab['bank_name'], ENT_QUOTES, 'UTF-8')."</div>
                                <div>Account: <strong>".htmlspecialchars($ab['account_number'], ENT_QUOTES, 'UTF-8')."</strong></div>
                                <div class='small text-muted'>".htmlspecialchars($ab['account_name'], ENT_QUOTES, 'UTF-8')."</div>
                            </div>";
                        }
                    }else{
                        echo "<p class='small text-muted'>No admin bank details configured.</p>";
                    }
                    ?>
                </div>
                <?php endif; ?>

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
                    alert('Clipboard copying is not supported in this browser.');
                }
            }
        }
    </script>

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
                    if(data.payhub)    messages.push("Aggregator (Provider): " + data.payhub.message);
                    if(data.paystack)  messages.push("Aggregator (Provider): " + data.paystack.message);
                    if(data.monnify)   messages.push("Aggregator (Provider): " + data.monnify.message);
                    if(data.payvessel) messages.push("Aggregator (Provider): " + data.payvessel.message);

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
	<?php bc_app_foot(); ?>
