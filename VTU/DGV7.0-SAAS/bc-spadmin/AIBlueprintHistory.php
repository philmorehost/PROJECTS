<?php session_start();
include("../func/bc-admin-config.php");

// Super Admin only
$is_super = (isset($get_logged_admin_details['id']) && $get_logged_admin_details['id'] == 1);
if (!$is_super) { header("Location: /bc-spadmin/Login.php"); exit(); }

// ── Manual Trigger ────────────────────────────────────────────────────────────
if (isset($_POST['run-blueprint-now'])) {
    // Run synchronously (will take 1-2 min — show loading UI)
    ob_start();
    define('CRON_CLI', true);
    // Re-use connection already open
    require_once '../func/bc-ai-engine.php';
    $_SESSION['blueprint_triggered'] = true;
    // Redirect to avoid re-run on refresh
    header("Location: AIBlueprintHistory.php?triggered=1");
    exit();
}

// ── Delete a Blueprint ────────────────────────────────────────────────────────
if (isset($_POST['delete-blueprint'])) {
    $del_id = (int)($_POST['blueprint-id'] ?? 0);
    if ($del_id > 0) mysqli_query($connection_server, "DELETE FROM sas_ai_blueprints WHERE id='$del_id'");
    $_SESSION['product_purchase_response'] = 'Blueprint deleted.';
    header("Location: AIBlueprintHistory.php"); exit();
}

// ── View a specific Blueprint ─────────────────────────────────────────────────
$view_id = (int)($_GET['view'] ?? 0);
if ($view_id > 0) {
    $bpq = mysqli_query($connection_server, "SELECT * FROM sas_ai_blueprints WHERE id='$view_id' LIMIT 1");
    $bp  = $bpq ? mysqli_fetch_assoc($bpq) : null;
    if ($bp) {
        // Render the Blueprint HTML directly (it's self-contained)
        echo $bp['blueprint_html'];
        echo '<div style="text-align:center;padding:20px;font-family:sans-serif">
            <a href="AIBlueprintHistory.php" style="color:#7c3aed;font-weight:700">← Back to Blueprint History</a>
            &nbsp;|&nbsp;
            <a href="javascript:window.print()" style="color:#7c3aed;font-weight:700">🖨 Print / Save as PDF</a>
        </div>';
        exit();
    }
    $_SESSION['product_purchase_response'] = 'Blueprint not found.';
    header("Location: AIBlueprintHistory.php"); exit();
}

// ── List all blueprints ───────────────────────────────────────────────────────
$blueprints_q = @mysqli_query($connection_server, "SELECT id, month_label, php_version, file_count, tx_count, revenue, email_sent, email_address, generated_at, elapsed_s FROM sas_ai_blueprints ORDER BY generated_at DESC");
$blueprints   = [];
if ($blueprints_q) while ($bp = mysqli_fetch_assoc($blueprints_q)) $blueprints[] = $bp;

