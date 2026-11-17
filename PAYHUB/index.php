<?php
// php-version/index.php
require_once 'includes/functions.php';

if (!isInstalled()) {
    header("Location: install/index.php");
    exit;
}

$pageTitle = 'Payhub - Modern Payments for Ambitious Businesses';
include 'includes/header.php';
?>

    <!-- Hero Section -->
    <section class="pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div class="max-w-2xl">
                    <h1 class="text-5xl lg:text-7xl font-bold tracking-tight text-slate-900 leading-[1.1] mb-6">
                        Modern payments for <span class="text-indigo-600">ambitious</span> businesses.
                    </h1>
                    <p class="text-xl text-slate-600 mb-10 leading-relaxed">
                        Payhub helps businesses in Africa get paid by anyone, anywhere in the world. 
                        Start accepting payments in minutes with our robust API and no-code tools.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="register.php" class="bg-indigo-600 text-white px-8 py-4 rounded-full text-lg font-semibold hover:bg-indigo-700 transition-all shadow-xl shadow-indigo-200 flex items-center justify-center gap-2">
                            Get Started Now <i data-lucide="arrow-right" class="w-5 h-5"></i>
                        </a>
                        <a href="support.php" class="bg-slate-50 text-slate-900 px-8 py-4 rounded-full text-lg font-semibold hover:bg-slate-100 transition-all flex items-center justify-center gap-2">
                            Contact Sales
                        </a>
                    </div>
                    
                </div>
                <div class="relative">
                    <div class="absolute -inset-4 bg-indigo-100 rounded-[2rem] blur-3xl opacity-30 animate-pulse"></div>
                    <img 
                        src="assets/payhub-payment-methods.jpg"
                        alt="Payment Methods"
                        class="relative rounded-2xl shadow-2xl border border-slate-200"
                        onerror="this.src='https://picsum.photos/seed/payhub/800/600'"
                    >
                </div>
            </div>
        </div>
    </section>

    <!-- Payment Methods Section -->
    <section class="py-12 bg-white border-y border-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-center items-center gap-12 grayscale opacity-50 hover:grayscale-0 hover:opacity-100 transition-all duration-500">
                <div class="flex items-center gap-2">
                    <i data-lucide="credit-card" class="w-8 h-8 text-slate-900"></i>
                    <span class="font-bold text-slate-900">MasterCard</span>
                </div>
                <div class="flex items-center gap-2">
                    <i data-lucide="credit-card" class="w-8 h-8 text-slate-900"></i>
                    <span class="font-bold text-slate-900">VISA</span>
                </div>
                <div class="flex items-center gap-2">
                    <i data-lucide="credit-card" class="w-8 h-8 text-slate-900"></i>
                    <span class="font-bold text-slate-900">Verve</span>
                </div>
                <div class="flex items-center gap-2">
                    <i data-lucide="building-2" class="w-8 h-8 text-slate-900"></i>
                    <span class="font-bold text-slate-900">Bank Transfer</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-24 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-20">
            <div class="bg-indigo-600 rounded-[3rem] p-12 lg:p-20 text-white flex flex-col lg:flex-row items-center gap-12 overflow-hidden relative shadow-2xl shadow-indigo-200">
                <div class="relative z-10 lg:w-1/2">
                    <h2 class="text-4xl font-bold mb-6">Accepted Payment Methods</h2>
                    <p class="text-indigo-100 text-lg mb-8">We support a wide range of payment methods to ensure your customers can pay you with ease, no matter where they are.</p>
                    <ul class="space-y-4">
                        <li class="flex items-center gap-3"><i data-lucide="check-circle-2" class="text-indigo-300 w-5 h-5"></i> Local & International Cards</li>
                        <li class="flex items-center gap-3"><i data-lucide="check-circle-2" class="text-indigo-300 w-5 h-5"></i> Direct Bank Transfers</li>
                        <li class="flex items-center gap-3"><i data-lucide="check-circle-2" class="text-indigo-300 w-5 h-5"></i> USSD & QR Codes</li>
                    </ul>
                </div>
                <div class="lg:w-1/2 relative">
                    <img src="assets/payment-methods.jpg" alt="Supported Methods" class="rounded-2xl shadow-lg rotate-3 hover:rotate-0 transition-transform duration-500" onerror="this.src='https://picsum.photos/seed/methods/600/400'">
                </div>
                <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full -mr-32 -mt-32 blur-3xl"></div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-20">
                <h2 class="text-sm font-bold text-indigo-600 uppercase tracking-widest mb-4">Why Payhub?</h2>
                <p class="text-4xl font-bold text-slate-900 mb-6">Everything you need to grow your business</p>
                <p class="text-lg text-slate-600">From startups to global corporations, Payhub provides the tools to scale your financial operations.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white p-10 rounded-3xl border border-slate-100 hover:shadow-xl transition-all group">
                    <div class="mb-6 p-4 bg-indigo-50 rounded-2xl w-fit group-hover:scale-110 transition-transform">
                        <i data-lucide="zap" class="w-8 h-8 text-indigo-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4">Fast Integration</h3>
                    <p class="text-slate-600 leading-relaxed">Get up and running in minutes with our well-documented APIs and SDKs.</p>
                </div>
                <div class="bg-white p-10 rounded-3xl border border-slate-100 hover:shadow-xl transition-all group">
                    <div class="mb-6 p-4 bg-indigo-50 rounded-2xl w-fit group-hover:scale-110 transition-transform">
                        <i data-lucide="shield" class="w-8 h-8 text-indigo-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4">Secure Payments</h3>
                    <p class="text-slate-600 leading-relaxed">PCI-DSS Level 1 compliant infrastructure with advanced fraud detection.</p>
                </div>
                <div class="bg-white p-10 rounded-3xl border border-slate-100 hover:shadow-xl transition-all group">
                    <div class="mb-6 p-4 bg-indigo-50 rounded-2xl w-fit group-hover:scale-110 transition-transform">
                        <i data-lucide="bar-chart-3" class="w-8 h-8 text-indigo-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-4">Deep Insights</h3>
                    <p class="text-slate-600 leading-relaxed">Understand your customers with real-time analytics and custom reports.</p>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>
