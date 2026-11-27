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
$svc_form_key = bc_svc_form_key($_POST['request_key'] ?? null);

if (!isServiceEnabled('esim')) {
    header("Location: Dashboard.php");
    exit();
}
$plans = [
    ['name' => 'Global Starter', 'data' => '3 GB', 'days' => '15 days', 'price' => 7.50],
    ['name' => 'Global Traveller', 'data' => '10 GB', 'days' => '30 days', 'price' => 18.00],
    ['name' => 'Global Pro', 'data' => '25 GB', 'days' => '30 days', 'price' => 39.00],
];
// §26: live plan catalogue. When the merchant's Platnova partner eSIM is
// configured, plans (with real NGN kobo pricing) come from GET
// /v1/partner/esim/plans; otherwise the static catalogue below is shown and
// orders are recorded for 1GLOBAL fulfilment. Display-only: no wallet is
// debited from this page (see DGV7_FINTECH_UPGRADE.md §26).
include_once(__DIR__ . "/../func/bc-fintech-platnova.php");
$live_plans = [];
$esim_rail = '1GLOBAL';
if (function_exists('bc_fintech_provider_configured') && bc_fintech_provider_configured($vid, 'platnova', 'esim_plans')['ready']) {
    $res = bc_platnova_esim_plans($vid);
    $rows = [];
    if (!empty($res['ok']) && is_array($res['json'])) {
        $rows = is_array($res['json']['data'] ?? null) ? $res['json']['data'] : $res['json'];
    }
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['plan_id'])) continue;
            $mb = (int)($row['data_mb'] ?? 0);
            $live_plans[] = [
                'plan_id' => (string)$row['plan_id'],
                'name' => (string)($row['name'] ?? $row['plan_id']),
                'data' => !empty($row['unlimited']) ? 'Unlimited data' : ($mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB' : $mb . ' MB'),
                'days' => ((int)($row['duration_days'] ?? 0)) . ' days',
                'price_ngn' => ((float)($row['amount_minor'] ?? 0)) / 100,
                'countries' => implode(', ', array_map('strval', (array)($row['countries'] ?? []))),
            ];
        }
    }
    if ($live_plans) $esim_rail = 'Platnova';
}
$notice = '';
$notice_type = 'info';

