<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once("../func/bc-config.php");

// Resolve Vendor ID for public or logged in access
$vendor_id = resolveVendorID();

// If not logged in, we still need site details which are already loaded in bc-config.php
// $get_all_site_details is available

$is_logged_in = isset($_SESSION["user_session"]) && isset($get_logged_user_details);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Service Pricing | <?php echo $get_all_site_details["site_title"] ?? 'Platform'; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <meta name="description" content="View our competitive pricing and discounts for all VTU services." />

    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">

    <style>
        body { background-color: #f8fafc; }
        .pricing-container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .card { border-radius: 1rem; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .accordion-item { border: none; margin-bottom: 1rem; border-radius: 1rem !important; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .accordion-button { font-weight: 700; border-radius: 1rem !important; padding: 1.25rem; }
        .accordion-button:not(.collapsed) { background-color: #f1f5ff; color: var(--primary-color); }
        .table thead th { background-color: #f8fafc; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #64748b; border-top: none; }
        .network-badge { padding: 5px 12px; border-radius: 50px; font-weight: 700; font-size: 0.7rem; }
        .empty-state { padding: 40px; text-align: center; color: #64748b; }
        .empty-state i { font-size: 3rem; margin-bottom: 15px; opacity: 0.3; }

        @media (max-width: 768px) {
            .table-responsive { font-size: 0.85rem; }
            .pagetitle h1 { font-size: 1.5rem; }
        }
    </style>
</head>

<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pricing-container">
        <div class="pagetitle mb-4">
            <h1><i class="bi bi-tags-fill me-2 text-primary"></i>Service Pricing & Discounts</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active">Pricing</li>
                </ol>
            </nav>
        </div>

        <div class="alert alert-info border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center">
            <i class="bi bi-info-circle-fill fs-4 me-3"></i>
            <div>
                <small class="fw-bold d-block">Pricing Transparency</small>
                <span class="small">Our prices are tiered based on your account level. Smart Users get standard discounts, while Agents and API Vendors enjoy higher profit margins.</span>
            </div>
        </div>

        <div class="accordion shadow-none" id="pricingAccordion">

            <!-- AIRTIME SECTION -->
            <div class="accordion-item shadow-sm">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#airtimeCollapse" data-no-lock>
                        <i class="bi bi-telephone-fill me-3 text-success"></i> Airtime Top-up Discounts
                    </button>
                </h2>
                <div id="airtimeCollapse" class="accordion-collapse collapse show" data-bs-parent="#pricingAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Network</th><th>Smart (%)</th><th>Agent (%)</th><th>API (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $has_data = false;
                                    $product_names = array("mtn", "airtel", "9mobile", "glo");
                                    foreach($product_names as $pname) {
                                        $status_q = mysqli_query($connection_server, "SELECT api_id FROM sas_airtime_status WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1");
                                        if($status_q && $status = mysqli_fetch_assoc($status_q)) {
                                            $api_id = $status['api_id'];
                                            $prod_q = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1");
                                            if($prod_q && $prod = mysqli_fetch_assoc($prod_q)) {
                                                $pid = $prod['id'];
                                                $d1 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_1 FROM sas_smart_parameter_values WHERE vendor_id='$vendor_id' AND api_id='$api_id' AND product_id='$pid' AND status=1 LIMIT 1"));
                                                $d2 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_1 FROM sas_agent_parameter_values WHERE vendor_id='$vendor_id' AND api_id='$api_id' AND product_id='$pid' AND status=1 LIMIT 1"));
                                                $d3 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_1 FROM sas_api_parameter_values WHERE vendor_id='$vendor_id' AND api_id='$api_id' AND product_id='$pid' AND status=1 LIMIT 1"));

                                                if($d1) {
                                                    $has_data = true;
                                                    echo '<tr>
                                                        <td><span class="badge network-badge bg-dark">'.strtoupper($pname).'</span></td>
                                                        <td class="fw-bold text-primary">'.toDecimal($d1["val_1"], 2).'%</td>
                                                        <td class="fw-bold text-success">'.toDecimal($d2["val_1"], 2).'%</td>
                                                        <td class="fw-bold text-indigo">'.toDecimal($d3["val_1"], 2).'%</td>
                                                    </tr>';
                                                }
                                            }
                                        }
                                    }
                                    if(!$has_data) echo '<tr><td colspan="4" class="empty-state"><i class="bi bi-exclamation-circle"></i><br>Airtime pricing not yet configured. Please contact admin.</td></tr>';
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DATA PLANS SECTION -->
            <div class="accordion-item shadow-sm">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#dataCollapse" data-no-lock>
                        <i class="bi bi-wifi me-3 text-primary"></i> Mobile Data Bundle Prices
                    </button>
                </h2>
                <div id="dataCollapse" class="accordion-collapse collapse" data-bs-parent="#pricingAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Network</th><th>Plan</th><th>Type</th><th>Smart</th><th>Agent</th><th>API</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $has_data = false;
                                    $product_names = array("mtn", "airtel", "9mobile", "glo");
                                    foreach($product_names as $pname) {
                                        $api_ids = [];
                                        $types = ['shared', 'sme', 'cg', 'dd'];
                                        foreach($types as $t) {
                                            $res = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT api_id FROM sas_{$t}_data_status WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1"));
                                            if($res) $api_ids[] = $res['api_id'];
                                        }
                                        $api_ids = array_unique($api_ids);
                                        if(empty($api_ids)) continue;

                                        $api_list = "'" . implode("','", $api_ids) . "'";
                                        $prod_res = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1"));
                                        if(!$prod_res) continue;
                                        $pid = $prod_res['id'];

                                        $q = mysqli_query($connection_server, "SELECT s.api_id, s.val_1, s.val_2 as p1, s.val_4, a.val_2 as p2, i.val_2 as p3, api.api_type
                                            FROM sas_smart_parameter_values s
                                            JOIN sas_agent_parameter_values a ON s.vendor_id=a.vendor_id AND s.product_id=a.product_id AND s.val_1=a.val_1 AND s.api_id=a.api_id
                                            JOIN sas_api_parameter_values i ON s.vendor_id=i.vendor_id AND s.product_id=i.product_id AND s.val_1=i.val_1 AND s.api_id=i.api_id
                                            JOIN sas_apis api ON s.api_id=api.id
                                            WHERE s.vendor_id='$vendor_id' AND s.product_id='$pid' AND s.api_id IN ($api_list) AND s.status=1
                                            ORDER BY api.api_type, CAST(s.val_2 AS UNSIGNED) ASC");

                                        while($row = mysqli_fetch_assoc($q)) {
                                            $has_data = true;
                                            $name = !empty($row['val_4']) ? $row['val_4'] : $row['val_1'];
                                            echo '<tr>
                                                <td><small class="fw-bold">'.strtoupper($pname).'</small></td>
                                                <td>'.$name.'</td>
                                                <td><span class="badge bg-light text-dark border">'.strtoupper(str_replace('-data','',$row['api_type'])).'</span></td>
                                                <td class="fw-bold">₦'.number_format($row['p1']).'</td>
                                                <td class="fw-bold">₦'.number_format($row['p2']).'</td>
                                                <td class="fw-bold">₦'.number_format($row['p3']).'</td>
                                            </tr>';
                                        }
                                    }
                                    if(!$has_data) echo '<tr><td colspan="6" class="empty-state"><i class="bi bi-exclamation-circle"></i><br>Data pricing not yet configured. Please contact admin.</td></tr>';
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CABLE TV SECTION -->
            <div class="accordion-item shadow-sm">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cableCollapse" data-no-lock>
                        <i class="bi bi-tv me-3 text-danger"></i> Cable TV Subscriptions
                    </button>
                </h2>
                <div id="cableCollapse" class="accordion-collapse collapse" data-bs-parent="#pricingAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Provider</th><th>Package</th><th>Smart</th><th>Agent</th><th>API</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $has_data = false;
                                    $providers = ["dstv", "gotv", "startimes", "showmax"];
                                    foreach($providers as $pname) {
                                        $status_q = mysqli_query($connection_server, "SELECT api_id FROM sas_cable_status WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1");
                                        if($status_q && $status = mysqli_fetch_assoc($status_q)) {
                                            $api_id = $status['api_id'];
                                            $prod_q = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1");
                                            if($prod_q && $prod = mysqli_fetch_assoc($prod_q)) {
                                                $pid = $prod['id'];
                                                $q = mysqli_query($connection_server, "SELECT s.val_1, s.val_2 as p1, s.val_4, a.val_2 as p2, i.val_2 as p3
                                                    FROM sas_smart_parameter_values s
                                                    JOIN sas_agent_parameter_values a ON s.vendor_id=a.vendor_id AND s.product_id=a.product_id AND s.val_1=a.val_1 AND s.api_id=a.api_id
                                                    JOIN sas_api_parameter_values i ON s.vendor_id=i.vendor_id AND s.product_id=i.product_id AND s.val_1=i.val_1 AND s.api_id=i.api_id
                                                    WHERE s.vendor_id='$vendor_id' AND s.product_id='$pid' AND s.api_id='$api_id' AND s.status=1");

                                                while($row = mysqli_fetch_assoc($q)) {
                                                    $has_data = true;
                                                    $name = !empty($row['val_4']) ? $row['val_4'] : $row['val_1'];
                                                    echo '<tr>
                                                        <td><small class="fw-bold">'.strtoupper($pname).'</small></td>
                                                        <td>'.$name.'</td>
                                                        <td class="fw-bold">₦'.number_format($row['p1']).'</td>
                                                        <td class="fw-bold">₦'.number_format($row['p2']).'</td>
                                                        <td class="fw-bold">₦'.number_format($row['p3']).'</td>
                                                    </tr>';
                                                }
                                            }
                                        }
                                    }
                                    if(!$has_data) echo '<tr><td colspan="5" class="empty-state"><i class="bi bi-exclamation-circle"></i><br>Cable TV pricing not yet configured. Please contact admin.</td></tr>';
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- UTILITY SECTION -->
            <div class="accordion-item shadow-sm">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#utilityCollapse" data-no-lock>
                        <i class="bi bi-lightning-charge-fill me-3 text-warning"></i> Utility Bill Commissions
                    </button>
                </h2>
                <div id="utilityCollapse" class="accordion-collapse collapse" data-bs-parent="#pricingAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Provider</th><th>Smart (%)</th><th>Agent (%)</th><th>API (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $has_data = false;
                                    $providers = ["ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc", "bedc"];
                                    foreach($providers as $pname) {
                                        $status_q = mysqli_query($connection_server, "SELECT api_id FROM sas_electric_status WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1");
                                        if($status_q && $status = mysqli_fetch_assoc($status_q)) {
                                            $api_id = $status['api_id'];
                                            $prod_q = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vendor_id' AND product_name='$pname' AND status=1 LIMIT 1");
                                            if($prod_q && $prod = mysqli_fetch_assoc($prod_q)) {
                                                $pid = $prod['id'];
                                                $d1 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_1 FROM sas_smart_parameter_values WHERE vendor_id='$vendor_id' AND api_id='$api_id' AND product_id='$pid' AND status=1 LIMIT 1"));
                                                $d2 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_1 FROM sas_agent_parameter_values WHERE vendor_id='$vendor_id' AND api_id='$api_id' AND product_id='$pid' AND status=1 LIMIT 1"));
                                                $d3 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_1 FROM sas_api_parameter_values WHERE vendor_id='$vendor_id' AND api_id='$api_id' AND product_id='$pid' AND status=1 LIMIT 1"));

                                                if($d1) {
                                                    $has_data = true;
                                                    echo '<tr>
                                                        <td><small class="fw-bold">'.strtoupper($pname).'</small></td>
                                                        <td class="text-primary fw-bold">'.toDecimal($d1["val_1"], 2).'%</td>
                                                        <td class="text-success fw-bold">'.toDecimal($d2["val_1"], 2).'%</td>
                                                        <td class="text-indigo fw-bold">'.toDecimal($d3["val_1"], 2).'%</td>
                                                    </tr>';
                                                }
                                            }
                                        }
                                    }
                                    if(!$has_data) echo '<tr><td colspan="4" class="empty-state"><i class="bi bi-exclamation-circle"></i><br>Utility pricing not yet configured. Please contact admin.</td></tr>';
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- End Accordion -->

        <div class="card mt-5 bg-dark text-white border-0 shadow-lg overflow-hidden">
            <div class="card-body p-4 p-md-5 d-flex align-items-center justify-content-between flex-wrap gap-4 position-relative">
                <div class="position-absolute end-0 top-0 opacity-10" style="font-size: 10rem; transform: translate(20%, -20%); pointer-events: none;">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div style="position: relative; z-index: 1;">
                    <h3 class="fw-bold mb-2">Ready to start earning?</h3>
                    <p class="opacity-75 mb-0">Join thousands of vendors already making profit through our platform.</p>
                </div>
                <div style="position: relative; z-index: 1;">
                    <?php if($is_logged_in): ?>
                        <a href="Dashboard.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow">Go to Dashboard</a>
                    <?php else: ?>
                        <a href="Register.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow">Create Free Account</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include("../func/bc-footer.php"); ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure accordion works even if other scripts interfere
            const accordionButtons = document.querySelectorAll('.accordion-button');
            accordionButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const target = document.querySelector(this.getAttribute('data-bs-target'));
                    if (target) {
                        const isExpanded = this.getAttribute('aria-expanded') === 'true';
                        // Close others
                        const parentId = this.closest('.accordion').id;
                        if (parentId) {
                            const allOtherButtons = document.querySelectorAll(`#${parentId} .accordion-button:not([data-bs-target="${this.getAttribute('data-bs-target')}"])`);
                            allOtherButtons.forEach(btn => {
                                btn.setAttribute('aria-expanded', 'false');
                                btn.classList.add('collapsed');
                                const otherTarget = document.querySelector(btn.getAttribute('data-bs-target'));
                                if (otherTarget) otherTarget.classList.remove('show');
                            });
                        }

                        // Toggle this
                        if (isExpanded) {
                            this.setAttribute('aria-expanded', 'false');
                            this.classList.add('collapsed');
                            target.classList.remove('show');
                        } else {
                            this.setAttribute('aria-expanded', 'true');
                            this.classList.remove('collapsed');
                            target.classList.add('show');
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
