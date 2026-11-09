<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_products');

$message = "";
$product_id = $_GET['id'] ?? 0;

if(!$product_id) {
    header("location: manage_products.php");
    exit;
}

// Handle Deletion of a gallery image
if(isset($_GET['delete_gallery_image'])) {
    $image_id_to_delete = $_GET['delete_gallery_image'];
    $sql_get_img = "SELECT image_url FROM product_images WHERE id = ? AND product_id = ?";
    $stmt_get = $mysqli->prepare($sql_get_img);
    $stmt_get->bind_param("ii", $image_id_to_delete, $product_id);
    $stmt_get->execute();
    $stmt_get->bind_result($image_url);
    $stmt_get->fetch();
    $stmt_get->close();

    if($image_url) {
        $sql_delete = "DELETE FROM product_images WHERE id = ?";
        $stmt_delete = $mysqli->prepare($sql_delete);
        $stmt_delete->bind_param("i", $image_id_to_delete);
        if($stmt_delete->execute()) {
            if(file_exists('../uploads/' . $image_url)) {
                unlink('../uploads/' . $image_url);
            }
            $message = '<div class="alert alert-success">Gallery image deleted.</div>';
        }
        $stmt_delete->close();
    }
}


// Helper function for variant generation
function get_cartesian_product($arrays) {
    $result = [[]];
    foreach ($arrays as $key => $values) {
        $append = [];
        foreach ($result as $product) {
            foreach ($values as $value) {
                $product[$key] = $value;
                $append[] = $product;
            }
        }
        $result = $append;
    }
    return $result;
}

