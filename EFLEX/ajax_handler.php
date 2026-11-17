<?php
// Set the content type to JSON
header('Content-Type: application/json');

// We need to start the session to access session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/db_connect.php';

// Initialize the cart session if it doesn't exist
if(!isset($_SESSION['cart'])){
    $_SESSION['cart'] = [];
}

$response = ['status' => 'error', 'message' => 'Invalid request.'];

// Determine the action from either JSON payload or POST data
$action = '';
// NOTE: the handlers below read $data, which was only assigned on the JSON branch -
// a form-encoded request therefore hit "Undefined variable $data" and every value
// read as null. Always initialise it.
$data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['action'])) {
        $action = $_POST['action'];
        $data = $_POST;
    } else {
        $decoded = json_decode(file_get_contents('php://input'), true);
        $data = is_array($decoded) ? $decoded : [];
        $action = $data['action'] ?? '';
    }
}


switch ($action) {
    case 'save_onesignal_player_id':
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
            $response['message'] = 'User not logged in.';
            break;
        }

        $player_id = $_POST['player_id'] ?? '';
        $user_id = $_SESSION['id'];

        if (!empty($player_id) && !empty($user_id)) {
            $sql = "UPDATE users SET onesignal_player_id = ? WHERE id = ?";
            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param("si", $player_id, $user_id);
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Player ID saved.'];
                } else {
                    $response['message'] = 'Failed to save Player ID.';
                }
                $stmt->close();
            }
        } else {
            $response['message'] = 'Invalid Player ID or User ID.';
        }
        break;

    case 'toggle_wishlist':
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
            $response['message'] = 'User not logged in.';
            $response['status'] = 'login_required';
            break;
        }

        $product_id = $data['product_id'] ?? 0;
        $user_id = $_SESSION['id'];

        if ($product_id > 0) {
            // Check if item is already in wishlist
            $stmt_check = $mysqli->prepare("SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?");
            $stmt_check->bind_param("ii", $user_id, $product_id);
            $stmt_check->execute();
            $stmt_check->store_result();

            if($stmt_check->num_rows > 0) {
                // Remove from wishlist
                $stmt_remove = $mysqli->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
                $stmt_remove->bind_param("ii", $user_id, $product_id);
                $stmt_remove->execute();
                $response = ['status' => 'success', 'action' => 'removed'];
            } else {
                // Add to wishlist
                $stmt_add = $mysqli->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
                $stmt_add->bind_param("ii", $user_id, $product_id);
                $stmt_add->execute();
                $response = ['status' => 'success', 'action' => 'added'];
            }
        } else {
            $response['message'] = 'Invalid product ID.';
        }
        break;

    case 'remove_from_cart':
        $product_id = $data['product_id'] ?? 0; // This can be a product or variant ID
        $is_variant = $data['is_variant'] ?? false;
        $cart_key = $is_variant ? 'v_' . $product_id : 'p_' . $product_id;

        if ($product_id > 0 && isset($_SESSION['cart'][$cart_key])) {
            unset($_SESSION['cart'][$cart_key]);

            $cart_item_count = 0;
            foreach($_SESSION['cart'] as $key => $qty) {
                $cart_item_count += $qty;
            }

            $response = [
                'status' => 'success',
                'message' => 'Item removed from cart.',
                'cart_count' => $cart_item_count
            ];
        } else {
            $response['message'] = 'Invalid product ID.';
        }
        break;

    case 'get_cart_contents':
        $cart_items = [];
        $total_price = 0;
        $currency_symbol = $_SESSION['currency_symbol'] ?? '$';

        if (!empty($_SESSION['cart'])) {
            $product_ids = [];
            $variant_ids = [];
            foreach($_SESSION['cart'] as $key => $qty) {
                if (strpos($key, 'p_') === 0) {
                    $product_ids[] = (int)substr($key, 2);
                } elseif (strpos($key, 'v_') === 0) {
                    $variant_ids[] = (int)substr($key, 2);
                }
            }

            // Fetch simple products
            if (!empty($product_ids)) {
                $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
                $sql = "SELECT id, name, price, image FROM products WHERE id IN ($placeholders)";
                if($stmt = $mysqli->prepare($sql)){
                    $types = str_repeat('i', count($product_ids));
                    $stmt->bind_param($types, ...$product_ids);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while($row = $result->fetch_assoc()){
                        $cart_key = 'p_' . $row['id'];
                        $quantity = $_SESSION['cart'][$cart_key];
                        $subtotal = $row['price'] * $quantity;
                        $total_price += $subtotal;
                        $cart_items[] = [
                            'id' => $row['id'],
                            'name' => $row['name'],
                            'price' => number_format($row['price'], 2),
                            'image' => $row['image'],
                            'quantity' => $quantity,
                            'subtotal' => number_format($subtotal, 2),
                            'is_variant' => false
                        ];
                    }
                    $stmt->close();
                }
            }

            // Fetch variant products
            if (!empty($variant_ids)) {
                $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
                $sql = "
                    SELECT v.id as variant_id, p.id as product_id, p.name, v.price, p.image,
                           GROUP_CONCAT(CONCAT(pa.name, ': ', av.value) SEPARATOR ', ') as options
                    FROM product_variants v
                    JOIN products p ON v.product_id = p.id
                    JOIN product_variant_options pvo ON v.id = pvo.variant_id
                    JOIN attribute_values av ON pvo.value_id = av.id
                    JOIN product_attributes pa ON pvo.attribute_id = pa.id
                    WHERE v.id IN ($placeholders)
                    GROUP BY v.id
                ";
                if($stmt = $mysqli->prepare($sql)){
                    $types = str_repeat('i', count($variant_ids));
                    $stmt->bind_param($types, ...$variant_ids);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while($row = $result->fetch_assoc()){
                        $cart_key = 'v_' . $row['variant_id'];
                        $quantity = $_SESSION['cart'][$cart_key];
                        $price = $row['price'] ?? $row['base_price']; // Fallback needed
                        $subtotal = $price * $quantity;
                        $total_price += $subtotal;
                        $cart_items[] = [
                            'id' => $row['variant_id'],
                            'name' => $row['name'] . ' (' . $row['options'] . ')',
                            'price' => number_format($price, 2),
                            'image' => $row['image'],
                            'quantity' => $quantity,
                            'subtotal' => number_format($subtotal, 2),
                            'is_variant' => true
                        ];
                    }
                    $stmt->close();
                }
            }
        }
        $response = [
            'status' => 'success',
            'cart_items' => $cart_items,
            'total_price' => number_format($total_price, 2),
            'currency_symbol' => $currency_symbol
        ];
        break;

    case 'filter_products':
        // This is a complex endpoint that essentially rebuilds the products.php grid
        $page = $data['page'] ?? 1;
        $category_filter = $data['category'] ?? null;
        $min_price_filter = $data['min_price'] ?? null;
        $max_price_filter = $data['max_price'] ?? null;

        $records_per_page = 8;
        $offset = ($page - 1) * $records_per_page;

        $where_conditions = [];
        $params = [];
        $types = '';

        if($category_filter) {
            $where_conditions[] = 'category_id = ?';
            $params[] = $category_filter;
            $types .= 'i';
        }
        if($min_price_filter !== null) {
            $where_conditions[] = 'price >= ?';
            $params[] = $min_price_filter;
            $types .= 'd';
        }
        if($max_price_filter !== null) {
            $where_conditions[] = 'price <= ?';
            $params[] = $max_price_filter;
            $types .= 'd';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get total records for pagination
        $total_records_sql = "SELECT COUNT(*) FROM products " . $where_clause;
        $stmt_total = $mysqli->prepare($total_records_sql);
        if(!empty($params)) $stmt_total->bind_param($types, ...$params);
        $stmt_total->execute();
        $total_records = $stmt_total->get_result()->fetch_row()[0];
        $total_pages = ceil($total_records / $records_per_page);
        $stmt_total->close();

        // Fetch products
        $sql = "SELECT * FROM products " . $where_clause . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $records_per_page;
        $params[] = $offset;
        $types .= 'ii';

        $products = [];
        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $products = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Now, we need to RENDER the HTML for the product grid and pagination
        // This is not ideal for a pure API, but it's the simplest way for this project
        ob_start();
        // Product Grid
        if (count($products) > 0) {
            foreach ($products as $product) {
                echo '<div class="col-md-6 col-lg-4 mb-4">';
                include 'includes/product_card.php'; // Use the existing card template
                echo '</div>';
            }
        } else {
            echo '<div class="col"><p>No products match your filters.</p></div>';
        }
        $products_html = ob_get_clean();

        // Pagination
        ob_start();
        // We need to include the helpers file to use the function
        require_once 'includes/helpers.php';

        $query_params = [];
        if ($category_filter) $query_params['category'] = $category_filter;
        if ($min_price_filter !== null) $query_params['min_price'] = $min_price_filter;
        if ($max_price_filter !== null) $query_params['max_price'] = $max_price_filter;
        $query_string = http_build_query($query_params);
        if (!empty($query_string)) $query_string .= '&';

        render_pagination('products.php', $total_pages, $page, $query_string);
        $pagination_html = ob_get_clean();

        $response = [
            'status' => 'success',
            'products_html' => $products_html,
            'pagination_html' => $pagination_html
        ];
        break;

    case 'product_search':
        $query = $data['query'] ?? '';
        $products = [];
        if(!empty($query)){
            $sql = "SELECT id, name, image, price FROM products WHERE name LIKE ? LIMIT 10";
            if($stmt = $mysqli->prepare($sql)){
                $search_query = "%" . $query . "%";
                $stmt->bind_param("s", $search_query);
                $stmt->execute();
                $result = $stmt->get_result();
                while($row = $result->fetch_assoc()){
                    $products[] = $row;
                }
                $stmt->close();
            }
        }
        $response = ['status' => 'success', 'products' => $products];
        break;

    default:
        // Keep the default invalid request message
        break;
}

// Echo the JSON response
echo json_encode($response);
exit();
?>
