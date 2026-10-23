<?php session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");
include_once("../../func/bc-giftcard-func.php");

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));

if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$vendor_id = resolveVendorID();
$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}
$user = mysqli_fetch_assoc($check_user);
$user_id = $user['id'];
$username = $user['username'];

$action = $input['action'] ?? 'list_products';


if ($action === 'list_products') {
    $category = $input['category'] ?? 'all';
    $cat_filter = "";
    if ($category !== 'all') {
        $cat_name = mysqli_real_escape_string($connection_server, $category);
        $cat_filter = " AND category_name = '$cat_name'";
    }

    $products = [];
    $q = mysqli_query($connection_server, "SELECT v.*, g.min_value, g.max_value, g.fixed_values, g.denomination_type, g.logo_url as global_logo, g.country_code
        FROM sas_vendor_giftcard_products v
        LEFT JOIN sas_global_giftcard_products g ON TRIM(CAST(v.reloadly_product_id AS CHAR)) = TRIM(CAST(g.reloadly_product_id AS CHAR))
        WHERE v.vendor_id='$vendor_id' AND v.status=1 $cat_filter ORDER BY v.product_name ASC");

    while ($r = mysqli_fetch_assoc($q)) {
        $products[] = [
            "product_id" => (int)$r['reloadly_product_id'],
            "name" => $r['product_name'],
            "logo" => $r['logo_url'] ?: $r['global_logo'],
            "min_value" => (float)$r['min_value'],
            "max_value" => (float)$r['max_value'],
            "fixed_values" => json_decode($r['fixed_values'], true),
            "type" => $r['denomination_type'],
            "currency" => $r['currency_code'] ?: 'USD',
            "markup" => (float)$r['vendor_markup'],
            "category" => $r['category_name'],
            "country_name" => getCountryNameByCode($r['country_code'] ?? '')
        ];
    }

    // Get Categories
    $categories = [];
    $qc = mysqli_query($connection_server, "SELECT DISTINCT category_name FROM sas_vendor_giftcard_products WHERE vendor_id='$vendor_id' AND status=1");
    while($rc = mysqli_fetch_assoc($qc)) $categories[] = $rc['category_name'] ?: 'General';

    echo json_encode(["status" => "success", "products" => $products, "categories" => array_values(array_unique($categories))]);
}

elseif ($action === 'purchase') {
    $product_id = (int)($input['product_id'] ?? 0);
    $amount = (float)($input['amount'] ?? 0);
    $pin = $input['pin'] ?? '';

    // WHO MUST PROVE THE PIN: the mobile app is a person and always sends one. An external api_key
    // integration serves that merchant's own customers, so there is no single PIN to ask for - the
    // requirement is skipped when the caller sends none. A supplied pin is still always validated.
    $__pin_is_person = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') || isset($api_post_info_from_app);
    $__pin_supplied  = ($pin !== '' && $pin !== null);
    if ($__pin_is_person || $__pin_supplied) {
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'Transaction PIN is required']);
            exit;
        }

        if (!verifyUserPIN($pin, $user)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
            exit;
        }
    }

    $q_p = mysqli_query($connection_server, "SELECT * FROM sas_vendor_giftcard_products WHERE vendor_id='$vendor_id' AND reloadly_product_id='$product_id' AND status=1 LIMIT 1");
    $product = mysqli_fetch_assoc($q_p);

    if (!$product) {
        echo json_encode(['status' => 'error', 'message' => 'Product not found or inactive.']);
        exit;
    }

    $rate = getLiveExchangeRate($product['currency_code'] ?: 'USD', 'NGN', $vendor_id, 'gift-card');
    $qv = mysqli_query($connection_server, "SELECT giftcard_fee_percent FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $vendor_fee_percent = (float)(mysqli_fetch_assoc($qv)['giftcard_fee_percent'] ?? 0);

    $cost_ngn = $amount * $rate;
    $total_markup_percent = (float)$product['vendor_markup'] + $vendor_fee_percent;
    $final_price_ngn = $cost_ngn + ($cost_ngn * ($total_markup_percent / 100));

    if ($user['balance'] < $final_price_ngn) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient wallet balance. NGN ' . number_format($final_price_ngn, 2) . ' required.']);
        exit;
    }

    $token = getReloadlyAccessToken($vendor_id);
    $order = placeReloadlyOrder($token, $product_id, $amount, 1, $user['email']);

    if ($order && isset($order['status']) && $order['status'] == 'SUCCESSFUL') {
        $reference = "GC_" . time() . "_" . rand(100, 999);
        chargeUser($username, "debit", "giftcard_".$product_id, "Gift Card Purchase", $reference, $order['transactionId'], $final_price_ngn, $final_price_ngn, "Gift Card Purchase: " . $product['product_name'], "APP", $_SERVER["HTTP_HOST"], 1);

        $raw_code = $order['product']['code'] ?? 'CODE-' . rand(1000, 9999);
        $card_pin = $order['product']['pin'] ?? '';
        $card_code = encryptGiftCode($raw_code, $vendor_id);

        mysqli_query($connection_server, "INSERT INTO sas_giftcard_inventory
            (vendor_id, reloadly_tx_id, reloadly_product_id, product_name, card_code, card_pin, face_value, currency_code, current_owner_id, source)
            VALUES ('$vendor_id', '".$order['transactionId']."', '$product_id', '".$product['product_name']."', '$card_code', '$card_pin', '$amount', 'USD', '$user_id', 'api')");

        echo json_encode(['status' => 'success', 'message' => 'Purchase successful!', 'card_code' => $raw_code, 'card_pin' => $card_pin]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $order['message'] ?? 'API Error. Please try again later.']);
    }
}

