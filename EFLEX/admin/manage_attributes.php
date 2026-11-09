<?php
ob_start();
// Include the new admin header
include 'includes/admin_header.php';
require_once '../includes/helpers.php';
// For now, let's reuse the products permission. We can create a new one later if needed.
require_permission('manage_products');

$message = "";

// Handle Add Attribute
if (isset($_POST['add_attribute'])) {
    $name = trim($_POST['name']);
    if (!empty($name)) {
        $stmt = $mysqli->prepare("INSERT INTO product_attributes (name) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param("s", $name);
            // `name` is UNIQUE and mysqli runs in strict error mode, so a duplicate
            // throws instead of returning false - catch it to avoid a fatal page.
            try {
                $stmt->execute();
                $message = '<div class="alert alert-success">Attribute added successfully.</div>';
            } catch (mysqli_sql_exception $e) {
                $message = '<div class="alert alert-danger">Error adding attribute. It may already exist.</div>';
            }
            $stmt->close();
        } else {
            $message = '<div class="alert alert-danger">Error preparing statement to add attribute.</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Attribute name cannot be empty.</div>';
    }
}

// Handle Update Attribute
if (isset($_POST['update_attribute'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    if (!empty($name) && !empty($id)) {
        $stmt = $mysqli->prepare("UPDATE product_attributes SET name = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $name, $id);
            // `name` is UNIQUE - a duplicate throws under strict mysqli error mode.
            try {
                $stmt->execute();
                header("Location: manage_attributes.php");
                exit();
            } catch (mysqli_sql_exception $e) {
                $message = '<div class="alert alert-danger">Error updating attribute. Name may already exist.</div>';
            }
            $stmt->close();
        } else {
            $message = '<div class="alert alert-danger">Error preparing statement to update attribute.</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Attribute name or ID cannot be empty.</div>';
    }
}

// Handle Delete Attribute
if (isset($_GET['delete_attribute'])) {
    $id = (int)$_GET['delete_attribute'];
    $stmt = $mysqli->prepare("DELETE FROM product_attributes WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            header("Location: manage_attributes.php");
            exit();
        } else {
            $message = '<div class="alert alert-danger">Error deleting attribute.</div>';
        }
        $stmt->close();
    } else {
        $message = '<div class="alert alert-danger">Error preparing statement to delete attribute.</div>';
    }
}


// Check if we are in edit mode for an attribute
$is_edit_mode = false;
$edit_attribute = ['id' => 0, 'name' => ''];
if (isset($_GET['edit_attribute'])) {
    $is_edit_mode = true;
    $id = (int)$_GET['edit_attribute'];
    $stmt = $mysqli->prepare("SELECT id, name FROM product_attributes WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows == 1) {
                $edit_attribute = $result->fetch_assoc();
            } else {
                $message = '<div class="alert alert-warning">The selected attribute could not be found.</div>';
            }
        } else {
            $message = '<div class="alert alert-danger">Error fetching attribute details.</div>';
        }
        $stmt->close();
    } else {
        $message = '<div class="alert alert-danger">Error preparing to fetch attribute details.</div>';
    }
}

// Pagination setup
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total number of attributes for pagination
$total_records = 0;
$total_result = $mysqli->query("SELECT COUNT(*) FROM product_attributes");
if ($total_result) {
    $total_records = $total_result->fetch_row()[0] ?? 0;
} else {
    $message .= '<div class="alert alert-danger">Error fetching attribute count.</div>';
}
$total_pages = ceil($total_records / $records_per_page);

// Fetch paginated attributes
$attributes = [];
$sql_attr = "SELECT * FROM product_attributes ORDER BY name ASC LIMIT ? OFFSET ?";
if ($stmt_attr = $mysqli->prepare($sql_attr)) {
    $stmt_attr->bind_param("ii", $records_per_page, $offset);
    if ($stmt_attr->execute()) {
        $result_attr = $stmt_attr->get_result();
        $attributes = $result_attr->fetch_all(MYSQLI_ASSOC);
    } else {
        $message .= '<div class="alert alert-danger">Error fetching attributes.</div>';
    }
    $stmt_attr->close();
} else {
    $message .= '<div class="alert alert-danger">Error preparing to fetch attributes.</div>';
}



?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Product Attributes</h2>
</div>

<?php echo $message; ?>

<!-- Form for Add/Edit Attribute -->
<div class="card shadow mb-4">
    <div class="card-header"><?php echo $is_edit_mode ? 'Edit Attribute' : 'Add New Attribute'; ?></div>
    <div class="card-body">
        <form action="manage_attributes.php" method="post">
            <input type="hidden" name="id" value="<?php echo $edit_attribute['id']; ?>">
            <div class="row">
                <div class="col-md-6">
                    <label for="name" class="form-label">Attribute Name</label>
                    <input type="text" name="name" id="name" class="form-control" placeholder="e.g., Color, Size" value="<?php echo htmlspecialchars($edit_attribute['name']); ?>" required>
                </div>
            </div>
            <div class="mt-3">
                <?php if ($is_edit_mode): ?>
                    <button class="btn btn-primary" type="submit" name="update_attribute">Update Attribute</button>
                    <a href="manage_attributes.php" class="btn btn-secondary">Cancel</a>
                <?php else: ?>
                    <button class="btn btn-primary" type="submit" name="add_attribute">Add Attribute</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Existing Attributes Table -->
<div class="card shadow">
    <div class="card-header">Existing Attributes</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Attribute Name</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($attributes) > 0): ?>
                        <?php foreach($attributes as $attribute): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($attribute['name']); ?></td>
                            <td class="text-end">
                                <a href="manage_attribute_values.php?attribute_id=<?php echo $attribute['id']; ?>" class="btn btn-sm btn-info">Manage Values</a>
                                <a href="manage_attributes.php?edit_attribute=<?php echo $attribute['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                <a href="manage_attributes.php?delete_attribute=<?php echo $attribute['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure? This will delete the attribute and all its values.')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" class="text-center">No attributes found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if($total_pages > 1): ?>
    <div class="card-footer">
        <?php render_pagination('manage_attributes.php', $total_pages, $page); ?>
    </div>
    <?php endif; ?>
</div>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
ob_end_flush();
?>