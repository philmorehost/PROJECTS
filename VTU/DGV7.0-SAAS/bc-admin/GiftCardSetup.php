<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-giftcard-func.php");

$vid = (int)$get_logged_admin_details['id'];

// AJAX: Bulk Install
if (isset($_POST['action']) && $_POST['action'] == 'bulk_install') {
    $products = json_decode($_POST['products_json'], true);
    $success_count = 0;
    $errors = [];

    if (is_array($products)) {
        foreach ($products as $product) {
            $pid = (int)($product['productId'] ?? $product['reloadly_product_id'] ?? 0);
            if ($pid <= 0) continue;
            $name = mysqli_real_escape_string($connection_server, $product['productName'] ?? $product['product_name'] ?? 'Unknown Product');
            $logo = mysqli_real_escape_string($connection_server, ($product['logoUrls'][0] ?? $product['logo_url'] ?? ''));

            // Extract brand/category info if available
            $brand = $product['brand'] ?? [];
            $cat_id = (int)($brand['categoryId'] ?? 0);
            $cat_name = mysqli_real_escape_string($connection_server, $brand['categoryName'] ?? 'General');

            $redeem = $product['redeemInstruction'] ?? [];
            $desc = mysqli_real_escape_string($connection_server, $redeem['concise'] ?? '');
            $instr = mysqli_real_escape_string($connection_server, $redeem['verbose'] ?? '');

            $default_markup = (float)($get_logged_admin_details['default_giftcard_markup'] ?? 0);
            $sql = "INSERT INTO `sas_vendor_giftcard_products` (vendor_id, reloadly_product_id, product_name, logo_url, category_id, category_name, description, redemption_instructions, vendor_markup, status)
                    VALUES ('$vid', '$pid', '$name', '$logo', '$cat_id', '$cat_name', '$desc', '$instr', '$default_markup', 1)
                    ON DUPLICATE KEY UPDATE product_name='$name', logo_url='$logo', category_id='$cat_id', category_name='$cat_name', description='$desc', redemption_instructions='$instr', status=1";

            if (mysqli_query($connection_server, $sql)) {
                $min = (float)($product['minRecipientDenomination'] ?? 0);
                $max = (float)($product['maxRecipientDenomination'] ?? 0);
                $curr = mysqli_real_escape_string($connection_server, $product['recipientCurrencyCode'] ?? 'USD');
                $country = mysqli_real_escape_string($connection_server, $product['country']['isoName'] ?? '');
                $type = mysqli_real_escape_string($connection_server, $product['denominationType'] ?? 'FIXED');
                $fixed = mysqli_real_escape_string($connection_server, json_encode($product['fixedRecipientDenominations'] ?? []));

                mysqli_query($connection_server, "INSERT INTO `sas_global_giftcard_products`
                    (reloadly_product_id, product_name, logo_url, min_value, max_value, fixed_values, denomination_type, currency_code, country_code, category_id, category_name, description, redemption_instructions)
                    VALUES ('$pid', '$name', '$logo', '$min', '$max', '$fixed', '$type', '$curr', '$country', '$cat_id', '$cat_name', '$desc', '$instr')
                    ON DUPLICATE KEY UPDATE product_name='$name', logo_url='$logo', min_value='$min', max_value='$max', fixed_values='$fixed', category_id='$cat_id', category_name='$cat_name', description='$desc', redemption_instructions='$instr'");

                $success_count++;
            } else {
                $errors[] = "$name: " . mysqli_error($connection_server);
            }
        }
    }

    if(count($errors) > 0) {
    }

    echo json_encode(['status' => ($success_count > 0 ? 'success' : 'error'), 'count' => $success_count, 'errors' => $errors]);
    exit;
}

// AJAX: Install Product
if (isset($_POST['action']) && $_POST['action'] == 'install') {
    $product = json_decode($_POST['product_data'], true);
    if ($product) {
        $pid = (int)($product['productId'] ?? $product['reloadly_product_id'] ?? 0);
        if ($pid <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Product ID from source.']);
            exit;
        }
        $name = mysqli_real_escape_string($connection_server, $product['productName'] ?? $product['product_name'] ?? 'Unknown Product');
        $logo = mysqli_real_escape_string($connection_server, ($product['logoUrls'][0] ?? $product['logo_url'] ?? ''));

        $brand = $product['brand'] ?? [];
        $cat_id = (int)($brand['categoryId'] ?? 0);
        $cat_name = mysqli_real_escape_string($connection_server, $brand['categoryName'] ?? 'General');

        $redeem = $product['redeemInstruction'] ?? [];
        $desc = mysqli_real_escape_string($connection_server, $redeem['concise'] ?? '');
        $instr = mysqli_real_escape_string($connection_server, $redeem['verbose'] ?? '');

        $default_markup = (float)($get_logged_admin_details['default_giftcard_markup'] ?? 0);
        $sql = "INSERT INTO `sas_vendor_giftcard_products` (vendor_id, reloadly_product_id, product_name, logo_url, category_id, category_name, description, redemption_instructions, vendor_markup, status)
                VALUES ('$vid', '$pid', '$name', '$logo', '$cat_id', '$cat_name', '$desc', '$instr', '$default_markup', 1)
                ON DUPLICATE KEY UPDATE product_name='$name', logo_url='$logo', category_id='$cat_id', category_name='$cat_name', description='$desc', redemption_instructions='$instr', status=1";

        if (mysqli_query($connection_server, $sql)) {
            // Also cache globally if not exists
            $min = (float)($product['minRecipientDenomination'] ?? 0);
            $max = (float)($product['maxRecipientDenomination'] ?? 0);
            $curr = mysqli_real_escape_string($connection_server, $product['recipientCurrencyCode'] ?? 'USD');
            $country = mysqli_real_escape_string($connection_server, $product['country']['isoName'] ?? '');
            $type = mysqli_real_escape_string($connection_server, $product['denominationType'] ?? 'FIXED');
            $fixed = mysqli_real_escape_string($connection_server, json_encode($product['fixedRecipientDenominations'] ?? []));

            mysqli_query($connection_server, "INSERT INTO `sas_global_giftcard_products`
                (reloadly_product_id, product_name, logo_url, min_value, max_value, fixed_values, denomination_type, currency_code, country_code, category_id, category_name, description, redemption_instructions)
                VALUES ('$pid', '$name', '$logo', '$min', '$max', '$fixed', '$type', '$curr', '$country', '$cat_id', '$cat_name', '$desc', '$instr')
                ON DUPLICATE KEY UPDATE product_name='$name', logo_url='$logo', min_value='$min', max_value='$max', fixed_values='$fixed', category_id='$cat_id', category_name='$cat_name', description='$desc', redemption_instructions='$instr'");

            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($connection_server)]);
        }
    }
    exit;
}

