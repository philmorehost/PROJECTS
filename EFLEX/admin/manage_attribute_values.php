<?php
ob_start();
// Include the new admin header
include 'includes/admin_header.php';
// Re-use the manage_products permission for now
require_permission('manage_products');

$message = "";
$attribute_id = isset($_GET['attribute_id']) ? (int)$_GET['attribute_id'] : 0;
$attribute = null; // Initialize attribute

if ($attribute_id === 0) {
    // Using die() because the rest of the page is dependent on a valid attribute ID
    die('<div class="alert alert-danger m-3">Invalid attribute ID provided.</div>');
}

// Fetch attribute details with proper error handling
$stmt_attr = $mysqli->prepare("SELECT name FROM product_attributes WHERE id = ?");
if ($stmt_attr) {
    $stmt_attr->bind_param("i", $attribute_id);
    if ($stmt_attr->execute()) {
        $result_attr = $stmt_attr->get_result();
        if ($result_attr->num_rows === 0) {
            die('<div class="alert alert-danger m-3">Attribute not found.</div>');
        }
        $attribute = $result_attr->fetch_assoc();
    } else {
        die('<div class="alert alert-danger m-3">Error executing query to fetch attribute details.</div>');
    }
    $stmt_attr->close();
} else {
    die('<div class="alert alert-danger m-3">Error preparing query to fetch attribute details.</div>');
}

// Handle Add Value
if (isset($_POST['add_value'])) {
    $value = trim($_POST['value']);
    if (!empty($value)) {
        $stmt = $mysqli->prepare("INSERT INTO attribute_values (attribute_id, value) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("is", $attribute_id, $value);
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Value added successfully.</div>';
            } else {
                $message = '<div class="alert alert-danger">Error adding value. It may already exist for this attribute.</div>';
            }
            $stmt->close();
        } else {
            $message = '<div class="alert alert-danger">Error preparing statement to add value.</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Value cannot be empty.</div>';
    }
}

// Handle Delete Value
if (isset($_GET['delete_value'])) {
    $id = (int)$_GET['delete_value'];
    $stmt = $mysqli->prepare("DELETE FROM attribute_values WHERE id = ? AND attribute_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $id, $attribute_id);
        if ($stmt->execute()) {
            header("Location: manage_attribute_values.php?attribute_id=" . $attribute_id);
            exit();
        } else {
            $message = '<div class="alert alert-danger">Error deleting value.</div>';
        }
        $stmt->close();
    } else {
        $message = '<div class="alert alert-danger">Error preparing statement to delete value.</div>';
    }
}

// Pagination setup
$records_per_page = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total number of values for this attribute
$total_records = 0;
$total_sql = "SELECT COUNT(*) FROM attribute_values WHERE attribute_id = ?";
if ($stmt_total = $mysqli->prepare($total_sql)) {
    $stmt_total->bind_param("i", $attribute_id);
    if ($stmt_total->execute()) {
        $result = $stmt_total->get_result();
        $total_records = $result->fetch_row()[0] ?? 0;
    } else {
        $message .= '<div class="alert alert-danger">Error fetching value count.</div>';
    }
    $stmt_total->close();
} else {
    $message .= '<div class="alert alert-danger">Error preparing to fetch value count.</div>';
}
$total_pages = ceil($total_records / $records_per_page);

// Fetch paginated values
$values = [];
$sql_values = "SELECT * FROM attribute_values WHERE attribute_id = ? ORDER BY value ASC LIMIT ? OFFSET ?";
if ($stmt_values = $mysqli->prepare($sql_values)) {
    $stmt_values->bind_param("iii", $attribute_id, $records_per_page, $offset);
    if ($stmt_values->execute()) {
        $result_values = $stmt_values->get_result();
        $values = $result_values->fetch_all(MYSQLI_ASSOC);
    } else {
        $message .= '<div class="alert alert-danger">Error fetching values.</div>';
    }
    $stmt_values->close();
} else {
    $message .= '<div class="alert alert-danger">Error preparing to fetch values.</div>';
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Values for "<?php echo htmlspecialchars($attribute['name']); ?>"</h2>
    <a href="manage_attributes.php" class="btn btn-secondary">Back to Attributes</a>
</div>

<?php echo $message; ?>

<!-- Form for Add Value -->
<div class="card shadow mb-4">
    <div class="card-header">Add New Value</div>
    <div class="card-body">
        <form action="manage_attribute_values.php?attribute_id=<?php echo $attribute_id; ?>" method="post">
            <div class="row">
                <div class="col-md-6">
                    <label for="value" class="form-label">Value Name</label>
                    <input type="text" name="value" id="value" class="form-control" placeholder="e.g., Red, Small, 16GB" required>
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" type="submit" name="add_value">Add Value</button>
            </div>
        </form>
    </div>
</div>

<!-- Existing Values Table -->
<div class="card shadow">
    <div class="card-header">Existing Values</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Value</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($values) > 0): ?>
                        <?php foreach($values as $value): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($value['value']); ?></td>
                            <td class="text-end">
                                <a href="manage_attribute_values.php?attribute_id=<?php echo $attribute_id; ?>&delete_value=<?php echo $value['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" class="text-center">No values found for this attribute.</td></tr>
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
                    <a class="page-link" href="?attribute_id=<?php echo $attribute_id; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
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