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
        }
    };

    window.InlinePaymentManager = InlinePaymentManager;
})(window);
</script>