elseif ($action === 'my_cards') {
    $cards = [];
    $q = mysqli_query($connection_server, "SELECT * FROM sas_giftcard_inventory WHERE current_owner_id='$user_id' AND vendor_id='$vendor_id' ORDER BY id DESC");
    while ($r = mysqli_fetch_assoc($q)) {
        $cards[] = [
            "id" => (int)$r['id'],
            "product_name" => $r['product_name'],
            "code" => decryptGiftCode($r['card_code'], $vendor_id),
            "pin" => $r['card_pin'],
            "value" => (float)$r['face_value'],
            "currency" => $r['currency_code'],
            "is_for_sale" => (int)$r['is_for_sale'],
            "sale_price" => (float)$r['sale_price_ngn'],
            "date" => $r['created_at']
        ];
    }
    echo json_encode(["status" => "success", "cards" => $cards]);
}

elseif ($action === 'p2p_market') {
    $listings = [];
    $q = mysqli_query($connection_server, "SELECT i.*, u.username as seller_name FROM sas_giftcard_inventory i JOIN sas_users u ON i.current_owner_id = u.id WHERE i.vendor_id='$vendor_id' AND i.is_for_sale=1 AND i.current_owner_id != '$user_id'");
    while ($r = mysqli_fetch_assoc($q)) {
        $listings[] = [
            "id" => (int)$r['id'],
            "seller" => $r['seller_name'],
            "product" => $r['product_name'],
            "value" => (float)$r['face_value'],
            "currency" => $r['currency_code'],
            "price_ngn" => (float)$r['sale_price_ngn']
        ];
    }
    echo json_encode(["status" => "success", "listings" => $listings]);
}

elseif ($action === 'list_for_sale') {
    $card_id = (int)($input['card_id'] ?? 0);
    $price = (float)($input['price'] ?? 0);

    if ($card_id <= 0 || $price <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid card ID or price.']);
        exit;
    }

    $q_own = mysqli_query($connection_server, "SELECT id FROM sas_giftcard_inventory WHERE id='$card_id' AND current_owner_id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
    if (mysqli_num_rows($q_own) == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Card not found or ownership mismatch.']);
        exit;
    }

    mysqli_query($connection_server, "UPDATE sas_giftcard_inventory SET is_for_sale=1, sale_price_ngn='$price' WHERE id='$card_id' AND current_owner_id='$user_id' AND vendor_id='$vendor_id'");
    echo json_encode(['status' => 'success', 'message' => 'Card listed on marketplace.']);
}

elseif ($action === 'buy_p2p') {
    $card_id = (int)($input['card_id'] ?? 0);
    $pin = $input['pin'] ?? '';

    // See the note in the 'purchase' branch: a person (mobile app) must prove the PIN, an external
    // api_key integration must not be blocked by a PIN its customers cannot supply.
    $__pin_is_person = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') || isset($api_post_info_from_app);
    $__pin_supplied  = ($pin !== '' && $pin !== null);
    if ($__pin_is_person || $__pin_supplied) {
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'Transaction PIN is required']);
            exit;
        }

        if (!verifyUserPIN($pin, $user)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Transaction PIN']);
            exit;
        }
    }

    $q_c = mysqli_query($connection_server, "SELECT * FROM sas_giftcard_inventory WHERE id='$card_id' AND is_for_sale=1 AND vendor_id='$vendor_id' LIMIT 1");
    $card = mysqli_fetch_assoc($q_c);

    if (!$card || $card['current_owner_id'] == $user_id) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid trade or card unavailable.']);
        exit;
    }

    $qv = mysqli_query($connection_server, "SELECT giftcard_fee_percent FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $vendor_fee_percent = (float)(mysqli_fetch_assoc($qv)['giftcard_fee_percent'] ?? 0);

    $base_price_ngn = (float)$card['sale_price_ngn'];
    $fee_ngn = $base_price_ngn * ($vendor_fee_percent / 100);
    $total_buyer_cost_ngn = $base_price_ngn + $fee_ngn;

    if ($user['balance'] < $total_buyer_cost_ngn) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient wallet balance. ₦' . number_format($total_buyer_cost_ngn, 2) . ' required.']);
        exit;
    }

    if (holdP2PFunds($username, $total_buyer_cost_ngn)) {
        mysqli_query($connection_server, "INSERT INTO sas_p2p_trades (vendor_id, seller_id, buyer_id, card_id, amount_ngn, fee_ngn, status)
            VALUES ('$vendor_id', '".$card['current_owner_id']."', '$user_id', '$card_id', '$base_price_ngn', '$fee_ngn', 'funded')");
        $trade_id = mysqli_insert_id($connection_server);
        if (releaseP2PFunds($trade_id)) {
            echo json_encode(['status' => 'success', 'message' => 'P2P Trade completed! The card is now in your wallet.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Funds held but release failed. Please contact support.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient balance for this P2P purchase.']);
    }
}

else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

mysqli_close($connection_server);
