<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}
$vid = (int)$get_logged_user_details['vendor_id'];
$svc_user = $get_logged_user_details;
require_once __DIR__ . '/../func/bc-service-request-idempotency.php';
require_once __DIR__ . '/../func/bc-service-intake-bridge.php';
$svc_form_key = bc_svc_form_key($_POST['request_key'] ?? null);
require_once __DIR__ . '/../func/bc-service-provider-routing.php';
$route_provider = bc_service_route_provider($vid, 'tuition');

if (!isServiceEnabled('tuition')) {
    header("Location: Dashboard.php");
    exit();
}
$universities = [
    'University of Oxford (United Kingdom)', 'University of Cambridge (United Kingdom)',
    'University of Manchester (United Kingdom)', 'University of Toronto (Canada)',
    'University of British Columbia (Canada)', 'McGill University (Canada)',
    'Harvard University (USA)', 'Stanford University (USA)', 'MIT (USA)',
    'University of Melbourne (Australia)', 'University of Sydney (Australia)',
    'University of Auckland (New Zealand)', 'Trinity College Dublin (Ireland)',
    'University of Amsterdam (Netherlands)', 'TU Munich (Germany)',
    'University of Cape Town (South Africa)', 'University of Ghana (Ghana)',
    'HIT — Harbin Institute of Technology (China)', 'Tsinghua University (China)',
    'University of Malaya (Malaysia)', 'University of Dubai (UAE)',
];
$notice = '';
$notice_type = 'info';

if (isset($_POST['pay_tuition'])) {
    bc_validate_csrf();
    if (!is_string($_POST['route_snapshot'] ?? null) || !hash_equals($route_provider, $_POST['route_snapshot'])) {
        http_response_code(409);
        exit('Service routing changed. Reload this page before submitting a new request.');
    }
    $payload = [
        'university' => trim(strip_tags($_POST['university'] ?? '')),
        'student_name' => trim(strip_tags($_POST['student_name'] ?? '')),
        'student_id' => trim(strip_tags($_POST['student_id'] ?? '')),
        'amount_usd' => (float)($_POST['amount_usd'] ?? 0),
    ];
    if ($payload['university'] === '' || $payload['student_id'] === '' || $payload['amount_usd'] <= 0) {
        $notice = 'Choose a university, enter the student ID and a valid amount. The student ID is validated before settlement.';
        $notice_type = 'danger';
    } else {
        $svc_result = bc_svc_request_once($vid, $svc_user, 'tuition', $route_provider, $payload, $_POST['request_key'] ?? null);
        $ref = $svc_result['reference'];
        if ($ref !== '' && empty($svc_result['replayed'])) {
            bc_svc_enqueue_held_attempt($vid, $ref);
        }
        $notice = $ref !== '' ? "Tuition payment request received ($ref). Student ID validation runs before settlement." : bc_svc_retry_message($svc_result);
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
if (isset($svc_result) && $svc_result['ok'] && $svc_result['replayed']) {
    $notice = 'Existing request recovered; no new request was created. ' . $notice;
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'tuition');

// Stats calculation
$total_tuition_req = count($my_requests);
$total_tuition_usd = 0.0;
$settled_tuition = 0;
foreach ($my_requests as $r) {
    $rp = json_decode($r['payload'] ?? '{}', true) ?: [];
    $total_tuition_usd += (float)($rp['amount_usd'] ?? 0);
    if (in_array(strtolower((string)($r['status'] ?? '')), ['completed', 'approved', 'settled', 'success'], true)) {
        $settled_tuition++;
    }
}
$vendor_brand = trim((string)($get_all_site_details['site_title'] ?? ''));
if ($vendor_brand === '') {
    $vendor_brand = 'Platform';
}

include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Pay Tuition Worldwide | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "PAY TUITION WORLDWIDE", "crumb" => "Home / Tuition"));
?>
<style>
    .ft-hero {
        position: relative; overflow: hidden; border-radius: 20px; padding: 28px; color: #fff;
        background: linear-gradient(135deg, #0b1220 0%, #172554 48%, var(--brand-dark, #0f766e) 100%);
        box-shadow: 0 20px 44px -22px rgba(11, 18, 32, 0.55);
    }
    .ft-pill {
        display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border-radius: 999px;
        background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22); font-size: 12px; font-weight: 700;
    }
    .ft-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }
    .ft-stat {
        background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px; height: 100%;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03); transition: transform .18s ease, box-shadow .18s ease;
    }
    .ft-stat:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06); }
    .ft-stat-ico {
        width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
        background: rgba(var(--bs-primary-rgb), .10); color: var(--brand-ink); margin-bottom: 12px;
    }
    .ft-stat-val { font-size: 1.45rem; font-weight: 800; color: var(--ink); line-height: 1.15; }
    .ft-stat-lbl { font-size: 12.5px; color: var(--muted); margin-top: 3px; }
    .ft-feat-card {
        border: 1px solid var(--line); border-radius: 16px; padding: 16px; background: #fff; height: 100%;
    }
    .ft-uni-chip {
        border: 1px solid var(--line); background: rgba(var(--wash-rgb), .7); color: var(--ink);
        padding: 7px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; cursor: pointer;
        transition: all .15s ease; text-align: left;
    }
    .ft-uni-chip:hover, .ft-uni-chip.is-on {
        border-color: var(--brand); background: rgba(var(--bs-primary-rgb), .10); color: var(--brand-ink);
    }
    .ft-amt-chip {
        border: 1px solid var(--line); background: #fff; color: var(--ink);
        padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; cursor: pointer;
    }
    .ft-amt-chip:hover, .ft-amt-chip.is-on {
        border-color: var(--brand); background: var(--brand); color: var(--brand-fg);
    }
    .ft-timeline-item {
        display: flex; gap: 12px; align-items: flex-start; padding-bottom: 14px;
        border-bottom: 1px dashed var(--line); margin-bottom: 14px;
    }
    .ft-timeline-item:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
    .ft-num {
        width: 28px; height: 28px; border-radius: 9px; flex: none;
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(var(--bs-primary-rgb), .12); color: var(--brand-ink);
        font-size: 12px; font-weight: 800;
    }
    .ft-status-pill {
        display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px;
        font-size: 11.5px; font-weight: 700; text-transform: capitalize;
    }
    .ft-status-pill.ok { background: rgba(var(--ok-rgb), .12); color: var(--ok); }
    .ft-status-pill.warn { background: rgba(var(--warn-rgb), .14); color: #92400e; }
    .ft-status-pill.muted { background: rgba(var(--muted-rgb), .14); color: var(--muted); }
    .ft-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); border-bottom: 1px solid var(--line); padding: 10px 12px; }
    .ft-table td { padding: 12px; border-bottom: 1px solid var(--line); font-size: 13.5px; vertical-align: middle; }
