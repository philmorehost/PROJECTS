<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-config.php");
require_once("../func/bc-virtualcard-flutterwave.php");
require_once("../func/bc-virtualcard-execution.php");

$vid = resolveVendorID();
$uname = $_SESSION["user_session"] ?? "";
$vc_execution_enabled = bc_vc_execution_enabled();

if (!isServiceEnabled('virtual_card')) {
    header("Location: Dashboard.php");
    exit();
}

// Fetch user's issued cards (zero CVV stored or exposed)
$cards = [];
$q_cards = mysqli_query($connection_server, "SELECT reference, card_name, masked_pan, expiry_month, expiry_year, balance_usd, status, provider, created_at FROM sas_virtual_cards_v2 WHERE vendor_id='$vid' AND username='$uname' AND status != 'terminated' ORDER BY created_at DESC");
if ($q_cards) {
    while ($r = mysqli_fetch_assoc($q_cards)) {
        $cards[] = $r;
    }
}

// Fetch pending / held virtual-card orders when migration table exists
$pending_orders = [];
$uid_self = (int)($get_logged_user_details['id'] ?? 0);
$tbl_chk = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_virtualcard_orders'");
if ($uid_self > 0 && $tbl_chk && mysqli_num_rows($tbl_chk) > 0) {
    $q_ord = $connection_server->prepare(
        "SELECT reference, action, amount_usd, fee_usd, total_ngn, state, version, created_at
         FROM sas_virtualcard_orders
         WHERE vendor_id=? AND user_id=? AND state IN ('held','in_flight','unknown')
         ORDER BY id DESC LIMIT 20"
    );
    if ($q_ord) {
        $q_ord->bind_param('ii', $vid, $uid_self);
        $q_ord->execute();
        $pending_orders = $q_ord->get_result()->fetch_all(MYSQLI_ASSOC);
        $q_ord->close();
    }
}

// §25: Flutterwave is the ONLY virtual-card provider. One USD product.
$products = [['product_id' => 'flw_usd', 'name' => 'USD Virtual Card']];

// Fetch Profit Settings for UI display
$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='$vid' AND setting_name LIKE 'vc_%'");
$vc_settings = [];
if ($q_set) {
    while ($rs = mysqli_fetch_assoc($q_set)) {
        $vc_settings[$rs['setting_name']] = $rs['setting_value'];
    }
}
$issuance_profit = (float)($vc_settings['vc_issuance_profit_usd'] ?? 2.00);
$funding_fee = (float)($vc_settings['vc_funding_profit_percent'] ?? 3.00);

