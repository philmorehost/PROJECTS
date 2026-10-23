<?php session_start();
include("../func/bc-admin-config.php");

$crypto_array = array("ngn", "usd", "gbp", "cad", "eur", "btc", "eth", "doge", "usdt", "usdc", "sol", "ada", "trx");
$telecoms_array = array("mtn", "airtel", "glo", "9mobile");
$cable_array = array("startimes", "dstv", "gotv", "showmax");
$card_array = array("chimoney");
$exam_array = array("waec", "neco", "nabteb", "jamb");
$electric_array = array("ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc", "bedc", "kaedco", "aba");
$betting_array = array("msport", "naijabet", "nairabet", "bet9ja-agent", "betland", "betlion", "supabet", "bet9ja", "bangbet", "betking", "1xbet", "betway", "merrybet", "mlotto", "western-lotto", "hallabet", "green-lotto");
$products_array = array_merge($crypto_array, $telecoms_array, $cable_array, $card_array, $exam_array, $electric_array, $betting_array);

// Auto-Installation: Ensure every known product is initialized to 'Enabled' for this vendor.
// Checked per-product (not just "vendor has zero rows") so products added after the vendor's
// first visit here, or installed piecemeal from individual service pages, still get seeded.
foreach ($products_array as $product_name) {
    $check_product = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_name='$product_name' LIMIT 1");
    if (mysqli_num_rows($check_product) == 0) {
        mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('" . $get_logged_admin_details["id"] . "', '$product_name', '1')");
    }
}

if (isset($_POST["install-all-product"])) {
    $all_product_status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["all-product-status"])));
    foreach ($products_array as $product_name) {
        if (is_numeric($all_product_status) && in_array($all_product_status, array("0", "1"))) {
            $product_status = $all_product_status;
        } else {
            $product_status = 1;
        }

        $select_product_lists = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_name='$product_name'");
        if (mysqli_num_rows($select_product_lists) == 0) {
            mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('" . $get_logged_admin_details["id"] . "', '$product_name', '$product_status')");
        } else {
            mysqli_query($connection_server, "UPDATE sas_products SET status='$product_status' WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_name='$product_name'");
        }
    }
    //All Product Installed Successfully
    $json_response_array = array("desc" => "All Product Installed Successfully");
    $_SESSION["product_purchase_response"] = $json_response_array["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}


if (isset($_POST["update-product"])) {
    $product_status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["product-status"])));
    $product_name = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["product-name"]))));
    $account_level_table_name_arrays = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values");
    $product_variety = array();
    if (!empty($product_name)) {
        if (in_array($product_name, $products_array)) {
            if (is_numeric($product_status) && in_array($product_status, array("0", "1"))) {
                $select_product_lists = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_name='$product_name'");
                if (mysqli_num_rows($select_product_lists) == 0) {
                    mysqli_query($connection_server, "INSERT INTO sas_products (vendor_id, product_name, status) VALUES ('" . $get_logged_admin_details["id"] . "', '$product_name', '$product_status')");
                } else {
                    mysqli_query($connection_server, "UPDATE sas_products SET status='$product_status' WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_name='$product_name'");
                }
                //Product Status Updated Successfully
                $json_response_array = array("desc" => "Product Status Updated Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                //Invalid Product Status
                $json_response_array = array("desc" => "Invalid Product Status");
                $json_response_encode = json_encode($json_response_array, true);
            }
        } else {
            //Invalid Product Name
            $json_response_array = array("desc" => "Invalid Product Name");
            $json_response_encode = json_encode($json_response_array, true);
        }
    } else {
        //Product Name Field Empty
        $json_response_array = array("desc" => "Product Name Field Empty");
        $json_response_encode = json_encode($json_response_array, true);
    }

    $json_response_decode = json_decode($json_response_encode, true);
    $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}
?>
<!DOCTYPE html>

