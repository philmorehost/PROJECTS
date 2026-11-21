/* No secrets in storage, URLs or console. Keep the setup card and errors in place. */
(function () {
  'use strict';
  document.querySelectorAll('.bc-totp-setup').forEach(function (card) {
    if (card.dataset.bound) return;
    card.dataset.bound = '1';
    var form = card.querySelector('form'), status = card.querySelector('[data-setup-status]');
    var busy = false;
    form.addEventListener('submit', function (event) { event.preventDefault(); });
    form.addEventListener('click', async function (event) {
      var button = event.target.closest('[data-step]');
      if (!button || busy) return;
      var step = button.dataset.step;
      var field = step === 'verify' ? 'email_code' : step === 'confirm' ? 'totp_code' : null;
      if (field && !/^\d{6}$/.test(form.elements[field].value)) {
        status.textContent = 'Enter the six-digit ' + (step === 'verify' ? 'email' : 'authenticator') + ' code.';
        form.elements[field].focus(); return;
      }
      busy = true;
      form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      status.textContent = 'Please wait…';
      try {
        var body = new FormData();
        body.set('_csrf_token', form.elements._csrf_token.value);
        body.set('totp-setup', step);
        if (field) body.set(field, form.elements[field].value);
        var response = await fetch(window.location.pathname, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body
        });
        var result = await response.json();
        status.textContent = result.message || 'Could not complete setup. Please try again.';
        status.classList.toggle('text-danger', !result.ok);
        status.classList.toggle('text-success', !!result.enabled);
        if (!result.ok) return;
        if (step === 'send') {
          card.querySelector('[data-key-stage]').hidden = true;
          card.querySelector('[data-secret-stage]').hidden = true;
          card.querySelector('[data-secret]').textContent = '';
          card.querySelector('[data-qr]').replaceChildren();
        }
        if (result.verified) {
          form.elements.email_code.value = '';
          card.querySelector('[data-key-stage]').hidden = false;
        }
        if (result.secret) {
          card.querySelector('[data-secret]').textContent = result.secret;
          card.querySelector('[data-secret-stage]').hidden = false;
          // Generate locally: the setup secret is never sent to a QR service.
          try {
            var qr = qrcode(0, 'M'); qr.addData(result.uri); qr.make();
            card.querySelector('[data-qr]').innerHTML = qr.createSvgTag(4, 8);
          } catch (ignored) { card.querySelector('[data-qr]').replaceChildren(); }
        }
        if (result.enabled) {
          card.querySelector('[data-secret]').textContent = '';
          card.querySelector('[data-qr]').replaceChildren();
          form.reset(); form.hidden = true;
          card.querySelector('[data-continue]').hidden = false;
        }
      } catch (error) {
        status.textContent = 'Connection or session error. Your setup card is still here. Retry; if your session expired, sign in again.';
        status.classList.add('text-danger');
      } finally {
        busy = false;
        form.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
      }
    });
  });
}());