$triggered = !empty($_GET['triggered']);
?>
<!DOCTYPE html>
<head>
    <title>AI Blueprint History | <?php echo $get_all_super_admin_site_details['site_title']??'System'; ?></title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <meta name="robots" content="noindex,nofollow"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .blueprint-card { transition: all .2s; border-left: 4px solid #7c3aed; }
        .blueprint-card:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(124,58,237,.1); }
        .stat-pill { background: #f3f0ff; color: #7c3aed; border-radius: 20px; padding: 2px 10px; font-size: .75rem; font-weight: 700; }
        .hero-gradient { background: linear-gradient(135deg,#1e1b4b,#4c1d95,#7c3aed); border-radius: 16px; color: #fff; }
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>
<div class="pagetitle">
    <h1>AI Blueprint History</h1>
    <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="#">Home</a></li><li class="breadcrumb-item active">Blueprint History</li></ol></nav>
</div>
<section class="section">

<?php if ($triggered): ?>
<div class="alert alert-info border-0 rounded-4 mb-4">
    <i class="bi bi-info-circle me-2"></i>Blueprint generation has been queued. It will run via the next cron cycle. Check your email in a few minutes after the cron fires, or run it manually from SSH: <code>php cron/ai_monthly_blueprint.php</code>
</div>
<?php endif; ?>

<!-- Hero Card -->
<div class="hero-gradient p-4 mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h4 class="fw-bold mb-1"><i class="bi bi-stars me-2"></i>AI Monthly Blueprint Audit</h4>
            <p class="mb-0 opacity-75 small">The AI scans your entire codebase and platform stats every month, then emails you a structured improvement Blueprint. Each Blueprint is formatted so you can paste it directly into an AI Agent.</p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <form method="post" class="d-inline">
                <button name="run-blueprint-now" type="submit" class="btn btn-light fw-bold rounded-pill px-4"
                    onclick="return confirm('This will trigger a Blueprint analysis (takes 1-3 minutes). Continue?')">
                    <i class="bi bi-play-circle me-2"></i>Run Blueprint Now
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Blueprint List -->
<?php if (empty($blueprints)): ?>
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body text-center py-5">
        <i class="bi bi-file-earmark-text opacity-25" style="font-size:4rem"></i>
        <h5 class="mt-3 text-muted fw-bold">No Blueprints yet</h5>
        <p class="text-muted small">The first Blueprint will be generated automatically on the 1st of next month, or you can trigger it manually above.</p>
        <div class="bg-dark rounded-3 text-start p-3 mt-3 d-inline-block">
            <code class="text-success d-block">php cron/ai_monthly_blueprint.php</code>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($blueprints as $bp):
        $revenue_fmt = '₦' . number_format((float)$bp['revenue'], 2);
        $date_fmt    = date('d M Y, H:i', strtotime($bp['generated_at']));
        $elapsed_fmt = $bp['elapsed_s'] . 's';
    ?>
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 blueprint-card">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-2">
                            <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;min-width:48px">
                                <i class="bi bi-file-earmark-bar-graph text-primary fs-5"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($bp['month_label']); ?> Blueprint</h6>
                                <small class="text-muted">Generated: <?php echo $date_fmt; ?> · <?php echo $elapsed_fmt; ?></small>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <span class="stat-pill"><i class="bi bi-file-code me-1"></i><?php echo number_format((int)$bp['file_count']); ?> files</span>
                            <span class="stat-pill"><i class="bi bi-arrow-left-right me-1"></i><?php echo number_format((int)$bp['tx_count']); ?> tx</span>
                            <span class="stat-pill"><i class="bi bi-currency-exchange me-1"></i><?php echo $revenue_fmt; ?></span>
                            <span class="stat-pill"><i class="bi bi-cpu me-1"></i>PHP <?php echo htmlspecialchars($bp['php_version']); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3 mt-3 mt-md-0">
                        <?php if ($bp['email_sent']): ?>
                        <span class="badge bg-success-subtle text-success rounded-pill px-3"><i class="bi bi-check-circle me-1"></i>Email Sent</span>
                        <div class="small text-muted mt-1">→ <?php echo htmlspecialchars(substr($bp['email_address'] ?? '', 0, 30)); ?></div>
                        <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning rounded-pill px-3"><i class="bi bi-clock me-1"></i>Not Emailed</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 text-md-end mt-3 mt-md-0">
                        <a href="AIBlueprintHistory.php?view=<?php echo $bp['id']; ?>" class="btn btn-primary btn-sm rounded-pill px-3 me-1">
                            <i class="bi bi-eye me-1"></i>View
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this Blueprint permanently?')">
                            <input type="hidden" name="blueprint-id" value="<?php echo $bp['id']; ?>">
                            <button name="delete-blueprint" type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Cron info card -->
<div class="card shadow-sm border-0 rounded-4 mt-4 bg-light border-0">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2 text-primary"></i>Scheduled Cron Configuration</h6>
        <p class="small text-muted mb-2">Add this to your cPanel cron jobs to run automatically on the 1st of each month:</p>
        <div class="bg-dark rounded-3 p-3">
            <code class="text-success d-block small">0 8 1 * * /usr/bin/php /home/YOUR_USERNAME/public_html/cron/ai_monthly_blueprint.php >> /home/YOUR_USERNAME/logs/blueprint.log 2>&1</code>
        </div>
    </div>
</div>

</section>
<?php include("../func/bc-spadmin-footer.php"); ?>
<?php if(isset($_SESSION["product_purchase_response"])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>Swal.fire('Notice','<?php echo addslashes($_SESSION["product_purchase_response"]); ?>','info');fetch('/func/unset-product-response.php');</script>
<?php unset($_SESSION["product_purchase_response"]); endif; ?>
</body></html>
