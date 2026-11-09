<?php
// php-version/register.php
require_once 'includes/functions.php';

if (isLoggedIn()) {
    redirect('merchant/dashboard.php');
}

// Allow user to restart from step 1
if (isset($_GET['clear_session'])) {
    unset($_SESSION['pending_registration']);
    redirect('register.php');
}

$error = '';
$step = 'form'; // 'form' | 'otp'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'send_otp';

        if ($action === 'send_otp') {
            $email         = sanitize($_POST['email'] ?? '');
            $password      = $_POST['password'] ?? '';
            $full_name     = sanitize($_POST['full_name'] ?? '');
            $business_name = sanitize($_POST['business_name'] ?? '');

            // Anti-bot guards: hidden honeypot field must be empty,
            // and the form can't be legitimately submitted within 3s of render.
            if (!empty($_POST['company_url']) || (isset($_SESSION['register_form_ts']) && (time() - (int)$_SESSION['register_form_ts']) < 3)) {
                log_email_abuse($email ?: 'unknown', 'REGISTER_HONEYPOT_BLOCK');
                // Fake success so the bot learns nothing.
                $_SESSION['pending_registration'] = [
                    'email'         => 'blocked@invalid.local',
                    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    'full_name'     => 'Blocked',
                    'business_name' => 'Blocked',
                ];
                $step = 'otp';
            } elseif (is_burner_email($email)) {
                $error = 'Please use a valid, deliverable email address.';
                log_email_abuse($email, 'REGISTER_BURNER_EMAIL');
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters';
            } elseif (empty($full_name)) {
                $error = 'Full name is required';
            } elseif (empty($business_name)) {
                $error = 'Business name is required';
            } else {
                $db   = Database::connect();
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'Email already registered';
                } elseif (!email_send_allowed($email, 'registration')) {
                    $error = 'Too many verification attempts. Please try again later.';
                    log_email_abuse($email, 'REGISTER_RATE_LIMITED');
                } else {
                    // Store pending registration in session
                    $_SESSION['pending_registration'] = [
                        'email'         => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'full_name'     => $full_name,
                        'business_name' => $business_name,
                    ];

                    if (generate_and_send_otp($email, 'registration')) {
                        $step = 'otp';
                    } else {
                        $error = 'Failed to send verification email. Please try again.';
                        unset($_SESSION['pending_registration']);
                    }
                }
            }
        } elseif ($action === 'verify_otp') {
            $otp = trim($_POST['otp'] ?? '');

            if (empty($_SESSION['pending_registration'])) {
                $error = 'Session expired. Please start over.';
            } else {
                $pending = $_SESSION['pending_registration'];
                $email   = $pending['email'];

                if (verify_otp($email, $otp, 'registration')) {
                    $db         = Database::connect();
                    $public_key = generateApiKey('pk_live_');
                    $secret_key = generateApiKey('sk_live_');
                    $test_pk    = generateApiKey('pk_test_');
                    $test_sk    = generateApiKey('sk_test_');

                    $stmt = $db->prepare("INSERT INTO users (email, password_hash, full_name, business_name, public_key, secret_key, test_public_key, test_secret_key, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'merchant')");
                    if ($stmt->execute([$email, $pending['password_hash'], $pending['full_name'], $pending['business_name'], $public_key, $secret_key, $test_pk, $test_sk])) {
                        unset($_SESSION['pending_registration']);
                        redirect('login.php?registered=1');
                    } else {
                        $error = 'Registration failed. Please try again.';
                        $step  = 'otp';
                    }
                } else {
                    $error = 'Invalid or expired verification code. Please try again.';
                    $step  = 'otp';
                }
            }
        } elseif ($action === 'resend_otp') {
            if (empty($_SESSION['pending_registration'])) {
                $error = 'Session expired. Please start over.';
            } elseif (!email_send_allowed($_SESSION['pending_registration']['email'], 'registration')) {
                $error = 'Too many verification attempts. Please try again later.';
                log_email_abuse($_SESSION['pending_registration']['email'], 'REGISTER_RESEND_RATE_LIMITED');
            } else {
                $email = $_SESSION['pending_registration']['email'];
                if (generate_and_send_otp($email, 'registration')) {
                    $step = 'otp';
                } else {
                    $error = 'Failed to resend code. Please try again.';
                    $step  = 'otp';
                }
            }
        }
    }
} elseif (isset($_SESSION['pending_registration'])) {
    // Resume OTP step if user navigates back
    $step = 'otp';
}

// Guard: if we somehow reach the OTP step without session data, fall back to form
if ($step === 'otp' && empty($_SESSION['pending_registration'])) {
    $step = 'form';
}

