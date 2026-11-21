/* Standalone public reference calculator. Never calls platform payment/FX endpoints. */
(function () {
    'use strict';
    var amount = document.getElementById('mz-send');
    var from = document.getElementById('mz-from'), to = document.getElementById('mz-to');
    var output = document.getElementById('mz-get'), status = document.getElementById('mz-rate-status');
    var line = document.getElementById('mz-rate-line'), swap = document.getElementById('mz-swap');
    var refresh = document.getElementById('mz-refresh'), badge = document.getElementById('mz-rate-badge');
    if (!amount || !from || !to || !output || !status || !line || !swap || !refresh) return;
    var endpoints = [
        'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.min.json',
        'https://latest.currency-api.pages.dev/v1/currencies/usd.min.json'
    ];
    var data = null, busy = false, loadedAt = 0, ttl = 15 * 60 * 1000;
    // Modern browsers supply the ISO list. Older browsers still get common supported fiat currencies.
    var codes = 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BYN BZD CAD CDF CHF CLP CNY COP CRC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VES VND VUV WST XAF XCD XOF XPF YER ZAR ZMW ZWG'.split(' ');
    try { if (Intl.supportedValuesOf) codes = Intl.supportedValuesOf('currency'); } catch (_) {}
    var names;
    try { names = new Intl.DisplayNames(undefined, { type: 'currency' }); } catch (_) {}
    function clear() { output.textContent = '—'; line.textContent = 'No conversion available.'; }
    function controls(disabled) { from.disabled = to.disabled = swap.disabled = disabled; }
    function validate(payload) {
        if (!payload || !/^\d{4}-\d{2}-\d{2}$/.test(payload.date) || !payload.usd || typeof payload.usd !== 'object') throw new Error('Invalid data');
        var date = Date.parse(payload.date + 'T00:00:00Z');
        if (!Number.isFinite(date) || date > Date.now() + 86400000) throw new Error('Invalid rate date');
        var rates = Object.create(null);
        codes.forEach(function (code) {
            var rate = payload.usd[code.toLowerCase()];
            if (typeof rate === 'number' && Number.isFinite(rate) && rate > 0) rates[code] = rate;
        });
        if (rates.USD !== 1 || Object.keys(rates).length < 2) throw new Error('Missing rates');
        return { date: payload.date, rates: rates, old: Date.now() - date > 3 * 86400000 };
    }
    async function fetchData(url) {
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 6000);
        try {
            var res = await fetch(url, { credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!res.ok) throw new Error('Rate source unavailable');
            return validate(await res.json());
        } finally { clearTimeout(timeout); }
    }
    function populate() {
        var selectedFrom = from.value || 'GBP', selectedTo = to.value || 'NGN';
        var available = Object.keys(data.rates).sort();
        [from, to].forEach(function (select) {
            select.textContent = '';
            available.forEach(function (code) {
                var option = document.createElement('option'); option.value = code;
                var name = names ? names.of(code) : code;
                option.textContent = code + (name && name !== code ? ' — ' + name : '');
                select.appendChild(option);
            });
        });
        from.value = data.rates[selectedFrom] ? selectedFrom : 'USD';
        to.value = data.rates[selectedTo] ? selectedTo : available.filter(function (c) { return c !== from.value; })[0];
    }
    function convert() {
        clear();
        if (!data || busy) return;
        var value = Number(amount.value);
        if (amount.value.trim() === '' || !Number.isFinite(value) || value < 0) { status.textContent = 'Enter a valid amount of zero or more.'; return; }
        var rate = data.rates[to.value] / data.rates[from.value], total = value * rate;
        if (!Number.isFinite(rate) || !Number.isFinite(total)) { status.textContent = 'Amount is too large to convert.'; return; }
        output.textContent = total.toLocaleString(undefined, { maximumFractionDigits: 2 });
        line.textContent = '1 ' + from.value + ' = ' + rate.toLocaleString(undefined, { maximumFractionDigits: 6 }) + ' ' + to.value;
        status.textContent = (data.old ? 'Older reference data' : 'Latest daily reference data') + ' · ' + data.date + '. Educational conversion only; no payment is made.';
    }
    async function load() {
        if (busy) return;
        busy = true; data = null; clear(); controls(true); refresh.disabled = true;
        status.textContent = 'Loading latest public currency rates…';
        if (badge) badge.textContent = 'Loading';
        try {
            var next;
            for (var i = 0; i < endpoints.length; i++) {
                try { next = await fetchData(endpoints[i]); break; } catch (_) { /* try independent mirror */ }
            }
            if (!next) throw new Error('Rates unavailable');
            data = next; loadedAt = Date.now(); populate(); busy = false; controls(false);
            if (badge) badge.textContent = data.old ? 'Older data' : 'Daily reference';
            convert();
        } catch (_) {
            data = null; clear(); controls(true);
            if (badge) badge.textContent = 'Unavailable';
            status.textContent = 'Public rates are unavailable. Please retry. No sample rate or transaction quote is shown.';
        } finally { busy = false; refresh.disabled = false; }
    }
    amount.addEventListener('input', convert); from.addEventListener('change', convert); to.addEventListener('change', convert);
    swap.addEventListener('click', function () { var old = from.value; from.value = to.value; to.value = old; convert(); });
    refresh.addEventListener('click', load);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - loadedAt > ttl) load(); });
    setInterval(function () { if (!document.hidden && Date.now() - loadedAt > ttl) load(); }, ttl);
    load();
})();
