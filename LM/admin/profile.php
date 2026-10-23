<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

$success_message = '';
$error = '';
$current_username = '';

// We must have an admin_id in the session now, set by the login page.
if (!isset($_SESSION['admin_id'])) {
    // If not, something is wrong, maybe the user session is old.
    // Forcing a re-login is the safest option.
    header('Location: logout.php');
    exit();
}
$admin_id = $_SESSION['admin_id'];

// Get current admin username to display in the form
try {
    $stmt = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        $current_username = $admin['username'];
    } else {
        // This case is unlikely if session is valid, but good to handle.
        $error = "Could not find your admin profile.";
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = $_POST['username'];
    $new_password = $_POST['password'];
    $confirm_password = $_POST['password_confirm'];

    if (empty($new_username)) {
        $error = "Username cannot be empty.";
    } elseif (!empty($new_password) && $new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            if (!empty($new_password)) {
                // Update both username and password
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
                $stmt->execute([$new_username, $password_hash, $admin_id]);
                $success_message = 'Profile updated successfully.';
            } else {
                // Update only username
                $stmt = $pdo->prepare("UPDATE admins SET username = ? WHERE id = ?");
                $stmt->execute([$new_username, $admin_id]);
                $success_message = 'Username updated successfully.';
            }
            // Update the username displayed on the form
            $current_username = $new_username;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
                $error = "That username is already taken.";
            } else {
                $error = "A database error occurred: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        .sidebar h1 {
            font-size: 1.5rem;
            padding: 2rem 1.5rem;
            text-align: center;
            background: rgba(15, 23, 42, 0.5);
            margin: 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar nav {
            flex-grow: 1;
            padding: 2rem 0;
        }
        .sidebar nav a {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }
        .sidebar nav a:hover,
        .sidebar nav a.active {
            background: rgba(59, 130, 246, 0.2);
            color: #fff;
            border-left: 3px solid #3b82f6;
            padding-left: calc(1.5rem - 3px);
        }
        .main-content {
            margin-left: 260px;
            flex-grow: 1;
            padding: 2rem;
            overflow-y: auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .header h2 {
            font-size: 2rem;
            color: #1e293b;
            font-weight: 700;
        }
        .btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
        }
        .card-header h3 {
            font-size: 1.25rem;
            color: #1e293b;
            margin: 0;
            font-weight: 700;
        }
        .card-body {
            padding: 2rem;
        }
        .input-group {
            margin-bottom: 1.5rem;
        }
        .input-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }
        .input-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }
        .input-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #a7f3d0;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #fca5a5;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
            .header h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="licenses.php">📜 Licenses</a>
            <a href="updates.php">🚀 Push Updates</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php" class="active">👤 Profile</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main-content">
        <div class="header">
            <h2>👤 Admin Profile</h2>
        </div>
        <div class="card">
            <div class="card-header"><h3>Update Your Details</h3></div>
            <div class="card-body">
                <?php if (!empty($success_message)): ?>
                    <div class="success">✓ <?= htmlspecialchars($success_message) ?></div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                    <div class="error">✗ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="input-group">
                        <label>Username</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($current_username) ?>" required>
                    </div>
                    <div class="input-group">
                        <label>New Password (leave blank to keep current password)</label>
                        <input type="password" name="password">
                    </div>
                    <div class="input-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="password_confirm">
                    </div>
                    <button type="submit" class="btn">💾 Update Profile</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
