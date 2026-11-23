(function () {
    // ── State ──────────────────────────────────────────────────────────────
    var regForm        = document.querySelector('form[method="post"]');
    var createBtn      = document.getElementById('btn-create-account');
    var overlay        = document.getElementById('otp-overlay');
    var digits         = Array.from(document.querySelectorAll('.otp-digit'));
    var errBox         = document.getElementById('otp-error');
    var successBox     = document.getElementById('otp-success');
    var countdownEl    = document.getElementById('otp-countdown');
    var verifyingEl    = document.getElementById('otp-verifying');
    var resendBtn      = document.getElementById('otp-resend-btn');
    var emailHint      = document.getElementById('otp-email-hint');
    var countdownTimer = null;
    var expiryTs       = 0;
    var verifying      = false;
    var sending        = false;
    var sendStatus=document.createElement('p');sendStatus.setAttribute('role','status');sendStatus.setAttribute('aria-live','polite');createBtn.after(sendStatus);

    // ── Helpers ────────────────────────────────────────────────────────────
    function showError(msg) {
        errBox.textContent = msg;
        errBox.style.display = 'block';
        successBox.style.display = 'none';
    }
    function showSuccess(msg) {
        successBox.textContent = msg;
        successBox.style.display = 'block';
        errBox.style.display = 'none';
    }
    function clearMessages() {
        errBox.style.display = 'none';
        successBox.style.display = 'none';
    }
    function getOTPValue() {
        return digits.map(function(d){ return d.value; }).join('');
    }
    function clearDigits() {
        digits.forEach(function(d){ d.value = ''; d.style.borderColor = '#dee2e6'; });
    }
    function startCountdown(seconds) {
        clearInterval(countdownTimer);
        expiryTs = Date.now() + seconds * 1000;
        countdownTimer = setInterval(function() {
            var remaining = Math.max(0, Math.round((expiryTs - Date.now()) / 1000));
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            countdownEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            if (remaining <= 0) {
                clearInterval(countdownTimer);
                showError('Code expired. Please request a new one.');
                digits.forEach(function(d){ d.disabled = true; });
            }
        }, 500);
    }

    // ── Open / close modal ─────────────────────────────────────────────────
    window.closeOtpModal = function() {
        if(sending || verifying)return;
        overlay.style.display = 'none';
        clearInterval(countdownTimer);
        clearDigits();
        clearMessages();
        verifying = false;
        verifyingEl.style.display = 'none';
        digits.forEach(function(d){ d.disabled = false; });
        createBtn.disabled = false;
        createBtn.textContent = 'CREATE MY ACCOUNT';
    };

    function openOtpModal(email) {
        emailHint.textContent = 'A 6-digit code has been sent to ' + email;
        clearDigits();
        clearMessages();
        verifyingEl.style.display = 'none';
        digits.forEach(function(d){ d.disabled = false; });
        overlay.style.display = 'flex';
        startCountdown(600);
        setTimeout(function(){ digits[0].focus(); }, 150);
    }

    // ── Digit-box keyboard behaviour ──────────────────────────────────────
    digits.forEach(function(box, i) {
        box.addEventListener('keydown', function(e) {
            // Allow backspace to move to previous box
            if (e.key === 'Backspace' && box.value === '' && i > 0) {
                digits[i - 1].focus();
                digits[i - 1].value = '';
            }
        });
        box.addEventListener('input', function(e) {
            // Keep only last typed digit (handles mobile paste of single char)
            var val = box.value.replace(/\D/g, '');
            box.value = val ? val.slice(-1) : '';
            box.style.borderColor = box.value ? '#4154f1' : '#dee2e6';
            clearMessages();

            if (box.value && i < digits.length - 1) {
                digits[i + 1].focus();
            }
            // Auto-verify when all 6 digits entered
            if (getOTPValue().length === 6) {
                verifyOTP();
            }
        });
        // Handle paste of full 6-digit code on first box
        box.addEventListener('paste', function(e) {
            var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            if (pasted.length >= 6) {
                e.preventDefault();
                for (var j = 0; j < 6; j++) {
                    digits[j].value = pasted[j] || '';
                    digits[j].style.borderColor = digits[j].value ? '#4154f1' : '#dee2e6';
                }
                digits[5].focus();
                verifyOTP();
            }
        });
    });

    // ── Send OTP (also called by Resend button) ────────────────────────────
    window.sendOTP = function(isResend) {
        if(sending || verifying)return;
        sending=true;sendStatus.textContent='Sending…';
        var data = new FormData(regForm);
        // Append referral if present in URL
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('referral')) data.append('referral', urlParams.get('referral'));

        if (!isResend) {
            createBtn.disabled = true;
            createBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Sending code…';
        } else {
            resendBtn.disabled = true;
            resendBtn.textContent = 'Sending…';
            clearMessages();
        }

        fetch('/web/api/send-reg-otp.php', { method: 'POST', body: data, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (isResend) {
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend OTP';
                } else {
                    createBtn.disabled = false;
                    createBtn.textContent = 'CREATE MY ACCOUNT';
                }

                if (res.status === 'success') {
                    var emailVal = document.querySelector('input[name="email"]').value.trim();
                    if(!isResend)openOtpModal(emailVal);
                    resendBtn.textContent='SENT';sendStatus.textContent='OTP SENT';createBtn.textContent='SENT';
                    digits.forEach(function(d){d.disabled=false;});
                    if (isResend) {
                        showSuccess('OTP SENT. Use the newest code.');
                        startCountdown(600);
                    }
                } else if (res.status === 'immediate') {
                    // Branch DG6.87: OTP disabled, account created immediately
                    Swal.fire({
                        title: 'Success!',
                        text: res.message || 'Account created successfully!',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = res.redirect || 'SecurityPIN.php';
                    });
                } else {
                    if (isResend) {
                        showError(res.message || 'Failed to resend OTP.');
                    } else {
                        sendStatus.textContent=res.message || 'Could not send code.';
                    }
                }
            })
            .catch(function() {
                if (isResend) {
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend OTP';
                    showError('Network error. Please try again.');
                } else {
                    createBtn.disabled = false;
                    createBtn.textContent = 'CREATE MY ACCOUNT';
                    sendStatus.textContent='Could not confirm dispatch. Please wait before retrying.';
                }
            }).finally(function(){sending=false;});
    };

    // ── Verify OTP ────────────────────────────────────────────────────────
    function verifyOTP() {
        if (verifying || sending) return;
        var otp = getOTPValue();
        if (otp.length !== 6) return;
        verifying = true;
        digits.forEach(function(d){ d.disabled = true; });
        verifyingEl.style.display = 'block';
        clearMessages();

        var fd = new FormData();
        fd.append('otp', otp);
        fd.append('_csrf_token',regForm.elements.namedItem('_csrf_token').value);

        fetch('/web/api/verify-reg-otp.php', { method: 'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                verifyingEl.style.display = 'none';
                if (res.status === 'success') {
                    // All digit boxes green, brief success message, then redirect
                    digits.forEach(function(d){ d.style.borderColor = '#198754'; });
                    showSuccess('✅ ' + (res.message || 'Account created!') + ' Redirecting…');
                    clearInterval(countdownTimer);
                    setTimeout(function(){
                        window.location.href = res.redirect || 'SecurityPIN.php';
                    }, 800);
                } else {
                    // Wrong OTP — shake boxes, clear, re-focus
                    digits.forEach(function(d){
                        d.style.borderColor = '#dc3545';
                        d.disabled = false;
                    });
                    showError(res.message || 'Incorrect code. Please try again.');
                    clearDigits();
                    digits[0].focus();
                    verifying = false;
                }
            })
            .catch(function() {
                verifyingEl.style.display = 'none';
                digits.forEach(function(d){ d.disabled = false; });
                showError('Network error. Please try again.');
                verifying = false;
            });
    }

    // ── Intercept form submit ──────────────────────────────────────────────
    regForm.addEventListener('submit', function(e) {
        e.preventDefault();
        sendOTP(false);
    });

})();
