(function () {
    'use strict';
    document.querySelectorAll('[data-app-update-otp]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) return;
            var form = button.closest('form');
            var status = form.querySelector('[data-app-update-otp-status]');
            var csrf = form.elements.namedItem('_csrf_token');
            if (!status || !csrf) return;
            var body = new FormData();
            body.set('_csrf_token', csrf.value);
            body.set('app_update_send_code', button.dataset.appUpdateOtp);
            button.disabled = true;
            status.textContent = 'Sending…';
            try {
                var response = await fetch(form.action || window.location.href, {
                    method: 'POST', credentials: 'same-origin', body: body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                var data = await response.json();
                if (!response.ok || data.status !== 'success') throw new Error(data.message || 'OTP could not be sent. Please retry.');
                status.textContent = 'OTP SENT';
            } catch (error) {
                status.textContent = error instanceof SyntaxError ? 'Verification unavailable. Your form values have been kept.' : error.message;
            } finally { button.disabled = false; }
        });
    });
}());
