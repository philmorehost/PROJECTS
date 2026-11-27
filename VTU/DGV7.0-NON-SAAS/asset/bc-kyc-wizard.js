'use strict';
/**
 * Guided step-by-step KYC/KYB verification wizard.
 * Minimises clicks: auto-advances on valid file upload, auto-starts & auto-advances
 * live selfie / "Say HI" live video stages, and auto-submits once all checks are complete.
 */
(function () {
    document.querySelectorAll('form[data-kyc-wizard]').forEach(function (form) {
        var steps = Array.from(form.querySelectorAll('[data-kyc-step]'));
        if (!steps.length) return;

        var mode = form.dataset.kycWizard || 'kyc';
        var submitBtn = form.querySelector('button[type="submit"], button[name="submit_media"], button[name="submit_business_kyb"], button[name="upload-vendor-kyc-doc"]');
        var guided = false;
        var showAll = false;
        var currentIdx = 0;
        var autoSubmitTimer = null;

        // Build clean wizard progress bar + start button above steps
        var header = document.createElement('div');
        header.className = 'mb-3 p-3 rounded-4 border bg-light';
        header.innerHTML =
            '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">' +
                '<div>' +
                    '<div class="fw-bold small text-dark"><i class="bi bi-stars text-primary me-1"></i>Automated Guided ' + (mode === 'kyb' ? 'KYB' : 'KYC') + ' Flow</div>' +
                    '<div class="text-muted" style="font-size:12px;" data-wiz-sub>Click Start once — we guide you automatically through each stage.</div>' +
                '</div>' +
                '<div class="d-flex gap-2">' +
                    '<button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" data-wiz-start>Start Guided Verification</button>' +
                    '<button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-wiz-toggle>Show all steps</button>' +
                '</div>' +
            '</div>' +
            '<div class="progress mb-2" style="height:6px;"><div class="progress-bar bg-primary" data-wiz-bar style="width:0%;transition:width .25s ease;"></div></div>' +
            '<div class="d-flex flex-wrap gap-1" data-wiz-pills></div>' +
            '<div class="alert alert-success small mt-2 mb-0 d-none" data-wiz-ready>' +
                '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' +
                    '<span data-wiz-ready-txt>All stages completed! Submitting automatically...</span>' +
                    '<div class="d-flex gap-2">' +
                        '<button type="button" class="btn btn-success btn-sm rounded-pill px-3 fw-bold" data-wiz-submit-now>Submit Now</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-2" data-wiz-cancel-auto>Review First</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        form.insertBefore(header, form.firstChild);

        var startBtn = header.querySelector('[data-wiz-start]');
        var toggleBtn = header.querySelector('[data-wiz-toggle]');
        var subText = header.querySelector('[data-wiz-sub]');
        var bar = header.querySelector('[data-wiz-bar]');
        var pillsWrap = header.querySelector('[data-wiz-pills]');
        var readyBox = header.querySelector('[data-wiz-ready]');
        var readyTxt = header.querySelector('[data-wiz-ready-txt]');
        var submitNowBtn = header.querySelector('[data-wiz-submit-now]');
        var cancelAutoBtn = header.querySelector('[data-wiz-cancel-auto]');

        var isStepComplete = function (step) {
            var fileInp = step.querySelector('input[type="file"]');
            if (fileInp) {
                if (!fileInp.files || !fileInp.files.length) return false;
                var sel = step.querySelector('select[required]');
                if (sel && !sel.value) return false;
                return true;
            }
            var reqFields = Array.from(step.querySelectorAll('input[required], select[required], textarea[required]'));
            if (!reqFields.length) return false;
            return reqFields.every(function (el) { return String(el.value || '').trim() !== ''; });
        };

        var triggerStepAutoAction = function (step) {
            if (!guided) return;
            var camStart = step.querySelector('[data-camera-start]');
            var fileInp = step.querySelector('input[type="file"]');
            if (camStart && !camStart.hidden && !isStepComplete(step)) {
                setTimeout(function () {
                    if (guided && !camStart.hidden && !isStepComplete(step)) {
                        try { camStart.click(); } catch (e) {}
                    }
                }, 250);
            } else if (fileInp && !step.querySelector('[data-live-capture]') && !isStepComplete(step)) {
                subText.textContent = 'Step ' + (currentIdx + 1) + ' of ' + steps.length + ': Select your document file to auto-advance.';
            }
        };

        var updateUI = function () {
            var doneCount = 0;
            pillsWrap.innerHTML = '';
            steps.forEach(function (step, idx) {
                var done = isStepComplete(step);
                if (done) doneCount++;
                var label = step.dataset.stepLabel || ('Step ' + (idx + 1));
                var pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'btn btn-sm rounded-pill ' + (done ? 'btn-success' : (idx === currentIdx ? 'btn-primary' : 'btn-outline-secondary'));
                pill.style.fontSize = '11px';
                pill.textContent = (done ? '✓ ' : (idx + 1) + '. ') + label;
                pill.addEventListener('click', function () {
                    clearTimeout(autoSubmitTimer);
                    readyBox.classList.add('d-none');
                    currentIdx = idx;
                    showAll = false;
                    toggleBtn.textContent = 'Show all steps';
                    updateUI();
                    triggerStepAutoAction(steps[currentIdx]);
                });
                pillsWrap.appendChild(pill);

                if (showAll) {
                    step.style.display = '';
                    step.classList.remove('border-primary');
                } else {
                    step.style.display = (idx === currentIdx) ? '' : 'none';
                    step.classList.toggle('border-primary', idx === currentIdx);
                }
            });

            var pct = Math.round((doneCount / steps.length) * 100);
            bar.style.width = pct + '%';

            if (!showAll) {
                subText.textContent = 'Stage ' + (currentIdx + 1) + ' of ' + steps.length + ': ' + (steps[currentIdx].dataset.stepLabel || '') + ' (' + doneCount + '/' + steps.length + ' done)';
            } else {
                subText.textContent = doneCount + ' of ' + steps.length + ' stages completed.';
            }
        };

        var checkAndAdvance = function (fromIdx) {
            clearTimeout(autoSubmitTimer);
            var firstIncomplete = -1;
            for (var i = 0; i < steps.length; i++) {
                if (!isStepComplete(steps[i])) {
                    firstIncomplete = i;
                    break;
                }
            }
            if (firstIncomplete === -1) {
                // Every stage in this wizard is complete!
                updateUI();
                if (guided && submitBtn) {
                    readyBox.classList.remove('d-none');
                    var left = 2;
                    readyTxt.textContent = 'All stages completed! Submitting automatically in ' + left + 's...';
                    autoSubmitTimer = setInterval(function () {
                        left--;
                        if (left <= 0) {
                            clearInterval(autoSubmitTimer);
                            readyTxt.textContent = 'Submitting your verification...';
                            submitBtn.click();
                        } else {
                            readyTxt.textContent = 'All stages completed! Submitting automatically in ' + left + 's...';
                        }
                    }, 1000);
                }
                return;
            }

            // Advance automatically to the next incomplete step
            currentIdx = (fromIdx + 1 < steps.length && !isStepComplete(steps[fromIdx + 1]))
                ? (fromIdx + 1)
                : firstIncomplete;
            updateUI();
            triggerStepAutoAction(steps[currentIdx]);
        };

        steps.forEach(function (step, idx) {
            var liveBox = step.querySelector('[data-live-capture]');
            if (liveBox) {
                liveBox.addEventListener('bc:capture-complete', function () {
                    setTimeout(function () { checkAndAdvance(idx); }, 650);
                });
            } else {
                var fileInp = step.querySelector('input[type="file"]');
                var sel = step.querySelector('select');
                if (fileInp) {
                    fileInp.addEventListener('change', function () {
                        // Wait briefly so inline blank/AV file-checks run first
                        setTimeout(function () {
                            if (isStepComplete(step)) {
                                checkAndAdvance(idx);
                            } else {
                                updateUI();
                            }
                        }, 350);
                    });
                }
                if (sel) {
                    sel.addEventListener('change', function () {
                        if (isStepComplete(step)) checkAndAdvance(idx);
                        else updateUI();
                    });
                }
                var textInputs = Array.from(step.querySelectorAll('input[type="text"], input[type="email"]'));
                textInputs.forEach(function (ti) {
                    ti.addEventListener('input', updateUI);
                });
            }
            var nextBtn = step.querySelector('[data-step-next]');
            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    if (!isStepComplete(step)) {
                        var firstReq = step.querySelector('[required]');
                        if (firstReq && firstReq.reportValidity) firstReq.reportValidity();
                        return;
                    }
                    checkAndAdvance(idx);
                });
            }
        });

        startBtn.addEventListener('click', function () {
            guided = true;
            showAll = false;
            toggleBtn.textContent = 'Show all steps';
            startBtn.textContent = 'Guided Mode Active';
            startBtn.classList.remove('btn-primary');
            startBtn.classList.add('btn-success');
            var firstPending = steps.findIndex(function (s) { return !isStepComplete(s); });
            currentIdx = firstPending >= 0 ? firstPending : 0;
            updateUI();
            triggerStepAutoAction(steps[currentIdx]);
        });

        toggleBtn.addEventListener('click', function () {
            showAll = !showAll;
            toggleBtn.textContent = showAll ? 'Focus active step' : 'Show all steps';
            updateUI();
        });

        submitNowBtn.addEventListener('click', function () {
            clearInterval(autoSubmitTimer);
            if (submitBtn) submitBtn.click();
        });

        cancelAutoBtn.addEventListener('click', function () {
            clearInterval(autoSubmitTimer);
            readyBox.classList.add('d-none');
            showAll = true;
            toggleBtn.textContent = 'Focus active step';
            updateUI();
        });

        // Default view: focus first step cleanly (or show all if user prefers)
        var initialPending = steps.findIndex(function (s) { return !isStepComplete(s); });
        currentIdx = initialPending >= 0 ? initialPending : 0;
        updateUI();
    });
})();
