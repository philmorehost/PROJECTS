<?php session_start();
include("../func/bc-config.php");
require_once("../func/api-gateway/chimoney.php");

$vid = resolveVendorID();
$uname = $_SESSION["user_session"] ?? "";

if(!isServiceEnabled('virtual_card')){
    header("Location: Dashboard.php");
    exit();
}

// Fetch user's cards
$cards = [];
$q_cards = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE vendor_id='$vid' AND username='$uname' AND status != 'terminated' ORDER BY created_at DESC");
while ($r = mysqli_fetch_assoc($q_cards)) {
    $cards[] = $r;
}

// Fetch installed products for issuance
$products = [];
$q_prod = mysqli_query($connection_server, "SELECT g.* FROM sas_global_virtual_card_products g JOIN sas_vendor_virtual_card_products v ON g.chimoney_product_id = v.chimoney_product_id WHERE v.vendor_id='$vid' AND v.status=1");
while ($p = mysqli_fetch_assoc($q_prod)) {
    $products[] = $p;
}

// Fetch Profit Settings for UI display
$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='$vid' AND setting_name LIKE 'vc_%'");
$vc_settings = []; while($rs = mysqli_fetch_assoc($q_set)) $vc_settings[$rs['setting_name']] = $rs['setting_value'];
$issuance_profit = (float)($vc_settings['vc_issuance_profit_usd'] ?? 2.00);
$funding_fee = (float)($vc_settings['vc_funding_profit_percent'] ?? 3.00);

