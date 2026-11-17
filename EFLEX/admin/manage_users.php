<?php
// Include the new admin header
include 'includes/admin_header.php';
require_once '../includes/helpers.php'; // ../ because it's in the parent directory
require_permission('manage_users');

$message = "";

// Handle Delete User
if(isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])){
    $user_id_to_delete = $_GET['id'];

    // Safety check: do not allow an admin to delete their own account
    if($user_id_to_delete == $_SESSION['id']){
        $message = '<div class="alert alert-danger">You cannot delete your own account.</div>';
    } else {
        // Check if the user has any orders
        $sql_check = "SELECT COUNT(*) FROM orders WHERE user_id = ?";
        if($stmt_check = $mysqli->prepare($sql_check)){
            $stmt_check->bind_param("i", $user_id_to_delete);
            $stmt_check->execute();
            $order_count = $stmt_check->get_result()->fetch_row()[0];
            $stmt_check->close();

            if($order_count > 0){
                $message = '<div class="alert alert-warning">Cannot delete user. This user has existing orders. Consider disabling their account instead.</div>';
            } else {
                // No orders, proceed with deletion
                $sql_delete = "DELETE FROM users WHERE id = ?";
                if($stmt_delete = $mysqli->prepare($sql_delete)){
                    $stmt_delete->bind_param("i", $user_id_to_delete);
                    if($stmt_delete->execute()){
                        header("location: manage_users.php");
                        exit;
                    } else {
                        $message = '<div class="alert alert-danger">Error deleting user.</div>';
                    }
                    $stmt_delete->close();
                }
            }
        }
    }
}

// Pagination variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Search term
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Base query
$sql_base = "FROM users WHERE role_id IS NULL";
$sql_where = "";
$params = [];
$types = "";

if(!empty($search_term)){
    $sql_where = " AND (username LIKE ? OR email LIKE ?)";
    $search_param = "%" . $search_term . "%";
    $params[] = &$search_param;
    $params[] = &$search_param;
    $types .= "ss";
}

// Get total number of customers
// NOTE: this must be a prepared statement when a search term is present, because
// $sql_where then contains "?" placeholders. Running it through query() sent the
// literal "?" to MySQL and produced an uncaught fatal on every customer search.
if(!empty($search_term)) {
    $stmt_count = $mysqli->prepare("SELECT COUNT(*) " . $sql_base . $sql_where);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_row()[0];
    $stmt_count->close();
} else {
    $total_records_result = $mysqli->query("SELECT COUNT(*) FROM users WHERE role_id IS NULL");
    $total_records = $total_records_result->fetch_row()[0];
}

$total_pages = ceil($total_records / $records_per_page);

// Fetch customers for the current page
$sql = "SELECT id, username, email, created_at " . $sql_base . $sql_where . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = &$records_per_page;
$params[] = &$offset;
$types .= "ii";

if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $users = [];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Customers</h2>
</div>

<?php echo $message; ?>

<div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>All Customer Accounts</span>
        <form action="manage_users.php" method="get" class="d-inline-flex">
            <input type="text" class="form-control form-control-sm me-2" name="search" placeholder="Search by username or email..." value="<?php echo htmlspecialchars($search_term); ?>">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <!-- Table Header -->
                <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Registered On</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <?php if(count($users) > 0): ?>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo $user['created_at']; ?></td>
                            <td class="text-end">
                                <!-- Actions for customers could be view orders, etc. For now, just delete. -->
                                <a href="manage_users.php?action=delete&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this user? This cannot be undone.')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center">No customers found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php
$query_string = !empty($search_term) ? "search=" . urlencode($search_term) . "&" : "";
render_pagination('manage_users.php', $total_pages, $page, $query_string);
?>
<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
