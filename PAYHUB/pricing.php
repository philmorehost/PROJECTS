<?php
// PayHub pricing is the gateway's actual Paystack processing cost plus a separately
// configurable PayHub markup. Webhook-confirmed Paystack fees take precedence over estimates.
require_once 'includes/functions.php';

$local_markup_percent = (float)getConfig('local_markup_percent', '0');
$local_markup_flat = (float)getConfig('local_markup_flat', '0');
$local_markup_cap = (float)getConfig('local_markup_cap', '0');
$int_markup_percent = (float)getConfig('international_markup_percent', '0');
$int_markup_flat = (float)getConfig('international_markup_flat', '0');
$int_markup_cap = (float)getConfig('international_markup_cap', '0');
$payout_markup_percent = (float)getConfig('payout_markup_percent', '0');
$payout_markup_flat = (float)getConfig('payout_markup', '5');
$markupText = function ($percent, $flat, $cap) {
    $parts = [];
    if ($percent > 0) $parts[] = rtrim(rtrim(number_format($percent, 2), '0'), '.') . '%';
    if ($flat > 0) $parts[] = formatCurrency($flat);
    $text = $parts ? implode(' + ', $parts) : '₦0';
    if ($cap > 0) $text .= ' (capped at ' . formatCurrency($cap) . ')';
    return $text;
};
$local_markup = $markupText($local_markup_percent, $local_markup_flat, $local_markup_cap);
$int_markup = $markupText($int_markup_percent, $int_markup_flat, $int_markup_cap);
$pageTitle = 'Simple & Transparent Pricing - Payhub';
include 'includes/header.php';
?>

<section class="pt-32 pb-20 lg:pt-48 lg:pb-24 bg-slate-50">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="text-sm font-bold text-indigo-600 uppercase tracking-widest mb-4">Transparent Pricing</h2>
        <h1 class="text-4xl lg:text-6xl font-bold text-slate-900 mb-6">Gateway cost + a clear PayHub markup.</h1>
        <p class="text-xl text-slate-600 max-w-3xl mx-auto">PayHub passes through Paystack's confirmed processing fee and adds only the markup shown below. Where Paystack returns its actual fee, that confirmed amount is used for the settlement calculation.</p>
    </div>
</section>

<section class="pb-24 -mt-10">
    <div class="max-w-6xl mx-auto px-4 grid md:grid-cols-2 gap-6">
        <article class="bg-white rounded-3xl shadow-xl border border-slate-100 p-8 lg:p-10">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Local collections</h2>
            <p class="text-slate-500 mb-6">Paystack processing rate, plus PayHub markup</p>
            <div class="space-y-4 mb-8">
                <div class="p-5 bg-slate-50 rounded-2xl">
                    <h3 class="font-bold text-slate-900">Cards, USSD and standard local payments</h3>
                    <p class="text-indigo-700 font-bold text-lg">1.5% + ₦100</p>
                    <p class="text-sm text-slate-500">₦100 waived below ₦2,500; Paystack fee capped at ₦2,000.</p>
                </div>
                <div class="p-5 bg-slate-50 rounded-2xl">
                    <h3 class="font-bold text-slate-900">Dedicated virtual account (DVA) transfers</h3>
                    <p class="text-indigo-700 font-bold text-lg">1% capped at ₦300</p>
                    <p class="text-sm text-slate-500">The actual fee reported by Paystack is used when available.</p>
                </div>
            </div>
            <div class="border-t border-slate-100 pt-5">
                <p class="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">PayHub markup</p>
                <p class="text-xl font-bold text-slate-900"><?php echo htmlspecialchars($local_markup, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-sm text-slate-500 mt-2">Paystack processing fee + this markup is deducted from the merchant's transaction settlement.</p>
            </div>
        </article>

        <article class="bg-slate-900 text-white rounded-3xl shadow-xl p-8 lg:p-10">
            <h2 class="text-2xl font-bold mb-2">International collections</h2>
            <p class="text-slate-300 mb-8">Paystack rate: <strong class="text-white">3.9% + ₦100</strong>. PayHub markup is <strong class="text-white"><?php echo htmlspecialchars($int_markup, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <div class="border-t border-white/10 pt-6">
                <h3 class="font-bold mb-4">Transfers (payouts)</h3>
                <ul class="space-y-3 text-slate-200 text-sm">
                    <li class="flex justify-between"><span>₦5,000 and below</span><strong>₦10</strong></li>
                    <li class="flex justify-between"><span>₦5,001 to ₦50,000</span><strong>₦25</strong></li>
                    <li class="flex justify-between"><span>Above ₦50,000</span><strong>₦50</strong></li>
                </ul>
                <p class="text-xs text-slate-400 mt-4">PayHub payout markup: <?php echo $payout_markup_percent > 0 ? htmlspecialchars(rtrim(rtrim(number_format($payout_markup_percent, 2), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') . ' + ' : ''; ?><?php echo formatCurrency($payout_markup_flat); ?> per payout, plus applicable statutory charges. The payout fee is quoted before submission.</p>
            </div>
            <a href="support.php" class="block w-full text-center mt-8 py-4 bg-indigo-500 text-white font-bold rounded-2xl hover:bg-indigo-400 transition-all">Contact Sales</a>
        </article>
    </div>
    <p class="max-w-5xl mx-auto px-4 mt-8 text-xs text-slate-500">Rates shown are Paystack's published Nigeria rates and may change. Contract-specific or channel-specific fees returned by Paystack take precedence over these estimates. PayHub markup can be adjusted in Platform Configuration.</p>
</section>

<?php include 'includes/footer.php'; ?>