?>
<!DOCTYPE html>
<head>
    <title>Virtual Card Dashboard | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link rel="stylesheet" href="../assets/css/fintech-cards.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .card-stack { perspective: 1000px; }
        .empty-state { text-align: center; padding: 60px 20px; background: rgba(255,255,255,0.05); border-radius: 20px; border: 1px dashed rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-light">
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle d-flex justify-content-between align-items-center">
        <div>
            <h1>Virtual Cards</h1>
            <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item active">Virtual Card</li></ol></nav>
        </div>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#issueCardModal">
            <i class="bi bi-plus-lg me-2"></i>New Card
        </button>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-lg-8">
                <?php if (empty($cards)): ?>
                <div class="empty-state mb-4">
                    <div class="display-1 text-muted opacity-25 mb-3"><i class="bi bi-credit-card-2-front"></i></div>
                    <h4 class="fw-bold text-muted">No Active Cards</h4>
                    <p class="text-muted small">Issue a premium USD virtual card to start shopping globally.</p>
                    <button class="btn btn-outline-primary btn-sm rounded-pill mt-2" data-bs-toggle="modal" data-bs-target="#issueCardModal">Get Started</button>
                </div>
                <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($cards as $card):
                        $is_frozen = ($card['status'] == 'frozen');
                    ?>
                    <div class="col-md-6 card-stack">
                        <div class="vc-glass-card <?php echo $is_frozen ? 'frozen' : ''; ?>" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                            <?php if($is_frozen): ?>
                            <div class="vc-frozen-overlay">
                                <span class="vc-frozen-badge">FROZEN</span>
                                <p class="text-white text-xs text-center px-3 mb-2">Insufficient funds or manual lock.</p>
                                <button class="btn btn-light btn-sm rounded-pill py-0 px-3 text-xs fw-bold" onclick="reactivateCard('<?php echo $card['reference']; ?>')">Reactivate</button>
                            </div>
                            <?php endif; ?>

                            <div class="vc-brand"><i class="bi bi-credit-card"></i></div>
                            <div class="vc-chip"></div>

                            <div class="vc-number" id="pan-<?php echo $card['reference']; ?>"><?php echo $card['masked_pan']; ?></div>

                            <div class="d-flex justify-content-between align-items-end">
                                <div>
                                    <div class="vc-info-label">Card Holder</div>
                                    <div class="vc-info-val text-uppercase"><?php echo $card['card_name']; ?></div>
                                </div>
                                <div class="text-center mx-3">
                                    <div class="vc-info-label">Expiry</div>
                                    <div class="vc-info-val" id="exp-<?php echo $card['reference']; ?>"><?php echo $card['expiry_month'] . '/' . $card['expiry_year']; ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="vc-info-label">CVV</div>
                                    <div class="vc-info-val" id="cvv-<?php echo $card['reference']; ?>">***</div>
                                </div>
                            </div>

                            <div class="mt-3 d-flex justify-content-between align-items-center">
                                <span class="small opacity-75"><span class="vc-status-indicator status-<?php echo $card['status']; ?>"></span> <?php echo ucfirst($card['status']); ?></span>
                                <button class="reveal-btn" onclick="revealSecurity('<?php echo $card['reference']; ?>')"><i class="bi bi-eye me-1"></i> Reveal Details</button>
                            </div>
                        </div>

                        <!-- Quick Actions for this Card -->
                        <div class="mt-3 d-flex gap-2">
                            <button class="btn btn-white border btn-sm flex-fill rounded-pill shadow-sm" onclick="openFundModal('<?php echo $card['reference']; ?>')">
                                <i class="bi bi-plus-circle text-success me-1"></i> Fund
                            </button>
                            <button class="btn btn-white border btn-sm flex-fill rounded-pill shadow-sm" onclick="withdrawToWallet('<?php echo $card['reference']; ?>', <?php echo $card['balance_usd']; ?>)">
                                <i class="bi bi-arrow-down-circle text-primary me-1"></i> Withdraw
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Spending Insights & Help -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden">
                    <div class="card-header bg-primary text-white py-3 border-0">
                        <h6 class="fw-bold mb-0">Balance & Insights</h6>
                    </div>
                    <div class="card-body p-4 text-center">
                        <div class="display-6 fw-bold text-primary mb-1">$<?php
                            $total_bal = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT SUM(balance_usd) as total FROM sas_virtual_cards_v2 WHERE vendor_id='$vid' AND username='$uname' AND status='active'"))['total'] ?? 0;
                            echo number_format($total_bal, 2);
                        ?></div>
                        <p class="text-muted small">Total Active Card Balance</p>
                        <hr>
                        <div class="row text-start g-2">
                            <div class="col-6">
                                <small class="text-muted d-block">Issuance Fee</small>
                                <span class="fw-bold">$<?php echo number_format($issuance_profit, 2); ?></span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Funding Fee</small>
                                <span class="fw-bold"><?php echo $funding_fee; ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock me-2 text-warning"></i>Security Tips</h6>
                        <ul class="list-unstyled small text-muted">
                            <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Cards are accepted on Netflix, Amazon, and more.</li>
                            <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Transactions are secured by 3D Secure OTP via email.</li>
                            <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Keep your transaction PIN private.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Modals -->
    <!-- Issue Card Modal -->
    <div class="modal fade" id="issueCardModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Issue New Virtual Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="issueForm">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Card Product</label>
                            <select name="product_id" class="form-select rounded-3">
                                <?php foreach($products as $p): ?>
                                <option value="<?php echo $p['chimoney_product_id']; ?>"><?php echo $p['name']; ?> (USD)</option>
                                <?php endforeach; if(empty($products)) echo "<option disabled>No products available. Contact Admin.</option>"; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Name on Card</label>
                            <input type="text" name="name_on_card" class="form-control rounded-3" placeholder="e.g. <?php echo $get_logged_user_details['firstname'] . ' ' . $get_logged_user_details['lastname']; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Initial Funding Amount ($)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="amount_usd" class="form-control" value="5" min="5" step="1" id="issAmt">
                            </div>
                            <small class="text-muted text-xs">Min $5. Total cost includes issuance fee of $<?php echo $issuance_profit; ?>.</small>
                        </div>
                        <div class="p-3 bg-light rounded-3 mb-3">
                            <div class="d-flex justify-content-between small">
                                <span>Est. Cost in Naira:</span>
                                <span class="fw-bold text-primary" id="issCost">NGN 0.00</span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold" onclick="submitIssuance()">Issue Card Now</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Fund Card Modal -->
    <div class="modal fade" id="fundCardModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0">
                    <h5 class="fw-bold">Top-up Virtual Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="fundForm">
                        <input type="hidden" name="card_ref" id="fund_ref">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Amount to Add ($)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="amount_usd" class="form-control" placeholder="0.00" step="0.1" id="fndAmt">
                            </div>
                            <small class="text-muted text-xs">Fee of <?php echo $funding_fee; ?>% applies.</small>
                        </div>
                        <div class="p-3 bg-light rounded-3 mb-3">
                            <div class="d-flex justify-content-between small">
                                <span>Total Debit (NGN):</span>
                                <span class="fw-bold text-primary" id="fndCost">NGN 0.00</span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-success w-100 rounded-pill fw-bold" onclick="submitFunding()">Confirm Funding</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    const USD_RATE = <?php require_once("../func/bc-giftcard-func.php"); echo getLiveExchangeRate('USD', 'NGN', $vid, 'virtual-card'); ?>;
    const ISS_FEE = <?php echo $issuance_profit; ?>;
    const FND_FEE_PCT = <?php echo $funding_fee; ?>;

    // Real-time cost updates
    document.getElementById('issAmt')?.addEventListener('input', updateIssCost);
    document.getElementById('fndAmt')?.addEventListener('input', updateFndCost);

    function updateIssCost() {
        let amt = parseFloat(document.getElementById('issAmt').value) || 0;
        let total_ngn = (amt + ISS_FEE) * USD_RATE;
        document.getElementById('issCost').innerText = 'NGN ' + total_ngn.toLocaleString();
    }

    function updateFndCost() {
        let amt = parseFloat(document.getElementById('fndAmt').value) || 0;
        let total_ngn = (amt * (1 + (FND_FEE_PCT/100))) * USD_RATE;
        document.getElementById('fndCost').innerText = 'NGN ' + total_ngn.toLocaleString();
    }

    updateIssCost();

    function revealSecurity(ref) {
        Swal.fire({
            title: 'Enter Transaction PIN',
            input: 'password',
            inputAttributes: { autocapitalize: 'off', maxlength: 4, pattern: '[0-9]*', inputmode: 'numeric' },
            showCancelButton: true,
            confirmButtonText: 'Reveal',
            showLoaderOnConfirm: true,
            preConfirm: (pin) => {
                return fetch('virtual-card-ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=reveal_security&pin=${pin}&card_ref=${ref}`
                }).then(res => res.json()).then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data.data;
                }).catch(err => Swal.showValidationMessage(`Error: ${err.message}`));
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const d = result.value;
                document.getElementById(`pan-${ref}`).innerText = d.masked_pan;
                document.getElementById(`exp-${ref}`).innerText = `${d.expiry_month}/${d.expiry_year}`;
                document.getElementById(`cvv-${ref}`).innerText = d.cvv;
                Swal.fire('Revealed', 'Card details are now visible on the card face.', 'success');
            }
        });
    }

    function submitIssuance() {
        const formData = new FormData(document.getElementById('issueForm'));
        formData.append('action', 'issue_card');

        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch('virtual-card-ajax.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                Swal.fire('Success!', data.message, 'success').then(() => window.location.reload());
            } else {
                Swal.fire('Failed', data.message, 'error');
            }
        });
    }

    function openFundModal(ref) {
        document.getElementById('fund_ref').value = ref;
        new bootstrap.Modal(document.getElementById('fundCardModal')).show();
    }

    function submitFunding() {
        const formData = new FormData(document.getElementById('fundForm'));
        formData.append('action', 'fund_card');

        Swal.fire({ title: 'Funding Card...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch('virtual-card-ajax.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                Swal.fire('Success!', data.message, 'success').then(() => window.location.reload());
            } else {
                Swal.fire('Failed', data.message, 'error');
            }
        });
    }

    function withdrawToWallet(ref, bal) {
        if(bal <= 0) return Swal.fire('Error', 'No funds to withdraw.', 'warning');

        Swal.fire({
            title: 'Withdraw to Wallet?',
            text: `Liquidation will return $${bal} (approx NGN ${(bal * USD_RATE).toLocaleString()}) to your main NGN wallet. This card will be terminated.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirm Withdrawal'
        }).then(res => {
            if(res.isConfirmed) {
                Swal.fire({ title: 'Liquidating...', didOpen: () => Swal.showLoading() });
                fetch('virtual-card-ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=withdraw_card&card_ref=${ref}`
                }).then(r => r.json()).then(data => {
                    if(data.status === 'success') Swal.fire('Success', data.message, 'success').then(() => window.location.reload());
                    else Swal.fire('Error', data.message, 'error');
                });
            }
        });
    }

    function reactivateCard(ref) {
        Swal.fire({
            title: 'Reactivate Card?',
            text: 'Ensure you have at least NGN 2,000 in your wallet to cover potential fees or provider retry charges.',
            icon: 'info',
            showCancelButton: true
        }).then(res => {
            if(res.isConfirmed) {
                fetch('virtual-card-ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=reactivate_card&card_ref=${ref}`
                }).then(r => r.json()).then(data => {
                    if(data.status === 'success') Swal.fire('Success', 'Card is now active.', 'success').then(() => window.location.reload());
                    else Swal.fire('Error', data.message, 'error');
                });
            }
        });
    }
    </script>
    <?php include("../func/bc-footer.php"); ?>
</body>
</html>
