<?php
// php-version/forgot-password.php
require_once 'includes/functions.php';

$error = '';
$success = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // In a real app, we'd check if email exists and send a token
        // For this demo, we'll just show success
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Payhub</title>
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
            <h1 class="text-3xl font-bold text-slate-900 mb-4">Check your email</h1>
            <p class="text-slate-500 mb-8 leading-relaxed">
                We've sent a password reset link to <span class="font-bold text-slate-900"><?php echo htmlspecialchars($email); ?></span>. Please check your inbox and follow the instructions.
            </p>
            <a href="login.php" class="inline-flex items-center gap-2 text-indigo-600 font-bold hover:text-indigo-700">
                Back to login <i class="lucide-arrow-right w-5 h-5"></i>
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
                <h1 class="text-3xl font-bold text-slate-900">Forgot password?</h1>
                <p class="text-slate-500 mt-2">No worries, we'll send you reset instructions.</p>
            </div>

            <div class="bg-white p-8 rounded-[2.5rem] shadow-xl shadow-slate-200/50 border border-slate-100">
                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-600 rounded-2xl text-sm font-medium border border-red-100">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-6">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Email Address</label>
                        <div class="relative">
                            <i class="lucide-mail absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i>
                            <input 
                                type="email" 
                                name="email"
                                required
                                value="<?php echo htmlspecialchars($email); ?>"
                                class="w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                placeholder="name@company.com"
                            >
                        </div>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200 flex items-center justify-center gap-2"
                    >
                        Send Reset Link
                    </button>
                </form>

                <div class="mt-8 text-center">
                    <a href="login.php" class="text-slate-500 hover:text-slate-700 text-sm font-medium">
                        Wait, I remember my password! <span class="text-indigo-600 font-bold">Log in</span>
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>
