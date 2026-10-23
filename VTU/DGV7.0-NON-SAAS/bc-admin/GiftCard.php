<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-giftcard-func.php");

$vid = $get_logged_admin_details['id'];

// AJAX: Update Markup
if (isset($_POST['action']) && $_POST['action'] == 'update_markup') {
    $pid = (int)$_POST['product_id'];
    $markup = (float)$_POST['markup'];
    $sql = "UPDATE `sas_vendor_giftcard_products` SET vendor_markup='$markup' WHERE vendor_id='$vid' AND reloadly_product_id='$pid'";
    if (mysqli_query($connection_server, $sql)) {
        echo json_encode(['status' => 'success', 'message' => 'Markup updated successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($connection_server)]);
    }
    exit;
}

// AJAX: Bulk Update Markup
if (isset($_POST['action']) && $_POST['action'] == 'bulk_update_markups') {
    $markup = (float)$_POST['markup'];
    $country = mysqli_real_escape_string($connection_server, $_POST['country'] ?? '');

    if (!empty($country)) {
        // Using TRIM and CAST for robust matching to avoid Join failures on misaligned data types
        $sql = "UPDATE `sas_vendor_giftcard_products`
                SET vendor_markup='$markup'
                WHERE vendor_id='$vid'
                AND TRIM(CAST(reloadly_product_id AS CHAR)) IN (
                    SELECT TRIM(CAST(reloadly_product_id AS CHAR))
                    FROM `sas_global_giftcard_products`
                    WHERE UPPER(TRIM(country_code)) = UPPER(TRIM('$country'))
                )";
    } else {
        $sql = "UPDATE `sas_vendor_giftcard_products` SET vendor_markup='$markup' WHERE vendor_id='$vid'";
    }

    if (mysqli_query($connection_server, $sql)) {
        $affected = mysqli_affected_rows($connection_server);
        echo json_encode(['status' => 'success', 'message' => "Successfully updated $affected products."]);
    } else {
        $err = mysqli_error($connection_server);
        echo json_encode(['status' => 'error', 'message' => $err]);
    }
    exit;
}

// Handle Processing Fee Update
if (isset($_POST["update-processing-fee"])) {
    $giftcard_fee = mysqli_real_escape_string($connection_server, (float)$_POST["giftcard_fee"]);
    $default_markup = mysqli_real_escape_string($connection_server, (float)$_POST["default_giftcard_markup"]);
    $conversion_spread = mysqli_real_escape_string($connection_server, (float)$_POST["gc_conversion_spread"]);

    mysqli_query($connection_server, "UPDATE sas_vendors SET giftcard_fee_percent='$giftcard_fee', default_giftcard_markup='$default_markup' WHERE id='$vid'");
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'gc_conversion_spread', '$conversion_spread') ON DUPLICATE KEY UPDATE setting_value='$conversion_spread'");

    // Optionally update all installed products if requested
    if (isset($_POST['apply_to_all'])) {
        mysqli_query($connection_server, "UPDATE `sas_vendor_giftcard_products` SET vendor_markup='$default_markup' WHERE vendor_id='$vid'");
        $_SESSION["product_purchase_response"] = "Default settings saved and applied to all products!";
    } else {
        $_SESSION["product_purchase_response"] = "Gift Card Fees Updated Successfully";
    }

    header("Location: GiftCard.php");
    exit();
}

// AJAX: Sync Global Library
if (isset($_POST['action']) && $_POST['action'] == 'sync_global_library') {
    $token = getReloadlyAccessToken($vid);
    if (!$token) {
        @file_put_contents($log_f, "[$timestamp] ERROR: Failed to get Reloadly Token. Check API Credentials.\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Failed to get Reloadly Token. Check Credentials.']);
        exit;
    }

    $countries = ['US', 'NG', 'GB', 'CA', 'GH', 'KE', 'ZA', 'AE', 'FR', 'DE', 'IN', 'CN', 'AU', 'IE', 'NL', 'MX', 'ES', 'IT', 'BR', 'SG', 'PH', 'TR', 'MY', 'ID', 'TH', 'VN', 'JP', 'KR', 'CH', 'NO', 'SE', 'DK', 'BE', 'AT', 'PT', 'GR', 'PL', 'CZ', 'HU', 'RO', 'IL', 'SA', 'QA', 'KW'];
    $success_count = 0;
    $total_discovered = 0;

    foreach ($countries as $country) {
        $products = fetchReloadlyProducts($token, $country, 1, 200);
        if ($products && isset($products['content'])) {
            $c_count = count($products['content']);
            $total_discovered += $c_count;
            @file_put_contents($log_f, "[$timestamp] Processing $c_count products for $country...\n", FILE_APPEND);

            foreach ($products['content'] as $p) {
                $pid = (int)$p['productId'];
                $name = mysqli_real_escape_string($connection_server, $p['productName']);
                $logo = mysqli_real_escape_string($connection_server, $p['logoUrls'][0] ?? '');
                $min = (float)($p['minRecipientDenomination'] ?? 0);
                $max = (float)($p['maxRecipientDenomination'] ?? 0);
                $curr = mysqli_real_escape_string($connection_server, $p['recipientCurrencyCode'] ?? 'USD');
                $type = mysqli_real_escape_string($connection_server, $p['denominationType'] ?? 'FIXED');
                $fixed = mysqli_real_escape_string($connection_server, json_encode($p['fixedRecipientDenominations'] ?? []));
                $brand = $p['brand'] ?? [];
                $cat_id = (int)($brand['categoryId'] ?? 0);
                $cat_name = mysqli_real_escape_string($connection_server, $brand['categoryName'] ?? 'General');

                $sql = "INSERT INTO `sas_global_giftcard_products`
                        (reloadly_product_id, product_name, logo_url, min_value, max_value, fixed_values, denomination_type, currency_code, country_code, category_id, category_name)
                        VALUES ('$pid', '$name', '$logo', '$min', '$max', '$fixed', '$type', '$curr', '$country', '$cat_id', '$cat_name')
                        ON DUPLICATE KEY UPDATE product_name='$name', logo_url='$logo', min_value='$min', max_value='$max', fixed_values='$fixed', category_id='$cat_id', category_name='$cat_name', currency_code='$curr', country_code='$country', denomination_type='$type'";

                if (mysqli_query($connection_server, $sql)) {
                    $success_count++;
                    // Also sync back to vendor table if this product is installed but missing details
                    mysqli_query($connection_server, "UPDATE `sas_vendor_giftcard_products`
                        SET product_name='$name', logo_url='$logo', category_id='$cat_id', category_name='$cat_name', status=1, denomination_type='$type', fixed_values='$fixed', currency_code='$curr', country_code='$country'
                        WHERE vendor_id='$vid' AND reloadly_product_id='$pid'");
                } else {
                    $db_err = mysqli_error($connection_server);
                    @file_put_contents($log_f, "[$timestamp] DB ERROR [PID: $pid, NAME: $name]: $db_err | SQL: $sql\n", FILE_APPEND);
                }
            }
        } else {
            $p_err = $products['message'] ?? 'Unknown API Response';
            @file_put_contents($log_f, "[$timestamp] API ERROR/SKIP $country: $p_err\n", FILE_APPEND);
        }
    }

    @file_put_contents($log_f, "[$timestamp] FINISH: Discover=$total_discovered, Saved=$success_count\n", FILE_APPEND);
    echo json_encode(['status' => 'success', 'message' => "Successfully synced $success_count global products and updated your active inventory."]);
    exit;
}

// Handle Actions
if (isset($_GET['action']) && isset($_GET['product_id'])) {
    $action = mysqli_real_escape_string($connection_server, $_GET['action']);
    $pid = (int)$_GET['product_id'];

    if ($action == 'enable' || $action == 'disable') {
        $status = ($action == 'enable') ? 1 : 0;
        mysqli_query($connection_server, "UPDATE `sas_vendor_giftcard_products` SET status=$status WHERE vendor_id='$vid' AND TRIM(CAST(reloadly_product_id AS CHAR))='$pid'");
        $_SESSION["product_purchase_response"] = "Product status updated.";
    } elseif ($action == 'delete') {
        mysqli_query($connection_server, "DELETE FROM `sas_vendor_giftcard_products` WHERE vendor_id='$vid' AND TRIM(CAST(reloadly_product_id AS CHAR))='$pid'");
        $_SESSION["product_purchase_response"] = "Product removed from store.";
    }
    header("Location: GiftCard.php");
    exit();
}

// Handle Credentials Update
if (isset($_POST["update-key"])) {
    $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-id"])));
    $client_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["client-id"])));
    $client_secret = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["client-secret"])));
    $apistatus = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-status"])));

    $combined_key = $client_id . ":" . $client_secret;

    if (!empty($api_id) && is_numeric($api_id)) {
        if (!empty($client_id) && !empty($client_secret)) {
            if (is_numeric($apistatus) && in_array($apistatus, array("0", "1"))) {
                $select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && id='$api_id' && api_type='gift-card'");
                if (mysqli_num_rows($select_api_lists) == 1) {
                    mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$combined_key', status='$apistatus' WHERE vendor_id='$vid' && id='$api_id' && api_type='gift-card'");
                    $json_response_array = array("desc" => "Reloadly API Credentials Updated Successfully");
                } else {
                    $json_response_array = array("desc" => "API Listing Not Found");
                }
            } else {
                $json_response_array = array("desc" => "Invalid API Status");
            }
        } else {
            $json_response_array = array("desc" => "Both Client ID and Secret are required");
        }
    } else {
        $json_response_array = array("desc" => "Invalid API Selection");
    }
    $_SESSION["product_purchase_response"] = $json_response_array["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

$q_stats = mysqli_query($connection_server, "SELECT COUNT(*) as total FROM `sas_vendor_giftcard_products` WHERE vendor_id='$vid'");
$stats = mysqli_fetch_assoc($q_stats);

$q_global_check = mysqli_query($connection_server, "SELECT COUNT(*) as total FROM `sas_global_giftcard_products` ");
$global_count = mysqli_fetch_assoc($q_global_check)['total'] ?? 0;

$q_spread = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings WHERE vendor_id='$vid' AND setting_name='gc_conversion_spread' LIMIT 1");
$gc_conversion_spread = mysqli_fetch_assoc($q_spread)['setting_value'] ?? '0.00';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Gift Card API Manager | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <?php include("../func/bc-admin-header-link.php"); ?>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>GIFT CARD API MANAGER</h1>
      <?php if($global_count == 0): ?>
      <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-3">
          <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
          <div>
              <h6 class="fw-bold mb-1">Global Library is Empty!</h6>
              <p class="small mb-2">Users cannot see products because your local database hasn't synced with Reloadly yet.</p>
              <button id="btnSyncGlobal" class="btn btn-warning btn-sm fw-bold rounded-pill px-3">
                  <i class="bi bi-arrow-repeat me-1"></i> Sync Global Library Now
              </button>
          </div>
      </div>
      <?php endif; ?>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Gift Card API</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="card-title mb-0"><i class="bi bi-gear me-2 text-primary"></i>Gift Card Fees</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Default Vendor Markup (%)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0">%</span>
                                    <input name="default_giftcard_markup" type="number" step="any" value="<?php echo $get_logged_admin_details['default_giftcard_markup'] ?? 0; ?>" class="form-control rounded-3" placeholder="0.00" />
                                </div>
                                <small class="text-muted text-xs">Your profit margin. This will be automatically applied to every new gift card you install and can be synced to all active ones.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Processing Fee (%)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0">%</span>
                                    <input name="giftcard_fee" type="number" step="any" value="<?php echo $get_logged_admin_details['giftcard_fee_percent'] ?? 0; ?>" class="form-control rounded-3" placeholder="0.00" />
                                </div>
                                <small class="text-muted text-xs">A transactional processing fee applied separately at checkout (optional).</small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase">Exchange Rate Spread (NGN)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0">₦</span>
                                    <input name="gc_conversion_spread" type="number" min="0" max="100" step="any" value="<?php echo $gc_conversion_spread; ?>" class="form-control rounded-3" placeholder="0.00" />
                                </div>
                                <small class="text-muted text-xs">Added to the live conversion rate (e.g., ₦50 spread makes ₦1300/$ become ₦1350/$).</small>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="apply_to_all" id="applyToAll">
                                <label class="form-check-label small fw-bold" for="applyToAll">
                                    Apply Default Markup to all currently installed products
                                </label>
                            </div>

                            <button name="update-processing-fee" type="submit" class="btn btn-primary w-100 rounded-pill fw-bold py-2 shadow-sm">
                                <i class="bi bi-save me-2"></i>Update Gift Card Settings
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="card-title mb-0"><i class="bi bi-key me-2 text-primary"></i>Reloadly Credentials</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Select API Gateway</label>
                                <select name="api-id" class="form-select rounded-3" required onchange="parseReloadlyKey(this)">
                                    <?php
                                    $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && api_type='gift-card'");
                                    if(mysqli_num_rows($get_api_lists) >= 1){
                                        echo '<option value="" default hidden selected>Choose API</option>';
                                        while($api_details = mysqli_fetch_assoc($get_api_lists)){
                                            echo '<option value="'.$api_details["id"].'" data-key="'.$api_details["api_key"].'" data-status="'.$api_details["status"].'">'.strtoupper($api_details["api_base_url"]).'</option>';
                                        }
                                    } else {
                                        echo '<option value="" disabled>Purchase Gift Card API first</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                                <select id="api-status" name="api-status" class="form-select rounded-3" required>
                                    <option value="1">Enabled</option>
                                    <option value="0">Disabled</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Client ID</label>
                                <input type="text" id="client-id" name="client-id" class="form-control rounded-3" placeholder="Reloadly Client ID" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase">Client Secret</label>
                                <input type="password" id="client-secret" name="client-secret" class="form-control rounded-3" placeholder="Reloadly Client Secret" required>
                            </div>
                            <button name="update-key" type="submit" class="btn btn-primary w-100 rounded-pill fw-bold py-2 shadow-sm">
                                <i class="bi bi-save me-2"></i>Save Credentials
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 text-center p-4">
                    <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                        <i class="bi bi-info-circle fs-3"></i>
                    </div>
                    <h6 class="fw-bold">How it works</h6>
                    <p class="small text-muted">Enter your Reloadly API credentials to enable real-time gift card issuance. You can find these in your Reloadly Dashboard under API Settings.</p>
                    <a href="https://www.reloadly.com/" target="_blank" class="btn btn-link btn-sm">Visit Reloadly <i class="bi bi-box-arrow-up-right"></i></a>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 rounded-4">
                            <div class="card-body p-4 text-center">
                                <h1 class="display-5 fw-bold text-primary mb-1"><?php echo $stats['total']; ?></h1>
                                <p class="text-muted mb-3">Installed Products</p>
                                <a href="GiftCardSetup.php" class="btn btn-outline-primary rounded-pill px-4 btn-sm">Discover Products</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 rounded-4">
                            <div class="card-body p-4 text-center">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 50px; height: 50px;">
                                    <i class="bi bi-shield-check fs-4"></i>
                                </div>
                                <h6 class="fw-bold">Escrow Protected</h6>
                                <p class="small text-muted mb-0">P2P trades are secured by our internal escrow engine.</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0">My Active Inventory</h6>
                                    <div class="input-group input-group-sm shadow-sm" style="width: 350px;">
                                        <select id="bulkCountry" class="form-select bg-light border-0">
                                            <option value="">All Countries</option>
                                            <?php
                                            $q_countries = mysqli_query($connection_server, "SELECT DISTINCT country_code FROM `sas_global_giftcard_products` WHERE reloadly_product_id IN (SELECT reloadly_product_id FROM `sas_vendor_giftcard_products` WHERE vendor_id='$vid') ORDER BY country_code ASC");
                                            while($c_row = mysqli_fetch_assoc($q_countries)):
                                            ?>
                                                <option value="<?php echo $c_row['country_code']; ?>"><?php echo $c_row['country_code']; ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                        <input type="number" id="bulkMarkupInput" class="form-control" placeholder="Bulk Fee %" step="any">
                                        <button class="btn btn-warning fw-bold" type="button" id="btnBulkUpdateMarkup">Update All</button>
                                    </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light">
                                            <tr class="small text-uppercase">
                                                    <th class="ps-4">Product</th><th>Vendor Markup (%)</th><th>Status</th><th class="pe-4 text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                // Join with global table in admin too, to show if IDs are missing
                                                $q = mysqli_query($connection_server, "SELECT v.*, g.product_name as g_name, g.reloadly_product_id as g_rid
                                                    FROM `sas_vendor_giftcard_products` v
                                                    LEFT JOIN `sas_global_giftcard_products` g ON v.reloadly_product_id = g.reloadly_product_id
                                                    WHERE v.vendor_id='$vid'
                                                    ORDER BY v.product_name ASC LIMIT 100");
                                            if(mysqli_num_rows($q) > 0):
                                            while($r = mysqli_fetch_assoc($q)):
                                            ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="d-flex align-items-center">
                                                        <img src="../web/func/giftcard-image.php?id=<?php echo $r['reloadly_product_id']; ?>"
                                                             class="rounded-3 border"
                                                             style="width:50px; height:50px; margin-right:15px; object-fit:contain;"
                                                             onerror="this.src='<?php echo $r['logo_url'] ?? ''; ?>'; this.onerror=null;">
                                                            <div>
                                                                <span class="fw-bold small text-uppercase d-block"><?php echo $r['product_name'] ?: ($r['g_name'] ?: 'Unknown Product'); ?></span>
                                                                <?php if(!$r['reloadly_product_id']): ?>
                                                                    <span class="badge bg-danger text-white text-xs">Missing ID - Reinstall required</span>
                                                                <?php else: ?>
                                                                    <small class="text-muted text-xs">ID: <?php echo $r['reloadly_product_id']; ?></small>
                                                                    <?php if(!$r['g_rid']): ?><span class="badge bg-warning text-dark text-xs">Not in Global Cache</span><?php endif; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                    </div>
                                                </td>
                                                    <td>
                                                        <div class="input-group input-group-sm" style="width: 140px;">
                                                            <input type="number" step="any" value="<?php echo $r['vendor_markup']; ?>" class="form-control markup-input" data-id="<?php echo $r['reloadly_product_id']; ?>">
                                                            <button class="btn btn-outline-success btn-update-markup" type="button"><i class="bi bi-check-lg"></i></button>
                                                        </div>
                                                        <small class="text-muted text-xs">Profit margin added to each individual gift card product.</small>
                                                    </td>
                                                <td><?php echo $r['status'] ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">Inactive</span>'; ?></td>
                                                <td class="pe-4 text-end">
                                                    <div class="d-flex gap-2 justify-content-end">
                                                        <button class="btn btn-sm btn-light border" onclick="openAdminChat(<?php echo $r['id']; ?>)" title="Trade Chat"><i class="bi bi-chat-dots"></i></button>
                                                        <?php if($r['status']): ?>
                                                            <a href="GiftCard.php?action=disable&product_id=<?php echo $r['reloadly_product_id']; ?>" class="btn btn-sm btn-light border text-warning" title="Disable"><i class="bi bi-power"></i></a>
                                                        <?php else: ?>
                                                            <a href="GiftCard.php?action=enable&product_id=<?php echo $r['reloadly_product_id']; ?>" class="btn btn-sm btn-light border text-primary" title="Enable"><i class="bi bi-power"></i></a>
                                                        <?php endif; ?>
                                                        <a href="GiftCard.php?action=delete&product_id=<?php echo $r['reloadly_product_id']; ?>" class="btn btn-sm btn-light border text-danger" title="Delete" onclick="return confirm('Remove this product from your store?')"><i class="bi bi-trash"></i></a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; else: ?>
                                            <tr><td colspan="4" class="text-center py-4 text-muted">No products installed yet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php if($stats['total'] > 10): ?>
                            <div class="card-footer bg-white text-center py-3">
                                <a href="GiftCardSetup.php" class="small fw-bold">View All Products</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>

    <!-- Admin P2P Chat Modal -->
    <div class="modal fade" id="adminChatModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Trade Support Chat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="adminChatWindow" style="height: 300px; overflow-y: auto; padding: 15px; border: 1px solid #e2e8f0; border-radius: 1rem; background: #fff;" class="mb-3"></div>
                    <form id="adminChatForm">
                        <input type="hidden" id="admin_chat_trade_id">
                        <div class="input-group">
                            <input type="text" id="adminChatInput" class="form-control rounded-start-pill" placeholder="Type a message..." required>
                            <button type="submit" class="btn btn-primary rounded-end-pill px-4"><i class="bi bi-send"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
        .chat-msg { margin-bottom: 10px; padding: 10px; border-radius: 15px; max-width: 80%; font-size: 0.85rem; }
        .chat-msg.mine { background: #0d6efd; color: white; margin-left: auto; border-bottom-right-radius: 2px; }
        .chat-msg.theirs { background: #f1f5f9; color: #1e293b; margin-right: auto; border-bottom-left-radius: 2px; }
    </style>

    <script>
    let adminChatInterval = null;
    function openAdminChat(tradeId) {
        document.getElementById('admin_chat_trade_id').value = tradeId;
        fetchAdminMessages(tradeId);
        if(adminChatInterval) clearInterval(adminChatInterval);
        adminChatInterval = setInterval(() => fetchAdminMessages(tradeId), 3000);
        new bootstrap.Modal(document.getElementById('adminChatModal')).show();
    }

    function fetchAdminMessages(tradeId) {
        fetch('../web/p2p-chat-ajax.php?action=fetch_messages&trade_id=' + tradeId)
        .then(r => r.json()).then(res => {
            const win = document.getElementById('adminChatWindow');
            win.innerHTML = '';
            res.messages.forEach(m => {
                win.innerHTML += `<div class="chat-msg ${m.is_mine ? 'mine' : 'theirs'}">${m.message}</div>`;
            });
            win.scrollTop = win.scrollHeight;
        });
    }

    document.getElementById('adminChatForm').onsubmit = function(e) {
        e.preventDefault();
        const tid = document.getElementById('admin_chat_trade_id').value;
        const msg = document.getElementById('adminChatInput').value;
        const fd = new FormData(); fd.append('action', 'send_message'); fd.append('trade_id', tid); fd.append('message', msg);
        fetch('../web/p2p-chat-ajax.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            document.getElementById('adminChatInput').value = '';
            fetchAdminMessages(tid);
        });
    }

    document.querySelectorAll('.btn-update-markup').forEach(btn => {
        btn.onclick = function() {
            const input = this.parentElement.querySelector('.markup-input');
            const pid = input.getAttribute('data-id');
            const markup = input.value;

            this.disabled = true;

            const fd = new FormData();
            fd.append('action', 'update_markup');
            fd.append('product_id', pid);
            fd.append('markup', markup);

            fetch('GiftCard.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(res => {
                this.disabled = false;
                if(res.status === 'success') {
                    alert(res.message || 'Markup updated!');
                } else {
                    alert('Error: ' + (res.message || 'Unknown error'));
                }
            }).catch(err => {
                this.disabled = false;
                alert('A network error occurred.');
            });
        };
    });

    const btnSync = document.getElementById('btnSyncGlobal');
    if(btnSync) {
        btnSync.onclick = function() {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Syncing Library...';

            const fd = new FormData();
            fd.append('action', 'sync_global_library');

            fetch('GiftCard.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                alert(res.message);
                location.reload();
            }).catch(err => {
                alert('An error occurred during sync.');
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Sync Global Library Now';
            });
        };
    }

    document.getElementById('btnBulkUpdateMarkup').onclick = function() {
        const markup = document.getElementById('bulkMarkupInput').value;
        const country = document.getElementById('bulkCountry').value;

        if(markup === '') { alert('Please enter a markup percentage'); return; }
        if(!confirm('Update markup for all ' + country + ' installed gift cards to ' + markup + '%?')) return;

        const btn = this;
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'bulk_update_markups');
        fd.append('markup', markup);
        fd.append('country', country);

        fetch('GiftCard.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(res => {
            btn.disabled = false;
            if(res.status === 'success') {
                alert(res.message || 'All markups updated successfully!');
                location.reload();
            } else {
                alert('Error: ' + (res.message || 'Unknown error occurred'));
            }
        }).catch(err => {
            btn.disabled = false;
            alert('A network error occurred.');
        });
    };

    function parseReloadlyKey(select) {
        const option = select.options[select.selectedIndex];
        const combined = option.getAttribute('data-key') || '';
        const status = option.getAttribute('data-status');

        if (combined.includes(':')) {
            const parts = combined.split(':');
            document.getElementById('client-id').value = parts[0];
            document.getElementById('client-secret').value = parts[1];
        } else {
            document.getElementById('client-id').value = combined;
            document.getElementById('client-secret').value = '';
        }
        document.getElementById('api-status').value = status || '1';
    }
    </script>
</body>
</html>
