<?php session_start();
include("../func/bc-admin-config.php");
require_once("../func/api-gateway/chimoney.php");
require_once("../func/api-gateway/bsicards.php");

// 1. Bulk Update Profit Settings
if (isset($_POST["update-profit-settings"])) {
    $issuance_profit = mysqli_real_escape_string($connection_server, (float)$_POST["vc_issuance_profit_usd"]);
    $funding_profit = mysqli_real_escape_string($connection_server, (float)$_POST["vc_funding_profit_percent"]);
    $conversion_spread = mysqli_real_escape_string($connection_server, (float)$_POST["vc_conversion_spread"]);
    $vid = $get_logged_admin_details["id"];

    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'vc_issuance_profit_usd', '$issuance_profit') ON DUPLICATE KEY UPDATE setting_value='$issuance_profit'");
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'vc_funding_profit_percent', '$funding_profit') ON DUPLICATE KEY UPDATE setting_value='$funding_profit'");
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'vc_conversion_spread', '$conversion_spread') ON DUPLICATE KEY UPDATE setting_value='$conversion_spread'");

    $_SESSION["product_purchase_response"] = "Profit Settings Updated Successfully";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

// 2. Install/Uninstall Products
if (isset($_GET["action"]) && isset($_GET["pid"])) {
    $pid = mysqli_real_escape_string($connection_server, $_GET["pid"]);
    $vid = $get_logged_admin_details["id"];

    if ($_GET["action"] == "install") {
        $q_p = mysqli_query($connection_server, "SELECT * FROM sas_global_virtual_card_products WHERE chimoney_product_id='$pid' LIMIT 1");
        if ($r_p = mysqli_fetch_assoc($q_p)) {
            mysqli_query($connection_server, "INSERT INTO sas_vendor_virtual_card_products (vendor_id, chimoney_product_id, status) VALUES ('$vid', '$pid', '1') ON DUPLICATE KEY UPDATE status='1'");
            $_SESSION["product_purchase_response"] = "Virtual Card Product Installed Successfully";
        }
    } elseif ($_GET["action"] == "uninstall") {
        mysqli_query($connection_server, "UPDATE sas_vendor_virtual_card_products SET status='0' WHERE vendor_id='$vid' AND chimoney_product_id='$pid'");
        $_SESSION["product_purchase_response"] = "Virtual Card Product Uninstalled Successfully";
    }
    header("Location: VirtualCard.php");
    exit();
}

// 3. Manual Freeze/Unfreeze
if (isset($_GET["control"]) && isset($_GET["ref"])) {
    $ref = $_GET["ref"];
    $vid = $get_logged_admin_details["id"];
    if ($_GET["control"] == "freeze") {
        $res = freezeVirtualCardV2($vid, $ref, 0);
    } else {
        $res = unfreezeVirtualCardV2($vid, $ref);
    }
    $_SESSION["product_purchase_response"] = $res['status'] == 'success' ? "Card Status Updated Successfully" : "Error: " . $res['message'];
    header("Location: VirtualCard.php");
    exit();
}

// 4. Update API Credentials
if (isset($_POST["update-api-key"])) {
    $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-id"])));
    $apikey = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-key"])));

    // Handle BSICards compound key
    if (isset($_POST["api-key-bsi-secret"])) {
        $secret = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-key-bsi-secret"])));
        $apikey = $apikey . "|" . $secret;
    }

    $apistatus = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-status"])));
    $vid = $get_logged_admin_details["id"];

    if (!empty($api_id)) {
        mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$apikey', status='$apistatus' WHERE id='$api_id' AND vendor_id='$vid'");
        $_SESSION["product_purchase_response"] = "API Credentials Updated Successfully";
    }
    header("Location: VirtualCard.php");
    exit();
}

