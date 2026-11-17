<?php
// This file should be included at the top of any admin page that requires a permission check.

function has_permission($permission_name) {
    global $mysqli;

    // Ensure user is logged in
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        return false;
    }

    // Load user's permissions if not already in session
    if (!isset($_SESSION['permissions'])) {
        if (!isset($_SESSION['role_id'])) {
            // If role_id is not in session, fetch it.
            $user_id = $_SESSION['id'];
            $stmt_role = $mysqli->prepare("SELECT role_id FROM users WHERE id = ?");
            $stmt_role->bind_param("i", $user_id);
            $stmt_role->execute();
            $result_role = $stmt_role->get_result();
            if($result_role->num_rows > 0) {
                $user_data = $result_role->fetch_assoc();
                $_SESSION['role_id'] = $user_data['role_id'];
            } else {
                 $_SESSION['permissions'] = []; // User has no role/permissions
                 return false;
            }
            $stmt_role->close();
        }

        $role_id = $_SESSION['role_id'];
        $_SESSION['permissions'] = [];
        if($role_id){
            $sql = "SELECT p.permission_name
                    FROM role_permissions rp
                    JOIN permissions p ON rp.permission_id = p.id
                    WHERE rp.role_id = ?";

            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param("i", $role_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $_SESSION['permissions'][] = $row['permission_name'];
                }
                $stmt->close();
            }
        }
    }

    // Check if the user has the required permission
    return in_array($permission_name, $_SESSION['permissions']);
}

function require_permission($permission_name) {
    if (!has_permission($permission_name)) {
        // You can either redirect or show an error message
        die('<div class="alert alert-danger m-3"><strong>Access Denied!</strong> You do not have permission to view this page.</div>');
    }
}
?>