// Search Logic
$country = $_GET['country'] ?? 'US';

// Diagnostic: Check if global library is empty
$q_global_check = mysqli_query($connection_server, "SELECT id FROM `sas_global_giftcard_products` LIMIT 1");
$global_is_empty = (mysqli_num_rows($q_global_check) == 0);
$search = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);

// Get Token
$token = getReloadlyAccessToken($vid);
$products = [];
if($token && $token != "MOCK_TOKEN") {
    $products = fetchReloadlyProducts($token, $country, $page, 50, $search);
} else {
    // For development/mock, let's use global cache if empty
    $q_global = mysqli_query($connection_server, "SELECT * FROM `sas_global_giftcard_products` WHERE country_code='$country' AND product_name LIKE '%$search%' LIMIT 50");
    while($r = mysqli_fetch_assoc($q_global)) {
        $products['content'][] = [
            'productId' => $r['reloadly_product_id'],
            'productName' => $r['product_name'],
            'logoUrls' => [$r['logo_url']],
            'minRecipientDenomination' => $r['min_value'],
            'maxRecipientDenomination' => $r['max_value'],
            'recipientCurrencyCode' => $r['currency_code'],
            'brand' => [
                'categoryId' => $r['category_id'],
                'categoryName' => $r['category_name']
            ],
            'denominationType' => $r['denomination_type'],
            'fixedRecipientDenominations' => json_decode($r['fixed_values'], true),
            'country' => ['isoName' => $r['country_code']],
            'redeemInstruction' => [
                'concise' => $r['description'],
                'verbose' => $r['redemption_instructions']
            ]
        ];
    }
}

