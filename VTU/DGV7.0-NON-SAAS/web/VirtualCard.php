<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");
require_once("../func/bc-virtualcard-flutterwave.php");

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
// §25: Flutterwave is the ONLY virtual-card provider. One USD product; legacy
// Legacy third-party card products and tables are no longer consulted.
$products = [['product_id' => 'flw_usd', 'name' => 'USD Virtual Card']];

// Fetch Profit Settings for UI display
$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='$vid' AND setting_name LIKE 'vc_%'");
$vc_settings = []; while($rs = mysqli_fetch_assoc($q_set)) $vc_settings[$rs['setting_name']] = $rs['setting_value'];
$issuance_profit = (float)($vc_settings['vc_issuance_profit_usd'] ?? 2.00);
$funding_fee = (float)($vc_settings['vc_funding_profit_percent'] ?? 3.00);

?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Virtual Card Dashboard | " . ($get_all_site_details["site_title"]));
?>
<link rel="stylesheet" href="../assets/css/fintech-cards.css">
<style>
	/* Virtual Cards (Platnova) — builds on assets/css/fintech-cards.css, which the shell
	   migration left unlinked. Tokens: --brand, --ink, --muted, --line, --radius, --shadow. */
	.vc-pagehead {
		display: flex; align-items: center; justify-content: space-between; gap: 1rem;
		flex-wrap: wrap; margin-bottom: 1.25rem;
	}
	.vc-pagehead__title { font-size: 1.35rem; font-weight: 800; color: var(--ink); margin: 0; letter-spacing: -.02em; }
	.vc-pagehead__sub { color: var(--muted); font-size: .85rem; margin: 2px 0 0; }

	/* Card face: premium brand-gradient plastic, contactless-era proportions. */
	.vc-glass-card {
		background: linear-gradient(135deg, var(--brand) 0%, color-mix(in srgb, var(--brand) 70%, #0b1220) 100%) !important;
		border: 1px solid rgba(255,255,255,.25);
		border-radius: 20px;
		aspect-ratio: 1.586 / 1;
		min-height: 0;
		padding: 1.4rem 1.5rem;
		display: flex; flex-direction: column;
		box-shadow: var(--shadow);
	}
	.vc-glass-card::after {
		content: ''; position: absolute; width: 300px; height: 300px; border-radius: 50%;
		background: rgba(255,255,255,.10); top: -150px; right: -70px; pointer-events: none;
	}
	.vc-glass-card:hover { transform: translateY(-4px); }
	.vc-glass-card > div:last-child { margin-top: auto; }
	.vc-chip {
		width: 46px; height: 34px; border-radius: 7px; margin-bottom: 1.1rem;
		background: linear-gradient(135deg, #f6d365 0%, #d4a017 55%, #b8860b 100%);
		box-shadow: inset 0 0 0 1px rgba(120,72,0,.35), inset 0 -10px 0 rgba(120,72,0,.18);
		position: relative; z-index: 1;
	}
	.vc-number {
		font-family: 'Inter', system-ui, sans-serif; font-weight: 700; font-size: 1.28rem;
		letter-spacing: .14em; margin-bottom: 1.1rem; text-shadow: 0 1px 2px rgba(0,0,0,.25);
		position: relative; z-index: 1;
	}
	.vc-info-label { font-size: .62rem; letter-spacing: .12em; font-weight: 700; opacity: .72; }
	.vc-info-val { font-size: .95rem; font-weight: 700; letter-spacing: .04em; }
	.vc-brand i { font-size: 2.1rem; opacity: .95; }
	.vc-frozen-overlay { backdrop-filter: blur(3px); background: rgba(11,18,32,.55); }
	.vc-frozen-badge { background: var(--bad); letter-spacing: .08em; }

	.vc-status-indicator { width: 9px; height: 9px; box-shadow: none; vertical-align: 0; }
	.status-active { background: #34d399; box-shadow: 0 0 8px rgba(52,211,153,.9); }
	.status-frozen { background: #f87171; box-shadow: 0 0 8px rgba(248,113,113,.9); }

	.reveal-btn {
		background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3);
		border-radius: 999px; padding: 4px 12px; font-size: .7rem; font-weight: 700;
		position: relative; z-index: 1;
	}
	.reveal-btn:hover { background: rgba(255,255,255,.32); }

	/* Quick actions under each card */
	.btn-white {
		background: var(--surface); border: 1px solid var(--line) !important; color: var(--ink);
		font-weight: 700; transition: all .18s ease;
	}
	.btn-white:hover { transform: translateY(-2px); box-shadow: var(--shadow); color: var(--ink); }

	/* Empty state */
	.empty-state {
		border: 1.5px dashed var(--line); border-radius: var(--radius); background: var(--surface);
		padding: 3rem 1.5rem; text-align: center;
	}
	.empty-state .display-1 { font-size: 3.2rem; line-height: 1; }

	.text-xs { font-size: .72rem; }

	/* Insights sidebar */
	.vc-insight-head {
		display: flex; align-items: center; gap: .7rem; padding: 1.1rem 1.4rem;
		border-bottom: 1px solid var(--line);
	}
	.vc-insight-ic {
		width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center;
		background: color-mix(in srgb, var(--brand) 12%, #fff); color: var(--brand); font-size: 1.1rem;
	}

	@media (max-width: 575px) {
		.vc-number { font-size: 1.05rem; }
		.vc-glass-card { padding: 1.1rem 1.2rem; }
	}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "Virtual Cards", "crumb" => "Home / Virtual Card")); ?>
    <div class="vc-pagehead">
        <div>
            <h2 class="vc-pagehead__title">My Virtual Cards</h2>
            <p class="vc-pagehead__sub">USD virtual cards for global online payments — fund, freeze and reveal securely.</p>
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
                        <div class="vc-glass-card <?php echo $is_frozen ? 'frozen' : ''; ?>">
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
                    <div class="vc-insight-head">
                        <div class="vc-insight-ic"><i class="bi bi-wallet2"></i></div>
                        <h6 class="fw-bold mb-0">Balance &amp; Insights</h6>
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
                                <option value="<?php echo $p['product_id']; ?>"><?php echo $p['name']; ?> (USD)</option>
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
    
	<?php bc_app_foot(); ?>
