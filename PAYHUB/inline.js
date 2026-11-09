/**
 * Payhub Inline Checkout JS
 */
const PayhubPop = {
    setup: function(options) {
        const scriptSource = document.currentScript ? document.currentScript.src : '';
        const baseUrl = scriptSource ? scriptSource.substring(0, scriptSource.lastIndexOf('/') + 1) : '';

        return {
            openIframe: function() {
                console.log("Opening Payhub Inline Checkout for", options.email);

                // Create overlay
                const overlay = document.createElement('div');
                overlay.style.position = 'fixed';
                overlay.style.top = '0';
                overlay.style.left = '0';
                overlay.style.width = '100%';
                overlay.style.height = '100%';
                overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                overlay.style.backdropFilter = 'blur(4px)';
                overlay.style.zIndex = '999999';
                overlay.style.display = 'flex';
                overlay.style.alignItems = 'center';
                overlay.style.justifyContent = 'center';
                overlay.id = 'payhub-checkout-overlay';

                // Create iframe container
                const container = document.createElement('div');
                container.style.width = '100%';
                container.style.maxWidth = '450px';
                container.style.height = '600px';
                container.style.backgroundColor = '#fff';
                container.style.borderRadius = '24px';
                container.style.overflow = 'hidden';
                container.style.boxShadow = '0 25px 50px -12px rgba(0, 0, 0, 0.25)';
                container.style.position = 'relative';

                // Close button
                const closeBtn = document.createElement('button');
                closeBtn.innerHTML = '&times;';
                closeBtn.style.position = 'absolute';
                closeBtn.style.top = '20px';
                closeBtn.style.right = '20px';
                closeBtn.style.width = '32px';
                closeBtn.style.height = '32px';
                closeBtn.style.borderRadius = '50%';
                closeBtn.style.border = 'none';
                closeBtn.style.backgroundColor = '#f1f5f9';
                closeBtn.style.color = '#64748b';
                closeBtn.style.fontSize = '24px';
                closeBtn.style.cursor = 'pointer';
                closeBtn.style.zIndex = '10';
                closeBtn.onclick = () => {
                    document.body.removeChild(overlay);
                    if (options.onClose) options.onClose();
                };

                // Tell the checkout which origin it may deliver its result to,
                // so it can target this page exactly instead of broadcasting.
                const checkoutUrl = baseUrl + 'checkout.php'
                    + '?amount=' + encodeURIComponent(options.amount / 100)
                    + '&email=' + encodeURIComponent(options.email)
                    + '&ref=' + encodeURIComponent(options.ref)
                    + '&origin=' + encodeURIComponent(window.location.origin)
                    + '&embed=1';

                // The origin the result must come from — derived from where
                // this script itself was served.
                let checkoutOrigin;
                try {
                    checkoutOrigin = new URL(baseUrl, window.location.href).origin;
                } catch (e) {
                    checkoutOrigin = null;
                }

                const iframe = document.createElement('iframe');
                iframe.src = checkoutUrl;
                iframe.style.width = '100%';
                iframe.style.height = '100%';
                iframe.style.border = 'none';
                iframe.setAttribute('allow', 'clipboard-read; clipboard-write');

                container.appendChild(closeBtn);
                container.appendChild(iframe);
                overlay.appendChild(container);
                document.body.appendChild(overlay);

                // Listen for messages from the checkout iframe.
                //
                // The origin check is what makes this trustworthy: without it
                // ANY frame on the merchant's page — an ad, a widget, an
                // injected iframe — could post a payhub_success and drive the
                // merchant's success callback without a payment ever happening.
                // The listener is also removed once it fires, so repeated
                // checkouts do not stack duplicate handlers that re-fire the
                // callback.
                function onCheckoutMessage(event) {
                    if (checkoutOrigin && event.origin !== checkoutOrigin) { return; }
                    if (event.source !== iframe.contentWindow) { return; }
                    if (!event.data || event.data.type !== 'payhub_success') { return; }

                    window.removeEventListener('message', onCheckoutMessage, false);

                    if (overlay.parentNode) { document.body.removeChild(overlay); }
                    if (options.callback) options.callback(event.data.data);
                }

                window.addEventListener('message', onCheckoutMessage, false);

                // Drop the listener if the customer just closes the popup.
                const previousOnClose = options.onClose;
                options.onClose = function () {
                    window.removeEventListener('message', onCheckoutMessage, false);
                    if (previousOnClose) previousOnClose();
                };
            }
        };
    }
};