// Handle POST requests based on the 'action' parameter
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {
        case 'update_product':
            $name = trim($_POST["name"]);
            $description = trim($_POST["description"]);
            $price = trim($_POST["price"]);
            $category_id = trim($_POST["category_id"]);
            $stock_new = isset($_POST['stock']) ? max(0, (int)$_POST['stock']) : 0;
            $is_featured_new = isset($_POST['is_featured']) ? 1 : 0;
            $is_top_seller_new = isset($_POST['is_top_seller']) ? 1 : 0;
            $has_variants = isset($_POST['has_variants']) ? 1 : 0;
            $current_image = $_POST['current_image'];

            // Handle main image upload
            $new_main_image = $current_image;
            if (isset($_FILES["main_image"]) && $_FILES["main_image"]["error"] == 0) {
                // Image validation and upload logic...
                $max_file_size = 2 * 1024 * 1024;
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
                $allowed_mime = ['image/jpeg', 'image/png', 'image/gif'];

                if ($_FILES['main_image']['size'] > $max_file_size) {
                    $message .= '<div class="alert alert-danger">Main image is too large. Max size is 2MB.</div>';
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $_FILES['main_image']['tmp_name']);
                    finfo_close($finfo);
                    $ext = strtolower(pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION));

                    if (in_array($mime_type, $allowed_mime) && in_array($ext, $allowed_ext)) {
                        $new_filename = "prod_" . uniqid() . '.' . $ext;
                        if (move_uploaded_file($_FILES["main_image"]["tmp_name"], "../uploads/" . $new_filename)) {
                            $new_main_image = $new_filename;
                            if ($current_image != 'default.jpg' && file_exists("../uploads/" . $current_image)) {
                                unlink("../uploads/" . $current_image);
                            }
                        }
                    } else {
                        $message .= '<div class="alert alert-danger">Invalid file type for main image.</div>';
                    }
                }
            }

            // NOTE: `stock` is included so a simple product's inventory can be corrected;
            // it was previously never written, leaving products at stock 0 (unbuyable).
            $sql_update = "UPDATE products SET name=?, description=?, price=?, stock=?, category_id=?, image=?, has_variants=?, is_featured=?, is_top_seller=? WHERE id=?";
            $stmt_update = $mysqli->prepare($sql_update);
            $stmt_update->bind_param("ssdissiiii", $name, $description, $price, $stock_new, $category_id, $new_main_image, $has_variants, $is_featured_new, $is_top_seller_new, $product_id);

            if ($stmt_update->execute()) {
                $message = '<div class="alert alert-success">Product details updated successfully.</div>';
            } else {
                $message = '<div class="alert alert-danger">Error updating product details.</div>';
            }
            $stmt_update->close();
            break;

        case 'upload_gallery_images':
            if (isset($_FILES['gallery_images']['name']) && is_array($_FILES['gallery_images']['name'])) {
                // Gallery image validation and upload logic...
                $max_file_size = 2 * 1024 * 1024;
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
                $allowed_mime = ['image/jpeg', 'image/png', 'image/gif'];

                $sql_gallery = "INSERT INTO product_images (product_id, image_url) VALUES (?, ?)";
                $stmt_gallery = $mysqli->prepare($sql_gallery);

                $image_count = count($_FILES['gallery_images']['name']);
                for ($i = 0; $i < $image_count; $i++) {
                    if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                        if ($_FILES['gallery_images']['size'][$i] > $max_file_size) {
                             $message .= '<div class="alert alert-danger">Gallery image ' . htmlspecialchars($_FILES['gallery_images']['name'][$i]) . ' is too large.</div>';
                            continue;
                        }
                        // More validation...
                         $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime_type = finfo_file($finfo, $_FILES['gallery_images']['tmp_name'][$i]);
                        finfo_close($finfo);
                        $ext = strtolower(pathinfo($_FILES['gallery_images']['name'][$i], PATHINFO_EXTENSION));

                        if (in_array($mime_type, $allowed_mime) && in_array($ext, $allowed_ext)) {
                            $new_gallery_filename = "prod_gallery_" . uniqid() . "." . $ext;
                            if (move_uploaded_file($_FILES['gallery_images']['tmp_name'][$i], "../uploads/" . $new_gallery_filename)) {
                                $stmt_gallery->bind_param("is", $product_id, $new_gallery_filename);
                                $stmt_gallery->execute();
                            }
                        } else {
                            $message .= '<div class="alert alert-danger">Invalid file type for ' . htmlspecialchars($_FILES['gallery_images']['name'][$i]) . '.</div>';
                        }
                    }
                }
                $stmt_gallery->close();
                $message = '<div class="alert alert-success">Gallery images uploaded.</div>';
            }
            break;

        case 'generate_variants':
            $attribute_ids = $_POST['product_attributes'] ?? [];

            if ($product_id && !empty($attribute_ids)) {
                // Delete existing variants
                $stmt_delete = $mysqli->prepare("DELETE FROM product_variants WHERE product_id = ?");
                $stmt_delete->bind_param("i", $product_id);
                $stmt_delete->execute();
                $stmt_delete->close();

                // Fetch values for selected attributes
                $attribute_values = [];
                $placeholders = implode(',', array_fill(0, count($attribute_ids), '?'));
                $sql_values = "SELECT attribute_id, id as value_id FROM attribute_values WHERE attribute_id IN ($placeholders)";
                $stmt_values = $mysqli->prepare($sql_values);
                $stmt_values->bind_param(str_repeat('i', count($attribute_ids)), ...$attribute_ids);
                $stmt_values->execute();
                $result = $stmt_values->get_result();
                while($row = $result->fetch_assoc()) {
                    $attribute_values[$row['attribute_id']][] = $row['value_id'];
                }
                $stmt_values->close();

                $combinations = get_cartesian_product($attribute_values);

                // Insert new variants
                $sql_variant = "INSERT INTO product_variants (product_id, price, sku, stock) VALUES (?, ?, ?, ?)";
                $stmt_variant = $mysqli->prepare($sql_variant);

                $sql_option = "INSERT INTO product_variant_options (variant_id, attribute_id, value_id) VALUES (?, ?, ?)";
                $stmt_option = $mysqli->prepare($sql_option);

                // Use the base price from the submitted form data
                $base_price = trim($_POST["price"]);

                foreach ($combinations as $combination) {
                    $sku = null; $stock = null;
                    $stmt_variant->bind_param("idsi", $product_id, $base_price, $sku, $stock);
                    $stmt_variant->execute();
                    $variant_id = $mysqli->insert_id;

                    foreach ($combination as $attribute_id => $value_id) {
                        $stmt_option->bind_param("iii", $variant_id, $attribute_id, $value_id);
                        $stmt_option->execute();
                    }
                }
                $stmt_variant->close();
                $stmt_option->close();

                $message = '<div class="alert alert-success">Variants generated.</div>';
            } else {
                $message = '<div class="alert alert-warning">No attributes selected.</div>';
            }
            break;

        case 'update_variants':
            $variant_ids = $_POST['variant_id'] ?? [];
            $variant_prices = $_POST['variant_price'] ?? [];
            $variant_skus = $_POST['variant_sku'] ?? [];
            $variant_stocks = $_POST['variant_stock'] ?? [];

            $stmt_update_variant = $mysqli->prepare("UPDATE product_variants SET price = ?, sku = ?, stock = ? WHERE id = ?");

            for ($i = 0; $i < count($variant_ids); $i++) {
                $price = !empty($variant_prices[$i]) ? (float)$variant_prices[$i] : null;
                $sku = !empty($variant_skus[$i]) ? trim($variant_skus[$i]) : null;
                $stock = !empty($variant_stocks[$i]) ? (int)$variant_stocks[$i] : null;
                $id = (int)$variant_ids[$i];

                $stmt_update_variant->bind_param("dsii", $price, $sku, $stock, $id);
                $stmt_update_variant->execute();
            }
            $stmt_update_variant->close();
            $message = '<div class="alert alert-success">Variant details updated.</div>';
            break;
    }
}


