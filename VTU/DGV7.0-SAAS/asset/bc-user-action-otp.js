/* Enhance only email dispatch; the final protected action remains a normal verified POST. */
(function () {
    'use strict';
    document.querySelectorAll('form[data-inline-user-otp]').forEach(function (form) {
        var button = form.querySelector('[name="sec33_send_code"]');
        var status = form.querySelector('[data-otp-status]');
        if (!button || !status) return;
        button.addEventListener('click', async function (event) {
            event.preventDefault();
            if (button.disabled) return;
            button.disabled = true;
            status.textContent = 'Sending…';
            // Only action context and CSRF, never PINs or entered verification codes.
            var body = new FormData();
            ['_csrf_token', 'sec33_user_action', 'sec33_status', 'account-user-id', 'account-username', 'vid'].forEach(function (name) {
                var field = form.elements.namedItem(name);
                if (field) body.set(name, field.value);
            });
            body.set('sec33_send_code', '1');
            try {
                var response = await fetch(form.action, {
                    method: 'POST', body: body, credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                var data = await response.json();
                if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Could not send OTP. Please try again.');
                status.textContent = 'OTP SENT';
                button.textContent = 'SENT';
                var method = form.elements.namedItem('factor_method');if(method)method.value='email';
                var factor = form.elements.namedItem('sec_factor');
                if (factor) factor.focus();
            } catch (error) {
                status.textContent = error instanceof SyntaxError ? 'Session or verification unavailable. Sign in again if needed.' : (error.message || 'Could not send OTP. Please try again.');
            } finally { button.disabled = false; }
        });
    });
}());
