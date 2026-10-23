<?php session_start();
include("../func/bc-admin-config.php");
include_once("../func/bc-number-checker.php");

// Admin scope = whole vendor/tenant (optional per-user filter).
$checker_vendor = $get_logged_admin_details["id"] ?? '';
$checker_username = '';

$checker_result = null;
$checker_error = '';
$form = array(
    'month'     => date('Y-m'),
    'service'   => 'data',
    'data_type' => 'sme-data',
    'numbers'   => '',
    'username'  => '',
);

if (isset($_POST['check-numbers'])) {
    $form['month']     = trim(strip_tags($_POST['month'] ?? date('Y-m')));
    $form['service']   = ($_POST['service'] ?? 'data') === 'airtime' ? 'airtime' : 'data';
    $form['data_type'] = trim(strip_tags($_POST['data_type'] ?? 'sme-data'));
    $form['numbers']   = trim(strip_tags($_POST['numbers'] ?? ''));
    $form['username']  = trim(strip_tags($_POST['username'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}$/', $form['month'])) {
        $checker_error = 'Please select a valid month.';
    } elseif ($form['numbers'] === '') {
        $checker_error = 'Please paste at least one phone number.';
    } elseif ($checker_vendor === '') {
        $checker_error = 'Session error: could not resolve your admin account.';
    } else {
        $norm = bc_checker_normalize_phones($form['numbers']);
        if (count($norm['phones']) === 0) {
            $checker_error = 'No valid Nigerian phone numbers were found. Please check your list (use 0803..., 234803..., etc.).';
        } else {
            $checker_result = bc_checker_run(
                $connection_server,
                $checker_vendor,
                $form['username'],          // '' = all users on this vendor
                $norm['phones'],
                $form['month'],
                $form['service'],
                $form['data_type']
            );
            $checker_result['_norm'] = $norm;

            // Export action (CSV or Excel) — streams a file and stops rendering.
            if (isset($_POST['export_format']) && $checker_error === '') {
                $export_format = trim(strip_tags($_POST['export_format']));
                if ($export_format === 'excel') {
                    bc_checker_export_excel($checker_result, $form, true);
                } else {
                    bc_checker_export_csv($checker_result, $form, true);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>

<head>
    <title>Number Credit Checker | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>

<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
        <h1>Number Credit Checker</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Number Credit Checker</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <i class="bi bi-patch-check text-primary" style="font-size:1.4rem;"></i>
                            <h5 class="fw-bold mb-0">Bulk Credit Check (Admin)</h5>
                        </div>
                        <p class="text-muted small mb-4">
                            Check across all customers of this vendor/tenant. Paste the phone numbers, pick a month and a
                            service — each number is marked <span class="text-success fw-semibold">credited</span>
                            (green, with its credited date) or <span class="text-danger fw-semibold">not credited</span>
                            (red) for that month. Leave the username blank to scan the whole vendor.
                        </p>

                        <?php if ($checker_error !== ''): ?>
                            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($checker_error); ?></div>
                        <?php endif; ?>

                        <form method="post" action="NumberChecker.php">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">Phone Numbers</label>
                                <textarea name="numbers" class="form-control" rows="6"
                                    style="border-radius:12px;"
                                    placeholder="Paste numbers separated by commas or new lines, e.g.&#10;08031234567, 08123456789&#10;09011112222"
                                    oninput="updateCount()" required><?php echo htmlspecialchars($form['numbers']); ?></textarea>
                                <div class="text-end mt-1">
                                    <span id="numbers-count" class="badge bg-primary rounded-pill">Numbers: 0</span>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-uppercase text-muted">Month</label>
                                    <input type="month" name="month" class="form-control"
                                        value="<?php echo htmlspecialchars($form['month']); ?>" required />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-uppercase text-muted">Service</label>
                                    <select name="service" id="checker-service" class="form-select" onchange="toggleDataType()">
                                        <option value="data" <?php echo ($form['service'] !== 'airtime') ? 'selected' : ''; ?>>Data</option>
                                        <option value="airtime" <?php echo ($form['service'] === 'airtime') ? 'selected' : ''; ?>>Airtime</option>
                                    </select>
                                </div>
                                <div class="col-md-3" id="data-type-wrap">
                                    <label class="form-label small fw-bold text-uppercase text-muted">Data Type</label>
                                    <select name="data_type" id="checker-data-type" class="form-select">
                                        <option value="sme-data" <?php echo ($form['data_type'] === 'sme-data') ? 'selected' : ''; ?>>SME Data</option>
                                        <option value="cg-data" <?php echo ($form['data_type'] === 'cg-data') ? 'selected' : ''; ?>>Corporate Gifting (CG)</option>
                                        <option value="dd-data" <?php echo ($form['data_type'] === 'dd-data') ? 'selected' : ''; ?>>Direct Data (DD)</option>
                                        <option value="shared-data" <?php echo ($form['data_type'] === 'shared-data') ? 'selected' : ''; ?>>Shared Data</option>
                                        <option value="any" <?php echo ($form['data_type'] === 'any') ? 'selected' : ''; ?>>Any Data</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold text-uppercase text-muted">Username (optional)</label>
                                    <input name="username" type="text" class="form-control"
                                        placeholder="All users"
                                        value="<?php echo htmlspecialchars($form['username']); ?>" />
                                </div>
                            </div>

                            <button type="submit" name="check-numbers" class="btn btn-primary w-100 rounded-pill">
                                <i class="bi bi-search me-1"></i> Check Numbers
                            </button>
                        </form>
                    </div>
                </div>

                <?php if ($checker_result !== null): ?>
                    <?php
                        $credited_count = $checker_result['summary']['credited'];
                        $not_count = $checker_result['summary']['not_credited'];
                        $invalid_count = $checker_result['_norm']['invalid'];
                        $service_label = $form['service'] === 'airtime'
                            ? 'Airtime'
                            : 'Data' . ($form['data_type'] === 'any' ? ' (All)' : ' (' . strtoupper(str_replace('-', ' ', $form['data_type'])) . ')');
                    ?>
                    <div class="row g-3 mt-1 mb-3">
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                                <div class="fs-4 fw-bold text-primary"><?php echo count($checker_result['_norm']['phones']); ?></div>
                                <div class="small text-muted">Checked Numbers</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                                <div class="fs-4 fw-bold text-success"><?php echo $credited_count; ?></div>
                                <div class="small text-muted">Credited</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                                <div class="fs-4 fw-bold text-danger"><?php echo $not_count; ?></div>
                                <div class="small text-muted">Not Credited</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                                <div class="fs-4 fw-bold text-warning"><?php echo $invalid_count; ?></div>
                                <div class="small text-muted">Invalid Entries</div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h6 class="fw-bold text-primary mb-0">
                                <i class="bi bi-list-check me-1"></i>
                                Result — <?php echo bc_checker_month_label($form['month']); ?> · <?php echo $service_label; ?>
                            </h6>
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-muted me-1">Green = credited · Red = not credited this month</span>
                                <form method="post" action="" class="d-inline-flex gap-1">
                                    <input type="hidden" name="check-numbers" value="1" />
                                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($form['month']); ?>" />
                                    <input type="hidden" name="service" value="<?php echo htmlspecialchars($form['service']); ?>" />
                                    <input type="hidden" name="data_type" value="<?php echo htmlspecialchars($form['data_type']); ?>" />
                                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($form['username']); ?>" />
                                    <input type="hidden" name="numbers" value="<?php echo htmlspecialchars($form['numbers']); ?>" />
                                    <button type="submit" name="export_format" value="csv" class="btn btn-success btn-sm" title="Export report as CSV">
                                        <i class="bi bi-filetype-csv me-1"></i>CSV
                                    </button>
                                    <button type="submit" name="export_format" value="excel" class="btn btn-outline-success btn-sm" title="Export report as Excel (.xlsx)">
                                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="border-0 px-4 py-3">#</th>
                                        <th class="border-0 py-3">Phone Number</th>
                                        <th class="border-0 py-3">Network</th>
                                        <th class="border-0 py-3">Status</th>
                                        <th class="border-0 py-3 text-end">Amount (₦)</th>
                                        <th class="border-0 py-3">Credited Date</th>
                                        <th class="border-0 py-3 text-end px-4">Reference / Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $n = 0;
                                        foreach ($checker_result['_norm']['phones'] as $phone):
                                            $n++;
                                            $credited_rows = $checker_result['credited'][$phone] ?? array();
                                            $attempt_rows = $checker_result['attempts'][$phone] ?? array();
                                            if (count($credited_rows) > 0):
                                                $latest = end($credited_rows);
                                                $credited_total = 0;
                                                foreach ($credited_rows as $cr) {
                                                    $credited_total += (float)($cr['amount'] ?? $cr['discounted_amount'] ?? 0);
                                                }
                                    ?>
                                        <tr>
                                            <td class="px-4"><?php echo $n; ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($phone); ?></td>
                                            <td><?php echo bc_checker_network_badge($credited_rows[0]['network'] ?? '', $phone); ?></td>
                                            <td><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle-fill me-1"></i>Credited</span></td>
                                            <td class="text-success fw-semibold text-end"><?php echo '₦' . number_format($credited_total, 2); ?></td>
                                            <td class="text-success fw-semibold"><?php echo date('d M Y', strtotime($latest['date'])); ?></td>
                                            <td class="text-end px-4">
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($latest['username'] . ' · ' . $latest['type_alternative']); ?></small>
                                                <?php if (count($credited_rows) > 1): ?>
                                                    <small class="text-muted">+<?php echo count($credited_rows) - 1; ?> more credit(s) this month</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr class="bg-danger bg-opacity-10">
                                            <td class="px-4"><?php echo $n; ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($phone); ?></td>
                                            <td><?php echo bc_checker_network_badge('', $phone); ?></td>
                                            <td><span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-x-circle-fill me-1"></i>Not Credited</span></td>
                                            <td class="text-danger text-end">—</td>
                                            <td class="text-danger">— <?php echo bc_checker_month_label($form['month']); ?></td>
                                            <td class="text-end px-4">
                                                <?php if (count($attempt_rows) > 0): ?>
                                                    <?php foreach (array_slice($attempt_rows, 0, 2) as $att): ?>
                                                        <a class="btn btn-warning btn-sm py-1 px-2 text-dark mb-1" style="font-size:11px;"
                                                           title="Check status with provider"
                                                           href="Transactions.php?requery=<?php echo urlencode($att['reference']); ?>">
                                                            Requery <?php echo ($att['status'] == 2) ? '(Pending)' : '(Failed)'; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <small class="text-muted">No top-up record this month</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>

    <script>
        function updateCount() {
            const raw = document.querySelector('textarea[name="numbers"]').value;
            const parts = raw.split(/[\s,;]+/).filter(function(s) { return s.trim() !== ''; });
            document.getElementById('numbers-count').textContent = 'Numbers: ' + parts.length;
        }
        function toggleDataType() {
            const svc = document.getElementById('checker-service').value;
            const wrap = document.getElementById('data-type-wrap');
            wrap.style.display = (svc === 'data') ? '' : 'none';
            document.getElementById('checker-data-type').required = (svc === 'data');
        }
        document.addEventListener('DOMContentLoaded', function() { updateCount(); toggleDataType(); });
    </script>
</body>
</html>
