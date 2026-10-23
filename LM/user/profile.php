<?php
session_start();

$user_email = $_SESSION['user_email'] ?? null;

if (!$user_email) {
    header('Location: login.php');
    exit();
}

require_once('../db.php');

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $company = $_POST['company'] ?? '';

    if (empty($name)) {
        $error_message = 'Name cannot be empty.';
    } else {
        // In a real application, you would store this in a users table
        $_SESSION['user_name'] = $name;
        $_SESSION['user_phone'] = $phone;
        $_SESSION['user_company'] = $company;
        $success_message = 'Profile updated successfully!';
    }
}

$user_name = $_SESSION['user_name'] ?? '';
$user_phone = $_SESSION['user_phone'] ?? '';
$user_company = $_SESSION['user_company'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .navbar h1 {
            font-size: 1.5rem;
            color: #667eea;
            margin: 0;
        }
        .navbar-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        .navbar-links a {
            color: #475569;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .navbar-links a:hover {
            color: #667eea;
        }
        .logout-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);
        }
        .container {
            max-width: 600px;
            margin: 3rem auto;
            padding: 2rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .card-header {
            padding: 2rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .card-header h2 {
            margin: 0;
            font-size: 1.75rem;
        }
        .card-body {
            padding: 2rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1e293b;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-group input[readonly] {
            background: #f8fafc;
            cursor: not-allowed;
        }
        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            flex-grow: 1;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
            flex-grow: 1;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
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
        .info-box {
            background: #eef2ff;
            border-left: 4px solid #667eea;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }
        .info-box p {
            color: #4c1d95;
            margin: 0;
        }
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 1rem;
            }
            .navbar-links {
                flex-direction: column;
                width: 100%;
                gap: 0.5rem;
            }
            .navbar-links a,
            .logout-btn {
                width: 100%;
                text-align: center;
            }
            .container {
                margin: 1rem;
                padding: 1rem;
            }
            .button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔐 License Manager</h1>
        <div class="navbar-links">
            <a href="index.php">📜 My Licenses</a>
            <a href="profile.php">👤 Profile</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2>👤 My Profile</h2>
            </div>
            <div class="card-body">
                <?php if ($success_message): ?>
                    <div class="success">✓ <?= htmlspecialchars($success_message) ?></div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="error">✗ <?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>

                <div class="info-box">
                    <p><strong>Email:</strong> <?= htmlspecialchars($user_email) ?></p>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($user_name) ?>" placeholder="Enter your full name">
                    </div>

                    <div class="form-group">
                        <label for="company">Company</label>
                        <input type="text" id="company" name="company" value="<?= htmlspecialchars($user_company) ?>" placeholder="Enter your company name">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user_phone) ?>" placeholder="Enter your phone number">
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                        <a href="index.php" class="btn btn-secondary">← Back to Licenses</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
