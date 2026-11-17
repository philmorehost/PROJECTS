<?php
require_once '../includes/db_connect.php'; // Need DB for processing
session_start(); // Need session for require_permission

// Define variables and initialize
$name = $description = $price = $category_id = "";
$is_featured = $is_top_seller = 0;
$stock = 0;
$message = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){

    $name = trim($_POST["name"]);
    $description = trim($_POST["description"]);
    $price = trim($_POST["price"]);
    $category_id = $_POST["category_id"];
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_top_seller = isset($_POST['is_top_seller']) ? 1 : 0;
    $stock = isset($_POST['stock']) ? max(0, (int)$_POST['stock']) : 0;

    // Basic validation
    if(empty($name) || empty($price) || empty($category_id)) {
        $message = '<div class="alert alert-danger">Please fill all required fields.</div>';
    } else {
        $main_image_filename = 'default.jpg';
        $gallery_images = [];

        // Handle file uploads
        if(isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            $image_count = count($_FILES['images']['name']);
            $max_file_size = 2 * 1024 * 1024; // 2MB
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            $allowed_mime = ['image/jpeg', 'image/png', 'image/gif'];

            for($i = 0; $i < $image_count; $i++) {
                if($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    // Check file size
                    if ($_FILES['images']['size'][$i] > $max_file_size) {
                        $message .= '<div class="alert alert-danger">File ' . htmlspecialchars($_FILES['images']['name'][$i]) . ' is too large. Max size is 2MB.</div>';
                        continue;
                    }

                    // Check MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $_FILES['images']['tmp_name'][$i]);
                    finfo_close($finfo);
                    if (!in_array($mime_type, $allowed_mime)) {
                        $message .= '<div class="alert alert-danger">File ' . htmlspecialchars($_FILES['images']['name'][$i]) . ' has an invalid type.</div>';
                        continue;
                    }

                    // Check extension
                    $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_ext)) {
                         $message .= '<div class="alert alert-danger">File ' . htmlspecialchars($_FILES['images']['name'][$i]) . ' has an invalid extension.</div>';
                        continue;
                    }

                    // Create unique filename and move
                    $new_filename = "prod_" . uniqid() . "." . $ext;
                    if(move_uploaded_file($_FILES['images']['tmp_name'][$i], "../uploads/" . $new_filename)){
                        if($i === 0) {
                            $main_image_filename = $new_filename;
                        } else {
                            $gallery_images[] = $new_filename;
                        }
                    } else {
                        $message .= '<div class="alert alert-danger">Failed to move uploaded file.</div>';
                    }
                }
            }
        }

        // Use a transaction to ensure all queries succeed or none do.
        $mysqli->begin_transaction();
        try {
            // Insert into products table
            // NOTE: `stock` must be written here. It defaults to 0, and cart.php refuses
            // to add a product whose stock is 0 - so omitting it made every product
            // created through this form permanently unbuyable.
            $sql = "INSERT INTO products (name, description, price, stock, category_id, image, is_featured, is_top_seller) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssdisiii", $name, $description, $price, $stock, $category_id, $main_image_filename, $is_featured, $is_top_seller);
            $stmt->execute();
            $product_id = $mysqli->insert_id;
            $stmt->close();

            // Insert into product_images table
            if(!empty($gallery_images)) {
                $sql_gallery = "INSERT INTO product_images (product_id, image_url, sort_order) VALUES (?, ?, ?)";
                $stmt_gallery = $mysqli->prepare($sql_gallery);
                foreach($gallery_images as $index => $img_name) {
                    $sort_order = $index + 1;
                    $stmt_gallery->bind_param("isi", $product_id, $img_name, $sort_order);
                    $stmt_gallery->execute();
                }
                $stmt_gallery->close();
            }

            $mysqli->commit();
            // Redirect to the edit page for the new product
            header("location: edit_product.php?id=" . $product_id);
            exit();

        } catch (mysqli_sql_exception $exception) {
            $mysqli->rollback();
            $message = '<div class="alert alert-danger">Oops! Something went wrong. Please try again later.</div>';
        }
    }
}

// Now include the header and display the page
include 'includes/admin_header.php';
require_permission('manage_products');

// Fetch categories for the dropdown
$sql_categories = "SELECT * FROM categories ORDER BY name ASC";
$result_categories = $mysqli->query($sql_categories);
$categories = $result_categories->fetch_all(MYSQLI_ASSOC);
?>

<h2>Add New Product</h2>
<?php echo $message; ?>
<div class="card shadow">
    <div class="card-body">
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="name" class="form-label">Product Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo $name; ?>" required>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" class="form-control" rows="5"><?php echo $description; ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="price" class="form-label">Price</label>
                    <input type="number" name="price" id="price" class="form-control" value="<?php echo $price; ?>" step="0.01" required>
                </div>
                 <div class="col-md-6 mb-3">
                    <label for="category_id" class="form-label">Category</label>
                    <select name="category_id" id="category_id" class="form-select" required>
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="stock" class="form-label">Stock Quantity</label>
                <input type="number" name="stock" id="stock" class="form-control" value="<?php echo (int)$stock; ?>" min="0" step="1" required>
                <div class="form-text">Number of units available. Ignored once the product has variants (stock is per-variant then).</div>
            </div>
            <div class="mb-3">
                <label for="images" class="form-label">Product Images</label>
                <input type="file" name="images[]" id="images" class="form-control" multiple>
                <div class="form-text">Upload multiple images. The first image selected will be the main product image.</div>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" name="is_featured" class="form-check-input" id="is_featured" value="1">
                <label class="form-check-label" for="is_featured">Featured Product</label>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" name="is_top_seller" class="form-check-input" id="is_top_seller" value="1">
                <label class="form-check-label" for="is_top_seller">Top Seller</label>
            </div>
            <button type="submit" class="btn btn-primary">Add Product</button>
            <a href="manage_products.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
