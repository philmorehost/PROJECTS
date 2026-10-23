<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Submitted - License Manager</title>
    <style>body{font-family:Inter, sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;height:100vh} .card{background:#fff;padding:2rem;border-radius:8px;box-shadow:0 10px 20px rgba(0,0,0,0.06);max-width:640px;text-align:center}</style>
</head>
<body>
    <div class="card">
        <h1>✅ Request Submitted</h1>
        <p>Your license request has been received. An administrator will review and approve it shortly.</p>
        <p>Reference: <strong><?= htmlspecialchars($_GET['ref'] ?? '') ?></strong></p>
        <a href="user/login.php" style="display:inline-block;margin-top:1rem;padding:0.6rem 1rem;background:#3b82f6;color:#fff;border-radius:6px;text-decoration:none;">Go to Login</a>
    </div>
</body>
</html>