include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Virtual Card Dashboard | " . ($get_all_site_details["site_title"]));
?>
<link rel="stylesheet" href="../assets/css/fintech-cards.css">
<style>
	.vc-pagehead {
		display: flex; align-items: center; justify-content: space-between; gap: 1rem;
		flex-wrap: wrap; margin-bottom: 1.25rem;
	}
	.vc-pagehead__title { font-size: 1.35rem; font-weight: 800; color: var(--ink); margin: 0; letter-spacing: -.02em; }
	.vc-pagehead__sub { color: var(--muted); font-size: .85rem; margin: 2px 0 0; }
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
	.btn-white {
		background: var(--surface); border: 1px solid var(--line) !important; color: var(--ink);
		font-weight: 700; transition: all .18s ease;
	}
	.btn-white:hover { transform: translateY(-2px); box-shadow: var(--shadow); color: var(--ink); }
	.empty-state {
		border: 1.5px dashed var(--line); border-radius: var(--radius); background: var(--surface);
		padding: 3rem 1.5rem; text-align: center;
	}
	.empty-state .display-1 { font-size: 3.2rem; line-height: 1; }
	.text-xs { font-size: .72rem; }
	.vc-insight-head {
		display: flex; align-items: center; gap: .7rem; padding: 1.1rem 1.4rem;
		border-bottom: 1px solid var(--line);
	}
	.vc-insight-ic {
		width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center;
		background: color-mix(in srgb, var(--brand) 12%, #fff); color: var(--brand); font-size: 1.1rem;
	}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar("Virtual Cards", "Home / Virtual Cards"); ?>

    <section class="section dashboard">
        <div class="vc-pagehead">
            <div>
                <h1 class="vc-pagehead__title">Virtual Cards</h1>
                <p class="vc-pagehead__sub">Spend globally in USD — masked PAN, durable settlement, and instant freeze controls.</p>
            </div>
            <?php if ($vc_execution_enabled): ?>
            <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#issueCardModal">
                <i class="bi bi-plus-circle me-1"></i> Issue New Card
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary rounded-pill px-4" disabled title="Virtual-card issuance is currently paused">
                <i class="bi bi-pause-circle me-1"></i> Issuance Paused
            </button>
            <?php endif; ?>
        </div>

        <?php if (!$vc_execution_enabled): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4">
            <strong><i class="bi bi-shield-lock me-1"></i> Virtual-Card Security Hold Active:</strong>
            New virtual-card issuance, funding, and legacy card reveal actions are currently paused while provider reconciliation is finalized. Existing cards remain listed read-only below.
        </div>
        <?php endif; ?>

        <?php if (!empty($pending_orders)): ?>
        <div class="card border-0 rounded-4 shadow-sm mb-4">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4">Pending Virtual-Card Reservations</div>
            <div class="card-body px-4 pb-3 table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Reference</th>
                            <th>Action</th>
                            <th>USD</th>
                            <th>Total (NGN)</th>
                            <th>State</th>
                            <th class="text-end">Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_orders as $po): ?>
                        <tr>
                            <td class="font-monospace small"><?php echo htmlspecialchars((string)$po['reference']); ?></td>
                            <td><?php echo htmlspecialchars(strtoupper((string)$po['action'])); ?></td>
                            <td>$<?php echo htmlspecialchars((string)$po['amount_usd']); ?></td>
                            <td>₦<?php echo number_format((float)$po['total_ngn'], 2); ?></td>
                            <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars((string)$po['state']); ?></span></td>
                            <td class="text-end">
                                <?php if ($vc_execution_enabled && ($po['state'] ?? '') === 'held'): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill" onclick="cancelHeldOrder('<?php echo htmlspecialchars((string)$po['reference'], ENT_QUOTES); ?>', <?php echo (int)$po['version']; ?>)">Cancel &amp; Refund</button>
                                <?php else: ?>
                                <span class="text-muted small">Worker pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="row g-4">
                    <?php if (empty($cards)): ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="bi bi-credit-card-2-front display-1 text-muted opacity-25"></i>
                            <h4 class="mt-3 fw-bold">No Virtual Cards Yet</h4>
                            <p class="text-muted">Create your first USD virtual card to pay for international ads, subscriptions, and shopping.</p>
                            <?php if ($vc_execution_enabled): ?>
                            <button class="btn btn-primary rounded-pill px-4 mt-2" data-bs-toggle="modal" data-bs-target="#issueCardModal">Get Started Now</button>
                            <?php else: ?>
                            <button class="btn btn-secondary rounded-pill px-4 mt-2" disabled>Issuance Paused</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: foreach ($cards as $c):
                        $is_frozen = ($c['status'] == 'frozen');
                        $ref = htmlspecialchars((string)$c['reference']);
                    ?>
                    <div class="col-md-6">
                        <div class="vc-card-container">
                            <div class="vc-glass-card">
                                <?php if ($is_frozen): ?>
                                <div class="vc-frozen-overlay">
                                    <span class="vc-frozen-badge">CARD FROZEN</span>
                                </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <span class="vc-status-indicator <?php echo $is_frozen ? 'status-frozen' : 'status-active'; ?>"></span>
                                        <span class="small fw-bold opacity-75"><?php echo strtoupper(htmlspecialchars((string)$c['status'])); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-white bg-opacity-25 rounded-pill px-3 py-1 fw-bold">$<?php echo number_format((float)$c['balance_usd'], 2); ?></span>
                                    </div>
                                </div>

                                <div class="vc-chip"></div>

                                <div class="vc-number" id="pan-<?php echo $ref; ?>">
                                    <?php echo htmlspecialchars((string)$c['masked_pan']); ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-end">
                                    <div class="d-flex gap-4">
                                        <div>
                                            <div class="vc-info-label">CARD HOLDER</div>
                                            <div class="vc-info-val text-uppercase"><?php echo htmlspecialchars((string)$c['card_name']); ?></div>
                                        </div>
                                        <div>
                                            <div class="vc-info-label">EXPIRES</div>
                                            <div class="vc-info-val" id="exp-<?php echo $ref; ?>"><?php echo htmlspecialchars((string)$c['expiry_month'] . '/' . (string)$c['expiry_year']); ?></div>
                                        </div>
                                    </div>
                                    <div class="vc-brand">
                                        <i class="bi bi-credit-card-2-back"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3 px-2">
                                <button class="btn btn-sm btn-white border shadow-sm flex-grow-1 rounded-3 py-2" <?php echo $vc_execution_enabled ? '' : 'disabled'; ?> onclick="openFundModal('<?php echo htmlspecialchars((string)$c['reference'], ENT_QUOTES); ?>')">
                                    <i class="bi bi-plus-circle text-primary me-1"></i> Fund
                                </button>
                                <span class="btn btn-sm btn-light border flex-grow-1 rounded-3 py-2 text-muted">
                                    <i class="bi bi-shield-check me-1"></i> Masked PAN
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="card border-0 rounded-4 shadow-sm">
                    <div class="vc-insight-head">
                        <div class="vc-insight-ic"><i class="bi bi-shield-check"></i></div>
                        <div>
                            <div class="fw-bold">Card Controls</div>
                            <div class="text-muted small">PCI-safe masked PAN &amp; durable settlement</div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <ul class="list-unstyled small text-muted mb-0 d-grid gap-2">
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Issuance Fee: <strong>$<?php echo number_format($issuance_profit, 2); ?></strong></li>
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Funding Fee: <strong><?php echo number_format($funding_fee, 2); ?>%</strong></li>
                            <li><i class="bi bi-lock-fill text-primary me-2"></i>Full PAN/CVV is never persisted in platform storage.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Issue New Card Modal -->
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
                                <?php foreach ($products as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['product_id']); ?>"><?php echo htmlspecialchars($p['name']); ?> (USD)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Name on Card</label>
                            <input type="text" name="card_name" id="issCardName" class="form-control rounded-3" placeholder="e.g. <?php echo htmlspecialchars(($get_logged_user_details['firstname'] ?? '') . ' ' . ($get_logged_user_details['lastname'] ?? '')); ?>" required>
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
                        <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold" <?php echo $vc_execution_enabled ? '' : 'disabled'; ?> onclick="submitIssuance()">Reserve Card Issuance</button>
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
                        <input type="hidden" name="card_reference" id="fund_ref">
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
                        <button type="button" class="btn btn-success w-100 rounded-pill fw-bold" <?php echo $vc_execution_enabled ? '' : 'disabled'; ?> onclick="submitFunding()">Reserve Card Funding</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    const USD_RATE = <?php require_once("../func/bc-giftcard-func.php"); echo (float)getLiveExchangeRate('USD', 'NGN', $vid, 'virtual-card'); ?>;
    const ISS_FEE = <?php echo $issuance_profit; ?>;
    const FND_FEE_PCT = <?php echo $funding_fee; ?>;
    const VC_CSRF_TOKEN = <?php echo json_encode((string)bc_csrf_token()); ?>;
    const VC_ENABLED = <?php echo $vc_execution_enabled ? 'true' : 'false'; ?>;

    document.getElementById('issAmt')?.addEventListener('input', updateIssCost);
    document.getElementById('fndAmt')?.addEventListener('input', updateFndCost);

    function updateIssCost() {
        let amt = parseFloat(document.getElementById('issAmt').value) || 0;
        let total_ngn = (amt + ISS_FEE) * USD_RATE;
        document.getElementById('issCost').innerText = 'NGN ' + total_ngn.toLocaleString();
    }

    function updateFndCost() {
        let amt = parseFloat(document.getElementById('fndAmt').value) || 0;
        let total_ngn = (amt * (1 + (FND_FEE_PCT / 100))) * USD_RATE;
        document.getElementById('fndCost').innerText = 'NGN ' + total_ngn.toLocaleString();
    }

    updateIssCost();

    function genRequestKey() {
        const bytes = new Uint8Array(32);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    async function callVirtualCardV2(payload) {
        const pinAsk = await Swal.fire({
            title: 'Enter Transaction PIN',
            input: 'password',
            inputAttributes: { autocapitalize: 'off', maxlength: 6, pattern: '[0-9]*', inputmode: 'numeric' },
            showCancelButton: true,
            confirmButtonText: 'Authorize'
        });
        if (!pinAsk.isConfirmed || !pinAsk.value) return;

        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const body = Object.assign({}, payload, { pin: pinAsk.value, request_key: genRequestKey() });
        const res = await fetch('api/virtual-card-v2.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': VC_CSRF_TOKEN
            },
            body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({ status: 'error', message: 'Unexpected server response.' }));
        if (res.ok && data.status === 'success') {
            Swal.fire('Reserved!', 'Order ' + (data.reference || '') + ' (' + (data.state || 'held') + ') queued for provider dispatch.', 'success').then(() => window.location.reload());
        } else {
            Swal.fire('Refused', data.message || data.code || 'Unable to process request.', 'error');
        }
    }

    function submitIssuance() {
        if (!VC_ENABLED) return Swal.fire('Paused', 'Virtual-card operations are currently paused.', 'info');
        const cardName = (document.getElementById('issCardName').value || '').trim();
        const amt = (document.getElementById('issAmt').value || '0').trim();
        if (!cardName) return Swal.fire('Required', 'Please enter a name for the card.', 'warning');
        callVirtualCardV2({ action: 'issue', card_name: cardName, amount_usd: amt });
    }

    function openFundModal(ref) {
        document.getElementById('fund_ref').value = ref;
        new bootstrap.Modal(document.getElementById('fundCardModal')).show();
    }

    function submitFunding() {
        if (!VC_ENABLED) return Swal.fire('Paused', 'Virtual-card operations are currently paused.', 'info');
        const cardRef = (document.getElementById('fund_ref').value || '').trim();
        const amt = (document.getElementById('fndAmt').value || '0').trim();
        callVirtualCardV2({ action: 'fund', card_reference: cardRef, amount_usd: amt });
    }

    function cancelHeldOrder(ref, version) {
        if (!VC_ENABLED) return;
        callVirtualCardV2({ action: 'cancel_held', reference: ref, version: version });
    }
    </script>

<?php bc_app_foot(); ?>