if (isset($_POST['order_esim'])) {
    bc_validate_csrf();
    $is_live = isset($_POST['plan_id']) && trim((string)$_POST['plan_id']) !== '';
    $payload = [
        'plan' => $is_live ? trim(strip_tags($_POST['plan_id'])) : trim(strip_tags($_POST['plan'] ?? '')),
        'email' => trim(strip_tags($_POST['email'] ?? '')),
    ];
    if ($is_live) $payload['plan_id'] = $payload['plan'];
    if ($payload['plan'] === '' || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $notice = 'Choose a plan and enter a valid email for QR delivery.';
        $notice_type = 'danger';
    } else {
        $svc_result = bc_svc_request_once($vid, $svc_user, 'esim', $is_live ? 'platnova' : '1global', $payload, $_POST['request_key'] ?? null);
        $ref = $svc_result['reference'];
        $notice = $ref !== '' ? "eSIM order received ($ref). This records a review request only; no provisioning or delivery is confirmed." : bc_svc_retry_message($svc_result);
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
if (isset($svc_result) && $svc_result['ok'] && $svc_result['replayed']) {
    $notice = 'Existing request recovered; no new request was created. ' . $notice;
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'esim');
$plan_count = $live_plans ? count($live_plans) : count($plans);
$order_count = count($my_requests);

include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("eSIM | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "ESIM", "crumb" => "Home / eSIM"));
?>
<style>
    .ft-hero {
        position: relative; overflow: hidden; border-radius: 20px; padding: 28px; color: #fff;
        background: linear-gradient(135deg, #0b1220 0%, #0f2942 52%, var(--brand-dark, #0f766e) 100%);
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
    .ft-step-card {
        border: 1px solid var(--line); border-radius: 16px; padding: 18px; background: #fff; height: 100%;
    }
    .ft-step-badge {
        display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 800;
        background: rgba(var(--bs-primary-rgb), .12); color: var(--brand-ink); margin-bottom: 8px;
    }
    .ft-plan-card {
        border: 1.5px solid var(--line); border-radius: 18px; padding: 18px; background: #fff; cursor: pointer;
        display: flex; flex-direction: column; justify-content: space-between; height: 100%;
        transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
    }
    .ft-plan-card:hover { transform: translateY(-2px); border-color: rgba(var(--bs-primary-rgb), .45); }
    .ft-plan-card.is-selected {
        border-color: var(--brand);
        background: rgba(var(--bs-primary-rgb), .035);
        box-shadow: 0 12px 26px -14px rgba(var(--bs-primary-rgb), .45);
    }
    .ft-plan-price { font-size: 1.35rem; font-weight: 800; color: var(--brand-ink); }
    .ft-feat-tile {
        border: 1px solid var(--line); border-radius: 14px; padding: 15px; background: rgba(var(--wash-rgb), .65); height: 100%;
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
                    <span class="ft-pill"><span class="ft-dot"></span> Travel eSIM</span>
                    <span class="ft-pill">190+ Countries</span>
                    <span class="ft-pill">Rail: <?php echo htmlspecialchars($esim_rail); ?></span>
                </div>
                <h3 class="fw-bold mb-2">Travel eSIM — instant data in 190+ countries</h3>
                <p class="opacity-75 mb-0" style="max-width:62ch">Pick a travel data bundle, receive your QR code &amp; SM-DP+ profile by email, and activate seamlessly on iOS &amp; Android with no physical SIM swap.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="#esim-order-form" class="btn btn-light rounded-pill px-4 fw-bold">Choose eSIM Plan</a>
            </div>
        </div>
    </div>

    <!-- 4 Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18"/></svg></div>
                <div class="ft-stat-val">190+</div>
                <div class="ft-stat-lbl">Countries &amp; destinations</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M9 7h6v5H9z"/></svg></div>
                <div class="ft-stat-val"><?php echo (int)$plan_count; ?></div>
                <div class="ft-stat-lbl">Available data plans</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg></div>
                <div class="ft-stat-val"><?php echo (int)$order_count; ?></div>
                <div class="ft-stat-lbl">My eSIM orders</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="ft-stat">
                <div class="ft-stat-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L4.5 13.5H12L11 22l8.5-11.5H12L13 2z"/></svg></div>
                <div class="ft-stat-val"><?php echo htmlspecialchars($esim_rail); ?></div>
                <div class="ft-stat-lbl">QR &amp; SM-DP+ provisioning</div>
            </div>
        </div>
    </div>

    <!-- 3-Step Activation + 4 Feature Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="ft-step-card"><span class="ft-step-badge">STEP 01 · PLAN</span><strong class="d-block mb-1">Choose a data plan</strong><p class="small text-muted mb-0">Pick data allowance and validity matched to your itinerary.</p></div></div>
        <div class="col-md-4"><div class="ft-step-card"><span class="ft-step-badge">STEP 02 · INSTALL</span><strong class="d-block mb-1">Scan QR / SM-DP+</strong><p class="small text-muted mb-0">Receive your QR activation code and SM-DP+ address via email.</p></div></div>
        <div class="col-md-4"><div class="ft-step-card"><span class="ft-step-badge">STEP 03 · ROAM</span><strong class="d-block mb-1">Activate in one tap</strong><p class="small text-muted mb-0">Install the eSIM profile and switch travel data on when you land.</p></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">No Physical SIM Swap</strong><span class="small text-muted">Keep your home SIM active for banking OTPs and calls while roaming.</span></div></div>
        <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">iOS &amp; Android Compatible</strong><span class="small text-muted">Works on all eSIM-unlocked modern smartphones and travel tablets.</span></div></div>
        <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">Zero Roaming Bill Shock</strong><span class="small text-muted">Prepaid data allowances with transparent validity windows.</span></div></div>
        <div class="col-sm-6 col-xl-3"><div class="ft-feat-tile"><strong class="d-block small mb-1">Idempotent Order Guard</strong><span class="small text-muted">Safe retry protection ensures duplicate clicks never double-submit.</span></div></div>
    </div>

    <!-- Order eSIM Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4" id="esim-order-form">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h6 class="fw-bold mb-0">Order an eSIM</h6>
                    <span class="small text-muted">Select your travel data package and confirm your QR delivery email</span>
                </div>
                <?php if ($live_plans): ?>
                    <input type="search" id="esimPlanFilter" class="form-control form-control-sm" style="max-width:240px" placeholder="Filter plans or countries...">
                <?php endif; ?>
            </div>
            <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

            <form method="post">
                <?php echo bc_csrf_field(); ?>
                <input type="hidden" name="request_key" value="<?php echo htmlspecialchars($svc_form_key, ENT_QUOTES, 'UTF-8'); ?>">
                <p class="small text-muted">Keep this form unchanged when retrying an unconfirmed submission. <a href="ESIM.php">Start a new request</a> only for a separate request.</p>

                <div class="row g-3 mb-4" id="esimPlansGrid">
                    <?php if ($live_plans): ?>
                        <?php foreach ($live_plans as $i => $plan): ?>
                        <div class="col-md-4 esim-plan-col" data-hay="<?php echo htmlspecialchars(strtolower($plan['name'] . ' ' . $plan['countries'] . ' ' . $plan['data']), ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="ft-plan-card<?php echo $i === 0 ? ' is-selected' : ''; ?>">
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <input type="radio" name="plan_id" value="<?php echo htmlspecialchars($plan['plan_id']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                        <span class="badge bg-primary bg-opacity-10 text-primary"><?php echo htmlspecialchars($plan['days']); ?></span>
                                    </div>
                                    <strong class="d-block fs-6 mb-1"><?php echo htmlspecialchars($plan['name']); ?></strong>
                                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($plan['data']); ?><?php echo $plan['countries'] !== '' ? ' · ' . htmlspecialchars($plan['countries']) : ''; ?></div>
                                </div>
                                <div class="ft-plan-price">₦<?php echo number_format($plan['price_ngn'], 2); ?></div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($plans as $i => $plan): ?>
                        <div class="col-md-4 esim-plan-col">
                            <label class="ft-plan-card<?php echo $i === 0 ? ' is-selected' : ''; ?>">
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <input type="radio" name="plan" value="<?php echo htmlspecialchars($plan['name']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                        <span class="badge bg-primary bg-opacity-10 text-primary"><?php echo htmlspecialchars($plan['days']); ?></span>
                                    </div>
                                    <strong class="d-block fs-6 mb-1"><?php echo htmlspecialchars($plan['name']); ?></strong>
                                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($plan['data']); ?> · Global Coverage</div>
                                </div>
                                <div class="ft-plan-price">$<?php echo number_format($plan['price'], 2); ?></div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Email for QR delivery</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($svc_user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <button type="submit" name="order_esim" class="btn btn-primary rounded-pill px-4 fw-bold">Order eSIM</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- eSIM Order History -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h6 class="fw-bold mb-0">My eSIM Order History</h6>
                    <span class="small text-muted">Track submitted travel eSIM orders and provisioning statuses</span>
                </div>
                <span class="badge bg-secondary bg-opacity-10 text-dark"><?php echo (int)$order_count; ?> orders</span>
            </div>
            <?php if ($my_requests): ?>
            <div class="table-responsive">
                <table class="table ft-table mb-0">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Plan</th>
                            <th>Delivery Email</th>
                            <th>Rail</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_requests as $r):
                            $p = json_decode($r['payload'] ?? '{}', true) ?: [];
                            $st = strtolower((string)($r['status'] ?? 'pending'));
                            $pill_cls = in_array($st, ['completed', 'approved', 'provisioned', 'success'], true) ? 'ok' : (in_array($st, ['pending', 'held', 'review'], true) ? 'warn' : 'muted');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['reference']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['plan'] ?? $p['plan_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(strtoupper((string)($r['provider'] ?? $esim_rail))); ?></td>
                            <td><span class="ft-status-pill <?php echo $pill_cls; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="p-4 text-center rounded-4 bg-light small text-muted">No eSIM orders yet. Choose a travel data plan above to place your first order.</div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function () {
    var planRadios = document.querySelectorAll('#esimPlansGrid input[type="radio"]');
    function syncCards() {
        for (var i = 0; i < planRadios.length; i++) {
            var card = planRadios[i].closest('.ft-plan-card');
            if (card) card.classList.toggle('is-selected', planRadios[i].checked);
        }
    }
    for (var i = 0; i < planRadios.length; i++) {
        planRadios[i].addEventListener('change', syncCards);
    }
    var filter = document.getElementById('esimPlanFilter');
    if (filter) {
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            var cols = document.querySelectorAll('.esim-plan-col');
            for (var j = 0; j < cols.length; j++) {
                var hay = cols[j].getAttribute('data-hay') || '';
                cols[j].style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
            }
        });
    }
})();
</script>
<?php bc_app_foot(); ?>
