<?php
/**
 * success.php — the page Paystack returns the buyer to.
 *
 * The polling behaviour is unchanged: api-verify-payment.php is asked every two
 * seconds until the webhook has created the licence (or the attempts run out).
 * What changed is that the page is server-rendered through the shared shell,
 * so there is a real header, footer and mobile menu instead of a bare div, and
 * the states are markup the script fills in rather than template strings.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$ref = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
$ref = preg_replace('/[^A-Za-z0-9._-]/', '', $ref);
?>
<?php lm_public_page_start('order', array('title' => 'Payment status')); ?>

<main class="lm-page lm-page--center" id="main">
    <div class="lm-wrap">
        <div class="lm-auth">
            <div id="lm-success" data-ref="<?= lm_e($ref) ?>">

                <div class="lm-card" id="state-processing">
                    <div class="lm-card__body lm-center">
                        <span class="lm-icon-badge lm-icon-badge--warn"><?= lm_icon('clock') ?></span>
                        <h1 style="font-size:clamp(22px,3vw,30px);margin-bottom:8px">Confirming your payment</h1>
                        <p class="lm-meta">Stay on this page for a moment — we are waiting for the payment provider
                            to confirm the charge, then the key appears here.</p>

                        <div class="lm-progressline">
                            <div class="lm-progressbar"><span id="progress-bar"></span></div>
                            <span id="progress-label">0%</span>
                        </div>
                        <p class="lm-help" id="progress-note">Attempt 0 of 30. This usually takes a few seconds.</p>

                        <p class="lm-help lm-mt-24">Reference
                            <code><?= $ref !== '' ? lm_e($ref) : 'not supplied' ?></code></p>
                    </div>
                </div>

                <div class="lm-card" id="state-success" hidden>
                    <div class="lm-card__body">
                        <div class="lm-center">
                            <span class="lm-icon-badge"><?= lm_icon('check') ?></span>
                            <h1 style="font-size:clamp(22px,3vw,30px);margin-bottom:8px">Payment successful</h1>
                            <p class="lm-meta">Your licence key is below, and a copy has been emailed to you.</p>
                        </div>

                        <div class="lm-field lm-mt-24">
                            <span class="lm-label">Licence key</span>
                            <div class="lm-keyrow">
                                <code class="lm-key" id="licenseKey">—</code>
                                <button class="lm-copybtn lm-copybtn--light" type="button" id="copyKey">
                                    <?= lm_icon('copy') ?><span>Copy</span>
                                </button>
                            </div>
                        </div>

                        <div class="lm-item__actions">
                            <a class="lm-btn lm-btn--glow lm-btn--block" href="<?= lm_e(lm_public_url('user/index.php')) ?>">
                                <?= lm_icon('grid') ?> Open my dashboard
                            </a>
                            <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                                <?= lm_icon('book') ?> Integrate it
                            </a>
                        </div>

                        <p class="lm-help lm-center lm-mt-16" id="auto-login-note" hidden>
                            Taking you to your dashboard… <a id="auto-login-link" href="<?= lm_e(lm_public_url('user/index.php')) ?>">continue now</a>.
                        </p>
                    </div>
                </div>

                <div class="lm-card" id="state-error" hidden>
                    <div class="lm-card__body">
                        <div class="lm-center">
                            <span class="lm-icon-badge lm-icon-badge--warn"><?= lm_icon('alert') ?></span>
                            <h1 style="font-size:clamp(22px,3vw,30px);margin-bottom:8px">We could not confirm it yet</h1>
                            <p class="lm-meta" id="error-message">The payment has not been confirmed by the provider.</p>
                        </div>

                        <div class="lm-note lm-note--warn lm-mt-24">
                            <?= lm_icon('question') ?>
                            <span>Your licence may still have been created. Sign in with the email you paid with — if
                                the key is there, nothing is wrong. Otherwise the reference
                                <code><?= $ref !== '' ? lm_e($ref) : '' ?></code> is what support will ask for.</span>
                        </div>

                        <div class="lm-item__actions">
                            <a class="lm-btn lm-btn--glow lm-btn--block" href="<?= lm_e(lm_public_url('user/login.php')) ?>">
                                <?= lm_icon('lock') ?> Sign in
                            </a>
                            <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= lm_e(lm_public_url('order.php')) ?>">
                                <?= lm_icon('refresh') ?> Start again
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<script>
(function () {
    'use strict';

    var root = document.getElementById('lm-success');
    if (!root) { return; }

    var ref = root.getAttribute('data-ref') || '';
    var attempt = 0;
    var maxAttempts = 30;
    var timer = null;

    var states = {
        processing: document.getElementById('state-processing'),
        success: document.getElementById('state-success'),
        error: document.getElementById('state-error')
    };
    var bar = document.getElementById('progress-bar');
    var label = document.getElementById('progress-label');
    var note = document.getElementById('progress-note');

    function show(name) {
        Object.keys(states).forEach(function (key) {
            if (states[key]) { states[key].hidden = key !== name; }
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function progress(step) {
        var pct = Math.min(96, Math.round((step / maxAttempts) * 100));
        if (bar) { bar.style.width = pct + '%'; }
        if (label) { label.textContent = pct + '%'; }
        if (note) {
            note.textContent = 'Attempt ' + step + ' of ' + maxAttempts
                + '. This usually takes a few seconds — you can safely leave this page open.';
        }
    }

    function copyText(text, btn) {
        var span = btn ? btn.querySelector('span') : null;
        var flash = function (ok) {
            if (!span) { return; }
            var old = span.textContent;
            span.textContent = ok ? 'Copied' : 'Failed';
            window.setTimeout(function () { span.textContent = old; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { flash(true); }, function () { flash(false); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); flash(true); } catch (e) { flash(false); }
            document.body.removeChild(ta);
        }
    }

    function submitToken(token) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = <?= json_encode(lm_public_url('user/auto_login.php')) ?>;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'token';
        input.value = token;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }

    function onPaid(data) {
        if (timer) { window.clearTimeout(timer); }

        var keyEl = document.getElementById('licenseKey');
        if (keyEl) { keyEl.textContent = data.license_key || '—'; }
        show('success');

        var copyBtn = document.getElementById('copyKey');
        if (copyBtn && data.license_key) {
            copyBtn.addEventListener('click', function () { copyText(data.license_key, copyBtn); });
            copyText(data.license_key, null);
        }

        if (data.auto_login_token) {
            var hint = document.getElementById('auto-login-note');
            var link = document.getElementById('auto-login-link');
            if (hint) { hint.hidden = false; }
            if (link) {
                link.href = <?= json_encode(lm_public_url('user/auto_login.php')) ?>
                    + '?token=' + encodeURIComponent(data.auto_login_token);
            }
            window.setTimeout(function () { submitToken(data.auto_login_token); }, 1500);
        } else {
            window.setTimeout(function () {
                window.location.href = <?= json_encode(lm_public_url('user/login.php')) ?>;
            }, 9000);
        }
    }

    function poll() {
        attempt++;
        progress(attempt);

        fetch(<?= json_encode(lm_public_url('api-verify-payment.php')) ?>
            + '?ref=' + encodeURIComponent(ref), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    onPaid(data);
                    return;
                }

                if (attempt < maxAttempts) {
                    timer = window.setTimeout(poll, 2000);
                    return;
                }

                var msg = document.getElementById('error-message');
                if (msg) {
                    msg.textContent = (data && data.message) || 'The provider has not confirmed the charge yet.';
                }
                show('error');
            })
            .catch(function () {
                if (attempt < maxAttempts) {
                    timer = window.setTimeout(poll, 2000);
                    return;
                }
                show('error');
            });
    }

    if (!ref) {
        show('error');
        var empty = document.getElementById('error-message');
        if (empty) {
            empty.textContent = 'This page was opened without a payment reference, so there is nothing to confirm.';
        }
        return;
    }

    progress(0);
    poll();
})();
</script>

<?php lm_public_page_end(array('active' => 'order', 'no_tabbar' => true)); ?>