// Fetch product data for the form
$sql_product = "SELECT * FROM products WHERE id = ?";
$stmt_product = $mysqli->prepare($sql_product);
$stmt_product->bind_param("i", $product_id);
$stmt_product->execute();
$product = $stmt_product->get_result()->fetch_assoc();
$stmt_product->close();

// Fetch gallery images
$sql_gallery_fetch = "SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order ASC";
$stmt_gallery_fetch = $mysqli->prepare($sql_gallery_fetch);
$stmt_gallery_fetch->bind_param("i", $product_id);
$stmt_gallery_fetch->execute();
$gallery_images = $stmt_gallery_fetch->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_gallery_fetch->close();

// Fetch categories
$sql_categories = "SELECT * FROM categories ORDER BY name ASC";
$result_categories = $mysqli->query($sql_categories);
$categories = $result_categories->fetch_all(MYSQLI_ASSOC);

// Fetch all product attributes
$sql_attributes = "SELECT * FROM product_attributes ORDER BY name ASC";
$result_attributes = $mysqli->query($sql_attributes);
$all_attributes = $result_attributes->fetch_all(MYSQLI_ASSOC);

// Fetch existing variants for this product
$sql_variants = "
    SELECT v.id, v.sku, v.price, v.stock, GROUP_CONCAT(CONCAT(pa.name, ': ', av.value) SEPARATOR ', ') as options
    FROM product_variants v
    JOIN product_variant_options pvo ON v.id = pvo.variant_id
    JOIN attribute_values av ON pvo.value_id = av.id
    JOIN product_attributes pa ON pvo.attribute_id = pa.id
    WHERE v.product_id = ?
    GROUP BY v.id
    ORDER BY v.id
";
$stmt_variants = $mysqli->prepare($sql_variants);
$stmt_variants->bind_param("i", $product_id);
$stmt_variants->execute();
$variants = $stmt_variants->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_variants->close();
?>

<h2>Edit Product: <?php echo htmlspecialchars($product['name']); ?></h2>
<?php echo $message; ?>

