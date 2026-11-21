<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");
include("../func/bc-crypto-func.php");

$ref = mysqli_real_escape_string($connection_server, $_GET['ref'] ?? '');
if (empty($ref)) {
    die("Invalid Invoice Reference");
}

$q = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `reference`='$ref' LIMIT 1");
$tx = ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : null;

if (!$tx) {
    error_log("Invoice Lookup Failed: Ref=" . $ref . " (Public View)");
    die("Invoice not found or access denied.");
}

// Resolve vendor ID and site details from the transaction for public view
$vid = $tx['vendor_id'];
$_SESSION['vendor_id'] = $vid; // Inject into session for logic that relies on it
$GLOBALS['vendor_id'] = $vid;
resolveVendorID(true); // Force resolution from global if available

// §28: invoice regeneration created NEW deposits on the removed legacy provider.
// This page is read-only history now: it displays the stored invoice and never
// contacts a provider. New deposits start from the Crypto Hub.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') == 'regenerate_invoice') {
    echo json_encode(['status' => 'error', 'message' => 'This invoice can no longer be regenerated. Please start a new deposit from the Crypto Hub.']);
    exit();
}

// Security: If not logged in, ensure we don't leak session-specific error logs
$current_username = $get_logged_user_details['username'] ?? 'Guest';

// Auto-expire invoice if 30 minutes have passed since creation (status 2 = pending)
if ($tx['status'] == 2) {
    $created_time = (!empty($tx['created_at']) && $tx['created_at'] != '0000-00-00 00:00:00') ? strtotime($tx['created_at']) : time();
    if ($created_time > 0 && (time() - $created_time > (30 * 60))) {
        mysqli_query($connection_server, "UPDATE sas_crypto_transactions SET status='3' WHERE reference='".$tx['reference']."'");
        $tx['status'] = '3';
    }
}

// §28: status checks read the LOCAL record only — the legacy provider is gone,
// so nothing is polled and no balance is credited from an external response here.
// (Payment confirmation for legacy invoices stays with admin reconciliation.)
if (isset($_GET['check_status'])) {
    echo json_encode(['status' => $tx['status']]);
    exit();
}

// Static network labels for the locally supported set (no provider lookup).
$crypto_network_names = ['BTC' => 'Bitcoin', 'ETH' => 'Ethereum', 'LTC' => 'Litecoin', 'TRX' => 'TRON', 'USDT_TRX' => 'Tether (TRC-20)', 'USDC' => 'USD Coin', 'BCH' => 'Bitcoin Cash', 'DOGE' => 'Dogecoin'];
$cur_info = isset($crypto_network_names[$tx['currency_code']]) ? ['cid' => $tx['currency_code'], 'name' => $crypto_network_names[$tx['currency_code']]] : null;

// Stored invoice data only; the QR below is generated from the stored address.
$live_qr = "";
$live_amount = $tx['amount'];