// Ensure at least one global product exists for demo/bootstrap if table is empty
$check_global = mysqli_query($connection_server, "SELECT id FROM sas_global_virtual_card_products LIMIT 1");
if (mysqli_num_rows($check_global) == 0) {
    mysqli_query($connection_server, "INSERT INTO sas_global_virtual_card_products (chimoney_product_id, name, description, logo_url) VALUES ('visa_usd_global', 'Visa USD Virtual Card', 'Premium USD Virtual Card accepted worldwide.', 'https://img.icons8.com/color/512/visa.png')");
}

// Ensure BSICards global products exist
$check_bsi = mysqli_query($connection_server, "SELECT id FROM sas_global_virtual_card_products WHERE chimoney_product_id='bsi_mastercard'");
if (mysqli_num_rows($check_bsi) == 0) {
    mysqli_query($connection_server, "INSERT INTO sas_global_virtual_card_products (chimoney_product_id, name, description, logo_url, currency) VALUES ('bsi_mastercard', 'BSICards MasterCard', 'BSI Virtual MasterCard with 3DS support.', 'https://img.icons8.com/color/512/mastercard.png', 'USD')");
    mysqli_query($connection_server, "INSERT INTO sas_global_virtual_card_products (chimoney_product_id, name, description, logo_url, currency) VALUES ('bsi_visa', 'BSICards Visa', 'BSI Virtual Visa Card (KYC Required).', 'https://img.icons8.com/color/512/visa.png', 'USD')");
}

// Fetch current profit settings for UI
$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='".$get_logged_admin_details["id"]."' AND setting_name LIKE 'vc_%'");
$vc_settings = []; while($rs = mysqli_fetch_assoc($q_set)) $vc_settings[$rs['setting_name']] = $rs['setting_value'];
$issuance_profit_val = $vc_settings['vc_issuance_profit_usd'] ?? '2.00';
$funding_profit_val = $vc_settings['vc_funding_profit_percent'] ?? '3.00';
$conversion_spread_val = $vc_settings['vc_conversion_spread'] ?? '0.00';

