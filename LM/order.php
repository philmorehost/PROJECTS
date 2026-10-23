<?php
session_start();
$settings_file = 'settings.json';
if (!file_exists($settings_file) || !is_readable($settings_file)) {
    die("Configuration error: settings.json not found or not readable.");
}
$settings = json_decode(file_get_contents($settings_file), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Configuration error: Invalid JSON in settings.json.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request License - <?= htmlspecialchars($settings['site_name'] ?? 'License Manager') ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        :root {
            --primary-color: #3b82f6;
            --primary-hover-color: #2563eb;
            --background-color: #f9fafb;
            --card-background: #ffffff;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --input-border: #d1d5db;
            --input-focus-border: #3b82f6;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .order-container {
            background: var(--card-background);
            padding: 2.5rem;
            border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 32rem; /* 512px */
            text-align: center;
        }
        .header img {
            height: 4rem;
            margin: 0 auto 1.5rem;
        }
        .header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        .header p {
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }
        .input-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .input-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .input-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid var(--input-border);
            box-sizing: border-box;
        }
        .submit-btn {
            background-color: var(--primary-color);
            color: #fff;
            font-weight: 600;
            padding: 0.75rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
        }
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            text-align: left;
            border: 1px solid #fca5a5;
        }
    </style>
</head>
<body>
    <div class="order-container">
        <div class="header">
            <?php if (isset($settings['site_logo'])): ?>
                <img src="<?= htmlspecialchars($settings['site_logo']) ?>" alt="Site Logo">
            <?php endif; ?>
            <h1>Request License</h1>
            <p>Submit your details to request your license key. Once approved, you will be able to access it in your dashboard.</p>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <form id="requestForm" method="POST" action="request_license.php">
            <div class="input-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="input-group">
                <label for="domain">Domain Name</label>
                <input type="text" id="domain" name="domain" value="<?= htmlspecialchars($_GET['domain'] ?? '') ?>" placeholder="e.g. example.com" required>
            </div>
            <p style="color: #475569; margin-bottom: 1rem; font-size: 0.95rem;">You will be redirected to your dashboard to monitor the status of your request.</p>
            <button type="submit" class="submit-btn">Request License</button>
        </form>
    </div>
</body>
</html>
