<?php
/**
 * Paystack return page.
 *
 * The reference in the URL is only a lookup key: the page polls
 * api-verify-payment.php, which decides whether this browser may see the
 * licence key or only a masked confirmation.
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
lm_ui_boot();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070b16">
    <meta name="robots" content="noindex">
    <title>Payment status — <?= htmlspecialchars(lm_site_name()) ?></title>
    <?php lm_public_assets(); ?>
</head>
<body>
<?php lm_public_header('order'); ?>

<main id="main" class="lm-page">
    <div class="lm-wrap">
        <div class="lm-auth" style="max-width:560px">
            <section class="lm-card">
                <div class="lm-card__body" id="content">
                    <div class="lm-center">
                        <div class="lm-icon-badge"><?= lm_icon('clock') ?></div>
                        <h1 style="font-size:22px">Checking your payment</h1>
                        <p class="lm-meta">This usually takes a few seconds.</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>

<?php lm_public_footer(); ?>
<?php lm_public_tabbar('order'); ?>

<script>
(function () {
    'use strict';

    var ref = <?= json_encode((string) ($_GET['ref'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var attemptCount = 0;
    var maxAttempts = 30;
    var content = document.getElementById('content');
    var stopped = false;

    function escapeHtml(text) {
        return String(text === undefined || text === null ? '' : text).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function icon(name) {
        var paths = {
            check: '<path d="m4.5 12.5 5 5 10-11"/>',
            clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            warn: '<path d="M12 3.5 21 20H3Z"/><path d="M12 9v5"/><path d="M12 17.2v.3"/>'
        };
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths[name] + '</svg>';
    }

    function checkLicense() {
        attemptCount++;

        fetch('api-verify-payment.php?ref=' + encodeURIComponent(ref), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    displaySuccess(data);
                } else if (attemptCount < maxAttempts) {
                    displayProcessing(data && data.message ? data.message : 'Confirming with Paystack…');
                    window.setTimeout(checkLicense, 2000);
                } else {
                    displayError(data && data.message ? data.message : 'The payment was not confirmed in time.');
                }
            })
            .catch(function () {
                if (attemptCount < maxAttempts) {
                    displayProcessing('Processing your payment…');
                    window.setTimeout(checkLicense, 2000);
                } else {
                    displayError('We could not reach the server. Check your email, or sign in to your dashboard.');
                }
            });
    }

    function displaySuccess(data) {
        var masked = !data.license_key;
        var keyBlock = masked
            ? '<div class="lm-alert lm-alert--info">' + icon('clock') +
                '<span>' + escapeHtml(data.message || 'Your licence is ready — the key has been emailed to you.') + '</span></div>' +
                '<a class="lm-btn lm-btn--block" href="user/login.php">Sign in to your dashboard</a>'
            : '<span class="lm-key" id="licenseKey">' + escapeHtml(data.license_key) + '</span>' +
                '<div class="lm-flex" style="margin-bottom:8px">' +
                    '<button class="lm-btn lm-btn--glow" type="button" id="copyKey">Copy key</button>' +
                    '<a class="lm-btn lm-btn--ghost" href="user/index.php">Open dashboard</a>' +
                '</div>' +
                '<p class="lm-help">The key is also in the email we just sent.</p>';

        content.innerHTML =
            '<div class="lm-center">' +
                '<div class="lm-icon-badge">' + icon('check') + '</div>' +
                '<h1 style="font-size:24px">Payment confirmed</h1>' +
                '<p class="lm-meta" style="margin-bottom:22px">Thank you — your licence is active.</p>' +
            '</div>' +
            keyBlock +
            (data.auto_login_token ? '<p class="lm-help lm-center">If automatic sign-in does not start, <a href="user/auto_login.php?token=' + encodeURIComponent(data.auto_login_token) + '">continue here</a>.</p>' : '') +
            '<hr class="lm-divider">' +
            '<details class="lm-install">' +
                '<summary>Installation guide</summary>' +
                '<ol class="lm-help" style="padding-left:18px;margin:12px 0 0">' +
                    '<li>Upload the main script to the server where it will run.</li>' +
                    '<li>Open <code>setup.php</code> in your browser.</li>' +
                    '<li>Enter the licence server URL, your key and the domain you registered.</li>' +
                    '<li>Follow the on-screen steps to finish.</li>' +
                '</ol>' +
            '</details>';

        var copy = document.getElementById('copyKey');
        if (copy) {
            copy.addEventListener('click', function () {
                var el = document.getElementById('licenseKey');
                navigator.clipboard.writeText(el ? el.innerText : '').then(function () {
                    copy.textContent = 'Copied';
                    window.setTimeout(function () { copy.textContent = 'Copy key'; }, 1600);
                }, function () {
                    copy.textContent = 'Select and copy';
                });
            });
        }

        if (data.auto_login_token) {
            window.setTimeout(function () { submitToken(data.auto_login_token); }, 1500);
        }
    }

    function displayProcessing(message) {
        content.innerHTML =
            '<div class="lm-center">' +
                '<div class="lm-icon-badge">' + icon('clock') + '</div>' +
                '<h1 style="font-size:22px">Processing payment</h1>' +
                '<p class="lm-meta">' + escapeHtml(message) + '</p>' +
                '<div class="lm-progress" role="progressbar" aria-valuemin="0" aria-valuemax="' + maxAttempts + '" aria-valuenow="' + attemptCount + '">' +
                    '<span style="width:' + Math.min(100, Math.round((attemptCount / maxAttempts) * 100)) + '%"></span>' +
                '</div>' +
                '<p class="lm-help">Attempt ' + attemptCount + ' of ' + maxAttempts + '. This page updates itself.</p>' +
            '</div>';
    }

    function displayError(message) {
        stopped = true;
        content.innerHTML =
            '<div class="lm-center">' +
                '<div class="lm-icon-badge lm-icon-badge--warn">' + icon('warn') + '</div>' +
                '<h1 style="font-size:22px">Payment not confirmed yet</h1>' +
                '<p class="lm-meta">' + escapeHtml(message) + '</p>' +
                (ref ? '<p class="lm-help">Reference: <code>' + escapeHtml(ref) + '</code></p>' : '') +
            '</div>' +
            '<div class="lm-flex" style="margin-top:12px">' +
                '<a class="lm-btn" href="user/login.php">Sign in</a>' +
                '<a class="lm-btn lm-btn--ghost" href="order.php">Try again</a>' +
            '</div>';
    }

    function submitToken(token) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'user/auto_login.php';
        form.style.display = 'none';
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'token';
        input.value = token;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }

    if (!ref) {
        displayError('No payment reference was provided.');
    } else {
        displayProcessing('Confirming with Paystack…');
        checkLicense();
    }
})();
</script>
</body>
</html>
