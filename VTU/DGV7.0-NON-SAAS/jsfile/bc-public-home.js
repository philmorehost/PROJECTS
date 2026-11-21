/* The supplied design's converter uses the existing live endpoint; never sample rates. */
(function () {
    'use strict';
    var amount = document.getElementById('mz-send');
    var from = document.getElementById('mz-from'), to = document.getElementById('mz-to');
    var output = document.getElementById('mz-get'), status = document.getElementById('mz-rate-status');
    var line = document.getElementById('mz-rate-line'), swap = document.getElementById('mz-swap');
    if (!amount || !from || !to || !output || !status) return;
    if (amount.getAttribute('data-fx-enabled') !== '1') {
        [amount, from, to, swap].forEach(function (el) { if (el) el.disabled = true; });
        status.textContent = 'Public currency quotes are not enabled. Sign in to review your available options.';
        return;
    }
    var timer, request = 0, controller;
    function reset() { output.textContent = '—'; line.textContent = 'Confirm the current quote in your account.'; }
    function quote() {
        var id = ++request;
        if (controller) controller.abort();
        reset();
        var value = Number(amount.value);
        if (amount.value.trim() === '' || !Number.isFinite(value) || value <= 0) { status.textContent = 'Enter an amount greater than zero.'; return; }
        controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        status.textContent = 'Fetching the current indicative rate…';
        fetch('/web/api/exchange-rate.php?from=' + encodeURIComponent(from.value) + '&to=' + encodeURIComponent(to.value), {
            credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined
        }).then(function (res) { if (!res.ok) throw new Error('Rate unavailable'); return res.json(); })
        .then(function (data) {
            if (id !== request) return;
            var rate = Number(data.rate), total = value * rate;
            if (!Number.isFinite(rate) || rate <= 0 || !Number.isFinite(total)) throw new Error('Rate unavailable');
            output.textContent = total.toLocaleString(undefined, { maximumFractionDigits: 2 });
            line.textContent = '1 ' + from.value + ' = ' + rate.toLocaleString(undefined, { maximumFractionDigits: 4 }) + ' ' + to.value;
            status.textContent = 'Indicative conversion only, excluding fees. Review the final quote in your account.';
        }).catch(function () { if (id === request) { reset(); status.textContent = 'Live rate unavailable. Please review the quote in your account.'; } });
    }
    function schedule() {
        // Invalidate a prior quote immediately, not after the debounce expires.
        request++; if (controller) controller.abort(); reset();
        clearTimeout(timer); timer = setTimeout(quote, 400);
    }
    amount.addEventListener('input', schedule); from.addEventListener('change', schedule); to.addEventListener('change', schedule);
    swap.addEventListener('click', function () { var old = from.value; from.value = to.value; to.value = old; schedule(); });
    quote();
})();
