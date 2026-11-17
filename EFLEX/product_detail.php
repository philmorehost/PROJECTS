<?php
// Include the header
include 'includes/header.php';

// Check if product ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])){
    echo "<h1>Product not found</h1>";
    include 'includes/footer.php';
    exit();
}

$product_id = $_GET['id'];

// Fetch product details
$sql = "SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ?";
$product = null;
if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows == 1){
        $product = $result->fetch_assoc();
    }
    $stmt->close();
}

// If product not found, display message
if(!$product){
    echo "<h1>Product not found</h1>";
    include 'includes/footer.php';
    exit();
}

// Fetch gallery images
$sql_gallery = "SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order ASC";
$stmt_gallery = $mysqli->prepare($sql_gallery);
$stmt_gallery->bind_param("i", $product_id);
$stmt_gallery->execute();
$gallery_images = $stmt_gallery->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_gallery->close();

// Fetch reviews and calculate average rating
$sql_reviews = "SELECT r.*, u.username FROM product_reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? AND r.is_approved = 1 ORDER BY r.created_at DESC";
$stmt_reviews = $mysqli->prepare($sql_reviews);
$stmt_reviews->bind_param("i", $product_id);
$stmt_reviews->execute();
$reviews = $stmt_reviews->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_reviews->close();

$total_reviews = count($reviews);
$average_rating = 0;
if($total_reviews > 0) {
    $total_rating = array_sum(array_column($reviews, 'rating'));
    $average_rating = round($total_rating / $total_reviews, 1);
}

// Fetch variant data if the product has variants
$variants_json = '[]';
$attributes_for_product = [];
if($product['has_variants']) {
    // Get all variants and their specific option values
    $sql_variants = "
        SELECT
            v.id as variant_id, v.sku, v.price, v.stock,
            pvo.attribute_id, pvo.value_id
        FROM product_variants v
        JOIN product_variant_options pvo ON v.id = pvo.variant_id
        WHERE v.product_id = ?
    ";
    $stmt_variants = $mysqli->prepare($sql_variants);
    $stmt_variants->bind_param("i", $product_id);
    $stmt_variants->execute();
    $variants_result = $stmt_variants->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_variants->close();

    $temp_variants = [];
    foreach($variants_result as $row) {
        $variant_id = $row['variant_id'];
        if (!isset($temp_variants[$variant_id])) {
            $temp_variants[$variant_id] = [
                'price' => $row['price'],
                'stock' => $row['stock'],
                'sku' => $row['sku'],
                'variant_id' => $variant_id,
                'options' => []
            ];
        }
        $temp_variants[$variant_id]['options'][$row['attribute_id']] = (int)$row['value_id'];
    }
    $variants_json = json_encode(array_values($temp_variants), JSON_NUMERIC_CHECK);

    // Get the attributes and their available values for this product
    $sql_attributes = "
        SELECT DISTINCT pa.id as attribute_id, pa.name, av.id as value_id, av.value
        FROM product_variant_options pvo
        JOIN product_attributes pa ON pvo.attribute_id = pa.id
        JOIN attribute_values av ON pvo.value_id = av.id
        WHERE pvo.variant_id IN (SELECT id FROM product_variants WHERE product_id = ?)
        ORDER BY pa.name, av.value
    ";
    $stmt_attributes = $mysqli->prepare($sql_attributes);
    $stmt_attributes->bind_param("i", $product_id);
    $stmt_attributes->execute();
    $attributes_result = $stmt_attributes->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_attributes->close();

    foreach($attributes_result as $row) {
        $attributes_for_product[$row['name']]['id'] = $row['attribute_id'];
        $attributes_for_product[$row['name']]['values'][$row['value_id']] = $row['value'];
    }
}

