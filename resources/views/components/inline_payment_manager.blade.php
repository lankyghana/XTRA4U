<script>
/* InlinePaymentManager: Unified inline payment UI for all gateways.
   Usage: InlinePaymentManager.open({ reference, authorization_url, gateway_name }, onComplete)
   onComplete(status) called with 'paid'|'failed'|'timeout'
*/
(function (window) {
    if (window.InlinePaymentManager) return;

    const API_STATUS_PATH = '/payment/status/';

    function createModal() {
        let existing = document.getElementById('inline-payment-modal');
        if (existing) return existing;

        const modal = document.createElement('div');
        modal.id = 'inline-payment-modal';
        modal.style.position = 'fixed';
        modal.style.left = '0';
        modal.style.top = '0';
        modal.style.right = '0';
        modal.style.bottom = '0';
        modal.style.background = 'rgba(0,0,0,0.6)';
        modal.style.zIndex = '99999';
        modal.innerHTML = `
            <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:90%;max-width:900px;height:80vh;background:#fff;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;">
                <div style="padding:8px 12px;background:#f7fafc;display:flex;justify-content:space-between;align-items:center;">
                    <strong id="ipm-title">Complete payment</strong>
                    <button id="inline-payment-close" style="background:#ef4444;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;">Close</button>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;">
                    <div id="inline-payment-loader" style="padding:16px;text-align:center;background:#fff;">
                        <div style="font-weight:600;margin-bottom:6px;">Waiting for payment confirmation…</div>
                        <div style="color:#6b7280;font-size:13px;">Do not close this window. You may be redirected to a payment provider.</div>
                    </div>
                    <iframe id="inline-payment-frame" style="flex:1;border:0;width:100%;height:100%;display:block;"></iframe>
                </div>
            </div>`;

        document.body.appendChild(modal);
        document.getElementById('inline-payment-close').addEventListener('click', () => {
            InlinePaymentManager.close();
        });

        return modal;
    }

    let payazaSdkPromise = null;

    /* Lazily load the Payaza Web Checkout SDK bundle exactly once. */
    function loadPayazaSdk() {
        if (payazaSdkPromise) return payazaSdkPromise;
        payazaSdkPromise = new Promise((resolve, reject) => {
            if (window.PayazaCheckout) { resolve(); return; }
            const script = document.createElement('script');
            script.src = 'https://checkout-v2.payaza.africa/js/v1/bundle.js';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load Payaza checkout SDK'));
            document.head.appendChild(script);
        });
        return payazaSdkPromise;
    }

    let pollTimer = null;

    function startPolling(reference, onUpdate, poll_url = null, timeoutMs = 3 * 60 * 1000) {
        if (pollTimer) return;
        const interval = 3000;
        const maxCount = Math.ceil(timeoutMs / interval);
        let count = 0;

        pollTimer = setInterval(async () => {
            count++;
            try {
                const url = poll_url ? poll_url : (API_STATUS_PATH + encodeURIComponent(reference));
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const body = await res.json();
                const status = (body && body.status) ? body.status : 'pending';
                if (typeof onUpdate === 'function') onUpdate(status);

                if (status === 'paid' || status === 'completed' || status === 'failed') {
                    clearInterval(pollTimer); pollTimer = null;
                }
            } catch (e) {
                // ignore transient errors
                console.error('InlinePaymentManager poll error', e);
            }

            if (count >= maxCount) {
                if (typeof onUpdate === 'function') onUpdate('timeout');
                clearInterval(pollTimer); pollTimer = null;
            }
        }, interval);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    /* Replace the iframe/loader with a final outcome panel so the user sees a
       clear "Payment completed" (or failed) state instead of whatever page the
       gateway's post-payment redirect happens to land on. */
    function showOutcome(kind, title, subtitle) {
        const loader = document.getElementById('inline-payment-loader');
        const iframe = document.getElementById('inline-payment-frame');
        const heading = document.getElementById('ipm-title');
        if (iframe) iframe.style.display = 'none';
        if (!loader) return;

        const colours = { success: '#16a34a', error: '#dc2626', info: '#6b7280' };
        const icons = {
            success: '<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 12.5l2.5 2.5L16 9"></path></svg>',
            error: '<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M15 9l-6 6M9 9l6 6"></path></svg>',
            info: '<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 7v5l3 2"></path></svg>',
        };

        if (heading) {
            heading.textContent = kind === 'success' ? 'Payment completed'
                : (kind === 'error' ? 'Payment failed' : 'Complete payment');
        }

        loader.style.display = 'block';
        loader.innerHTML = `
            <div style="padding:40px 16px;text-align:center;">
                ${icons[kind] || icons.info}
                <div style="font-weight:700;font-size:18px;margin-top:12px;color:${colours[kind] || colours.info};">${title}</div>
                ${subtitle ? `<div style="color:#6b7280;font-size:13px;margin-top:6px;">${subtitle}</div>` : ''}
            </div>`;
    }

    const InlinePaymentManager = {
        open(opts = {}, onComplete) {
            const { reference, authorization_url = null, gateway_name = null, flow_type = 'checkout', callback_url = null, no_redirect = false, poll_url = null } = opts || {};
            if (!reference) {
                console.warn('InlinePaymentManager.open called without reference');
                return;
            }

            const modal = createModal();
            const iframe = document.getElementById('inline-payment-frame');
            const loader = document.getElementById('inline-payment-loader');

            // Disable submits
            const submits = document.querySelectorAll('button[type=submit], input[type=submit]');
            submits.forEach(s => s.setAttribute('disabled', 'disabled'));

            function restoreSubmits() { submits.forEach(s => s.removeAttribute('disabled')); }

            // Show the outcome in the modal first, then close and hand off to
            // onComplete / the redirect after a short pause so the user always
            // sees a clear final state instead of an abrupt disappearance.
            let settled = false;

            function finish(status) {
                if (settled) return;
                settled = true;
                stopPolling();

                if (status === 'paid') {
                    showOutcome('success', 'Payment completed', 'Thank you! Finishing up…');
                } else if (status === 'failed') {
                    showOutcome('error', 'Payment failed', 'No charge was completed. You can try again.');
                } else {
                    showOutcome('info', 'Still waiting for confirmation', 'If you approved the payment, it may take a moment to reflect. This window will close shortly.');
                }

                setTimeout(() => {
                    InlinePaymentManager.close();
                    restoreSubmits();

                    let handled = false;
                    if (typeof onComplete === 'function') {
                        handled = onComplete(status);
                    }

                    if (no_redirect) return;

                    if (status === 'paid') {
                        // For wallet top-ups, invoke callback to credit wallet before redirecting
                        if (flow_type === 'wallet_topup') {
                            const cbUrl = callback_url || `/vendor/wallet/topup/callback/${encodeURIComponent(reference)}`;
                            fetch(cbUrl, { headers: { 'Accept': 'application/json' } })
                                .then(() => {
                                    window.location.href = '/vendor/wallet?tab=topups';
                                })
                                .catch(err => {
                                    console.error('Wallet callback error:', err);
                                    window.location.href = '/vendor/wallet?tab=topups';
                                });
                        } else {
                            // For checkout flow, redirect to checkout success
                            window.location.href = '/checkout/success';
                        }
                    } else if (status === 'failed') {
                        alert('Payment failed. Please try another method.');
                    } else if (status === 'timeout') {
                        alert('Payment confirmation timed out. Please check your payment provider or try again.');
                    }
                }, status === 'paid' ? 1800 : 3000);
            }

            function onUpdate(status) {
                if (status === 'paid' || status === 'completed') {
                    finish('paid');
                } else if (status === 'failed' || status === 'timeout') {
                    finish(status);
                }
            }

            // If provider gave an authorization_url, attempt to embed it; fallback to new tab on failure.
            if (authorization_url) {
                let embedTimeout = setTimeout(() => {
                    // fallback: open in new tab and continue polling
                    InlinePaymentManager.close();
                    window.open(authorization_url, '_blank');
                    startPolling(reference, onUpdate, poll_url);
                }, 4000);

                iframe.onload = function () {
                    clearTimeout(embedTimeout);
                    if (loader) loader.style.display = 'block';
                    startPolling(reference, onUpdate, poll_url);
                };

                iframe.src = authorization_url;
            } else {
                // No auth URL to embed — just start polling and show loader
                if (loader) loader.style.display = 'block';
                startPolling(reference, onUpdate, poll_url);
            }
        },

        close() {
            stopPolling();
            const modal = document.getElementById('inline-payment-modal');
            if (modal) modal.remove();
            const iframe = document.getElementById('inline-payment-frame');
            if (iframe) try { iframe.src = 'about:blank'; } catch (e) {}
            const submits = document.querySelectorAll('button[type=submit], input[type=submit]');
            submits.forEach(s => s.removeAttribute('disabled'));
        },

        /* Payaza Web Checkout SDK.
           Usage: InlinePaymentManager.openPayaza({ reference, checkout_config, verify_url }, onComplete)
              or: InlinePaymentManager.openPayaza({ reference, checkout_config, poll_url, no_redirect }, onComplete)
           onComplete(status) called with 'paid'|'failed'|'timeout'|'sdk_unavailable'.
           'sdk_unavailable' means the Payaza SDK script never loaded/initialised
           (e.g. blocked by CSP, network error) — no payment was attempted, so
           callers must not present it the same way as 'failed'.

           Two confirmation modes, chosen by which URL the caller supplies —
           every existing flow already has one of these two shapes, so this
           never needs its own bespoke settlement logic:
             - verify_url: POST {reference} once per check, expects
               {status: 'success'|'failed'|'pending', redirect}. Used by pages
               whose backend does a single verify-then-complete call (main
               checkout, AFA, Result Checker).
             - poll_url: handed straight to the existing open()/startPolling()
               GET-polling primitive below — i.e. once the popup closes, this
               is treated exactly like any other inline gateway already
               polled on that page (USSD subscription).

           SECURITY: the SDK's own `callback`/`onClose` results are never
           trusted directly — both modes end in a server round-trip (either
           verify_url's own request, or poll_url's, which itself calls the
           existing verify+complete logic) before anything is treated as paid. */
        openPayaza(opts = {}, onComplete) {
            const {
                reference,
                checkout_config: checkoutConfig,
                verify_url: verifyUrl = null,
                poll_url: pollUrl = null,
                gateway_name: gatewayName = 'payaza',
                no_redirect: noRedirect = false,
            } = opts || {};

            if (!reference || !checkoutConfig || !checkoutConfig.merchant_key) {
                console.warn('InlinePaymentManager.openPayaza called without reference/checkout_config');
                return;
            }

            if (!verifyUrl && !pollUrl) {
                console.warn('InlinePaymentManager.openPayaza requires either verify_url or poll_url');
                return;
            }

            const submits = document.querySelectorAll('button[type=submit], input[type=submit]');
            submits.forEach(s => s.setAttribute('disabled', 'disabled'));

            let popupHandled = false;

            function finish(status, redirectUrl) {
                submits.forEach(s => s.removeAttribute('disabled'));

                if (typeof onComplete === 'function') onComplete(status);

                if (status === 'paid') {
                    window.location.href = redirectUrl || '/checkout/success';
                } else if (status === 'failed') {
                    alert('Payment failed. Please try another method.');
                } else if (status === 'timeout') {
                    alert('Payment confirmation timed out. Please check your payment provider or try again.');
                } else if (status === 'sdk_unavailable') {
                    // The SDK itself never loaded/initialised — no payment was ever
                    // attempted, so this must never read as "payment failed". Keep
                    // the message generic; the underlying cause (e.g. a blocked
                    // script, network hiccup) is not something a customer can act on.
                    alert('Unable to load the payment service. Please try again or choose another payment method.');
                }
            }

            async function checkNow() {
                try {
                    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    const res = await fetch(verifyUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ reference }),
                    });
                    return await res.json();
                } catch (e) {
                    console.error('Payaza server-side verify error', e);
                    return null;
                }
            }

            let settled = false;

            function pollUntilSettled(maxAttempts, intervalMs) {
                maxAttempts = maxAttempts || 20;
                intervalMs = intervalMs || 3000;
                let attempts = 0;

                const timer = setInterval(async () => {
                    if (settled) { clearInterval(timer); return; }
                    attempts++;
                    const body = await checkNow();
                    const status = body && body.status;

                    if (status === 'success') {
                        clearInterval(timer);
                        settled = true;
                        finish('paid', body.redirect);
                    } else if (status === 'failed') {
                        clearInterval(timer);
                        settled = true;
                        finish('failed');
                    } else if (attempts >= maxAttempts) {
                        clearInterval(timer);
                        settled = true;
                        finish('timeout');
                    }
                }, intervalMs);
            }

            // Called once the popup itself has produced some result (a genuine
            // completion or the customer closing it) — the popup's job is done
            // either way; from here on this is just "confirm with our backend".
            function confirmWithBackend() {
                if (popupHandled) return;
                popupHandled = true;

                if (verifyUrl) {
                    checkNow().then((body) => {
                        const status = body && body.status;
                        if (status === 'success') {
                            settled = true;
                            finish('paid', body.redirect);
                        } else if (status === 'failed') {
                            settled = true;
                            finish('failed');
                        } else {
                            // Pending: the popup's own event fired before the status
                            // query settled (or before our webhook landed). Keep
                            // checking briefly rather than failing prematurely.
                            pollUntilSettled();
                        }
                    });
                    return;
                }

                // poll_url mode: hand off entirely to the existing generic
                // inline-gateway polling loop used elsewhere on this page.
                submits.forEach(s => s.setAttribute('disabled', 'disabled'));
                InlinePaymentManager.open({
                    reference,
                    authorization_url: null,
                    gateway_name: gatewayName,
                    poll_url: pollUrl,
                    no_redirect: noRedirect,
                }, onComplete);
            }

            loadPayazaSdk().then(() => {
                if (!window.PayazaCheckout) {
                    console.error('Payaza SDK loaded but PayazaCheckout is undefined');
                    finish('sdk_unavailable');
                    return;
                }

                const checkout = window.PayazaCheckout.setup(Object.assign({}, checkoutConfig, {
                    // The SDK's own response payload is client-side and untrusted
                    // (see docblock above) — callback and onClose both just trigger
                    // the same server-side confirmation path.
                    callback: function () {
                        confirmWithBackend();
                    },
                    onClose: function () {
                        confirmWithBackend();
                    },
                }));

                checkout.showPopup();
            }).catch((e) => {
                // SDK failed to load/attach (e.g. blocked by CSP, network error) — no
                // payment attempt was ever made, so this is distinct from a real
                // 'failed' payment and must not be reported to the customer as one.
                console.error('Payaza SDK failed to load', e);
                finish('sdk_unavailable');
            });
        }
    };

    window.InlinePaymentManager = InlinePaymentManager;
})(window);
</script>
