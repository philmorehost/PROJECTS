<?php
ob_start();
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_categories');

$message = "";

// Function to handle image upload (no DB interaction, so no changes needed here)
function handle_image_upload($file_input_name) {
    if(isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]["error"] == 0){
        $allowed = ["jpg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif"];
        $filename = $_FILES[$file_input_name]["name"];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if(!array_key_exists($ext, $allowed)) {
            return [ "error" => "Invalid file type. Please upload a JPG, PNG, or GIF." ];
        }

        $new_filename = "cat_" . uniqid() . "." . $ext;
        $upload_path = "../uploads/" . $new_filename;

        if(move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $upload_path)){
            return [ "success" => $new_filename ];
        } else {
            return [ "error" => "Error uploading file." ];
        }
    }
    return [ "no_file" => true ];
}


// Handle Add Category
if(isset($_POST['add_category'])){
    $name = trim($_POST['name']);
    $image_filename = null;

    if(!empty($name)){
        $upload_result = handle_image_upload('image');
        if(isset($upload_result['error'])){
            $message = '<div class="alert alert-danger">' . $upload_result['error'] . '</div>';
        } else {
            if(isset($upload_result['success'])) {
                $image_filename = $upload_result['success'];
            }
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : NULL;
            $sql = "INSERT INTO categories (name, image, parent_id) VALUES (?, ?, ?)";
            if($stmt = $mysqli->prepare($sql)){
                $stmt->bind_param("ssi", $name, $image_filename, $parent_id);
                if($stmt->execute()){
                    $message = '<div class="alert alert-success">Category added successfully.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error adding category.</div>';
                }
                $stmt->close();
            } else {
                 $message = '<div class="alert alert-danger">Error preparing to add category.</div>';
            }
        }
    } else {
        $message = '<div class="alert alert-danger">Category name cannot be empty.</div>';
    }
}

// Handle Delete Category
if(isset($_GET['delete'])){
    $id = (int)$_GET['delete'];
    $sql_check = "SELECT id FROM products WHERE category_id = ?";
    if($stmt_check = $mysqli->prepare($sql_check)){
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $stmt_check->store_result();
        if($stmt_check->num_rows > 0){
            $message = '<div class="alert alert-danger">Cannot delete. Category is linked to existing products.</div>';
        } else {
            $image_to_delete = null;
            $sql_img = "SELECT image FROM categories WHERE id = ?";
            if ($stmt_img = $mysqli->prepare($sql_img)) {
                $stmt_img->bind_param("i", $id);
                $stmt_img->execute();
                $stmt_img->bind_result($image_to_delete);
                $stmt_img->fetch();
                $stmt_img->close();
            }

            $sql_delete = "DELETE FROM categories WHERE id = ?";
            if($stmt_delete = $mysqli->prepare($sql_delete)){
                $stmt_delete->bind_param("i", $id);
                if($stmt_delete->execute()){
                    if($image_to_delete && file_exists("../uploads/" . $image_to_delete)){
                        unlink("../uploads/" . $image_to_delete);
                    }
                    header("location: manage_categories.php");
                    exit();
                } else {
                    $message = '<div class="alert alert-danger">Error deleting category.</div>';
                }
                $stmt_delete->close();
            } else {
                 $message = '<div class="alert alert-danger">Error preparing to delete category.</div>';
            }
        }
        $stmt_check->close();
    } else {
        $message = '<div class="alert alert-danger">Error preparing to check for products.</div>';
    }
}

// Handle Update Category
if(isset($_POST['update_category'])){
    $name = trim($_POST['name']);
    $id = (int)$_POST['id'];
    $image_filename = $_POST['existing_image'];

    if(!empty($name) && !empty($id)){
        $upload_result = handle_image_upload('image');
        if(isset($upload_result['error'])){
            $message = '<div class="alert alert-danger">' . $upload_result['error'] . '</div>';
        } else {
            if(isset($upload_result['success'])) {
                $image_filename = $upload_result['success'];
            }
            $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : NULL;

            if ($id == $parent_id) {
                $message = '<div class="alert alert-danger">A category cannot be its own parent.</div>';
            } else {
                $sql = "UPDATE categories SET name = ?, image = ?, parent_id = ? WHERE id = ?";
                if($stmt = $mysqli->prepare($sql)){
                    $stmt->bind_param("ssii", $name, $image_filename, $parent_id, $id);
                    if($stmt->execute()){
                        header("location: manage_categories.php");
                        exit();
                    } else {
                        $message = '<div class="alert alert-danger">Error updating category.</div>';
                    }
                    $stmt->close();
                } else {
                    $message = '<div class="alert alert-danger">Error preparing to update category.</div>';
                }
            }
        }
    } else {
        $message = '<div class="alert alert-danger">Category name or ID is invalid.</div>';
    }
}

