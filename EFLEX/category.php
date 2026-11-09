<?php
// Include the header
include 'includes/header.php';

// Check if category ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])){
    echo "<h1>Category not found</h1>";
    include 'includes/footer.php';
    exit();
}

$category_id = $_GET['id'];

// Fetch category details
$sql_cat = "SELECT name FROM categories WHERE id = ?";
$category_name = "Category";
if($stmt_cat = $mysqli->prepare($sql_cat)){
    $stmt_cat->bind_param("i", $category_id);
    $stmt_cat->execute();
    $stmt_cat->bind_result($cat_name);
    if($stmt_cat->fetch()){
        $category_name = $cat_name;
    }
    $stmt_cat->close();
}


// Fetch products in this category
$sql_prod = "SELECT * FROM products WHERE category_id = ? ORDER BY created_at DESC";
$products = [];
if($stmt_prod = $mysqli->prepare($sql_prod)){
    $stmt_prod->bind_param("i", $category_id);
    $stmt_prod->execute();
    $result = $stmt_prod->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt_prod->close();
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Products in: <?php echo htmlspecialchars($category_name); ?></h2>
</div>

<div class="row">
    <?php if (count($products) > 0): ?>
        <?php foreach ($products as $product): ?>
            <div class="col-md-4 col-lg-3 mb-4">
                <?php include 'includes/product_card.php'; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col">
            <p>No products found in this category.</p>
        </div>
    <?php endif; ?>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>
