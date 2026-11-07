<?php session_start();
include("../func/bc-admin-config.php");

// Handle POST actions for Whitelist / Block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $product_id = mysqli_real_escape_string($connection_server, trim($_POST['product_id']));
    $vid = $get_logged_admin_details["id"];

    if (!empty($product_id)) {
        if ($action === 'whitelist') {
            // Check if already whitelisted
            $check = mysqli_query($connection_server, "SELECT * FROM sas_validated_user_purchase_id_list WHERE vendor_id='$vid' AND product_id='$product_id'");
            if (mysqli_num_rows($check) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_validated_user_purchase_id_list (vendor_id, product_id) VALUES ('$vid', '$product_id')");
                $_SESSION["product_purchase_response"] = "$product_id has been successfully whitelisted.";
            } else {
                $_SESSION["product_purchase_response"] = "$product_id is already whitelisted.";
            }
        } elseif ($action === 'remove_whitelist') {
            mysqli_query($connection_server, "DELETE FROM sas_validated_user_purchase_id_list WHERE vendor_id='$vid' AND product_id='$product_id'");
            $_SESSION["product_purchase_response"] = "Whitelist removed for $product_id.";
        } elseif ($action === 'block') {
            // Check if already blocked
            $check = mysqli_query($connection_server, "SELECT * FROM sas_id_blocking_system WHERE vendor_id='$vid' AND product_id='$product_id'");
            if (mysqli_num_rows($check) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_id_blocking_system (vendor_id, product_id) VALUES ('$vid', '$product_id')");
                $_SESSION["product_purchase_response"] = "$product_id has been successfully blocked.";
            } else {
                $_SESSION["product_purchase_response"] = "$product_id is already blocked.";
            }
        } elseif ($action === 'remove_block') {
            mysqli_query($connection_server, "DELETE FROM sas_id_blocking_system WHERE vendor_id='$vid' AND product_id='$product_id'");
            $_SESSION["product_purchase_response"] = "Block removed for $product_id.";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Retrieve Limits
$q_purchase_limit = mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='" . $get_logged_admin_details["id"] . "' LIMIT 1");
$limits = ($q_purchase_limit && mysqli_num_rows($q_purchase_limit) > 0) ? mysqli_fetch_array($q_purchase_limit) : ['limit_phone' => 5, 'limit_cable' => 5, 'limit_betting' => 5, 'limit_electric' => 5];

$shared_types = array('sme-data','cg-data','dd-data','shared-data','airtime','data');

function getLimitForType($type, $limits, $shared_types) {
    if ($type == "cable") return $limits["limit_cable"] ?? 5;
    if ($type == "betting") return $limits["limit_betting"] ?? 5;
    if ($type == "electric") return $limits["limit_electric"] ?? 5;
    if (in_array($type, $shared_types)) return $limits["limit_phone"] ?? 5;
    return $limits["limit_phone"] ?? 5;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Transaction Limits Monitor | <?php echo $get_all_super_admin_site_details["site_title"] ?? 'Vendor Dashboard'; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    
    <div class="pagetitle">
        <h1>Abuse & Limits Monitor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item">System Function</li>
                <li class="breadcrumb-item active">Transaction Limits</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                
                <?php
                if (isset($_SESSION["product_purchase_response"])) {
                    echo "<script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: 'Notice',
                                html: '" . addslashes($_SESSION["product_purchase_response"]) . "',
                                icon: 'info',
                                confirmButtonColor: '" . $vendor_primary_color . "'
                            });
                        });
                    </script>";
                    unset($_SESSION["product_purchase_response"]);
                }
                ?>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-shield-exclamation me-2"></i>Limits Monitor</h5>
                            <small class="text-muted">Showing IDs that have reached at least 60% of their daily purchase limit.</small>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover datatable align-middle" style="width:100%">
                                <thead class="table-light">
                                    <tr>
                                        <th>Target ID (Phone/Meter)</th>
                                        <th>Type</th>
                                        <th>User(s)</th>
                                        <th>Usage vs Limit</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $vid = $get_logged_admin_details["id"];
                                    $today = date("Y-m-d");
                                    
                                    // Query aggregate from tracker
                                    $tracker_q = mysqli_query($connection_server, "
                                        SELECT product_id, product_type, GROUP_CONCAT(DISTINCT username SEPARATOR ', ') as users, COUNT(*) as tx_count 
                                        FROM sas_daily_purchase_tracker 
                                        WHERE vendor_id='$vid' AND date_purchased='$today' 
                                        GROUP BY product_id, product_type
                                        ORDER BY tx_count DESC
                                    ");

                                    // Preload whitelists and blocks for fast checking
                                    $whitelists = [];
                                    $wl_q = mysqli_query($connection_server, "SELECT product_id FROM sas_validated_user_purchase_id_list WHERE vendor_id='$vid'");
                                    while($wl = mysqli_fetch_assoc($wl_q)) $whitelists[] = $wl['product_id'];

                                    $blocks = [];
                                    $bl_q = mysqli_query($connection_server, "SELECT product_id FROM sas_id_blocking_system WHERE vendor_id='$vid'");
                                    while($bl = mysqli_fetch_assoc($bl_q)) $blocks[] = $bl['product_id'];

                                    if ($tracker_q) {
                                        while ($row = mysqli_fetch_assoc($tracker_q)) {
                                            $pid = $row['product_id'];
                                            $type = $row['product_type'];
                                            $count = $row['tx_count'];
                                            
                                            $limit = getLimitForType($type, $limits, $shared_types);
                                            
                                            // 60% threshold
                                            if ($count >= (0.6 * $limit)) {
                                                
                                                $is_whitelisted = in_array($pid, $whitelists);
                                                $is_blocked = in_array($pid, $blocks);

                                                $status_badge = '<span class="badge bg-secondary">Normal</span>';
                                                if ($is_blocked) $status_badge = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Blocked</span>';
                                                elseif ($is_whitelisted) $status_badge = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Whitelisted</span>';
                                                elseif ($count >= $limit) $status_badge = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i> Limit Reached</span>';

                                                // Progress bar color
                                                $pct = min(100, ($count / $limit) * 100);
                                                $pg_color = $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-info');

                                                echo "<tr>";
                                                echo "<td class='fw-bold'>$pid</td>";
                                                echo "<td><span class='badge bg-light text-dark border'>" . strtoupper($type) . "</span></td>";
                                                echo "<td><small class='text-muted'>{$row['users']}</small></td>";
                                                echo "<td>
                                                        <div class='d-flex justify-content-between small mb-1'>
                                                            <span class='fw-bold'>$count <span class='text-muted'>/ $limit</span></span>
                                                            <span class='text-muted'>" . round($pct) . "%</span>
                                                        </div>
                                                        <div class='progress' style='height: 6px;'>
                                                            <div class='progress-bar $pg_color' role='progressbar' style='width: $pct%'></div>
                                                        </div>
                                                      </td>";
                                                echo "<td>$status_badge</td>";
                                                
                                                echo "<td>
                                                        <form method='post' class='d-inline' onsubmit=\"return confirm('Are you sure?');\">
                                                            <input type='hidden' name='product_id' value='$pid'>
                                                            <div class='btn-group btn-group-sm'>";
                                                
                                                if ($is_whitelisted) {
                                                    echo "<button type='submit' name='action' value='remove_whitelist' class='btn btn-outline-secondary' title='Remove Whitelist'><i class='bi bi-shield-minus'></i></button>";
                                                } else {
                                                    echo "<button type='submit' name='action' value='whitelist' class='btn btn-outline-success' title='Whitelist Number'><i class='bi bi-shield-check'></i> Whitelist</button>";
                                                }

                                                if ($is_blocked) {
                                                    echo "<button type='submit' name='action' value='remove_block' class='btn btn-outline-secondary' title='Remove Block'><i class='bi bi-unlock'></i></button>";
                                                } else {
                                                    echo "<button type='submit' name='action' value='block' class='btn btn-outline-danger' title='Block Number'><i class='bi bi-lock'></i> Block</button>";
                                                }
                                                
                                                echo "      </div>
                                                        </form>
                                                      </td>";
                                                echo "</tr>";
                                            }
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>
    
    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../assets-2/vendor/simple-datatables/simple-datatables.js"></script>
    <script src="../assets-2/js/main.js"></script>
</body>
</html>
