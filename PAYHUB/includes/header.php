<?php
// php-version/header.php
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Payhub - Modern Payments'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php $logo = getConfig('site_logo'); ?>
    <?php if ($logo): ?>
        <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>uploads/<?php echo $logo; ?>">
    <?php endif; ?>
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('nav', {
                mobileMenuOpen: false,
                toggle() { this.mobileMenuOpen = !this.mobileMenuOpen }
            })
        })
    </script>
</head>
<body class="bg-white text-slate-900" x-data>
    <nav class="fixed top-0 w-full bg-white/80 backdrop-blur-md z-50 border-b border-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <a href="<?php echo BASE_URL; ?>index.php" class="flex items-center gap-2">
                    <?php $logo = getConfig('site_logo'); ?>
                    <?php if ($logo): ?>
                        <img src="<?php echo BASE_URL; ?>uploads/<?php echo $logo; ?>" alt="Logo" class="h-10 object-contain">
                    <?php else: ?>
                        <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center">
                            <i data-lucide="credit-card" class="text-white w-6 h-6"></i>
                        </div>
                    <?php endif; ?>
                    <span class="text-2xl font-bold tracking-tight text-slate-900"><?php echo getConfig('site_name', 'Payhub'); ?></span>
                </a>
                
                <div class="hidden md:flex items-center gap-8">
                    <a href="<?php echo BASE_URL; ?>pricing.php" class="text-sm font-medium text-slate-600 hover:text-indigo-600 transition-colors">Pricing</a>
                    <a href="<?php echo BASE_URL; ?>docs.php" class="text-sm font-medium text-slate-600 hover:text-indigo-600 transition-colors">Developers</a>
                    <a href="<?php echo BASE_URL; ?>blog.php" class="text-sm font-medium text-slate-600 hover:text-indigo-600 transition-colors">Blog</a>
                    <?php if (isLoggedIn()): ?>
                        <a href="<?php echo BASE_URL; ?>merchant/dashboard.php" class="text-sm font-medium text-slate-600 hover:text-indigo-600 transition-colors">Dashboard</a>
                        <a href="<?php echo BASE_URL; ?>logout.php" class="bg-indigo-600 text-white px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200">Logout</a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>login.php" class="text-sm font-medium text-slate-600 hover:text-indigo-600 transition-colors">Login</a>
                        <a href="<?php echo BASE_URL; ?>register.php" class="bg-indigo-600 text-white px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200">Create free account</a>
                    <?php endif; ?>
                </div>

                <button @click="$store.nav.toggle()" class="md:hidden text-slate-600 p-2 relative z-[70]">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div x-show="$store.nav.mobileMenuOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-4"
             class="md:hidden bg-white border-b border-slate-100 p-4 space-y-4 shadow-xl" x-cloak>
            <a href="<?php echo BASE_URL; ?>pricing.php" class="block text-sm font-medium text-slate-600 hover:text-indigo-600">Pricing</a>
            <a href="<?php echo BASE_URL; ?>docs.php" class="block text-sm font-medium text-slate-600 hover:text-indigo-600">Developers</a>
            <a href="<?php echo BASE_URL; ?>blog.php" class="block text-sm font-medium text-slate-600 hover:text-indigo-600">Blog</a>
            <hr class="border-slate-100">
            <?php if (isLoggedIn()): ?>
                <a href="<?php echo BASE_URL; ?>merchant/dashboard.php" class="block text-sm font-medium text-slate-600 hover:text-indigo-600">Dashboard</a>
                <a href="<?php echo BASE_URL; ?>logout.php" class="block text-sm font-bold text-red-500">Logout</a>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>login.php" class="block text-sm font-medium text-slate-600 hover:text-indigo-600">Login</a>
                <a href="<?php echo BASE_URL; ?>register.php" class="block bg-indigo-600 text-white px-6 py-3 rounded-xl text-sm font-bold text-center">Create free account</a>
            <?php endif; ?>
        </div>
    </nav>