?>
<!DOCTYPE html>
<head>
    <title>Virtual Card Manager | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>VIRTUAL CARD MANAGER (V2)</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Virtual Cards</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <div class="row">
        <!-- Profit Settings -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between">
                    <h5 class="fw-bold mb-0 text-primary">Profit Margins</h5>
                    <i class="bi bi-cash-coin text-primary"></i>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Issuance Profit (USD)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input name="vc_issuance_profit_usd" type="number" step="any" class="form-control" value="<?php echo $issuance_profit_val; ?>">
                            </div>
                            <small class="text-xs text-muted">Added to the provider's cost.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Funding Fee (%)</label>
                            <div class="input-group">
                                <span class="input-group-text">%</span>
                                <input name="vc_funding_profit_percent" type="number" step="any" class="form-control" value="<?php echo $funding_profit_val; ?>">
                            </div>
                            <small class="text-xs text-muted">Percentage profit on every top-up.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Exchange Rate Spread (NGN)</label>
                            <div class="input-group">
                                <span class="input-group-text">₦</span>
                                <input name="vc_conversion_spread" type="number" min="0" max="100" step="any" class="form-control" value="<?php echo $conversion_spread_val; ?>">
                            </div>
                            <small class="text-xs text-muted">This amount is added to the live conversion rate per $1. For example, if the live rate is ₦1300 and you set this to 50, the user will be charged ₦1350/$. This helps cover the gap between official and market rates.</small>
                        </div>
                        <button name="update-profit-settings" type="submit" class="btn btn-primary w-100 rounded-pill">Save Margins</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mt-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary">API Configuration</h5>
                    <a href="javascript:void(0)" onclick="toggleGuide()" class="text-primary" title="Setup Guide">
                        <i class="bi bi-info-circle-fill"></i>
                    </a>
                </div>
                <div class="card-body">
                    <!-- Setup Guide (Initially Hidden) -->
                    <div id="setup-guide" class="alert alert-info small mb-4" style="display:none;">
                        <h6 class="fw-bold"><i class="bi bi-lightbulb me-2"></i>Setup Instructions</h6>
                        <p class="mb-2"><strong>Chimoney:</strong> Get your API Key from the Chimoney dashboard settings.</p>
                        <p class="mb-0"><strong>BSICards:</strong> You need both Public Key and Secret Key. Enter them in the respective fields below.</p>
                    </div>

                    <!-- Chimoney Config -->
                    <form method="post" class="mb-4 pb-4 border-bottom">
                        <h6 class="fw-bold small text-uppercase mb-3">Chimoney API</h6>
                        <?php
                            $q_api = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' AND api_type='chimoney' LIMIT 1");
                            $chimoney_api = mysqli_fetch_assoc($q_api);
                            if(!$chimoney_api):
                        ?>
                        <div class="alert alert-warning small py-2">
                            <i class="bi bi-exclamation-triangle me-2"></i> Chimoney not installed.
                            <a href="MarketPlace.php" class="fw-bold">Marketplace</a>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="api-id" value="<?php echo $chimoney_api['id']; ?>">
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Status</label>
                            <select name="api-status" class="form-select form-select-sm">
                                <option value="1" <?php echo $chimoney_api['status'] == 1 ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?php echo $chimoney_api['status'] == 0 ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">API Key</label>
                            <input type="text" name="api-key" class="form-control form-control-sm" value="<?php echo $chimoney_api['api_key']; ?>" placeholder="Chimoney API Key">
                        </div>
                        <button name="update-api-key" type="submit" class="btn btn-outline-primary w-100 rounded-pill btn-sm fw-bold">Update Chimoney</button>
                        <?php endif; ?>
                    </form>

                    <!-- BSICards Config -->
                    <form method="post">
                        <h6 class="fw-bold small text-uppercase mb-3">BSICards API</h6>
                        <?php
                            $bsi_api = getBSICardsDetails($get_logged_admin_details["id"]);
                            if(!$bsi_api):
                        ?>
                        <div class="alert alert-warning small py-2">
                            <i class="bi bi-exclamation-triangle me-2"></i> BSICards not installed.
                            <a href="MarketPlace.php" class="fw-bold">Marketplace</a>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="api-id" value="<?php echo $bsi_api['id']; ?>">
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Status</label>
                            <select name="api-status" class="form-select form-select-sm">
                                <option value="1" <?php echo $bsi_api['status'] == 1 ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?php echo $bsi_api['status'] == 0 ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Public Key</label>
                            <input type="text" name="api-key" class="form-control form-control-sm" value="<?php echo $bsi_api['public_key']; ?>" placeholder="BSICards Public Key">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Secret Key</label>
                            <input type="password" name="api-key-bsi-secret" class="form-control form-control-sm" value="<?php echo $bsi_api['secret_key']; ?>" placeholder="BSICards Secret Key">
                        </div>
                        <button name="update-api-key" type="submit" class="btn btn-outline-success w-100 rounded-pill btn-sm fw-bold">Update BSICards</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- Available Products -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0 text-primary">Card Products & Installation</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Provider</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Chimoney Products
                                $q_gp = mysqli_query($connection_server, "SELECT * FROM sas_global_virtual_card_products WHERE status=1");
                                while($gp = mysqli_fetch_assoc($q_gp)):
                                    $check_inst = mysqli_query($connection_server, "SELECT status FROM sas_vendor_virtual_card_products WHERE vendor_id='".$get_logged_admin_details["id"]."' AND chimoney_product_id='".$gp['chimoney_product_id']."' LIMIT 1");
                                    $inst = mysqli_fetch_assoc($check_inst);
                                    $is_installed = ($inst && $inst['status'] == 1);
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="<?php echo $gp['logo_url']; ?>" class="rounded-circle me-2" width="35" height="35">
                                            <div>
                                                <h6 class="mb-0 fw-bold"><?php echo $gp['name']; ?></h6>
                                                <small class="text-muted text-xs"><?php echo $gp['currency']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-primary bg-opacity-10 text-primary">Chimoney</span></td>
                                    <td>
                                        <?php if($is_installed): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success">Installed</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary">Not Installed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if($is_installed): ?>
                                            <a href="?action=uninstall&pid=<?php echo $gp['chimoney_product_id']; ?>" class="btn btn-outline-danger btn-sm rounded-pill px-3">Uninstall</a>
                                        <?php else: ?>
                                            <a href="?action=install&pid=<?php echo $gp['chimoney_product_id']; ?>" class="btn btn-primary btn-sm rounded-pill px-3">Install</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>

                                <!-- BSICards Products (Loaded from Global Products) -->
                                <?php
                                    $q_bsi = mysqli_query($connection_server, "SELECT * FROM sas_global_virtual_card_products WHERE chimoney_product_id LIKE 'bsi_%' AND status=1");
                                    while($gp = mysqli_fetch_assoc($q_bsi)):
                                        $check_inst = mysqli_query($connection_server, "SELECT status FROM sas_vendor_virtual_card_products WHERE vendor_id='".$get_logged_admin_details["id"]."' AND chimoney_product_id='".$gp['chimoney_product_id']."' LIMIT 1");
                                        $inst = mysqli_fetch_assoc($check_inst);
                                        $is_installed = ($inst && $inst['status'] == 1);
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center me-2" style="width:35px; height:35px;">
                                                <i class="bi bi-credit-card-2-front text-success"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 fw-bold"><?php echo $gp['name']; ?></h6>
                                                <small class="text-muted text-xs"><?php echo $gp['currency']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-success bg-opacity-10 text-success">BSICards</span></td>
                                    <td>
                                        <?php if($is_installed): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success">Installed</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary">Not Installed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if($is_installed): ?>
                                            <a href="?action=uninstall&pid=<?php echo $gp['chimoney_product_id']; ?>" class="btn btn-outline-danger btn-sm rounded-pill px-3">Uninstall</a>
                                        <?php else: ?>
                                            <a href="?action=install&pid=<?php echo $gp['chimoney_product_id']; ?>" class="btn btn-primary btn-sm rounded-pill px-3">Install</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Active User Cards -->
            <div class="card shadow-sm border-0 rounded-4 mt-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0 text-primary">Active User Cards</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Card Ref</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th class="text-end">Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $q_uc = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE vendor_id='".$get_logged_admin_details["id"]."' ORDER BY created_at DESC LIMIT 50");
                                while($uc = mysqli_fetch_assoc($q_uc)):
                                ?>
                                <tr>
                                    <td><span class="fw-bold text-muted"><?php echo $uc['username']; ?></span></td>
                                    <td><code class="text-xs"><?php echo $uc['reference']; ?></code></td>
                                    <td><span class="fw-bold text-success">$<?php echo number_format($uc['balance_usd'], 2); ?></span></td>
                                    <td>
                                        <?php if($uc['status'] == 'active'): ?>
                                            <span class="badge bg-success rounded-pill">Active</span>
                                        <?php elseif($uc['status'] == 'frozen'): ?>
                                            <span class="badge bg-warning rounded-pill">Frozen <?php echo $uc['is_frozen_auto'] ? '(Auto)' : ''; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill">Terminated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if($uc['status'] == 'active'): ?>
                                            <a href="?control=freeze&ref=<?php echo $uc['reference']; ?>" class="btn btn-warning btn-sm rounded-pill" title="Freeze Card"><i class="bi bi-pause-fill"></i></a>
                                        <?php elseif($uc['status'] == 'frozen'): ?>
                                            <a href="?control=unfreeze&ref=<?php echo $uc['reference']; ?>" class="btn btn-success btn-sm rounded-pill" title="Unfreeze Card"><i class="bi bi-play-fill"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; if(mysqli_num_rows($q_uc) == 0) echo "<tr><td colspan='5' class='text-center py-4 text-muted'>No user cards found.</td></tr>"; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
    <script>
    function toggleGuide() {
        var x = document.getElementById("setup-guide");
        if (x.style.display === "none") {
            x.style.display = "block";
        } else {
            x.style.display = "none";
        }
    }
    </script>
</body>
</html>
