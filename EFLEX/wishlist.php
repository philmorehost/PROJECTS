<?php
// We need the session and database connection first for the auth check.
// db_connect.php also starts the session.
require_once 'includes/db_connect.php';

// Check if the user is logged in, if not then redirect to login page
// This check MUST happen before any HTML is output.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['id'];

// Handle Remove from Wishlist action.
// NOTE: must run before the header is included, otherwise HTML output has begun
// and header() cannot send the redirect ("headers already sent").
if(isset($_GET['action']) && $_GET['action'] == 'remove' && isset($_GET['product_id'])){
    $product_id_to_remove = $_GET['product_id'];
    $stmt = $mysqli->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id_to_remove);
    $stmt->execute();
    $stmt->close();
    header("location: wishlist.php"); // Redirect to clean the URL
    exit();
}

// Now that the user is authenticated, we can include the header.
include 'includes/header.php';

// Fetch wishlist items for the user
$wishlist_items = [];
$sql = "SELECT p.id, p.name, p.price, p.image
        FROM products p
        JOIN wishlist w ON p.id = w.product_id
        WHERE w.user_id = ?";

if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $wishlist_items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<div class="container mt-5">
    <h2>My Wishlist</h2>
    <hr>
    <?php if(!empty($wishlist_items)): ?>
        <div class="row">
            <?php foreach($wishlist_items as $product): ?>
                <div class="col-md-4 col-lg-3 mb-4">
                    <div class="card h-100">
                        <a href="product_detail.php?id=<?php echo $product['id']; ?>">
                            <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($product['name']); ?>" style="height: 200px; object-fit: cover;">
                        </a>
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><a href="product_detail.php?id=<?php echo $product['id']; ?>" class="text-dark text-decoration-none"><?php echo htmlspecialchars($product['name']); ?></a></h5>
                            <p class="card-text text-muted"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo htmlspecialchars($product['price']); ?></p>
                            <div class="mt-auto">
                                <a href="#" class="btn btn-primary btn-sm ajax-add-to-cart" data-product-id="<?php echo $product['id']; ?>">Add to Cart</a>
                                <a href="wishlist.php?action=remove&product_id=<?php echo $product['id']; ?>" class="btn btn-outline-danger btn-sm">Remove</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info">Your wishlist is empty.</div>
        <a href="products.php" class="btn btn-primary">Discover Products</a>
    <?php endif; ?>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>