// Fetch related products (from the same category)
$related_products = [];
if($product['category_id']) {
    $sql_related = "SELECT * FROM products WHERE category_id = ? AND id != ? LIMIT 4";
    $stmt_related = $mysqli->prepare($sql_related);
    $stmt_related->bind_param("ii", $product['category_id'], $product_id);
    $stmt_related->execute();
    $related_products = $stmt_related->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_related->close();
}
?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-6">
            <div class="main-image-container mb-3">
                 <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" class="img-fluid w-100" id="mainProductImage" alt="<?php echo htmlspecialchars($product['name']); ?>">
            </div>
            <?php if(count($gallery_images) > 0): ?>
            <div class="product-thumbnails d-flex gap-2">
                <div class="thumbnail-item"><img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" class="img-fluid" alt="Thumbnail" onclick="changeMainImage(this)"></div>
                <?php foreach($gallery_images as $img): ?>
                <div class="thumbnail-item"><img src="uploads/<?php echo htmlspecialchars($img['image_url']); ?>" class="img-fluid" alt="Thumbnail" onclick="changeMainImage(this)"></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="category.php?id=<?php echo $product['category_id']; ?>"><?php echo htmlspecialchars($product['category_name']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($product['name']); ?></li>
                </ol>
            </nav>

            <?php
            // Display error messages if any
            if (isset($_SESSION['error_message'])) {
                echo '<div class="alert alert-danger" role="alert">' . $_SESSION['error_message'] . '</div>';
                // Unset the error message so it doesn't show again on refresh
                unset($_SESSION['error_message']);
            }
            ?>

            <h2><?php echo htmlspecialchars($product['name']); ?></h2>
            <div class="d-flex align-items-center mb-3">
                <div class="star-rating-display me-2" data-rating="<?php echo $average_rating; ?>">
                    <?php for($i = 1; $i <= 5; $i++): ?><i class="fas fa-star <?php echo ($i <= $average_rating) ? 'text-warning' : 'text-secondary'; ?>"></i><?php endfor; ?>
                </div>
                <a href="#reviews-container" class="text-muted">(<?php echo $total_reviews; ?> customer reviews)</a>
            </div>
            <h4 class="text-success mb-3" id="product-price"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo htmlspecialchars(number_format($product['price'], 2)); ?></h4>

            <form id="add-to-cart-form" action="cart.php" method="post">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <input type="hidden" name="variant_id" id="selected-variant-id" value="">

                <?php if($product['has_variants']): ?>
                <div id="variants-container">
                    <?php foreach($attributes_for_product as $name => $attribute): ?>
                        <div class="mb-3 variant-group">
                            <label for="attribute-<?php echo $attribute['id']; ?>" class="form-label fw-bold"><?php echo htmlspecialchars($name); ?>:</label>
                            <select class="form-select variant-select" name="attribute-<?php echo $attribute['id']; ?>" id="attribute-<?php echo $attribute['id']; ?>">
                                <option value="" selected>Select <?php echo htmlspecialchars($name); ?></option>
                                <?php foreach($attribute['values'] as $value_id => $value_name): ?>
                                    <option value="<?php echo $value_id; ?>"><?php echo htmlspecialchars($value_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="stock-display" class="mt-3 mb-3" style="display: none;">
                    <span class="fw-bold">Availability:</span> <span id="stock-level" class="fw-bold"></span>
                </div>
                <?php endif; ?>

                <div class="row align-items-end">
                    <div id="quantity-container" class="col-md-4" <?php echo ($product['has_variants']) ? 'style="display: none;"' : ''; ?>>
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" name="quantity" id="quantity" class="form-control" value="1" min="1">
                    </div>
                    <div class="col-md-8">
                         <button type="submit" name="add_to_cart" id="add-to-cart-btn" class="btn btn-primary btn-lg w-100" <?php echo ($product['has_variants']) ? 'disabled' : ''; ?>>
                             <?php echo ($product['has_variants']) ? 'Select Options' : 'Add to Cart'; ?>
                         </button>
                    </div>
                </div>
            </form>

            <div class="product-share mt-4">
                <span class="me-2">Share:</span>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fab fa-facebook-f"></i></a>
                <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&text=<?php echo urlencode('Check out this product: ' . $product['name']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fab fa-twitter"></i></a>
                <a href="https://pinterest.com/pin/create/button/?url=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&media=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . '/uploads/' . $product['image']); ?>&description=<?php echo urlencode($product['name']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fab fa-pinterest"></i></a>
            </div>
            <hr class="my-4">
            <p class="lead"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
        </div>
    </div>
</div>
<!-- ... rest of the file ... -->
<script>
function changeMainImage(thumbnail) {
    document.getElementById('mainProductImage').src = thumbnail.src;
    document.querySelectorAll('.thumbnail-item').forEach(item => item.classList.remove('active'));
    thumbnail.parentElement.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    const firstThumbnail = document.querySelector('.thumbnail-item');
    if (firstThumbnail) {
        firstThumbnail.classList.add('active');
    }

    <?php if($product['has_variants']): ?>
    const variantsData = <?php echo $variants_json; ?>;
    const priceElement = document.getElementById('product-price');
    const addToCartBtn = document.getElementById('add-to-cart-btn');
    const stockDisplay = document.getElementById('stock-display');
    const stockLevelElement = document.getElementById('stock-level');
    const selectedVariantIdInput = document.getElementById('selected-variant-id');
    const quantityContainer = document.getElementById('quantity-container');
    const basePriceText = '<?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo htmlspecialchars(number_format($product['price'], 2)); ?>';
    const currencySymbol = '<?php echo htmlspecialchars($_SESSION['currency_symbol']); ?>';
    const variantGroups = document.querySelectorAll('.variant-group');

    function updateVariantState() {
        const selectedOptions = {};
        let allOptionsSelected = true;

        variantGroups.forEach(group => {
            const select = group.querySelector('select.variant-select');
            if (select && select.value) {
                const attributeId = select.name.split('-')[1];
                selectedOptions[attributeId] = parseInt(select.value, 10);
            } else {
                allOptionsSelected = false;
            }
        });

        if (allOptionsSelected) {
            let bestMatch = null;
            let highestScore = -1;

            variantsData.forEach(variant => {
                let currentScore = 0;
                let keysInVariant = Object.keys(variant.options).length;
                let keysInSelected = Object.keys(selectedOptions).length;

                if (keysInVariant !== keysInSelected) {
                    // Skip variants that don't have the same number of attributes
                    return;
                }

                for (const attrId in selectedOptions) {
                    if (variant.options[attrId] === selectedOptions[attrId]) {
                        currentScore++;
                    }
                }

                if (currentScore > highestScore) {
                    highestScore = currentScore;
                    bestMatch = variant;
                }
            });

            const matchedVariant = (highestScore === Object.keys(selectedOptions).length) ? bestMatch : null;

            // if (isDebug) {
            //     debugOutput.innerHTML += `\n\n<strong>bestMatch:</strong>\n${JSON.stringify(bestMatch, null, 2)}`;
            // }

            if (matchedVariant) {
                priceElement.textContent = currencySymbol + parseFloat(matchedVariant.price).toFixed(2);
                selectedVariantIdInput.value = matchedVariant.variant_id;

                if (matchedVariant.stock > 0) {
                    stockLevelElement.textContent = `${matchedVariant.stock} in stock`;
                    stockLevelElement.classList.remove('text-danger');
                    stockLevelElement.classList.add('text-success');
                    addToCartBtn.disabled = false;
                    addToCartBtn.textContent = 'Add to Cart';
                    quantityContainer.style.display = 'block';
                } else {
                    stockLevelElement.textContent = 'Out of stock';
                    stockLevelElement.classList.remove('text-success');
                    stockLevelElement.classList.add('text-danger');
                    addToCartBtn.disabled = true;
                    addToCartBtn.textContent = 'Out of Stock';
                    quantityContainer.style.display = 'none';
                }
                stockDisplay.style.display = 'block';
            } else {
                priceElement.textContent = basePriceText;
                stockDisplay.style.display = 'none';
                addToCartBtn.disabled = true;
                addToCartBtn.textContent = 'Unavailable';
                selectedVariantIdInput.value = '';
                quantityContainer.style.display = 'none';
            }
        } else {
            priceElement.textContent = basePriceText;
            stockDisplay.style.display = 'none';
            addToCartBtn.disabled = true;
            addToCartBtn.textContent = 'Select Options';
            selectedVariantIdInput.value = '';
            quantityContainer.style.display = 'none';
        }
    }

    document.querySelectorAll('.variant-select').forEach(select => {
        select.addEventListener('change', updateVariantState);
    });

    <?php endif; ?>
});
</script>
<?php include 'includes/footer.php'; ?>
