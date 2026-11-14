/* ==========================================================================
   License Manager — console behaviour
   - drawer + tab bar (mobile)
   - copy buttons, tabs, modals, toast
   - lmPost(): the hardened AJAX helper every admin page calls

   lmPost() is deliberately defensive: shared hosts behind mod_security often
   answer a POST with a bare "403 Forbidden" before PHP runs. It retries with
   base64-encoded values, then probes ping.php to name the layer that refused.
   No dependencies.
   ========================================================================== */
(function () {
    'use strict';

    var doc = document;

    /* --------------------------------------------------------- csrf + meta */

    var csrfMeta = doc.querySelector('meta[name="csrf-token"]');
    var versionMeta = doc.querySelector('meta[name="lm-version"]');

    window.LM_CSRF = csrfMeta ? csrfMeta.getAttribute('content') : '';
    window.LM_PAGE_VERSION = versionMeta ? versionMeta.getAttribute('content') : '';

    /* ------------------------------------------------------------- helpers */

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    window.escapeHtml = escapeHtml;

    window.toast = function (message, kind) {
        var el = doc.getElementById('lm-toast');
        if (!el) {
            window.console.log(message);
            return;
        }

        el.textContent = String(message === undefined || message === null ? '' : message);
        var tone = kind === 'error' || kind === 'err' ? 'lm-toast--error'
            : (kind === 'ok' || kind === 'success' ? 'lm-toast--ok' : '');
        el.className = 'lm-toast is-visible' + (tone ? ' ' + tone : '');

        window.clearTimeout(window.__lmToastTimer);
        window.__lmToastTimer = window.setTimeout(function () {
            el.className = 'lm-toast';
        }, 7000);
    };

    window.openModal = function (id) {
        var el = doc.getElementById(id);
        if (el) { el.classList.add('is-open', 'open'); }
    };

    window.closeModal = function (id) {
        var el = doc.getElementById(id);
        if (el) { el.classList.remove('is-open', 'open'); }
    };

    window.lmVersionMismatch = function (result) {
        if (!result || !result.server_version || !window.LM_PAGE_VERSION) { return false; }
        if (result.server_version === window.LM_PAGE_VERSION) { return false; }
        window.toast('This page is from version ' + window.LM_PAGE_VERSION + ' but the server runs '
            + result.server_version + '. Re-upload the whole admin/ folder.', 'error');
        return true;
    };

    /* ------------------------------------------------------------- drawer */

    var drawer = doc.querySelector('[data-lm-drawer]');
    var openers = doc.querySelectorAll('[data-lm-drawer-open]');
    var closers = doc.querySelectorAll('[data-lm-drawer-close]');
    var lastFocus = null;

    function setDrawer(open) {
        if (!drawer) { return; }
        drawer.setAttribute('data-open', open ? 'true' : 'false');
        doc.body.setAttribute('data-lm-drawer', open ? 'true' : 'false');
        Array.prototype.forEach.call(openers, function (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (open) {
            lastFocus = doc.activeElement;
            var first = drawer.querySelector('a, button');
            if (first) { window.setTimeout(function () { first.focus(); }, 40); }
        } else if (lastFocus && lastFocus.focus) {
            lastFocus.focus();
        }
    }

    Array.prototype.forEach.call(openers, function (btn) {
        btn.addEventListener('click', function () {
            setDrawer(!drawer || drawer.getAttribute('data-open') !== 'true');
        });
    });

    Array.prototype.forEach.call(closers, function (btn) {
        btn.addEventListener('click', function () { setDrawer(false); });
    });

    Array.prototype.forEach.call(drawer ? drawer.querySelectorAll('a') : [], function (link) {
        link.addEventListener('click', function () { setDrawer(false); });
    });

    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer && drawer.getAttribute('data-open') === 'true') {
            setDrawer(false);
        }
    });

    /* --------------------------------------------------------- copy buttons */

    Array.prototype.forEach.call(doc.querySelectorAll('[data-lm-copy]'), function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-lm-copy') || '';
            var label = btn.querySelector('span');
            var flash = function (ok) {
                if (!label) { return; }
                var old = label.textContent;
                label.textContent = ok ? 'Copied' : 'Press Ctrl+C';
                window.setTimeout(function () { label.textContent = old; }, 1600);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(
                    function () { flash(true); },
                    function () { flash(false); }
                );
                return;
            }

            var ta = doc.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', 'readonly');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            doc.body.appendChild(ta);
            ta.select();
            try { doc.execCommand('copy'); flash(true); } catch (err) { flash(false); }
            doc.body.removeChild(ta);
        });
    });

    /* ---------------------------------------------------------------- tabs */

    Array.prototype.forEach.call(doc.querySelectorAll('.lm-tabs'), function (list) {
        var buttons = list.querySelectorAll('[role="tab"]');
        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function () {
                Array.prototype.forEach.call(buttons, function (other) {
                    var active = other === btn;
                    other.setAttribute('aria-selected', active ? 'true' : 'false');
                    var panel = doc.getElementById(other.getAttribute('aria-controls'));
                    if (panel) { panel.hidden = !active; }
                });
            });
        });
    });

    /* ------------------------------------------------------------- filters */

    /*
     * <input data-lm-filter="#tableId"> hides rows whose data-filter-text does
     * not contain the typed value. Optional #[id]Empty paragraph is shown when
     * nothing matches. Client side only — the page always renders every row.
     */
    Array.prototype.forEach.call(doc.querySelectorAll('[data-lm-filter]'), function (input) {
        var table = doc.querySelector(input.getAttribute('data-lm-filter'));
        if (!table) { return; }

        var rows = table.querySelectorAll('tbody tr');
        var empty = doc.getElementById(table.id + 'Empty')
            || doc.getElementById(input.id + 'Empty');

        input.addEventListener('input', function () {
            var needle = input.value.trim().toLowerCase();
            var shown = 0;

            Array.prototype.forEach.call(rows, function (row) {
                var haystack = row.getAttribute('data-filter-text');
                if (!haystack) { haystack = row.textContent.toLowerCase(); }
                var match = needle === '' || haystack.indexOf(needle) !== -1;
                row.hidden = !match;
                if (match) { shown++; }
            });

            if (empty) { empty.hidden = shown !== 0; }
        });
    });

    /* -------------------------------------------------------------- modals */

    Array.prototype.forEach.call(doc.querySelectorAll('.lm-modal, .modal'), function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.classList.remove('is-open', 'open');
            }
        });
        Array.prototype.forEach.call(modal.querySelectorAll('[data-lm-modal-close]'), function (btn) {
            btn.addEventListener('click', function () {
                modal.classList.remove('is-open', 'open');
            });
        });
    });

    /* ================================================================== */
    /* lmPost — the AJAX helper shared by every console screen            */
    /* ================================================================== */

    function lmB64(value) {
        var bytes = new TextEncoder().encode(String(value));
        var binary = '';
        for (var i = 0; i < bytes.length; i++) { binary += String.fromCharCode(bytes[i]); }
        return btoa(binary);
    }

    function lmTryParse(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    function lmReadable(text) {
        return String(text)
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function lmFields(data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    if (item !== undefined && item !== null) { fd.append(key, item); }
                });
            } else if (value !== undefined && value !== null) {
                fd.append(key, value);
            }
        });
        fd.append('csrf_token', window.LM_CSRF);
        return fd;
    }

    function lmPostEncoded(url, fd) {
        var out = new FormData();
        fd.forEach(function (value, key) { out.append(key, lmB64(value)); });
        out.append('_encoding', 'base64');
        return fetch(url, {
            method: 'POST',
            body: out,
            headers: { 'X-LM-Encoding': 'base64' },
            credentials: 'same-origin'
        });
    }

    /**
     * Which layer refused the request? Returns a sentence plus probe results.
     */
    async function lmDiagnose(url) {
        var steps = [];

        async function probe(label, target, init) {
            try {
                var response = await fetch(target, Object.assign(
                    { credentials: 'same-origin', cache: 'no-store' }, init || {}
                ));
                var text = await response.text();
                var okay = !!lmTryParse(text);
                steps.push(label + ': HTTP ' + response.status + (okay ? ' (JSON)' : ' (not JSON)'));
                return { ok: !!okay && response.ok, status: response.status, text: text };
            } catch (e) {
                steps.push(label + ': network error — ' + e.message);
                return { ok: false, status: 0, text: '' };
            }
        }

        var big = new FormData();
        big.append('filler', new Array(64 * 1024 + 1).join('x'));

        var get = await probe('GET admin/ping.php', 'ping.php');
        if (!get.ok) {
            if (get.status === 404) {
                return {
                    steps: steps,
                    message: 'admin/ping.php is not on the server, so this cannot be tested yet. '
                        + 'Upload the complete admin/ folder and try again.'
                };
            }
            return {
                steps: steps,
                message: 'The web server refuses files in admin/ before PHP runs — that is .htaccess or a hosting '
                    + 'security rule, not the panel. Rename .htaccess to .htaccess.off in the License Manager folder, '
                    + 'reload, and try again.'
            };
        }

        var post = await probe('POST admin/ping.php (empty)', 'ping.php', { method: 'POST', body: new FormData() });
        if (!post.ok) {
            return {
                steps: steps,
                message: 'PHP answers a GET but not a POST: the host blocks POST requests (mod_security). '
                    + 'Ask the host to whitelist POST for the admin folder, or switch the account to DetectionOnly.'
            };
        }

        var large = await probe('POST admin/ping.php (64 KB)', 'ping.php', { method: 'POST', body: big });
        if (!large.ok) {
            return {
                steps: steps,
                message: 'Small POSTs arrive but a 64 KB body does not: the host caps the request body. '
                    + 'Tick one folder instead of the whole repository, or ask the host to raise SecRequestBodyLimit.'
            };
        }

        var endpoint = await probe('POST ' + url + ' (action=ping)', url, {
            method: 'POST', body: lmFields({ action: 'ping' })
        });
        if (!endpoint.ok) {
            return {
                steps: steps,
                message: 'Other files in admin/ answer, but ' + url + ' is refused — a security rule is matching '
                    + 'that file name or its parameters. Ask the host to whitelist ' + url + ' in ModSecurity.'
            };
        }

        var endpointJson = lmTryParse(endpoint.text) || {};
        if (endpointJson.status !== 'success') {
            return {
                steps: steps,
                message: 'PHP answered ' + url + ' but refused the request: '
                    + (endpointJson.message || 'no reason given')
                    + ' (code ' + (endpointJson.code || 'unknown') + ').'
            };
        }

        return {
            steps: steps,
            message: 'Every probe answered, so the refusal came from the request body itself. The panel retried '
                + 'with encoded values and did not get through either; shorten the text and try again.'
        };
    }

    window.lmPost = async function (url, data) {
        var fd = lmFields(data);
        var response;

        try {
            response = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) {
            return { status: 'error', message: 'Network error: ' + e.message };
        }

        var text = await response.text();
        var parsed = lmTryParse(text);

        if (parsed) {
            window.lmVersionMismatch(parsed);
            return parsed;
        }

        /* Not JSON: either PHP died, or the web server refused the request. */
        var status = response.status;
        var body = text;

        if ([403, 406, 413, 400, 501].indexOf(status) !== -1) {
            try {
                var retry = await lmPostEncoded(url, fd);
                var retryText = await retry.text();
                var retryParsed = lmTryParse(retryText);
                if (retryParsed) {
                    window.toast('Your host blocked the plain request; it went through encoded.', 'info');
                    window.lmVersionMismatch(retryParsed);
                    return retryParsed;
                }
                status = retry.status || status;
                body = retryText || body;
            } catch (e) {
                /* keep the original refusal */
            }
        }

        var clean = lmReadable(body);
        var first = clean.split('\n').filter(function (line) {
            return line.trim() !== '';
        }).slice(0, 2).join(' ');

        var why = '';
        if (status === 403) {
            var diag = await lmDiagnose(url);
            why = ' ' + diag.message;
            return {
                status: 'error',
                http_status: status,
                message: (first || 'HTTP 403.') + why,
                diagnostic: diag.steps,
                raw: clean.slice(0, 600)
            };
        }

        if (status === 401 || status === 419) {
            why = ' Your sign-in has expired: reload this page and sign in again.';
        } else if (status === 404) {
            why = ' That endpoint is not on the server — a file from the release is missing.';
        } else if (status >= 500) {
            why = ' The server errored (HTTP ' + status + ') — check the PHP error log.';
        } else if (/Fatal error|Warning|Notice|Undefined variable|Uncaught/i.test(clean)) {
            why = ' (server error — check the PHP error log)';
        }

        return {
            status: 'error',
            http_status: status,
            message: (first || ('The server returned HTTP ' + status + ' with no explanation.')) + why,
            raw: clean.slice(0, 600)
        };
    };
})();