<form action="edit_product.php?id=<?php echo $product_id; ?>" method="post" enctype="multipart/form-data">
<div class="card shadow mb-4">
    <div class="card-header">Product Details</div>
    <div class="card-body">
            <input type="hidden" name="id" value="<?php echo $product_id; ?>">
            <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($product['image']); ?>">

            <div class="mb-3">
                <label for="name" class="form-label">Product Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($product['name']); ?>">
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" class="form-control" rows="5"><?php echo htmlspecialchars($product['description']); ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label for="price" class="form-label">Price</label><input type="number" name="price" id="price" class="form-control" value="<?php echo htmlspecialchars($product['price']); ?>" step="0.01"></div>
                <div class="col-md-6 mb-3"><label for="category_id" class="form-label">Category</label>
                    <select name="category_id" id="category_id" class="form-select">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] == $cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="stock" class="form-label">Stock Quantity</label>
                <input type="number" name="stock" id="stock" class="form-control" value="<?php echo (int)($product['stock'] ?? 0); ?>" min="0" step="1">
                <div class="form-text">Units available. Ignored when this product has variants (stock is per-variant then).</div>
            </div>
            <div class="mb-3">
                <label for="main_image" class="form-label">Main Product Image</label>
                <div class="mb-2"><img src="../uploads/<?php echo htmlspecialchars($product['image']); ?>" style="width: 100px; height: 100px; object-fit: cover;"></div>
                <input type="file" name="main_image" id="main_image" class="form-control">
                <div class="form-text">Upload a new file to replace the current main image.</div>
            </div>
            <div class="mb-3 form-check"><input type="checkbox" name="is_featured" class="form-check-input" id="is_featured" value="1" <?php echo ($product['is_featured']) ? 'checked' : ''; ?>><label class="form-check-label" for="is_featured">Featured</label></div>
            <div class="mb-3 form-check"><input type="checkbox" name="is_top_seller" class="form-check-input" id="is_top_seller" value="1" <?php echo ($product['is_top_seller']) ? 'checked' : ''; ?>><label class="form-check-label" for="is_top_seller">Top Seller</label></div>

            <hr>
            <!-- Variants Section integrated into main form -->
            <h5 class="mt-4">Product Variants</h5>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="has_variants" id="has_variants" value="1" <?php echo ($product['has_variants']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="has_variants">Enable Variants for this Product</label>
            </div>

            <div id="variants-config-container" style="<?php echo ($product['has_variants']) ? '' : 'display: none;'; ?>">
                <div class="mb-3">
                    <label for="product_attributes" class="form-label">Select Attributes</label>
                    <select name="product_attributes[]" id="product_attributes" class="form-select" multiple>
                        <?php foreach($all_attributes as $attribute): ?>
                            <option value="<?php echo $attribute['id']; ?>"><?php echo htmlspecialchars($attribute['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Select the attributes that apply to this product (e.g., Color, Size). Hold Ctrl/Cmd to select multiple.</div>
                </div>
                <button type="submit" name="action" value="generate_variants" class="btn btn-info">Generate Variants</button>
                <small class="d-block mt-1">Note: Generating variants will delete any existing variants for this product.</small>
            </div>

            <?php if (!empty($variants)): ?>
            <hr>
            <h6 class="mt-4">Manage Generated Variants</h6>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Variant</th>
                            <th>Price</th>
                            <th>SKU</th>
                            <th>Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($variants as $variant): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($variant['options']); ?></td>
                            <td>
                                <input type="hidden" name="variant_id[]" value="<?php echo $variant['id']; ?>">
                                <input type="number" name="variant_price[]" class="form-control" value="<?php echo htmlspecialchars($variant['price']); ?>" placeholder="Same as base" step="0.01">
                            </td>
                            <td><input type="text" name="variant_sku[]" class="form-control" value="<?php echo htmlspecialchars($variant['sku'] ?? ''); ?>"></td>
                            <td><input type="number" name="variant_stock[]" class="form-control" value="<?php echo htmlspecialchars($variant['stock'] ?? ''); ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" name="action" value="update_variants" class="btn btn-success">Save Variant Details</button>
            <?php endif; ?>
            <hr>

            <button type="submit" name="action" value="update_product" class="btn btn-primary">Update Product</button>
            <a href="manage_products.php" class="btn btn-secondary">Back to Products</a>
    </div>
</div>

<div class="card shadow">
    <div class="card-header">Product Gallery</div>
    <div class="card-body">
        <div class="row">
            <?php foreach($gallery_images as $img): ?>
            <div class="col-md-3 text-center mb-3">
                <img src="../uploads/<?php echo htmlspecialchars($img['image_url']); ?>" class="img-fluid mb-2" style="height: 150px; object-fit: cover;">
                <a href="edit_product.php?id=<?php echo $product_id; ?>&delete_gallery_image=<?php echo $img['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this image?')">Delete</a>
            </div>
            <?php endforeach; ?>
        </div>
        <hr>
        <h5>Add New Gallery Images</h5>
        <div class="mb-3">
            <label for="gallery_images" class="form-label">New Images</label>
            <input type="file" name="gallery_images[]" id="gallery_images" class="form-control" multiple>
        </div>
        <button type="submit" name="action" value="upload_gallery_images" class="btn btn-info">Upload New Images</button>
    </div>
</div>
</form>

<script>
document.getElementById('has_variants').addEventListener('change', function() {
    const container = document.getElementById('variants-config-container');
    if (this.checked) {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }
});
</script>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
