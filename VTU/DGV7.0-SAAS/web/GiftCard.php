<?php
ob_start();
session_start();
include("../func/bc-config.php");
include("../func/bc-giftcard-func.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}

$vid = $get_logged_user_details['vendor_id'];
$username = $get_logged_user_details['username'];
$user_id = $get_logged_user_details['id'];

if(!isServiceEnabled('gift_card')){
    header("Location: Dashboard.php");
    exit();
}

// Handle AJAX Actions
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['status' => 'error', 'message' => 'Unknown action'];

    if ($action == 'purchase_card') {
        $product_id = (int)$_POST['product_id'];
        $amount = (float)$_POST['amount'];
        $pin = $_POST['pin'] ?? '';
        $otp = $_POST['otp'] ?? '';

        if (!verifyUserPIN($pin, $get_logged_user_details)) {
            $response = ['status' => 'error', 'message' => 'Invalid Transaction PIN'];
        } elseif (empty($_SESSION["giftcard_otp"]) || $otp !== $_SESSION["giftcard_otp"]) {
            $response = ['status' => 'error', 'message' => 'Invalid Email OTP'];
        } elseif (time() - $_SESSION["giftcard_otp_time"] > 600) {
            $response = ['status' => 'error', 'message' => 'OTP has expired'];
        } else {
            $q_p = mysqli_query($connection_server, "SELECT * FROM `sas_vendor_giftcard_products` WHERE vendor_id='$vid' AND reloadly_product_id='$product_id' AND status=1 LIMIT 1");
            $product = mysqli_fetch_assoc($q_p);

            if (!$product) {
                $response = ['status' => 'error', 'message' => 'Product not found or inactive.'];
            } else {
                $rate = getLiveExchangeRate($product['currency_code'] ?: 'USD', 'NGN', $vid, 'gift-card');
                $qv = mysqli_query($connection_server, "SELECT giftcard_fee_percent FROM sas_vendors WHERE id='$vid' LIMIT 1");
                $vendor_fee_percent = (float)(mysqli_fetch_assoc($qv)['giftcard_fee_percent'] ?? 0);

                $cost_ngn = $amount * $rate;
                $total_markup_percent = (float)$product['vendor_markup'] + $vendor_fee_percent;
                $final_price_ngn = $cost_ngn + ($cost_ngn * ($total_markup_percent / 100));

                if ($get_logged_user_details['balance'] < $final_price_ngn) {
                    $response = ['status' => 'error', 'message' => 'Insufficient wallet balance.'];
                } else {
                    $token = getReloadlyAccessToken($vid);
                    $order = placeReloadlyOrder($token, $product_id, $amount, 1, $get_logged_user_details['email']);

                    if ($order && isset($order['status']) && $order['status'] == 'SUCCESSFUL') {
                        $tx_id = mysqli_real_escape_string($connection_server, $order['transactionId']);
                        $p_name = mysqli_real_escape_string($connection_server, $product['product_name']);
                        $reference = "GC_" . time() . "_" . rand(100, 999);

                        chargeUser("debit", "giftcard_".$product_id, "Gift Card Purchase", $reference, $tx_id, $final_price_ngn, $final_price_ngn, "Gift Card Purchase: " . $p_name, "WEB", $_SERVER["HTTP_HOST"], 1);

                        $raw_code = $order['product']['code'] ?? 'CODE-' . rand(1000, 9999);
                        $card_pin = mysqli_real_escape_string($connection_server, $order['product']['pin'] ?? '');
                        $card_code = mysqli_real_escape_string($connection_server, encryptGiftCode($raw_code, $vid));

                        mysqli_query($connection_server, "INSERT INTO `sas_giftcard_inventory`
                            (vendor_id, reloadly_tx_id, reloadly_product_id, product_name, card_code, card_pin, face_value, currency_code, current_owner_id, source)
                            VALUES ('$vid', '$tx_id', '$product_id', '$p_name', '$card_code', '$card_pin', '$amount', '".$product['currency_code']."', '$user_id', 'api')");

                        unset($_SESSION["giftcard_otp"]);
                        $response = ['status' => 'success', 'message' => 'Purchase successful! Check "My Cards" for your code.'];
                    } else {
                        $response = ['status' => 'error', 'message' => $order['message'] ?? 'API Error. Please try again later.'];
                    }
                }
            }
        }
    } elseif ($action == 'send_otp') {
        $otp = generateOTP();
        $_SESSION["giftcard_otp"] = $otp;
        $_SESSION["giftcard_otp_time"] = time();
        $subject = "Gift Card Purchase OTP";
        $body = "Your verification code for gift card purchase is: <b>$otp</b>. Expires in 10 minutes.";
        sendVendorEmail($get_logged_user_details["email"], $subject, $body);
        $response = ['status' => 'success', 'message' => 'OTP sent to your email'];
    } elseif ($action == 'list_for_sale') {
        $card_id = (int)$_POST['card_id'];
        $price = (float)$_POST['price'];
        mysqli_query($connection_server, "UPDATE `sas_giftcard_inventory` SET is_for_sale=1, sale_price_ngn='$price' WHERE id='$card_id' AND current_owner_id='$user_id' AND vendor_id='$vid'");
        $response = ['status' => 'success', 'message' => 'Card listed on marketplace.'];
    } elseif ($action == 'cancel_listing') {
        $card_id = (int)$_POST['card_id'];
        mysqli_query($connection_server, "UPDATE `sas_giftcard_inventory` SET is_for_sale=0 WHERE id='$card_id' AND current_owner_id='$user_id' AND vendor_id='$vid'");
        $response = ['status' => 'success', 'message' => 'Card removed from marketplace.'];
    } elseif ($action == 'transfer_card') {
        $card_id = (int)$_POST['card_id'];
        $recipient_user = mysqli_real_escape_string($connection_server, $_POST['recipient']);
        $pin = $_POST['pin'] ?? '';

        if (!verifyUserPIN($pin, $get_logged_user_details)) {
            $response = ['status' => 'error', 'message' => 'Invalid Transaction PIN'];
        } else {
            $q_r = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE (username='$recipient_user' OR email='$recipient_user') AND vendor_id='$vid' LIMIT 1");
            $recipient = mysqli_fetch_assoc($q_r);

            if (!$recipient) {
                $response = ['status' => 'error', 'message' => 'Recipient user not found.'];
            } elseif ($recipient['id'] == $user_id) {
                $response = ['status' => 'error', 'message' => 'Cannot transfer to yourself.'];
            } else {
                mysqli_query($connection_server, "UPDATE `sas_giftcard_inventory` SET current_owner_id='".$recipient['id']."', is_for_sale=0 WHERE id='$card_id' AND current_owner_id='$user_id' AND vendor_id='$vid'");
                $response = ['status' => 'success', 'message' => 'Card transferred successfully!'];
            }
        }
    } elseif ($action == 'buy_p2p') {
        $card_id = (int)$_POST['card_id'];
        $pin = $_POST['pin'] ?? '';

        if (!verifyUserPIN($pin, $get_logged_user_details)) {
            $response = ['status' => 'error', 'message' => 'Invalid Transaction PIN'];
        } else {
            $q_c = mysqli_query($connection_server, "SELECT * FROM `sas_giftcard_inventory` WHERE id='$card_id' AND is_for_sale=1 AND vendor_id='$vid' LIMIT 1");
            $card = mysqli_fetch_assoc($q_c);

            if (!$card || $card['current_owner_id'] == $user_id) {
                $response = ['status' => 'error', 'message' => 'Invalid trade.'];
            } else {
                $qv = mysqli_query($connection_server, "SELECT giftcard_fee_percent FROM sas_vendors WHERE id='$vid' LIMIT 1");
                $vendor_fee_percent = (float)(mysqli_fetch_assoc($qv)['giftcard_fee_percent'] ?? 0);
                $total_buyer_cost_ngn = (float)$card['sale_price_ngn'] * (1 + ($vendor_fee_percent / 100));

                if (holdP2PFunds($username, $total_buyer_cost_ngn)) {
                    mysqli_query($connection_server, "INSERT INTO `sas_p2p_trades` (vendor_id, seller_id, buyer_id, card_id, amount_ngn, fee_ngn, status)
                        VALUES ('$vid', '".$card['current_owner_id']."', '$user_id', '$card_id', '".$card['sale_price_ngn']."', '".($total_buyer_cost_ngn - $card['sale_price_ngn'])."', 'funded')");
                    if (releaseP2PFunds(mysqli_insert_id($connection_server))) {
                        $response = ['status' => 'success', 'message' => 'P2P Trade completed!'];
                    } else {
                        $response = ['status' => 'error', 'message' => 'Funds held but release failed.'];
                    }
                } else {
                    $response = ['status' => 'error', 'message' => 'Insufficient balance.'];
                }
            }
        }
    }

    if(ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Fetch Data for UI
$selected_category = $_GET['category'] ?? 'all';
$category_filter = "";
if ($selected_category !== 'all') {
    $cat_name = mysqli_real_escape_string($connection_server, $selected_category);
    if ($cat_name === 'General') {
        $category_filter = " AND (v.category_name = 'General' OR v.category_name IS NULL OR v.category_name = '')";
    } else {
        $category_filter = " AND v.category_name = '$cat_name'";
    }
}

$sql_products = "SELECT v.*, g.min_value, g.max_value, g.denomination_type, g.fixed_values, g.logo_url as global_logo, g.country_code, g.description as global_desc
    FROM `sas_vendor_giftcard_products` v
    LEFT JOIN `sas_global_giftcard_products` g ON TRIM(v.reloadly_product_id) = TRIM(g.reloadly_product_id)
    WHERE v.vendor_id='$vid' AND v.status=1 $category_filter
    ORDER BY v.product_name ASC";
$installed_products = mysqli_query($connection_server, $sql_products);

$my_cards = mysqli_query($connection_server, "SELECT * FROM `sas_giftcard_inventory` WHERE vendor_id='$vid' AND current_owner_id='$user_id' ORDER BY id DESC");
$p2p_listings = mysqli_query($connection_server, "SELECT i.*, u.username as seller_name FROM `sas_giftcard_inventory` i JOIN sas_users u ON i.current_owner_id = u.id WHERE i.vendor_id='$vid' AND i.is_for_sale=1 AND i.current_owner_id != '$user_id'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Gift Card Hub | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        :root { --primary: <?php echo $vendor_primary_color; ?>; --primary-soft: <?php echo $vendor_primary_color; ?>15; }
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; }

        .balance-hero {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 2.5rem; color: white; padding: 4rem 3rem; position: relative; overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05);
        }
        .balance-hero::before {
            content: ''; position: absolute; top: -50%; right: -20%; width: 500px; height: 500px;
            background: radial-gradient(circle, var(--primary) 0%, transparent 70%); opacity: 0.15; filter: blur(60px);
        }
        .balance-hero::after {
            content: ''; position: absolute; bottom: -20%; left: -10%; width: 300px; height: 300px;
            background: radial-gradient(circle, #6366f1 0%, transparent 70%); opacity: 0.1; filter: blur(50px);
        }
        .glass-pill {
            background: rgba(255,255,255,0.05); backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 2rem; padding: 0.5rem 1.2rem;
        }

        .nav-pills-modern { background: #fff; padding: 0.5rem; border-radius: 1.25rem; display: inline-flex; gap: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .nav-pills-modern .nav-link {
            border-radius: 1rem; padding: 0.6rem 1.5rem; font-weight: 600; color: #64748b; transition: 0.3s;
        }
        .nav-pills-modern .nav-link.active { background: var(--primary); color: #fff; box-shadow: 0 4px 12px var(--primary-soft); }

        .card-modern { border: none; border-radius: 1.5rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: #fff; }
        .card-modern:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }

        .search-wrapper { position: relative; }
        .search-wrapper i { position: absolute; left: 1.5rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.2rem; }
        .search-input-modern {
            padding: 1.2rem 1.2rem 1.2rem 3.5rem !important; border-radius: 1.5rem; border: 2px solid #e2e8f0;
            font-weight: 500; transition: 0.3s;
        }
        .search-input-modern:focus { border-color: var(--primary); box-shadow: 0 0 0 5px var(--primary-soft); }

        .category-chip {
            padding: 0.5rem 1.2rem; border-radius: 2rem; background: #fff; color: #64748b;
            text-decoration: none; font-weight: 500; border: 1px solid #e2e8f0; transition: 0.2s; white-space: nowrap;
        }
        .category-chip.active { background: var(--primary); color: #fff; border-color: var(--primary); }

        .btn-modern { padding: 0.8rem 1.5rem; border-radius: 1.25rem; font-weight: 700; transition: 0.3s; }
        .badge-modern { padding: 0.5rem 1rem; border-radius: 0.75rem; font-weight: 600; }

        .asset-card { border-left: 5px solid var(--primary); }
        .code-box { background: #f8fafc; border: 1px dashed #cbd5e1; padding: 1rem; border-radius: 1rem; font-family: monospace; position: relative; }

        @media (max-width: 768px) { .balance-hero { padding: 2rem 1.5rem; } }
    </style>
</head>
<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="container-fluid py-4">
        <div class="balance-hero mb-5 shadow-lg">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <div class="d-inline-flex align-items-center glass-pill mb-3">
                        <span class="status-dot on me-2"></span>
                        <span class="text-uppercase x-small fw-bold tracking-widest opacity-75">Secure Fintech Wallet</span>
                    </div>
                    <h1 class="display-3 fw-bold mb-2">₦<?php echo number_format($get_logged_user_details['balance'], 2); ?></h1>
                    <p class="text-white opacity-50 small mb-4"><i class="bi bi-shield-check me-1"></i> Assets protected by multi-layer encryption</p>
                    <div class="d-flex gap-3">
                        <button class="btn btn-primary btn-modern px-5 shadow-lg border-0" style="background: var(--primary) !important;" onclick="document.getElementById('buy-tab-btn').click()">Purchase Cards</button>
                        <button class="btn btn-outline-light btn-modern px-4 border-opacity-25" onclick="document.getElementById('my-cards-tab-btn').click()">View Assets</button>
                    </div>
                </div>
                <div class="col-md-5 d-none d-md-flex justify-content-end">
                    <div class="text-end">
                        <div class="d-flex gap-2 mb-3 justify-content-end">
                            <div class="glass-pill"><i class="bi bi-cpu me-1"></i> AI Engine</div>
                            <div class="glass-pill"><i class="bi bi-lightning-charge me-1"></i> Instant</div>
                        </div>
                        <h4 class="fw-bold mb-0 opacity-75">Global GiftCard<br>Ecosystem</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mb-5">
            <div class="nav nav-pills nav-pills-modern shadow-sm" id="gcTab" role="tablist">
                <button class="nav-link active" id="buy-tab-btn" data-bs-toggle="tab" data-bs-target="#buy-pane">Global Store</button>
                <button class="nav-link" id="my-cards-tab-btn" data-bs-toggle="tab" data-bs-target="#my-cards-pane">My Assets</button>
                <button class="nav-link" id="p2p-tab-btn" data-bs-toggle="tab" data-bs-target="#p2p-pane">P2P Market</button>
            </div>
        </div>

        <div class="tab-content">
            <!-- Global Store -->
            <div class="tab-pane fade show active" id="buy-pane">
                <div class="row mb-4 align-items-center">
                    <div class="col-lg-6 mb-3 mb-lg-0">
                        <div class="search-wrapper">
                            <i class="bi bi-search"></i>
                            <input type="text" id="gcSearch" class="form-control form-control-lg search-input-modern" placeholder="Search card, country, or currency...">
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="d-flex gap-2 overflow-auto pb-2 px-1 no-scrollbar">
                            <a href="GiftCard.php?category=all" class="category-chip <?php echo ($selected_category == 'all') ? 'active' : ''; ?>">All Brands</a>
                            <?php
                            $q_cats = mysqli_query($connection_server, "SELECT DISTINCT category_name FROM `sas_vendor_giftcard_products` WHERE vendor_id='$vid' AND status=1");
                            while($cat = mysqli_fetch_assoc($q_cats)):
                                $c_name = trim($cat['category_name'] ?: 'General');
                            ?>
                                <a href="GiftCard.php?category=<?php echo urlencode($c_name); ?>" class="category-chip <?php echo ($selected_category == $c_name) ? 'active' : ''; ?>"><?php echo $c_name; ?></a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-4" id="productGrid">
                    <?php while($p = mysqli_fetch_assoc($installed_products)):
                        $c_name = getCountryNameByCode($p['country_code'] ?? '');
                    ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 gc-item"
                         data-name="<?php echo htmlspecialchars(strtolower($p['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         data-country="<?php echo htmlspecialchars(strtolower($c_name), ENT_QUOTES, 'UTF-8'); ?>"
                         data-currency="<?php echo htmlspecialchars(strtolower($p['currency_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="card card-modern h-100 shadow-sm border-0 p-3" onclick='openPurchaseModal(<?php echo htmlspecialchars(json_encode($p, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES, "UTF-8"); ?>)'>
                            <div class="text-center mb-3">
                                <img src="func/giftcard-image.php?id=<?php echo $p['reloadly_product_id']; ?>"
                                     class="img-fluid rounded-4" style="height: 140px; object-fit: contain;"
                                     onerror="this.src='<?php echo htmlspecialchars($p['logo_url'] ?: ($p['global_logo'] ?: '../asset/dash_unknown.jpg'), ENT_QUOTES, 'UTF-8'); ?>'">
                            </div>
                            <div class="card-body p-0">
                                <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($p['product_name']); ?></h6>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="badge bg-light text-dark border"><?php echo $p['currency_code'] ?: 'USD'; ?></span>
                                    <small class="text-muted fw-bold">Fee: <?php echo (float)$p['vendor_markup']; ?>%</small>
                                </div>
                                <button class="btn btn-primary btn-modern w-100 rounded-pill" data-no-lock>Buy Now</button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <div id="noResults" class="col-12 text-center py-5" style="display:none;">
                        <i class="bi bi-search display-1 text-muted opacity-25"></i>
                        <p class="mt-3 text-muted lead">No matching gift cards found.</p>
                    </div>
                </div>
            </div>

            <!-- My Assets -->
            <div class="tab-pane fade" id="my-cards-pane">
                <div class="row g-4">
                    <?php if(mysqli_num_rows($my_cards) > 0): while($c = mysqli_fetch_assoc($my_cards)): ?>
                    <div class="col-xl-4 col-md-6">
                        <div class="card card-modern asset-card shadow-sm border-0 h-100">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between mb-4">
                                    <div class="d-flex align-items-center">
                                        <img src="func/giftcard-image.php?id=<?php echo $c['reloadly_product_id']; ?>" class="rounded-3 me-3" width="50" height="50" style="object-fit: contain;">
                                        <div>
                                            <h6 class="fw-bold mb-0"><?php echo $c['product_name']; ?></h6>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($c['created_at'])); ?></small>
                                        </div>
                                    </div>
                                    <span class="badge bg-success-subtle text-success badge-modern h-50">Active</span>
                                </div>

                                <div class="code-box mb-4">
                                    <?php $code = decryptGiftCode($c['card_code'], $vid); ?>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fs-5 fw-bold text-dark tracking-widest"><?php echo $code; ?></span>
                                        <button class="btn btn-link p-0" onclick="copyText('Code copied!', '<?php echo $code; ?>')"><i class="bi bi-clipboard text-primary fs-5"></i></button>
                                    </div>
                                    <?php if($c['card_pin']): ?><small class="text-muted d-block mt-1">PIN: <?php echo $c['card_pin']; ?></small><?php endif; ?>
                                </div>

                                <div class="row g-2">
                                    <?php if($c['is_for_sale']): ?>
                                        <div class="col-6"><button class="btn btn-warning btn-modern w-100 py-2" onclick="cancelListing(<?php echo $c['id']; ?>)">Delist</button></div>
                                    <?php else: ?>
                                        <?php
                                        $q_sell_rate = mysqli_query($connection_server, "SELECT credit_amount FROM sas_dollar_exchange_rates WHERE vendor_id='$vid' AND product_type='gift-card' AND currency='ngn' LIMIT 1");
                                        $sell_rate = (float)(mysqli_fetch_assoc($q_sell_rate)['credit_amount'] ?? 1450);
                                        ?>
                                        <div class="col-6"><button class="btn btn-outline-primary btn-modern w-100 py-2" data-no-lock onclick="listCard(<?php echo $c['id']; ?>, <?php echo $c['face_value'] * $sell_rate; ?>)">Sell Asset</button></div>
                                    <?php endif; ?>
                                    <div class="col-6"><button class="btn btn-light btn-modern w-100 py-2" data-no-lock onclick='openTransferModal(<?php echo (int)$c["id"]; ?>, <?php echo htmlspecialchars(json_encode($c["product_name"], JSON_HEX_APOS), ENT_QUOTES); ?>)'>Transfer</button></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="col-12 text-center py-5"><p class="text-muted lead">No assets in your wallet yet.</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- P2P Market -->
            <div class="tab-pane fade" id="p2p-pane">
                <div class="card card-modern shadow-sm border-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr class="text-uppercase small fw-bold text-muted">
                                    <th class="px-4 py-3">Seller</th>
                                    <th class="py-3">Card Item</th>
                                    <th class="py-3 text-center">Value</th>
                                    <th class="py-3 text-end">Market Price</th>
                                    <th class="px-4 py-3 text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(mysqli_num_rows($p2p_listings) > 0): while($l = mysqli_fetch_assoc($p2p_listings)): ?>
                                <tr>
                                    <td class="px-4"><div class="d-flex align-items-center"><div class="avatar-sm me-2 bg-primary-soft text-primary rounded-circle p-2 fw-bold"><?php echo strtoupper($l['seller_name'][0]); ?></div> @<?php echo $l['seller_name']; ?></div></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="func/giftcard-image.php?id=<?php echo $l['reloadly_product_id']; ?>" class="rounded me-2" width="30" height="30" style="object-fit: contain;">
                                            <span class="fw-600"><?php echo $l['product_name']; ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold"><?php echo $l['currency_code'] . ' ' . (float)$l['face_value']; ?></td>
                                    <td class="text-end fw-bold text-success">₦<?php echo number_format($l['sale_price_ngn'], 2); ?></td>
                                    <td class="px-4 text-end">
                                        <button class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" data-no-lock onclick='buyP2P(<?php echo (int)$l["id"]; ?>, <?php echo (float)$l["sale_price_ngn"]; ?>, <?php echo htmlspecialchars(json_encode($l["product_name"], JSON_HEX_APOS), ENT_QUOTES); ?>)'>Buy Now</button>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted lead">No active listings in the market.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals (Purchase, Transfer, PIN) -->
    <div class="modal fade" id="purchaseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content card-modern shadow-lg">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <h5 class="fw-bold" id="modalTitle">Complete Purchase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="purchaseForm">
                        <input type="hidden" id="p_id" name="product_id">
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Select Denomination</label>
                            <select name="amount" class="form-select form-select-lg rounded-4 border-2" required></select>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted text-uppercase">Transaction PIN</label>
                                <input type="password" name="pin" class="form-control form-control-lg rounded-4 border-2 text-center" maxlength="4" placeholder="****" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted text-uppercase">Email OTP</label>
                                <div class="input-group">
                                    <input type="text" name="otp" class="form-control form-control-lg rounded-start-4 border-2 text-center" placeholder="6-digit" required>
                                    <button type="button" class="btn btn-outline-primary rounded-end-4 border-2" onclick="sendGCOTP(this)">Get</button>
                                </div>
                            </div>
                        </div>
                        <div id="calcDisplay"></div>
                        <button type="submit" class="btn btn-primary w-100 btn-modern py-3 shadow-sm mt-4" data-no-lock>Process Purchase</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content card-modern">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <h5 class="fw-bold">Transfer Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="transferForm">
                        <input type="hidden" id="t_card_id" name="card_id">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Recipient (Username or Email)</label>
                            <input type="text" name="recipient" class="form-control form-control-lg rounded-4" placeholder="e.g. user123" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted">Transaction PIN</label>
                            <input type="password" name="pin" class="form-control form-control-lg rounded-4" maxlength="4" placeholder="****" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 btn-modern py-3" data-no-lock>Secure Transfer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include("../func/bc-footer.php"); ?>

    <script>
    const VENDOR_FEE = <?php echo (float)($vendor_fee_percent ?? 0); ?>;
    let currentRate = 1.0;
    let currentCurrency = 'USD';

    // Real-time Search Logic
    (function() {
        const initSearch = () => {
            const searchInput = document.getElementById('gcSearch');
            const noResults = document.getElementById('noResults');
            if(!searchInput) return;

            const filterItems = () => {
                const query = searchInput.value.toLowerCase().trim();
                const items = document.querySelectorAll('#productGrid .gc-item');
                let visibleCount = 0;

                items.forEach(item => {
                    const name = (item.getAttribute('data-name') || '').toLowerCase();
                    const country = (item.getAttribute('data-country') || '').toLowerCase();
                    const currency = (item.getAttribute('data-currency') || '').toLowerCase();

                    if(query === "" || name.indexOf(query) !== -1 || country.indexOf(query) !== -1 || currency.indexOf(query) !== -1) {
                        item.style.setProperty('display', 'block', 'important');
                        visibleCount++;
                    } else {
                        item.style.setProperty('display', 'none', 'important');
                    }
                });

                if(noResults) {
                    noResults.style.setProperty('display', (visibleCount === 0 ? 'block' : 'none'), 'important');
                }
            };

            searchInput.addEventListener('input', filterItems);
            // Also support "keyup" for some browsers
            searchInput.addEventListener('keyup', filterItems);

            if(searchInput.value) filterItems();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSearch);
        } else {
            initSearch();
        }
    })();

    async function openPurchaseModal(p) {
        document.getElementById('p_id').value = p.reloadly_product_id;
        document.getElementById('modalTitle').innerText = 'Buy ' + p.product_name;
        const select = document.querySelector('#purchaseForm select[name="amount"]');
        const calcArea = document.getElementById('calcDisplay');
        
        select.innerHTML = '<option value="">Loading amounts...</option>';
        calcArea.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Calculating...</div>';
        new bootstrap.Modal(document.getElementById('purchaseModal')).show();

        const currency = p.currency_code || 'USD';
        try {
            const res = await fetch(`func/get-rate-ajax.php?from=${currency}&type=gift-card`).then(r => r.json());
            currentRate = res.rate || 1.0;
            currentCurrency = currency;

            let amounts = [];
            try {
                const fixed = (typeof p.fixed_values === 'string') ? JSON.parse(p.fixed_values) : p.fixed_values;
                const minV = parseFloat(p.min_value || 0);
                const maxV = parseFloat(p.max_value || 999999);
                amounts = (p.denomination_type === 'FIXED' && Array.isArray(fixed)) ? fixed : [10, 25, 50, 100, 200, 500].filter(v => v >= minV && v <= maxV);
            } catch(e) { amounts = [10, 25, 50, 100]; }

            select.innerHTML = '';
            amounts.forEach(v => {
                const opt = new Option(`${v} ${currency}`, v);
                opt.dataset.markup = p.vendor_markup || 0;
                select.add(opt);
            });
            select.onchange = () => updateCalculation(select);
            updateCalculation(select);
        } catch(e) { calcArea.innerHTML = '<div class="alert alert-danger py-1 small">Connection failed</div>'; }
    }

    function updateCalculation(select) {
        if (!select || !select.value || select.selectedIndex === -1) return;
        const amount = parseFloat(select.value);
        const markup = parseFloat(select.options[select.selectedIndex].dataset.markup || 0) + VENDOR_FEE;
        const totalNaira = (amount * currentRate) * (1 + (markup / 100));

        document.getElementById('calcDisplay').innerHTML = `
            <div class="bg-primary-soft p-3 rounded-4 mt-3 small">
                <div class="d-flex justify-content-between mb-1"><span>Rate:</span><span class="fw-bold">₦${currentRate.toLocaleString()}/${currentCurrency}1</span></div>
                <div class="d-flex justify-content-between mb-1"><span>Platform Fee:</span><span class="fw-bold text-primary">${markup.toFixed(1)}%</span></div>
                <hr class="my-2 opacity-10">
                <div class="d-flex justify-content-between"><span class="fw-bold">Total Cost:</span><h4 class="fw-bold text-success mb-0">₦${totalNaira.toLocaleString(undefined, {minimumFractionDigits: 2})}</h4></div>
            </div>
        `;
    }

    function sendGCOTP(btn) {
        btn.disabled = true; btn.innerText = '...';
        fetch('GiftCard.php', { method: 'POST', body: 'action=send_otp', headers: {'Content-Type': 'application/x-www-form-urlencoded'} })
        .then(r => r.json()).then(res => {
            alert(res.message);
            if(res.status === 'success') {
                let c = 60; const t = setInterval(() => { btn.innerText = c+'s'; c--; if(c<0){clearInterval(t); btn.innerText='Get'; btn.disabled=false;} }, 1000);
            } else btn.disabled = false;
        });
    }

    function listCard(id, defPrice) {
        const price = prompt("Enter Selling Price (NGN):", defPrice);
        if(price) {
            const fd = new FormData(); fd.append('action', 'list_for_sale'); fd.append('card_id', id); fd.append('price', price);
            fetch('GiftCard.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { alert(res.message); if(res.status=='success') location.reload(); });
        }
    }

    function cancelListing(id) {
        if(!confirm('Delist this card?')) return;
        const fd = new FormData(); fd.append('action', 'cancel_listing'); fd.append('card_id', id);
        fetch('GiftCard.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { alert(res.message); if(res.status=='success') location.reload(); });
    }

    function buyP2P(id, price, name) {
        if(!confirm(`Confirm P2P Purchase of ${name} for ₦${price.toLocaleString()}?`)) return;
        const pin = prompt("Enter Transaction PIN:");
        if(pin) {
            const fd = new FormData(); fd.append('action', 'buy_p2p'); fd.append('card_id', id); fd.append('pin', pin);
            fetch('GiftCard.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { alert(res.message); if(res.status=='success') location.reload(); });
        }
    }

    function openTransferModal(id, name) {
        document.getElementById('t_card_id').value = id;
        new bootstrap.Modal(document.getElementById('transferModal')).show();
    }

    document.getElementById('purchaseForm').onsubmit = function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]'); btn.disabled = true; btn.innerText = 'Processing...';
        fetch('GiftCard.php', { method: 'POST', body: new FormData(this) }).then(r => r.json()).then(res => {
            alert(res.message); if(res.status === 'success') location.reload(); else { btn.disabled = false; btn.innerText = 'Process Purchase'; }
        });
    };

    document.getElementById('transferForm').onsubmit = function(e) {
        e.preventDefault();
        const fd = new FormData(this); fd.append('action', 'transfer_card');
        fetch('GiftCard.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            alert(res.message); if(res.status === 'success') location.reload();
        });
    };

    function copyText(msg, val) { navigator.clipboard.writeText(val); alert(msg); }
    </script>
</body>
</html>
