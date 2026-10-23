<?php
/**
 * Customer sign-in.
 *
 * 8.9.0 removes the legacy "?email=…&license_key=…" GET sign-in: a licence
 * key in a URL ends up in access logs, Referer headers and browser history,
 * and it let a crafted link sign a visitor into somebody else's dashboard.
 * Sign-in is POST-only, CSRF-protected and throttled.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

$error = '';

if (!empty($_SESSION['user_email'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $license_key = trim((string) ($_POST['license_key'] ?? ''));

    $bucket = lm_rate_limit_ip('user-login', strtolower($email));
    $state = lm_rate_limit($bucket, 8, 900, false);

    if (!lm_verify_csrf()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!$state['allowed']) {
        $minutes = max(1, (int) ceil($state['retry_after'] / 60));
        $error = 'Too many attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
        http_response_code(429);
    } elseif ($email === '' || $license_key === '') {
        $error = 'Please enter both email and your key/reference.';
    } else {
        require_once('../db.php');

        try {
            $stmt = $pdo->prepare('SELECT id, customer_email FROM licenses WHERE customer_email = ? AND license_key = ? LIMIT 1');
            $stmt->execute(array($email, $license_key));
            $license = $stmt->fetch(PDO::FETCH_ASSOC);

            $request = null;
            if (!$license) {
                $stmt_req = $pdo->prepare('SELECT id, customer_email FROM license_requests WHERE customer_email = ? AND request_ref = ? LIMIT 1');
                $stmt_req->execute(array($email, $license_key));
                $request = $stmt_req->fetch(PDO::FETCH_ASSOC);
            }

            if ($license || $request) {
                lm_session_start(true);
                $_SESSION['user_email'] = $email;
                if ($license) {
                    $_SESSION['user_license_id'] = (int) $license['id'];
                }
                unset($_SESSION['lm_csrf']);
                lm_csrf_token();
                lm_rate_limit_reset($bucket);
                header('Location: index.php');
                exit();
            }

            lm_rate_limit($bucket, 8, 900, true);
            $error = 'Invalid email or License Key / Request Reference.';
        } catch (PDOException $e) {
            $error = 'A database error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Login - License Manager</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .container {
            width: 100%;
            max-width: 450px;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .card-header {
            padding: 3rem 2rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-align: center;
        }
        .card-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .card-header p {
            font-size: 1rem;
            opacity: 0.9;
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
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #fca5a5;
        }
        .submit-btn {
            width: 100%;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            color: #cbd5e1;
            font-size: 0.9rem;
        }
        .help-link {
            display: block;
            text-align: center;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .help-link:hover {
            color: #764ba2;
        }
        .back-link {
            display: inline-block;
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }
        .back-link:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        @media (max-width: 480px) {
            .card-header h1 {
                font-size: 1.5rem;
            }
            .card-header,
            .card-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-link">← Back to Home</a>
        
        <div class="card">
            <div class="card-header">
                <h1>🔐 User Login</h1>
                <p>Access your licenses and account</p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="error">✗ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">
                    <?= lm_csrf_field() ?>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="Enter your email" required>
                    </div>

                    <div class="form-group">
                        <label for="license_key">License Key / Request Reference</label>
                        <input type="text" id="license_key" name="license_key" placeholder="Enter your license key or request reference" required>
                    </div>

                    <button type="submit" class="submit-btn">🔓 Login</button>
                </form>

                <div class="divider">OR</div>

                <a href="../order.php" class="help-link">📦 Request a New License</a>
            </div>
        </div>
    </div>
</body>
</html>