$pending_email = htmlspecialchars($_SESSION['pending_registration']['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Payhub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@0.321.0/font/lucide.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex">
    <!-- Left Side - Form -->
    <div class="flex-1 flex items-center justify-center p-8">
        <div class="max-w-md w-full">
            <div class="mb-10">
                <a href="index.php" class="inline-flex items-center gap-2 mb-8">
                    <?php $logo = getConfig('site_logo'); ?>
                    <?php if ($logo): ?>
                        <img src="<?php echo BASE_URL; ?>uploads/<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="h-12 object-contain">
                    <?php else: ?>
                        <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center">
                            <i data-lucide="credit-card" class="text-white w-6 h-6"></i>
                        </div>
                    <?php endif; ?>
                    <span class="text-2xl font-bold tracking-tight text-slate-900"><?php echo htmlspecialchars(getConfig('site_name', 'Payhub')); ?></span>
                </a>
                <?php if ($step === 'otp'): ?>
                    <h1 class="text-3xl font-bold text-slate-900">Verify your email</h1>
                    <p class="text-slate-500 mt-2">Enter the 6-digit code sent to <strong><?php echo $pending_email; ?></strong>.</p>
                <?php else: ?>
                    <h1 class="text-3xl font-bold text-slate-900">Create your account</h1>
                    <p class="text-slate-500 mt-2">Start accepting payments in minutes.</p>
                <?php endif; ?>
            </div>

            <div class="bg-white p-8 rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-100">
                <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-600 rounded-2xl text-sm font-medium border border-red-100">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'otp'): ?>
                <!-- Step 2: OTP Verification -->
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="verify_otp">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Verification Code</label>
                        <input
                            type="text"
                            name="otp"
                            required
                            maxlength="6"
                            pattern="\d{6}"
                            autofocus
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-center text-2xl tracking-[0.5em] font-bold"
                            placeholder="000000"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200 flex items-center justify-center gap-2"
                    >
                        Verify &amp; Create Account <i class="lucide-arrow-right w-5 h-5"></i>
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="resend_otp">
                        <button type="submit" class="text-sm text-indigo-600 font-bold hover:text-indigo-700">
                            Resend code
                        </button>
                    </form>
                    <span class="text-slate-300 mx-2">|</span>
                    <a href="register.php?clear_session=1" class="text-sm text-slate-500 hover:text-slate-700">Start over</a>
                </div>

                <?php else: ?>
                <!-- Step 1: Registration Form -->
                <?php $_SESSION['register_form_ts'] = time(); ?>
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="send_otp">
                    <!-- Honeypot: hidden from humans, auto-filled by bots. Must stay empty. -->
                    <div class="hidden" aria-hidden="true">
                        <label for="company_url">Leave this field empty</label>
                        <input type="text" id="company_url" name="company_url" tabindex="-1" autocomplete="off" value="">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Full Name</label>
                            <input
                                type="text"
                                name="full_name"
                                required
                                class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                placeholder="John Doe"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Business Name</label>
                            <input
                                type="text"
                                name="business_name"
                                required
                                class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                placeholder="Acme Inc."
                            >
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Email Address</label>
                        <input
                            type="email"
                            name="email"
                            required
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                            placeholder="name@company.com"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Password</label>
                        <input
                            type="password"
                            name="password"
                            required
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                            placeholder="••••••••"
                        >
                    </div>

                    <div class="flex items-start gap-3 py-2">
                        <input type="checkbox" required class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <p class="text-xs text-slate-500 leading-relaxed">
                            I agree to the <a href="terms.php" target="_blank" class="text-indigo-600 font-bold">Terms of Service</a> and <a href="privacy.php" target="_blank" class="text-indigo-600 font-bold">Privacy Policy</a>.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200 flex items-center justify-center gap-2"
                    >
                        Continue <i class="lucide-arrow-right w-5 h-5"></i>
                    </button>
                </form>
                <?php endif; ?>

                <div class="mt-8 pt-8 border-t border-slate-100 text-center">
                    <p class="text-slate-500">
                        Already have an account? <a href="login.php" class="text-indigo-600 font-bold hover:text-indigo-700">Log in</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Side - Features -->
    <div class="hidden lg:flex flex-1 bg-indigo-600 p-12 items-center justify-center relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-full opacity-10">
            <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-white rounded-full blur-[120px]"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-white rounded-full blur-[120px]"></div>
        </div>

        <div class="max-w-md relative z-10">
            <h2 class="text-4xl font-bold text-white mb-12 leading-tight">Join thousands of businesses growing with Payhub</h2>
            <div class="space-y-8 text-white">
                <div class="flex gap-4">
                    <div class="mt-1">
                        <i class="lucide-check-circle-2 text-indigo-300 w-6 h-6"></i>
                    </div>
                    <div>
                        <h4 class="text-lg font-bold mb-1">Accept payments globally</h4>
                        <p class="text-indigo-100 text-sm leading-relaxed">Support for cards, bank transfers, USSD, and mobile money in 50+ countries.</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="mt-1">
                        <i class="lucide-check-circle-2 text-indigo-300 w-6 h-6"></i>
                    </div>
                    <div>
                        <h4 class="text-lg font-bold mb-1">Get paid on time</h4>
                        <p class="text-indigo-100 text-sm leading-relaxed">Automated settlements directly to your bank account with transparent fees.</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="mt-1">
                        <i class="lucide-check-circle-2 text-indigo-300 w-6 h-6"></i>
                    </div>
                    <div>
                        <h4 class="text-lg font-bold mb-1">Best-in-class security</h4>
                        <p class="text-indigo-100 text-sm leading-relaxed">PCI-DSS Level 1 compliance and advanced fraud monitoring built-in.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