// Get already installed IDs
$installed_ids = [];
$q_inst = mysqli_query($connection_server, "SELECT reloadly_product_id FROM `sas_vendor_giftcard_products` WHERE vendor_id='$vid'");
while($r = mysqli_fetch_assoc($q_inst)) $installed_ids[] = $r['reloadly_product_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Gift Card Setup | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .product-card { transition: all 0.3s; border: 1px solid #eee; }
        .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
        .logo-placeholder { width: 60px; height: 60px; object-fit: contain; }
    </style>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>GIFT CARD SETUP</h1>
      <?php if($global_is_empty): ?>
      <div class="alert alert-danger border-0 shadow-sm mb-3">
          <i class="bi bi-exclamation-octagon-fill me-2"></i>
          <strong>Global Library Empty:</strong> Please go to <a href="GiftCard.php" class="fw-bold text-decoration-underline">API Manager</a> and click "Sync Global Library" to populate product details.
      </div>
      <?php endif; ?>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Gift Card Setup</li>
        </ol>
      </nav>
    </div>

    <section class="section">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body p-4">
                <form method="get" id="filterForm" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Country</label>
                        <select name="country" class="form-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="US" <?php echo $country=='US'?'selected':''; ?>>United States</option>
                            <option value="NG" <?php echo $country=='NG'?'selected':''; ?>>Nigeria</option>
                            <option value="GB" <?php echo $country=='GB'?'selected':''; ?>>United Kingdom</option>
                            <option value="CA" <?php echo $country=='CA'?'selected':''; ?>>Canada</option>
                            <option value="GH" <?php echo $country=='GH'?'selected':''; ?>>Ghana</option>
                            <option value="KE" <?php echo $country=='KE'?'selected':''; ?>>Kenya</option>
                            <option value="ZA" <?php echo $country=='ZA'?'selected':''; ?>>South Africa</option>
                            <option value="AE" <?php echo $country=='AE'?'selected':''; ?>>United Arab Emirates</option>
                            <option value="FR" <?php echo $country=='FR'?'selected':''; ?>>France</option>
                            <option value="DE" <?php echo $country=='DE'?'selected':''; ?>>Germany</option>
                            <option value="IN" <?php echo $country=='IN'?'selected':''; ?>>India</option>
                            <option value="CN" <?php echo $country=='CN'?'selected':''; ?>>China</option>
                            <option value="AU" <?php echo $country=='AU'?'selected':''; ?>>Australia</option>
                            <option value="IE" <?php echo $country=='IE'?'selected':''; ?>>Ireland</option>
                            <option value="NL" <?php echo $country=='NL'?'selected':''; ?>>Netherlands</option>
                            <option value="MX" <?php echo $country=='MX'?'selected':''; ?>>Mexico</option>
                            <option value="ES" <?php echo $country=='ES'?'selected':''; ?>>Spain</option>
                            <option value="IT" <?php echo $country=='IT'?'selected':''; ?>>Italy</option>
                            <option value="BR" <?php echo $country=='BR'?'selected':''; ?>>Brazil</option>
                            <option value="SG" <?php echo $country=='SG'?'selected':''; ?>>Singapore</option>
                            <option value="PH" <?php echo $country=='PH'?'selected':''; ?>>Philippines</option>
                            <option value="TR" <?php echo $country=='TR'?'selected':''; ?>>Turkey</option>
                            <option value="MY" <?php echo $country=='MY'?'selected':''; ?>>Malaysia</option>
                            <option value="ID" <?php echo $country=='ID'?'selected':''; ?>>Indonesia</option>
                            <option value="TH" <?php echo $country=='TH'?'selected':''; ?>>Thailand</option>
                            <option value="VN" <?php echo $country=='VN'?'selected':''; ?>>Vietnam</option>
                            <option value="JP" <?php echo $country=='JP'?'selected':''; ?>>Japan</option>
                            <option value="KR" <?php echo $country=='KR'?'selected':''; ?>>South Korea</option>
                            <option value="CH" <?php echo $country=='CH'?'selected':''; ?>>Switzerland</option>
                            <option value="NO" <?php echo $country=='NO'?'selected':''; ?>>Norway</option>
                            <option value="SE" <?php echo $country=='SE'?'selected':''; ?>>Sweden</option>
                            <option value="DK" <?php echo $country=='DK'?'selected':''; ?>>Denmark</option>
                            <option value="BE" <?php echo $country=='BE'?'selected':''; ?>>Belgium</option>
                            <option value="AT" <?php echo $country=='AT'?'selected':''; ?>>Austria</option>
                            <option value="PT" <?php echo $country=='PT'?'selected':''; ?>>Portugal</option>
                            <option value="GR" <?php echo $country=='GR'?'selected':''; ?>>Greece</option>
                            <option value="PL" <?php echo $country=='PL'?'selected':''; ?>>Poland</option>
                            <option value="CZ" <?php echo $country=='CZ'?'selected':''; ?>>Czech Republic</option>
                            <option value="HU" <?php echo $country=='HU'?'selected':''; ?>>Hungary</option>
                            <option value="RO" <?php echo $country=='RO'?'selected':''; ?>>Romania</option>
                            <option value="IL" <?php echo $country=='IL'?'selected':''; ?>>Israel</option>
                            <option value="SA" <?php echo $country=='SA'?'selected':''; ?>>Saudi Arabia</option>
                            <option value="QA" <?php echo $country=='QA'?'selected':''; ?>>Qatar</option>
                            <option value="KW" <?php echo $country=='KW'?'selected':''; ?>>Kuwait</option>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label small fw-bold">Search Product</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="Amazon, iTunes, Steam...">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selectAll">
                    <label class="form-check-label fw-bold" for="selectAll">Select All</label>
                </div>
                <button id="btnBulkInstall" class="btn btn-success rounded-pill px-4 shadow-sm" style="display:none;">
                    <i class="bi bi-download me-2"></i>Install Selected (<span id="selectedCount">0</span>)
                </button>
            </div>
        </div>

        <div class="row g-3">
            <?php if(!empty($products['content'])): foreach($products['content'] as $p):
                $is_installed = in_array($p['productId'], $installed_ids);
                $pid = (int)$p['productId'];
            ?>
            <div class="col-lg-4 col-md-6">
                <div class="card product-card h-100 rounded-4 overflow-hidden position-relative">
                    <?php if(!$is_installed): ?>
                    <div class="position-absolute top-0 end-0 p-3" style="z-index:10;">
                        <input class="form-check-input product-check" type="checkbox" data-product='<?php echo json_encode($p, JSON_HEX_APOS); ?>'>
                    </div>
                    <?php endif; ?>
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <img src="../web/func/giftcard-image.php?id=<?php echo $pid; ?>" class="logo-placeholder me-3 rounded" onerror="this.src='<?php echo $p['logoUrls'][0] ?? ''; ?>'">
                            <div>
                                <h6 class="fw-bold mb-0"><?php echo $p['productName']; ?></h6>
                                <small class="text-muted"><?php echo $p['recipientCurrencyCode']; ?> <?php echo $p['minRecipientDenomination']; ?> - <?php echo $p['maxRecipientDenomination']; ?></small>
                            </div>
                        </div>

                        <?php if($is_installed): ?>
                            <button class="btn btn-success w-100 rounded-pill disabled"><i class="bi bi-check-circle-fill me-2"></i>Installed</button>
                        <?php else: ?>
                            <button class="btn btn-outline-primary w-100 rounded-pill btn-install" data-product='<?php echo json_encode($p, JSON_HEX_APOS); ?>'>Install Product</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="col-12 text-center py-5">
                <i class="bi bi-search fs-1 text-muted opacity-25"></i>
                <p class="mt-3">No products found. Make sure your Reloadly API keys are configured.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>

    <script>
    document.querySelectorAll('.btn-install').forEach(btn => {
        btn.onclick = function() {
            const data = this.getAttribute('data-product');
            this.disabled = true;
            this.innerText = 'Installing...';

            const fd = new FormData();
            fd.append('action', 'install');
            fd.append('product_data', data);

            fetch('GiftCardSetup.php', {
                method: 'POST',
                body: fd
            }).then(r => r.json()).then(res => {
                if(res.status === 'success') {
                    location.reload();
                } else {
                    alert('Error: ' + res.message);
                    this.disabled = false;
                    this.innerText = 'Install Product';
                }
            });
        };
    });

    const selectAll = document.getElementById('selectAll');
    const bulkBtn = document.getElementById('btnBulkInstall');
    const selectedCount = document.getElementById('selectedCount');
    const checkboxes = document.querySelectorAll('.product-check');

    selectAll.onchange = function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateBulkUI();
    };

    checkboxes.forEach(cb => {
        cb.onchange = updateBulkUI;
    });

    function updateBulkUI() {
        const checked = document.querySelectorAll('.product-check:checked');
        selectedCount.innerText = checked.length;
        bulkBtn.style.display = checked.length > 0 ? 'block' : 'none';
    }

    bulkBtn.onclick = async function() {
        const checked = document.querySelectorAll('.product-check:checked');
        if(!confirm(`Install ${checked.length} selected products?`)) return;

        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Installing...';

        const products = [];
        checked.forEach(cb => {
            products.push(JSON.parse(cb.getAttribute('data-product')));
        });

        const fd = new FormData();
        fd.append('action', 'bulk_install');
        fd.append('products_json', JSON.stringify(products));

        try {
            const res = await fetch('GiftCardSetup.php', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                location.reload();
            } else {
                alert('Installation completed with errors: ' + res.errors.join('\n'));
                location.reload();
            }
        } catch (e) {
            console.error(e);
            alert('A network error occurred.');
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-download me-2"></i>Install Selected';
        }
    };
    </script>
</body>
</html>