<head>
    <title>Product Function | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
        <h1>PRODUCT SETTINGS</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Product Settings</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row g-4">
            <!-- Global Setup -->
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                    <div class="card-header bg-white py-4 border-0 text-center">
                        <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px;">
                        <i class="bi bi-gear-fill fs-1"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Global Product Setup</h4>
                        <p class="text-muted small">Bulk manage service installations across the entire portal</p>
                    </div>
                    <div class="card-body p-4 p-md-5 bg-light bg-opacity-50">
                        <div class="row justify-content-center">
                            <div class="col-lg-6">
                                <div class="bg-white p-4 rounded-4 shadow-sm border">
                                    <form method="post" action="">
                                        <label class="form-label small fw-bold text-muted mb-3">SET INITIAL STATUS FOR ALL PRODUCTS</label>
                                        <select name="all-product-status" class="form-select form-select-lg rounded-3 mb-4" required>
                                            <option value="" hidden>Choose Status...</option>
                                            <option value="1">Enable All Services</option>
                                            <option value="0">Disable All Services</option>
                                        </select>
                                        <button id="install-all-product" name="install-all-product"
                                            onclick="return confirm('This will reset/install all products to the selected status. Continue?')"
                                            type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                                            Apply Global Setup
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Specific Setup -->
            <?php
            $categories = [
                ['name' => 'Telecoms', 'id' => 'telecoms', 'products' => $telecoms_array, 'icon' => 'bi-reception-4', 'type' => 'telecoms', 'ext' => 'png', 'filter' => 'none'],
                ['name' => 'Cable TV', 'id' => 'cable', 'products' => $cable_array, 'icon' => 'bi-tv', 'type' => 'cable', 'ext' => 'jpg', 'filter' => 'none'],
                ['name' => 'Virtual Cards', 'id' => 'card', 'products' => $card_array, 'icon' => 'bi-credit-card-2-front', 'type' => 'card', 'ext' => 'png', 'filter' => 'none'],
                ['name' => 'Exam PINs', 'id' => 'exam', 'products' => $exam_array, 'icon' => 'bi-mortarboard', 'type' => 'exam', 'ext' => 'jpg', 'filter' => 'none'],
                ['name' => 'Electricity', 'id' => 'electric', 'products' => $electric_array, 'icon' => 'bi-lightning-charge', 'type' => 'electric', 'ext' => 'jpg', 'filter' => 'none'],
                ['name' => 'Betting', 'id' => 'betting', 'products' => $betting_array, 'icon' => 'bi-controller', 'type' => 'betting', 'ext' => 'jpg', 'filter' => 'none']
            ];

            // Special handling for Gift Cards since it has a dedicated setup page
            ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4 h-100 border-primary border-opacity-25">
                    <div class="card-body p-4 text-center d-flex flex-column justify-content-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px;">
                            <i class="bi bi-gift fs-1"></i>
                        </div>
                        <h4 class="fw-bold">Gift Card Marketplace</h4>
                        <p class="text-muted small">Access and manage thousands of global gift cards (Amazon, iTunes, etc.) from Reloadly.</p>
                        <a href="GiftCardSetup.php" class="btn btn-primary rounded-pill px-4 fw-bold">Open Gift Card Store</a>
                    </div>
                </div>
            </div>
            <?php

            foreach($categories as $cat):
            ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="card-title mb-0 d-flex align-items-center">
                            <i class="bi <?php echo $cat['icon']; ?> me-2 text-primary"></i>
                            <?php echo $cat['name']; ?>
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-center gap-2 mb-4 p-3 bg-light rounded-4">
                            <?php foreach($cat['products'] as $p): ?>
                                <img alt="<?php echo $p; ?>" id="<?php echo $p; ?>-lg"
                                     src="/asset/<?php echo $p; ?>.<?php echo $cat['ext']; ?>"
                                     onclick="tickProduct(this, '<?php echo $p; ?>', 'api-<?php echo $cat['id']; ?>-name', 'install-<?php echo $cat['id']; ?>', '<?php echo $cat['ext']; ?>');"
                                     class="rounded-3 border cursor-pointer"
                                     style="width: 50px; height: 50px; object-fit: contain; <?php echo $cat['filter']; ?>"/>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" action="">
                            <input id="api-<?php echo $cat['id']; ?>-name" name="product-name" type="text" hidden required />
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <select name="product-status" class="form-select rounded-3" required>
                                        <option value="" hidden>Status...</option>
                                        <option value="1">Enable</option>
                                        <option value="0">Disable</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button id="install-<?php echo $cat['id']; ?>" name="update-product" type="submit"
                                            class="btn btn-primary w-100 rounded-3 fw-bold border-2" style="pointer-events: none; opacity: 0.6;">Update</button>
                                </div>
                            </div>
                        </form>

                        <div class="mt-4 table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 small">
                                <thead class="bg-light">
                                    <tr><th>Product</th><th class="text-end">Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $p_list = "'" . implode("','", $cat['products']) . "'";
                                    $q = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name IN ($p_list)");
                                    if(mysqli_num_rows($q) > 0){
                                        while($r = mysqli_fetch_assoc($q)){
                                            $s_badge = ($r["status"] == 1) ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2">Inactive</span>';
                                            echo '<tr><td class="fw-bold text-uppercase">'.str_replace("-"," ",$r["product_name"]).'</td><td class="text-end">'.$s_badge.'</td></tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="2" class="text-center text-muted py-3">No products installed in this category</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>

</body>

</html>
