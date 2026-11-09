<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_roles');

$message = "";

// --- Data Fetching ---
// Fetch all roles for the dropdown
$roles_result = $mysqli->query("SELECT * FROM roles ORDER BY role_name");
$all_roles = $roles_result->fetch_all(MYSQLI_ASSOC);

// Fetch all non-customer users (staff/admins)
$sql_staff = "SELECT u.id, u.username, u.email, r.role_name
              FROM users u
              JOIN roles r ON u.role_id = r.id
              ORDER BY u.username ASC";
$staff_result = $mysqli->query($sql_staff);
$all_staff = $staff_result->fetch_all(MYSQLI_ASSOC);


// --- Handle Form Submissions ---

// Handle Add/Edit Staff
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_staff'])) {
    $user_id = $_POST['user_id'];
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role_id = $_POST['role_id'];

    if (empty($username) || empty($email) || empty($role_id)) {
        $message = '<div class="alert alert-danger">Username, Email, and Role are required.</div>';
    } elseif (!empty($password) && strlen($password) < 6) {
        $message = '<div class="alert alert-danger">Password must be at least 6 characters long.</div>';
    } else {
        if (empty($user_id)) { // Add new staff
            if(empty($password)) {
                 $message = '<div class="alert alert-danger">Password is required for new staff members.</div>';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT INTO users (username, email, password, role_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $username, $email, $hashed_password, $role_id);
                // username/email are UNIQUE; under strict mysqli error mode a duplicate
                // throws rather than returning false, so catch it and show the reason.
                try {
                    $stmt->execute();
                    $message = '<div class="alert alert-success">Staff member added successfully.</div>';
                } catch (mysqli_sql_exception $e) {
                    $message = '<div class="alert alert-danger">Error: That username or email is already in use.</div>';
                }
                $stmt->close();
            }
        } else { // Update existing staff
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE users SET username = ?, email = ?, password = ?, role_id = ? WHERE id = ?");
                $stmt->bind_param("sssii", $username, $email, $hashed_password, $role_id, $user_id);
            } else {
                $stmt = $mysqli->prepare("UPDATE users SET username = ?, email = ?, role_id = ? WHERE id = ?");
                $stmt->bind_param("ssii", $username, $email, $role_id, $user_id);
            }
            // username/email are UNIQUE - a duplicate throws under strict mysqli error mode.
            try {
                $stmt->execute();
                $message = '<div class="alert alert-success">Staff member updated successfully.</div>';
            } catch (mysqli_sql_exception $e) {
                $message = '<div class="alert alert-danger">Error: That username or email is already in use.</div>';
            }
            $stmt->close();
        }
        // Refresh staff list after update
        $staff_result = $mysqli->query($sql_staff);
        $all_staff = $staff_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Handle Delete Staff
if(isset($_GET['delete'])){
    $user_id_to_delete = $_GET['delete'];
    if($user_id_to_delete == $_SESSION['id']){
        $message = '<div class="alert alert-danger">You cannot delete your own account.</div>';
    } else {
        $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id_to_delete);
        if($stmt->execute()){
            header("location: manage_staff.php");
            exit();
        } else {
             $message = '<div class="alert alert-danger">Error deleting staff member.</div>';
        }
    }
}

// Check if we are in edit mode
$is_edit_mode = false;
$edit_user = ['id' => '', 'username' => '', 'email' => '', 'role_id' => ''];
if(isset($_GET['edit'])){
    $is_edit_mode = true;
    $user_id = $_GET['edit'];
    $stmt = $mysqli->prepare("SELECT id, username, email, role_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows == 1){
        $edit_user = $result->fetch_assoc();
    }
    $stmt->close();
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Staff</h2>
</div>

<?php echo $message; ?>

<div class="row">
    <!-- Add/Edit Staff Form -->
    <div class="col-lg-5">
        <div class="card shadow mb-4">
            <div class="card-header"><?php echo $is_edit_mode ? 'Edit Staff Member' : 'Add New Staff Member'; ?></div>
            <div class="card-body">
                <form action="manage_staff.php" method="post">
                    <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                    <div class="mb-3"><label for="username" class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($edit_user['username']); ?>" required></div>
                    <div class="mb-3"><label for="email" class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit_user['email']); ?>" required></div>
                    <div class="mb-3"><label for="password" class="form-label">Password</label><input type="password" name="password" class="form-control" <?php if(!$is_edit_mode) echo 'required'; ?>>
                        <?php if($is_edit_mode): ?><div class="form-text">Leave blank to keep current password.</div><?php endif; ?>
                    </div>
                    <div class="mb-3"><label for="role_id" class="form-label">Role</label>
                        <select name="role_id" class="form-select" required>
                            <option value="">Select a role</option>
                            <?php foreach($all_roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php if($edit_user['role_id'] == $role['id']) echo 'selected'; ?>><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="save_staff" class="btn btn-primary"><?php echo $is_edit_mode ? 'Update Staff' : 'Add Staff'; ?></button>
                     <?php if($is_edit_mode): ?><a href="manage_staff.php" class="btn btn-secondary">Cancel Edit</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Staff Table -->
    <div class="col-lg-7">
        <div class="card shadow">
            <div class="card-header">Existing Staff</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr><th>Username</th><th>Email</th><th>Role</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach($all_staff as $staff): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                <td><?php echo htmlspecialchars($staff['email']); ?></td>
                                <td><span class="badge bg-info"><?php echo htmlspecialchars($staff['role_name']); ?></span></td>
                                <td class="text-end">
                                    <a href="manage_staff.php?edit=<?php echo $staff['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                     <?php if($staff['id'] != $_SESSION['id']): ?>
                                    <a href="manage_staff.php?delete=<?php echo $staff['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include 'includes/admin_footer.php';
?>
