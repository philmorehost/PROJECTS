<?php
// PayHub pricing is the aggregator's actual processing cost plus a separately
// configurable PayHub markup. Webhook-confirmed aggregator fees take precedence over estimates.
require_once 'includes/functions.php';

$local_markup_percent = (float)getConfig('local_markup_percent', '0');
$local_markup_flat = (float)getConfig('local_markup_flat', '0');
$local_markup_cap = (float)getConfig('local_markup_cap', '0');
$int_markup_percent = (float)getConfig('international_markup_percent', '0');
$int_markup_flat = (float)getConfig('international_markup_flat', '0');
$int_markup_cap = (float)getConfig('international_markup_cap', '0');
$payout_markup_percent = (float)getConfig('payout_markup_percent', '0');
$payout_markup_flat = (float)getConfig('payout_markup', '5');
$providerLocalPercent = (float)getConfig('aggregator_local_fee_percent', '1.5');
$providerLocalFlat = (float)getConfig('aggregator_local_fee_flat', '100');
$providerLocalCap = (float)getConfig('aggregator_local_fee_cap', '2000');
$providerWaiverBelow = (float)getConfig('aggregator_local_flat_waiver_below', '2500');
$providerDvaPercent = (float)getConfig('aggregator_dva_fee_percent', '1');
$providerDvaCap = (float)getConfig('aggregator_dva_fee_cap', '300');
$providerInternationalPercent = (float)getConfig('aggregator_international_fee_percent', '3.9');
$providerInternationalFlat = (float)getConfig('aggregator_international_fee_flat', '100');
$rateText = function ($value) { return rtrim(rtrim(number_format((float)$value, 2), '0'), '.'); };
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
        <p class="text-xl text-slate-600 max-w-3xl mx-auto">PayHub passes through our payment aggregator's confirmed processing fee and adds only the markup shown below. Where our payment aggregator returns its actual fee, that confirmed amount is used for the settlement calculation.</p>
    </div>
</section>

<section class="pb-16 bg-slate-50" aria-labelledby="pricing-calculator-title">
    <div class="max-w-6xl mx-auto px-4 grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <p class="text-sm font-bold text-indigo-600 uppercase tracking-widest mb-3">Interactive estimate</p>
            <h2 id="pricing-calculator-title" class="text-3xl lg:text-4xl font-bold text-slate-900 mb-4">Do the math</h2>
            <p class="text-slate-600">Enter the amount your customer pays to estimate the payment aggregator fee, PayHub markup, and net settlement. Switch between local and international pricing.</p>
        </div>
        <div class="bg-[#001b2e] text-white rounded-3xl shadow-2xl p-6 sm:p-8" data-pricing-calculator>
            <label for="pricingAmount" class="block text-xs font-bold uppercase tracking-widest text-slate-300 mb-3">If your customer pays</label>
            <div class="flex items-center rounded-xl bg-[#092943] px-5 py-4 mb-5 focus-within:ring-2 focus-within:ring-indigo-400">
                <span class="text-slate-300 font-bold mr-3">NGN</span>
                <input id="pricingAmount" type="number" min="0" step="0.01" inputmode="decimal" value="2500" class="w-full bg-transparent text-3xl font-bold text-white outline-none" aria-describedby="pricing-calculator-note">
            </div>

            <div class="flex gap-2 mb-4" role="tablist" aria-label="Payment type">
                <button type="button" data-pricing-mode="local" role="tab" aria-selected="true" class="rounded-full bg-indigo-500 px-4 py-2 text-sm font-bold">Local</button>
                <button type="button" data-pricing-mode="international" role="tab" aria-selected="false" class="rounded-full bg-white/10 px-4 py-2 text-sm font-bold text-slate-300">International</button>
            </div>
            <div id="localMethodWrap" class="mb-5">
                <label for="localMethod" class="block text-xs font-semibold text-slate-300 mb-2">Local payment type</label>
                <select id="localMethod" class="w-full rounded-xl bg-[#092943] px-4 py-3 text-white outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="standard">Card, USSD or standard local payment</option>
                    <option value="dva">Dedicated virtual account transfer</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4 border-b border-white/10 pb-5 mb-4">
                <div><p class="text-[10px] uppercase tracking-widest text-slate-400 mb-1">Estimated settlement</p><p id="estimateSettlement" class="text-2xl font-bold">₦0.00</p></div>
                <div class="text-right"><p class="text-[10px] uppercase tracking-widest text-slate-400 mb-1">Total fees</p><p id="estimateTotalFees" class="text-2xl font-bold">₦0.00</p></div>
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-300">Payment aggregator fee</dt><dd id="estimateProviderFee" class="font-semibold">₦0.00</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-300">PayHub markup</dt><dd id="estimatePayhubMarkup" class="font-semibold">₦0.00</dd></div>
            </dl>
            <p id="pricing-calculator-note" class="mt-5 text-xs leading-relaxed text-slate-400">Estimate uses the published rate for the selected payment type and your current standard PayHub markup. Actual aggregator fees may vary by transaction; merchant-specific pricing may differ.</p>
        </div>
    </div>
