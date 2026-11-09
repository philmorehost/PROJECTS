<?php
// php-version/pricing.php
require_once 'includes/functions.php';

$fee_percent = getConfig('transaction_fee_percent', '1.5');
$fee_flat = getConfig('transaction_fee_flat', '100');
$fee_cap = getConfig('transaction_fee_cap', '2000');
$int_fee_percent = getConfig('international_fee_percent', '3.9');
$int_fee_flat = getConfig('international_fee_flat', '100');

$pageTitle = 'Simple & Transparent Pricing - Payhub';
include 'includes/header.php';
?>

    <section class="pt-32 pb-20 lg:pt-48 lg:pb-32 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-sm font-bold text-indigo-600 uppercase tracking-widest mb-4">Pricing Plans</h2>
            <h1 class="text-4xl lg:text-6xl font-bold text-slate-900 mb-6">Simple pricing for <br>growing businesses.</h1>
            <p class="text-xl text-slate-600 max-w-2xl mx-auto">No hidden fees, no monthly commitments. Pay only for what you process.</p>
        </div>
    </section>

    <section class="pb-24 -mt-16">
        <div class="max-w-4xl mx-auto px-4">
            <div class="bg-white rounded-[3rem] shadow-2xl border border-slate-100 overflow-hidden grid md:grid-cols-2">
                <div class="p-12 lg:p-16 border-b md:border-b-0 md:border-r border-slate-100">
                    <h3 class="text-2xl font-bold text-slate-900 mb-8">Local Payments</h3>
                    <div class="flex items-baseline gap-1 mb-2">
                        <span class="text-5xl font-extrabold text-slate-900"><?php echo $fee_percent; ?>%</span>
                        <span class="text-slate-400 font-bold">+ <?php echo formatCurrency($fee_flat); ?></span>
                    </div>
                    <p class="text-sm text-slate-500 mb-8">per successful transaction</p>

                    <ul class="space-y-4 mb-10">
                        <li class="flex gap-3 text-slate-600 font-medium">
                            <i class="lucide-check text-emerald-500 w-5 h-5"></i>
                            <span>Fee capped at <?php echo formatCurrency($fee_cap); ?></span>
                        </li>
                        <li class="flex gap-3 text-slate-600">
                            <i data-lucide="check" class="text-emerald-500 w-5 h-5"></i>
                            <span><?php echo formatCurrency($fee_flat); ?> fee waived for transactions under ₦2500</span>
                        </li>
                        <li class="flex gap-3 text-slate-600">
                            <i class="lucide-check text-emerald-500 w-5 h-5"></i>
                            <span>All local cards supported</span>
                        </li>
                        <li class="flex gap-3 text-slate-600">
                            <i class="lucide-check text-emerald-500 w-5 h-5"></i>
                            <span>Bank Transfers & USSD</span>
                        </li>
                        <li class="flex gap-3 text-slate-600">
                            <i class="lucide-check text-emerald-500 w-5 h-5"></i>
                            <span>Instant webhook alerts</span>
                        </li>
                    </ul>

                    <a href="register.php" class="block w-full text-center py-4 bg-indigo-600 text-white font-bold rounded-2xl hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Get Started</a>
                </div>
                
                <div class="p-12 lg:p-16 bg-slate-50">
                    <h3 class="text-2xl font-bold text-slate-900 mb-8">International</h3>
                    <div class="flex items-baseline gap-1 mb-2">
                        <span class="text-5xl font-extrabold text-slate-900"><?php echo $int_fee_percent; ?>%</span>
                        <span class="text-slate-400 font-bold">+ <?php echo formatCurrency($int_fee_flat); ?></span>
                    </div>
                    <p class="text-sm text-slate-500 mb-8">per successful transaction</p>

                    <ul class="space-y-4 mb-10">
                        <li class="flex gap-3 text-slate-600">
                            <i class="lucide-check text-indigo-500 w-5 h-5"></i>
                            <span>USD and Global cards</span>
                        </li>
                        <li class="flex gap-3 text-slate-600">
                            <i class="lucide-check text-indigo-500 w-5 h-5"></i>
                            <span>Payout in local currency</span>
                        </li>
                        <li class="flex gap-3 text-slate-600">
                            <i class="lucide-check text-indigo-500 w-5 h-5"></i>
                            <span>Advanced fraud detection</span>
                        </li>
                    </ul>

                    <a href="support.php" class="block w-full text-center py-4 bg-white border border-slate-200 text-slate-900 font-bold rounded-2xl hover:bg-slate-100 transition-all shadow-sm">Contact Sales</a>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>