// Check if we are in edit mode
$is_edit_mode = false;
$edit_category = ['name' => '', 'image' => '', 'id' => 0, 'parent_id' => null];
if(isset($_GET['edit'])){
    $is_edit_mode = true;
    $id = (int)$_GET['edit'];
    $sql = "SELECT id, name, image, parent_id FROM categories WHERE id = ?";
    if($stmt = $mysqli->prepare($sql)){
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if($result->num_rows == 1){
                $edit_category = $result->fetch_assoc();
            }
        } else {
            $message = '<div class="alert alert-danger">Error fetching category details.</div>';
        }
        $stmt->close();
    } else {
        $message = '<div class="alert alert-danger">Error preparing to fetch category details.</div>';
    }
}

// Fetch all categories for the parent dropdown
$all_categories = [];
$sql_all = "SELECT id, name FROM categories ORDER BY name ASC";
if ($result_all = $mysqli->query($sql_all)) {
    $all_categories = $result_all->fetch_all(MYSQLI_ASSOC);
} else {
    $message .= '<div class="alert alert-danger">Error fetching categories for dropdown.</div>';
}

// Pagination setup
$records_per_page = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total number of categories
$total_records = 0;
if ($total_result = $mysqli->query("SELECT COUNT(*) FROM categories")) {
    $total_records = $total_result->fetch_row()[0] ?? 0;
} else {
    $message .= '<div class="alert alert-danger">Error fetching category count.</div>';
}
$total_pages = ceil($total_records / $records_per_page);

// Fetch paginated categories for display
$categories = [];
$sql_paginated = "
    SELECT c.id, c.name, c.image, p.name AS parent_name
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    ORDER BY c.name ASC
    LIMIT ? OFFSET ?";
if ($stmt_paginated = $mysqli->prepare($sql_paginated)) {
    $stmt_paginated->bind_param("ii", $records_per_page, $offset);
    if ($stmt_paginated->execute()) {
        $result_paginated = $stmt_paginated->get_result();
        $categories = $result_paginated->fetch_all(MYSQLI_ASSOC);
    } else {
        $message .= '<div class="alert alert-danger">Error fetching paginated categories.</div>';
    }
    $stmt_paginated->close();
} else {
    $message .= '<div class="alert alert-danger">Error preparing to fetch paginated categories.</div>';
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Categories</h2>
</div>

<?php echo $message; ?>

<!-- Form for Add/Edit -->
<div class="card shadow mb-4">
    <div class="card-header"><?php echo $is_edit_mode ? 'Edit Category' : 'Add New Category'; ?></div>
    <div class="card-body">
        <form action="manage_categories.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo $edit_category['id']; ?>">
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($edit_category['image']); ?>">
            <div class="row">
                <div class="col-md-6">
                    <label for="name" class="form-label">Category Name</label>
                    <input type="text" name="name" id="name" class="form-control" placeholder="Category Name" value="<?php echo htmlspecialchars($edit_category['name']); ?>" required>
                </div>
                <div class="col-md-4">
                     <label for="image" class="form-label">Category Image</label>
                    <input type="file" name="image" id="image" class="form-control">
                </div>
                <div class="col-md-4">
                    <label for="parent_id" class="form-label">Parent Category</label>
                    <select name="parent_id" id="parent_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach($all_categories as $cat): ?>
                            <?php if($is_edit_mode && $cat['id'] == $edit_category['id']) continue; ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_category['parent_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                 <?php if($is_edit_mode): ?>
                    <button class="btn btn-primary" type="submit" name="update_category">Update Category</button>
                    <a href="manage_categories.php" class="btn btn-secondary">Cancel</a>
                <?php else: ?>
                    <button class="btn btn-primary" type="submit" name="add_category">Add Category</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Categories Table -->
<div class="card shadow">
    <div class="card-header">Existing Categories</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Parent</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($categories) > 0): ?>
                        <?php foreach($categories as $category): ?>
                        <tr>
                            <td><img src="../uploads/<?php echo htmlspecialchars($category['image'] ?? 'default.jpg'); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 50%;"></td>
                            <td><?php echo htmlspecialchars($category['name']); ?></td>
                            <td><?php echo htmlspecialchars($category['parent_name'] ?? 'None'); ?></td>
                            <td class="text-end">
                                <a href="manage_categories.php?edit=<?php echo $category['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="manage_categories.php?delete=<?php echo $category['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center">No categories found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if($total_pages > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination justify-content-center">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php if($page == $i) echo 'active'; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php
// Include the new admin footer
include 'includes/admin_footer.php';
ob_end_flush();
?>