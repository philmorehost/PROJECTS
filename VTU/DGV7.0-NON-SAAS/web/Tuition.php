<?php session_start();
include("../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}
$vid = (int)$get_logged_user_details['vendor_id'];
$svc_user = $get_logged_user_details;

if(!isServiceEnabled('tuition')){
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
$notice = ''; $notice_type = 'info';
if (isset($_POST['pay_tuition'])) {
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
        $ref = bc_svc_request_create($vid, $svc_user, 'tuition', 'platnova', $payload);
        $notice = $ref !== '' ? "Tuition payment request received ($ref). Student ID validation runs before settlement." : 'We could not record your request. Please try again.';
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'tuition');
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Pay Tuition Worldwide | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "PAY TUITION WORLDWIDE", "crumb" => "Home / Tuition"));
?>
<section class="section dashboard">
    <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
        <span class="badge bg-primary mb-2">Tuition Payments</span>
        <h4 class="fw-bold mb-1">Pay tuition to 160+ universities worldwide</h4>
        <p class="text-muted small mb-0">Search your institution, validate the student ID, and settle in the school's currency at live FX — no correspondent-bank delays.</p>
    </div></div>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
                <h6 class="fw-bold mb-3">Tuition payment</h6>
                <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
                <form method="post">
                    <div class="mb-3"><label class="form-label small fw-bold">University / Institution</label>
                        <input type="text" name="university" class="form-control" list="uniList" placeholder="Start typing to search 160+ institutions" required>
                        <datalist id="uniList"><?php foreach ($universities as $u): ?><option value="<?php echo htmlspecialchars($u); ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small fw-bold">Student full name</label><input type="text" name="student_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Student ID</label><input type="text" name="student_id" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Amount (USD)</label><input type="number" step="0.01" min="1" name="amount_usd" class="form-control" required></div>
                    </div>
                    <button type="submit" name="pay_tuition" class="btn btn-primary rounded-pill px-4 fw-bold mt-4">Validate &amp; Pay Tuition</button>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                <h6 class="fw-bold mb-3">How it works</h6>
                <ol class="small text-muted ps-3">
                    <li class="mb-2">Search and select the institution.</li>
                    <li class="mb-2">Enter the student ID — it is validated against the institution before money moves.</li>
                    <li class="mb-2">Confirm the live FX quote and pay from your wallet.</li>
                    <li>Track settlement from your request history.</li>
                </ol>
                <?php if ($my_requests): ?><h6 class="fw-bold mt-4 mb-2">My tuition requests</h6>
                <?php foreach ($my_requests as $r): ?><div class="d-flex justify-content-between border-bottom py-2"><span><strong><?php echo htmlspecialchars($r['reference']); ?></strong></span><span class="badge bg-secondary"><?php echo htmlspecialchars($r['status']); ?></span></div><?php endforeach; ?><?php endif; ?>
            </div></div>
        </div>
    </div>
</section>
<?php bc_app_foot(); ?>
