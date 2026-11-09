<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_roles');

$message = "";

// --- Permissions ---
// Fetch all available permissions
$permissions_result = $mysqli->query("SELECT * FROM permissions ORDER BY permission_name");
$all_permissions = $permissions_result->fetch_all(MYSQLI_ASSOC);

// --- Handle Form Submissions ---

// Handle Add/Update Role
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_role'])) {
    $role_id = $_POST['role_id'];
    $role_name = trim($_POST['role_name']);
    $assigned_permissions = $_POST['permissions'] ?? [];

    if (!empty($role_name)) {
        $mysqli->begin_transaction();
        try {
            if (empty($role_id)) { // Add new role
                $stmt = $mysqli->prepare("INSERT INTO roles (role_name) VALUES (?)");
                $stmt->bind_param("s", $role_name);
                $stmt->execute();
                $role_id = $mysqli->insert_id;
                $message = '<div class="alert alert-success">Role added successfully.</div>';
            } else { // Update existing role
                $stmt = $mysqli->prepare("UPDATE roles SET role_name = ? WHERE id = ?");
                $stmt->bind_param("si", $role_name, $role_id);
                $stmt->execute();
                $message = '<div class="alert alert-success">Role updated successfully.</div>';

                // Clear existing permissions for this role before re-adding
                $stmt_clear = $mysqli->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $stmt_clear->bind_param("i", $role_id);
                $stmt_clear->execute();
                $stmt_clear->close();
            }
            $stmt->close();

            // Assign selected permissions to the role
            $stmt_assign = $mysqli->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($assigned_permissions as $permission_id) {
                $stmt_assign->bind_param("ii", $role_id, $permission_id);
                $stmt_assign->execute();
            }
            $stmt_assign->close();

            $mysqli->commit();
        } catch (mysqli_sql_exception $exception) {
            $mysqli->rollback();
            $message = '<div class="alert alert-danger">Database error: ' . $exception->getMessage() . '</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">Role name cannot be empty.</div>';
    }
}


// Handle Delete Role
if (isset($_GET['delete'])) {
    $role_id_to_delete = $_GET['delete'];
    // TODO: Add check if any user is assigned this role before deleting
    $stmt = $mysqli->prepare("DELETE FROM roles WHERE id = ?");
    $stmt->bind_param("i", $role_id_to_delete);
    if($stmt->execute()){
        header("location: manage_roles.php");
        exit();
    } else {
        $message = '<div class="alert alert-danger">Error deleting role.</div>';
    }
}


// --- Data Fetching for Display ---
// Check if we are in edit mode
$is_edit_mode = false;
$edit_role = ['id' => '', 'role_name' => ''];
$edit_role_permissions = [];
if (isset($_GET['edit'])) {
    $is_edit_mode = true;
    $role_id = $_GET['edit'];
    $stmt = $mysqli->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $edit_role = $result->fetch_assoc();
        // Fetch permissions for this role
        $stmt_perms = $mysqli->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
        $stmt_perms->bind_param("i", $role_id);
        $stmt_perms->execute();
        $res_perms = $stmt_perms->get_result();
        while($row = $res_perms->fetch_assoc()){
            $edit_role_permissions[] = $row['permission_id'];
        }
    }
    $stmt->close();
}

// Fetch all roles for the table
$roles_result = $mysqli->query("SELECT * FROM roles ORDER BY role_name");
$all_roles = $roles_result->fetch_all(MYSQLI_ASSOC);

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Roles & Permissions</h2>
</div>

<?php echo $message; ?>

<div class="row">
    <!-- Add/Edit Role Form -->
    <div class="col-lg-5">
        <div class="card shadow mb-4">
            <div class="card-header"><?php echo $is_edit_mode ? 'Edit Role' : 'Add New Role'; ?></div>
            <div class="card-body">
                <form action="manage_roles.php" method="post">
                    <input type="hidden" name="role_id" value="<?php echo $edit_role['id']; ?>">
                    <div class="mb-3">
                        <label for="role_name" class="form-label">Role Name</label>
                        <input type="text" name="role_name" id="role_name" class="form-control" value="<?php echo htmlspecialchars($edit_role['role_name']); ?>" required>
                    </div>
                    <h5>Permissions</h5>
                    <div class="permission-list">
                        <?php foreach ($all_permissions as $permission): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $permission['id']; ?>" id="perm_<?php echo $permission['id']; ?>"
                                    <?php if (in_array($permission['id'], $edit_role_permissions)) echo 'checked'; ?>>
                                <label class="form-check-label" for="perm_<?php echo $permission['id']; ?>">
                                    <?php echo htmlspecialchars($permission['permission_name']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <button class="btn btn-primary" type="submit" name="save_role"><?php echo $is_edit_mode ? 'Update Role' : 'Add Role'; ?></button>
                    <?php if($is_edit_mode): ?>
                        <a href="manage_roles.php" class="btn btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Roles Table -->
    <div class="col-lg-7">
        <div class="card shadow">
            <div class="card-header">Existing Roles</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Role Name</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_roles as $role): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($role['role_name']); ?></td>
                                <td class="text-end">
                                    <a href="manage_roles.php?edit=<?php echo $role['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php if ($role['role_name'] !== 'Super Admin'): // Prevent deleting Super Admin role ?>
                                    <a href="manage_roles.php?delete=<?php echo $role['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
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

<style>
.permission-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    padding: 10px;
    border-radius: 5px;
}
</style>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>