</section>

<section class="pb-24 -mt-16">
    <div class="max-w-6xl mx-auto px-4 grid md:grid-cols-2 gap-6">
        <article class="bg-white rounded-3xl shadow-xl border border-slate-100 p-8 lg:p-10">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Local collections</h2>
            <p class="text-slate-500 mb-6">Our Payment Aggregator processing rate, plus PayHub markup</p>
            <div class="space-y-4 mb-8">
                <div class="p-5 bg-slate-50 rounded-2xl">
                    <h3 class="font-bold text-slate-900">Cards, USSD and standard local payments</h3>
                    <p class="text-indigo-700 font-bold text-lg"><?php echo htmlspecialchars($rateText($providerLocalPercent), ENT_QUOTES, 'UTF-8'); ?>% + <?php echo formatCurrency($providerLocalFlat); ?></p>
                    <p class="text-sm text-slate-500"><?php echo formatCurrency($providerLocalFlat); ?> waived below <?php echo formatCurrency($providerWaiverBelow); ?>; payment aggregator fee <?php echo $providerLocalCap > 0 ? 'capped at ' . formatCurrency($providerLocalCap) : 'has no cap'; ?>.</p>
                </div>
                <div class="p-5 bg-slate-50 rounded-2xl">
                    <h3 class="font-bold text-slate-900">Dedicated virtual account (DVA) transfers</h3>
                    <p class="text-indigo-700 font-bold text-lg"><?php echo htmlspecialchars($rateText($providerDvaPercent), ENT_QUOTES, 'UTF-8'); ?>%<?php echo $providerDvaCap > 0 ? ' capped at ' . formatCurrency($providerDvaCap) : ''; ?></p>
                    <p class="text-sm text-slate-500">The actual fee reported by our payment aggregator is used when available.</p>
                </div>
            </div>
            <div class="border-t border-slate-100 pt-5">
                <p class="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">PayHub markup</p>
                <p class="text-xl font-bold text-slate-900"><?php echo htmlspecialchars($local_markup, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-sm text-slate-500 mt-2">Our Payment Aggregator processing fee + this markup is deducted from the merchant's transaction settlement.</p>
            </div>
        </article>

        <article class="bg-slate-900 text-white rounded-3xl shadow-xl p-8 lg:p-10">
            <h2 class="text-2xl font-bold mb-2">International collections</h2>
            <p class="text-slate-300 mb-8">Our Payment Aggregator rate: <strong class="text-white"><?php echo htmlspecialchars($rateText($providerInternationalPercent), ENT_QUOTES, 'UTF-8'); ?>% + <?php echo formatCurrency($providerInternationalFlat); ?></strong>. PayHub markup is <strong class="text-white"><?php echo htmlspecialchars($int_markup, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
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
    <p class="max-w-5xl mx-auto px-4 mt-8 text-xs text-slate-500">Rates shown are our payment aggregator's published Nigeria rates and may change. Contract-specific or channel-specific fees returned by our payment aggregator take precedence over these estimates. PayHub markup can be adjusted in Platform Configuration.</p>
</section>

<script>
(() => {
    const calculator = document.querySelector('[data-pricing-calculator]');
    if (!calculator) return;
    const pricing = {
        localMarkupPercent: <?php echo json_encode($local_markup_percent); ?>,
        localMarkupFlat: <?php echo json_encode($local_markup_flat); ?>,
        localMarkupCap: <?php echo json_encode($local_markup_cap); ?>,
        internationalMarkupPercent: <?php echo json_encode($int_markup_percent); ?>,
        internationalMarkupFlat: <?php echo json_encode($int_markup_flat); ?>,
        internationalMarkupCap: <?php echo json_encode($int_markup_cap); ?>,
        localPercent: <?php echo json_encode($providerLocalPercent); ?>,
        localFlat: <?php echo json_encode($providerLocalFlat); ?>,
        localCap: <?php echo json_encode($providerLocalCap); ?>,
        localWaiverBelow: <?php echo json_encode($providerWaiverBelow); ?>,
        dvaPercent: <?php echo json_encode($providerDvaPercent); ?>,
        dvaCap: <?php echo json_encode($providerDvaCap); ?>,
        internationalPercent: <?php echo json_encode($providerInternationalPercent); ?>,
        internationalFlat: <?php echo json_encode($providerInternationalFlat); ?>
    };
    const amountInput = document.getElementById('pricingAmount');
    const localMethod = document.getElementById('localMethod');
    const localMethodWrap = document.getElementById('localMethodWrap');
    const tabs = calculator.querySelectorAll('[data-pricing-mode]');
    const money = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let mode = 'local';

    function cappedMarkup(amount, percent, flat, cap) {
        let value = amount * Math.max(0, percent) / 100 + Math.max(0, flat);
        if (cap > 0) value = Math.min(value, cap);
        return value;
    }

    function updateEstimate() {
        const amount = Math.max(0, Number.parseFloat(amountInput.value) || 0);
        let providerFee;
        let markup;
        if (mode === 'international') {
            providerFee = amount * pricing.internationalPercent / 100 + pricing.internationalFlat;
            markup = cappedMarkup(amount, pricing.internationalMarkupPercent, pricing.internationalMarkupFlat, pricing.internationalMarkupCap);
        } else if (localMethod.value === 'dva') {
            providerFee = amount * pricing.dvaPercent / 100;
            if (pricing.dvaCap > 0) providerFee = Math.min(providerFee, pricing.dvaCap);
            markup = cappedMarkup(amount, pricing.localMarkupPercent, pricing.localMarkupFlat, pricing.localMarkupCap);
        } else {
            providerFee = amount * pricing.localPercent / 100 + (amount >= pricing.localWaiverBelow ? pricing.localFlat : 0);
            if (pricing.localCap > 0) providerFee = Math.min(providerFee, pricing.localCap);
            markup = cappedMarkup(amount, pricing.localMarkupPercent, pricing.localMarkupFlat, pricing.localMarkupCap);
        }
        const totalFees = providerFee + markup;
        document.getElementById('estimateProviderFee').textContent = money.format(providerFee);
        document.getElementById('estimatePayhubMarkup').textContent = money.format(markup);
        document.getElementById('estimateTotalFees').textContent = money.format(totalFees);
        document.getElementById('estimateSettlement').textContent = money.format(Math.max(0, amount - totalFees));
    }

    tabs.forEach((tab) => tab.addEventListener('click', () => {
        mode = tab.dataset.pricingMode;
        tabs.forEach((item) => {
            const active = item === tab;
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.classList.toggle('bg-indigo-500', active);
            item.classList.toggle('text-slate-300', !active);
            item.classList.toggle('bg-white/10', !active);
        });
        localMethodWrap.hidden = mode !== 'local';
        updateEstimate();
    }));
    amountInput.addEventListener('input', updateEstimate);
    localMethod.addEventListener('change', updateEstimate);
    updateEstimate();
})();
</script>

<?php include 'includes/footer.php'; ?>