$status_map = [
    '1' => ['Success', 'success', 'bi-check-circle-fill'],
    '2' => ['Pending Payment', 'warning', 'bi-clock-history'],
    '3' => ['Failed / Expired', 'danger', 'bi-x-circle-fill']
];
$st = $status_map[$tx['status']] ?? ['Unknown', 'secondary', 'bi-question-circle'];
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Crypto Invoice | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "CRYPTO INVOICE", "crumb" => "Home / Crypto / Invoice")); ?>

    <section class="section p-4">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card invoice-card shadow-sm">
                    <div class="card-header bg-white py-3 border-0 text-center">
                        <span class="badge bg-<?php echo $st[1]; ?> bg-opacity-10 text-<?php echo $st[1]; ?> p-2 px-3 rounded-pill">
                            <i class="bi <?php echo $st[2]; ?> me-1"></i> <?php echo $st[0]; ?>
                        </span>
                    </div>
                    <div class="card-body p-5 text-center">
                        <?php if($tx['status'] == 2): ?>
                        <div id="expiry-timer" class="badge bg-dark rounded-pill p-2 px-3 mb-4">
                            Expiring in: <span id="timer-val">30:00</span>
                        </div>
                        <?php endif; ?>

                        <div class="mb-4 p-4 border-0 rounded-4 shadow-sm" style="background: linear-gradient(145deg, #fffcf0, #fff); border: 1px solid #ffeeba !important;">
                            <p class="text-danger small mb-2 text-uppercase fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> IMPORTANT: SEND EXACT AMOUNT</p>
                            <div class="amount-val text-primary d-flex align-items-center justify-content-center" style="font-size: 2.5rem; border-bottom: 2px dashed #ffeeba; padding-bottom: 15px; margin-bottom: 15px; letter-spacing: -1px;">
                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $tx['currency_code'])); ?>.png" style="width:48px; height:48px; vertical-align:middle; margin-right:15px;">
                                <span id="exactAmount"><?php echo $live_amount; ?></span>
                                <span class="ms-2 opacity-50" style="font-size: 1.25rem;"><?php echo $tx['currency_code']; ?></span>
                                <i class="bi bi-clipboard-plus ms-3 fs-3 text-muted" onclick="copyValText('exactAmount')" style="cursor: pointer;" title="Copy Amount"></i>
                            </div>
                            <?php if($cur_info): ?>
                                <p class="small text-muted mt-1 fw-bold"><i class="bi bi-hdd-network me-1"></i> Network: <?php echo $cur_info['name']; ?> (<?php echo $tx['currency_code']; ?>)</p>
                            <?php endif; ?>
                            <div class="alert alert-danger py-2 px-3 border-0 rounded-3 mt-3 small mb-0 d-inline-block">
                                <i class="bi bi-shield-fill-exclamation me-1"></i> <strong>Security Notice:</strong> Partial payments will NOT be credited automatically.
                            </div>
                        </div>

                        <?php if($tx['status'] == 2 && !empty($tx['address'])): ?>
                        <div class="qr-box mb-4 shadow-sm">
                            <?php if(!empty($live_qr)): ?>
                                <img src="<?php echo $live_qr; ?>" class="img-fluid rounded" style="max-width: 200px;">
                            <?php else: ?>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo $tx['address']; ?>" class="img-fluid rounded">
                            <?php endif; ?>
                        </div>

                        <div class="mb-4 text-start">
                            <label class="form-label small fw-bold text-muted text-uppercase">Wallet Address (<?php echo $tx['currency_code']; ?>)</label>
                            <div class="input-group">
                                <input type="text" id="addrBox" class="form-control bg-light border-0 py-2" value="<?php echo $tx['address']; ?>" readonly style="font-size: 0.9rem;">
                                <button class="btn btn-primary" onclick="copyVal('addrBox')"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="alert alert-info border-0 rounded-4 mt-3 small">
                                <i class="bi bi-info-circle-fill me-2"></i> Funds will be credited automatically after 1 network confirmation.
                            </div>
                            <div class="alert alert-warning border-0 rounded-4 mt-2 small py-2">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> <strong>Save this reference:</strong> <code><?php echo $tx['reference']; ?></code>. You may need it for self-help support.
                            </div>
                        </div>
                        <?php elseif($tx['status'] == 3): ?>
                        <div class="alert alert-danger border-0 rounded-4 mb-4">
                            <h6 class="fw-bold">Invoice Expired</h6>
                            <p class="small text-muted">The payment window for this invoice has closed. Please create a new invoice to continue.</p>
                        </div>
                        <?php elseif(empty($tx['address'])): ?>
                        <div class="alert alert-warning border-0 rounded-4 mb-4">
                            <h6 class="fw-bold">White Label Not Enabled</h6>
                            <p class="small mb-3 text-muted">Direct address display is currently unavailable. Please use the secure payment page to complete your payment.</p>
                            <a href="<?php echo $tx['invoice_url']; ?>" target="_blank" class="btn btn-warning w-100 rounded-pill fw-bold py-2">
                                Go to Payment Page <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between text-start border-top pt-4">
                            <div>
                                <p class="text-muted small mb-0">Reference</p>
                                <p class="fw-bold mb-0"><?php echo $tx['reference']; ?></p>
                            </div>
                            <div class="text-end">
                                <p class="text-muted small mb-0">Created At</p>
                                <p class="fw-bold mb-0">
                                    <?php
                                        $display_ts = !empty($tx['created_at']) ? strtotime($tx['created_at']) : 0;
                                        echo ($display_ts > 0) ? date('M d, H:i', $display_ts) : "Just Now";
                                    ?>
                                </p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <?php if($tx['status'] == 2): ?>
                                <button id="btnSync" class="btn btn-info rounded-pill px-4 me-2" onclick="syncStatus()">
                                    <i class="bi bi-arrow-repeat me-1"></i> Update Status
                                </button>
                            <?php elseif($tx['status'] == 3): ?>
                                <button class="btn btn-warning rounded-pill px-4 me-2" onclick="regenerateInvoice()">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Restart Timer & Invoice
                                </button>
                            <?php endif; ?>
                            <button class="btn btn-outline-primary rounded-pill px-4" onclick="shareInvoice()">
                                <i class="bi bi-share me-1"></i> Share Invoice Link
                            </button>
                        </div>

                        <?php if(isset($_SESSION['user_session'])): ?>
                        <div class="mt-4">
                            <a href="CryptoHub.php" class="btn btn-link text-muted">
                                <i class="bi bi-arrow-left me-1"></i> Back to Crypto
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        let countdownTimer;

        function startTimer() {
            <?php
                $ts = !empty($tx['created_at']) ? strtotime($tx['created_at']) : time();
                if ($ts <= 0) $ts = time();
            ?>
            const createdAt = <?php echo $ts; ?> * 1000;
            const expiryAt = createdAt + (30 * 60 * 1000);
            const timerVal = document.getElementById('timer-val');
            const timerBox = document.getElementById('expiry-timer');

            function update() {
                const now = new Date().getTime();
                const dist = expiryAt - now;

                if (dist < 0) {
                    clearInterval(countdownTimer);
                    if (timerVal) timerVal.innerHTML = "00:00";
                    if (timerBox) {
                        timerBox.classList.replace('bg-dark', 'bg-danger');
                        timerBox.innerHTML = "Invoice Expired";
                    }
                    // If status is pending, reload to trigger server-side expiration logic
                    if ('<?php echo $tx['status']; ?>' == '2') {
                        location.reload();
                    }
                    return;
                }

                const mins = Math.floor((dist % (1000 * 60 * 60)) / (1000 * 60));
                const secs = Math.floor((dist % (1000 * 60)) / 1000);
                if (timerVal) timerVal.innerHTML = (mins < 10 ? "0"+mins : mins) + ":" + (secs < 10 ? "0"+secs : secs);
            }

            update();
            countdownTimer = setInterval(update, 1000);
        }

        <?php if($tx['status'] == 2): ?>
        window.onload = startTimer;
        <?php endif; ?>

        function copyVal(id) {
            const el = document.getElementById(id);
            el.select();
            document.execCommand('copy');
            alert('Wallet address copied!');
        }

        function copyValText(id) {
            const text = document.getElementById(id).innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('Amount copied: ' + text);
            });
        }

        function shareInvoice() {
            if (navigator.share) {
                navigator.share({
                    title: 'Crypto Invoice',
                    text: 'Please pay <?php echo $tx['amount']; ?> <?php echo $tx['currency_code']; ?> using this link.',
                    url: window.location.href
                }).catch(console.error);
            } else {
                navigator.clipboard.writeText(window.location.href);
                alert('Invoice link copied to clipboard!');
            }
        }

        function syncStatus() {
            const btn = document.getElementById('btnSync');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Checking...';

            fetch(window.location.href + '&check_status=1&sync=1')
            .then(r => r.json())
            .then(res => {
                if(res.status != '<?php echo $tx['status']; ?>') {
                    location.reload();
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Update Status';
                    alert('No payment detected yet. Please ensure you have sent the funds.');
                }
            });
        }

        function regenerateInvoice() {
            if(!confirm('Create a new invoice with the same details?')) return;

            const formData = new URLSearchParams();
            formData.append('action', 'regenerate_invoice');

            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if(res.status == 'success') {
                    window.location.href = res.url;
                } else {
                    alert(res.message);
                }
            })
            .catch(err => alert('Request Failed: ' + err));
        }

        // Auto-refresh status
        setInterval(() => {
            fetch(window.location.href + '&check_status=1')
            .then(r => r.json())
            .then(res => {
                if(res.status != '<?php echo $tx['status']; ?>') {
                    location.reload();
                }
            });
        }, 10000);
    </script>

    
	<?php bc_app_foot(); ?>
