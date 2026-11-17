<?php
// php-version/reset-password.php
require_once 'includes/functions.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

if (!$token) {
    // In a real app, we'd validate the token against the database
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        // In a real app, we'd update the password in the database for the user associated with the token
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Payhub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@0.321.0/font/lucide.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex items-center justify-center p-8">
    <?php if ($success): ?>
        <div class="max-w-md w-full bg-white p-10 rounded-[2.5rem] shadow-xl shadow-slate-200/50 border border-slate-100 text-center">
            <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-8">
                <i class="lucide-check-circle-2 text-emerald-600 w-10 h-10"></i>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 mb-4">Password reset!</h1>
            <p class="text-slate-500 mb-8 leading-relaxed">
                Your password has been successfully reset. You can now log in with your new password.
            </p>
            <a href="login.php" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200 flex items-center justify-center gap-2">
                Log in now <i class="lucide-arrow-right w-5 h-5"></i>
            </a>
        </div>
    <?php elseif (!$token): ?>
        <div class="max-w-md w-full bg-white p-10 rounded-[2.5rem] shadow-xl shadow-slate-200/50 border border-slate-100 text-center">
            <h1 class="text-3xl font-bold text-slate-900 mb-4">Invalid Link</h1>
            <p class="text-slate-500 mb-8 leading-relaxed">
                This password reset link is invalid or has expired.
            </p>
            <a href="forgot-password.php" class="text-indigo-600 font-bold hover:text-indigo-700">
                Request a new link
            </a>
        </div>
    <?php else: ?>
        <div class="max-w-md w-full">
            <div class="text-center mb-10">
                <a href="index.php" class="inline-flex items-center gap-2 mb-8">
                    <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center">
                        <i class="lucide-credit-card text-white w-6 h-6"></i>
                    </div>
                    <span class="text-2xl font-bold tracking-tight text-slate-900">Payhub</span>
                </a>
                <h1 class="text-3xl font-bold text-slate-900">Reset password</h1>
                <p class="text-slate-500 mt-2">Enter your new password below.</p>
            </div>

            <div class="bg-white p-8 rounded-[2.5rem] shadow-xl shadow-slate-200/50 border border-slate-100">
                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-600 rounded-2xl text-sm font-medium border border-red-100">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">New Password</label>
                        <div class="relative">
                            <i class="lucide-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i>
                            <input 
                                type="password" 
                                name="password"
                                required
                                class="w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                placeholder="••••••••"
                            >
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Confirm New Password</label>
                        <div class="relative">
                            <i class="lucide-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i>
                            <input 
                                type="password" 
                                name="confirm_password"
                                required
                                class="w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                placeholder="••••••••"
                            >
                        </div>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200 flex items-center justify-center gap-2"
                    >
                        Reset Password
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>