</style>

<section class="section dashboard">
    <!-- Hero -->
    <div class="ft-hero mb-4">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="ft-pill"><span class="ft-dot"></span> Global Tuition Payments</span>
                    <span class="ft-pill">160+ Partner Universities</span>
                    <span class="ft-pill">Student ID Pre-Validation</span>
                </div>
                <h3 class="fw-bold mb-2">Pay tuition to 160+ universities worldwide</h3>
                <p class="opacity-75 mb-0" style="max-width:62ch">Search your institution, validate the student ID, and settle tuition or accommodation fees in the school's expected currency without correspondent-bank delays.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" onclick="var f=document.getElementById('tuition-request-form');if(f){f.scrollIntoView({behavior:'smooth',block:'start'});}var u=document.getElementById('tuitionUniInput');if(u){setTimeout(function(){u.focus({preventScroll:true});},350);}return false;">Start Tuition Payment</button>
            </div>
        </div>
    </div>

    <!-- 4 Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/></svg></div>
                <div class="ft-stat-val">160+</div>
                <div class="ft-stat-lbl">Supported universities globally</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div class="ft-stat-val">$<?php echo number_format($total_tuition_usd, 2); ?></div>
                <div class="ft-stat-lbl">Total tuition requested</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8"/></svg></div>
                <div class="ft-stat-val"><?php echo (int)$total_tuition_req; ?></div>
                <div class="ft-stat-lbl">Requests (<?php echo (int)$settled_tuition; ?> settled)</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.5 9 8 10c4.5-1 8-5 8-10V6z"/></svg></div>
                <div class="ft-stat-val"><?php echo htmlspecialchars($route_provider !== '' ? $vendor_brand : 'Paused'); ?></div>
                <div class="ft-stat-lbl">Verified institutional rail</div>
            </div>
        </div>
    </div>

    <!-- 4 Feature & Function Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="ft-feat-card">
                <span class="badge bg-primary bg-opacity-10 text-primary mb-2">Direct Settlement</span>
                <strong class="d-block mb-1">Zero Intermediary Delays</strong>
                <span class="small text-muted">Avoid hidden correspondent wire fees—schools receive the exact fee invoiced.</span>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="ft-feat-card">
                <span class="badge bg-success bg-opacity-10 text-success mb-2">Verification</span>
                <strong class="d-block mb-1">Student ID Pre-Check</strong>
                <span class="small text-muted">Every student ID and institution pair is verified before funds are released.</span>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="ft-feat-card">
                <span class="badge bg-info bg-opacity-10 text-info mb-2">Multi-Currency</span>
                <strong class="d-block mb-1">USD · GBP · EUR · CAD · CNY</strong>
                <span class="small text-muted">Settle tuition, housing deposits and application charges in local institutional currency.</span>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="ft-feat-card">
                <span class="badge bg-secondary bg-opacity-10 text-dark mb-2">Audit Trail</span>
                <strong class="d-block mb-1">Verifiable Payment Receipts</strong>
                <span class="small text-muted">Every request gets an immutable reference for bursar and visa confirmation.</span>
            </div>
        </div>
    </div>

    <!-- Main Form + Timeline & Quick Pick -->
    <div class="row g-4" id="tuition-request-form">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">Tuition payment request</h6>
                        <a href="Tuition.php" class="small fw-semibold">New request</a>
                    </div>
                    <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
                    <?php if ($route_provider === ''): ?><p class="alert alert-warning small">Request routing is disabled or unavailable. No request can be submitted.</p><?php endif; ?>
                    <p class="small text-muted mb-3">Submitting records a request for review only. It does not debit a wallet, execute a payment or confirm provider acceptance.</p>

                    <form method="post">
                        <input type="hidden" name="request_key" value="<?php echo htmlspecialchars($svc_form_key, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo bc_csrf_field(); ?>
                        <input type="hidden" name="route_snapshot" value="<?php echo htmlspecialchars($route_provider, ENT_QUOTES, 'UTF-8'); ?>">
                        <p class="small text-muted">Keep this form unchanged when retrying an unconfirmed submission. <a href="Tuition.php">Start a new request</a> only for a separate request.</p>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">University / Institution</label>
                            <input type="text" name="university" id="tuitionUniInput" class="form-control" list="uniList" placeholder="Start typing to search 160+ institutions" required>
                            <datalist id="uniList"><?php foreach ($universities as $u): ?><option value="<?php echo htmlspecialchars($u); ?>"><?php endforeach; ?></datalist>
                        </div>

                        <div class="mb-3">
                            <span class="d-block small fw-bold mb-2">Popular Institutions (Click to Select)</span>
                            <div class="d-flex flex-wrap gap-2" id="tuitionUniChips">
                                <?php foreach (array_slice($universities, 0, 6) as $u): ?>
                                    <button type="button" class="ft-uni-chip" data-uni="<?php echo htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($u); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Student full name</label>
                                <input type="text" name="student_name" id="tuitionStudentName" class="form-control" placeholder="Name on admission letter" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Student ID</label>
                                <input type="text" name="student_id" id="tuitionStudentId" class="form-control" placeholder="e.g. STU-2026-9041" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">Amount (USD)</label>
                                <div class="d-flex flex-wrap gap-2 mb-2" id="tuitionAmtChips">
                                    <button type="button" class="ft-amt-chip" data-amt="500">$500</button>
                                    <button type="button" class="ft-amt-chip" data-amt="1500">$1,500</button>
                                    <button type="button" class="ft-amt-chip" data-amt="3000">$3,000</button>
                                    <button type="button" class="ft-amt-chip" data-amt="5000">$5,000</button>
                                </div>
                                <input type="number" step="0.01" min="1" name="amount_usd" id="tuitionAmtInput" class="form-control" placeholder="Enter tuition amount in USD" required>
                            </div>
                        </div>
                        <button type="submit" name="pay_tuition" <?php echo $route_provider === '' ? 'disabled' : ''; ?> class="btn btn-primary rounded-pill px-4 fw-bold mt-4">Submit Tuition Request</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4 d-flex flex-column justify-content-between gap-3">
                    <div>
                        <h6 class="fw-bold mb-3">How tuition settlement works</h6>
                        <div class="ft-timeline-item">
                            <span class="ft-num">01</span>
                            <div><strong class="d-block small">Select Institution</strong><span class="small text-muted">Search and choose from 160+ supported universities globally.</span></div>
                        </div>
                        <div class="ft-timeline-item">
                            <span class="ft-num">02</span>
                            <div><strong class="d-block small">Validate Student ID</strong><span class="small text-muted">The student ID is matched against institutional records before money moves.</span></div>
                        </div>
                        <div class="ft-timeline-item">
                            <span class="ft-num">03</span>
                            <div><strong class="d-block small">Confirm Live FX</strong><span class="small text-muted">Review locked FX rates and settle from your wallet balance.</span></div>
                        </div>
                        <div class="ft-timeline-item">
                            <span class="ft-num">04</span>
                            <div><strong class="d-block small">Track &amp; Download Receipt</strong><span class="small text-muted">Monitor institutional settlement in your request history below.</span></div>
                        </div>
                    </div>

                    <div class="p-3 rounded-4 bg-light border">
                        <div class="small fw-bold mb-2">Live Request Preview</div>
                        <div class="d-flex justify-content-between small py-1"><span class="text-muted">Institution</span><strong id="sumUni">—</strong></div>
                        <div class="d-flex justify-content-between small py-1"><span class="text-muted">Student ID</span><strong id="sumSid">—</strong></div>
                        <div class="d-flex justify-content-between small py-1"><span class="text-muted">Amount</span><strong id="sumTuitAmt">$0.00</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tuition Request History Ledger -->
    <div class="card border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h6 class="fw-bold mb-0">My Tuition Payment History</h6>
                    <span class="small text-muted">All recorded tuition requests and live validation statuses</span>
                </div>
                <span class="badge bg-secondary bg-opacity-10 text-dark"><?php echo (int)$total_tuition_req; ?> records</span>
            </div>
            <?php if ($my_requests): ?>
            <div class="table-responsive">
                <table class="table ft-table mb-0">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Institution</th>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_requests as $r):
                            $p = json_decode($r['payload'] ?? '{}', true) ?: [];
                            $st = strtolower((string)($r['status'] ?? 'pending'));
                            $pill_cls = in_array($st, ['completed', 'approved', 'settled', 'success'], true) ? 'ok' : (in_array($st, ['pending', 'held', 'review'], true) ? 'warn' : 'muted');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['reference']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['university'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['student_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['student_id'] ?? '—'); ?></td>
                            <td><strong>$<?php echo number_format((float)($p['amount_usd'] ?? 0), 2); ?></strong></td>
                            <td><span class="ft-status-pill <?php echo $pill_cls; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="p-4 text-center rounded-4 bg-light small text-muted">No tuition payment requests yet. Select an institution above to begin.</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function () {
    var uniInput = document.getElementById('tuitionUniInput');
    var sidInput = document.getElementById('tuitionStudentId');
    var amtInput = document.getElementById('tuitionAmtInput');
    var uniChips = document.querySelectorAll('#tuitionUniChips [data-uni]');
    var amtChips = document.querySelectorAll('#tuitionAmtChips [data-amt]');
    var sumUni = document.getElementById('sumUni');
    var sumSid = document.getElementById('sumSid');
    var sumAmt = document.getElementById('sumTuitAmt');

    function syncPreview() {
        if (sumUni && uniInput) sumUni.textContent = uniInput.value.trim() || '—';
        if (sumSid && sidInput) sumSid.textContent = sidInput.value.trim() || '—';
        if (sumAmt && amtInput) {
            var v = parseFloat(amtInput.value) || 0;
            sumAmt.textContent = '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    for (var i = 0; i < uniChips.length; i++) {
        uniChips[i].addEventListener('click', function () {
            if (uniInput) uniInput.value = this.getAttribute('data-uni');
            for (var k = 0; k < uniChips.length; k++) uniChips[k].classList.toggle('is-on', uniChips[k] === this);
            syncPreview();
        });
    }
    for (var j = 0; j < amtChips.length; j++) {
        amtChips[j].addEventListener('click', function () {
            if (amtInput) amtInput.value = this.getAttribute('data-amt');
            for (var k = 0; k < amtChips.length; k++) amtChips[k].classList.toggle('is-on', amtChips[k] === this);
            syncPreview();
        });
    }
    if (uniInput) uniInput.addEventListener('input', syncPreview);
    if (sidInput) sidInput.addEventListener('input', syncPreview);
    if (amtInput) amtInput.addEventListener('input', syncPreview);
    syncPreview();
})();
</script>
<?php bc_app_foot(); ?>
