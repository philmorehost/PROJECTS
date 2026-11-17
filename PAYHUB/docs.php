<?php
// php-version/docs.php
require_once 'includes/functions.php';
$pageTitle = 'Documentation - Payhub';
include 'includes/header.php';
?>
<div class="pt-20 flex min-h-screen">
    <aside class="w-64 border-r border-slate-100 p-8 hidden md:block sticky top-20 h-[calc(100vh-80px)] overflow-y-auto">
        <h3 class="font-bold text-slate-900 mb-6 uppercase text-xs tracking-widest">Documentation</h3>
        <nav class="space-y-4">
            <a href="#getting-started" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Getting Started</a>
            <a href="#integration-guide" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Integration Guide</a>
            <a href="#payment-methods" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Payment Methods</a>
            <a href="#webhooks" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium">Webhooks</a>
            <a href="api-reference.php" class="block text-sm text-slate-600 hover:text-indigo-600 font-medium border-t border-slate-100 pt-4">API Reference</a>
        </nav>
    </aside>
    <main class="flex-1 p-8 lg:p-16 max-w-4xl">
        <section id="getting-started" class="mb-20 scroll-mt-24">
            <h1 class="text-4xl font-extrabold text-slate-900 mb-8">Getting Started</h1>
            <p class="text-lg text-slate-600 mb-8 leading-relaxed">
                Welcome to Payhub! This guide will help you set up your account and start accepting payments.
                Payhub provides a comprehensive suite of payment tools designed for businesses of all sizes.
            </p>

            <div class="grid md:grid-cols-2 gap-6 mb-12">
                <div class="p-6 bg-white border border-slate-100 rounded-3xl shadow-sm">
                    <h3 class="font-bold text-slate-900 mb-2">1. Create an Account</h3>
                    <p class="text-sm text-slate-500">Sign up for a free merchant account and get your API keys immediately.</p>
                </div>
                <div class="p-6 bg-white border border-slate-100 rounded-3xl shadow-sm">
                    <h3 class="font-bold text-slate-900 mb-2">2. Complete KYC</h3>
                    <p class="text-sm text-slate-500">Submit your identification documents to move from test mode to live payments.</p>
                </div>
            </div>
        </section>

        <section id="integration-guide" class="mb-20 scroll-mt-24">
            <h2 class="text-3xl font-bold text-slate-900 mb-6">Integration Guide</h2>
            <p class="text-slate-600 mb-8 leading-relaxed">
                Integrating Payhub is simple. You can use our pre-built checkout page,
                our inline popup, or build a custom flow using our server-side APIs.
            </p>
            <div class="space-y-6">
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600 font-bold shrink-0">1</div>
                    <div>
                        <h4 class="font-bold text-slate-900">Choose your integration path</h4>
                        <p class="text-sm text-slate-500">Most developers start with <strong>Payhub Inline</strong> for the easiest setup.</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600 font-bold shrink-0">2</div>
                    <div>
                        <h4 class="font-bold text-slate-900">Add the script to your site</h4>
                        <p class="text-sm text-slate-500">Include our lightweight JavaScript library on your checkout page.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="payment-methods" class="mb-20 scroll-mt-24">
            <h2 class="text-3xl font-bold text-slate-900 mb-6">Payment Methods</h2>
            <p class="text-slate-600 mb-8">We support a variety of payment methods to ensure your customers can pay you easily.</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php
                $methods = [
                    ['icon' => 'credit-card', 'name' => 'Cards'],
                    ['icon' => 'landmark', 'name' => 'Bank Transfer'],
                    ['icon' => 'phone', 'name' => 'USSD'],
                    ['icon' => 'wallet', 'name' => 'Mobile Money']
                ];
                foreach($methods as $m):
                ?>
                <div class="p-6 bg-slate-50 rounded-2xl text-center">
                    <i class="lucide-<?php echo $m['icon']; ?> text-indigo-600 mb-2"></i>
                    <p class="text-xs font-bold text-slate-900"><?php echo $m['name']; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section id="webhooks" class="mb-20 scroll-mt-24">
            <h2 class="text-3xl font-bold text-slate-900 mb-6">Webhooks</h2>
            <p class="text-slate-600 mb-6 leading-relaxed">
                Webhooks allow your server to receive real-time notifications about events in your Payhub account.
                When an event occurs, we send an HTTP POST request to the URL you configured in your dashboard.
            </p>
            <div class="bg-amber-50 border border-amber-100 p-6 rounded-2xl flex gap-4">
                <i class="lucide-shield-check text-amber-600"></i>
                <p class="text-sm text-amber-800">
                    <strong>Security:</strong> Always verify the signature of the webhook request to ensure it came from Payhub.
                </p>
            </div>
        </section>
    </main>
</div>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
<?php include 'includes/footer.php'; ?>
