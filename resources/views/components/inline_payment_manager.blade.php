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

    const InlinePaymentManager = {
        open(opts = {}, onComplete) {
            const { reference, authorization_url = null, gateway_name = null, flow_type = 'checkout', callback_url = null, no_redirect = false } = opts || {};
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

            // onUpdate will handle status transitions
            function onUpdate(status) {
                if (status === 'paid' || status === 'completed') {
                    stopPolling();
                    InlinePaymentManager.close();
                    restoreSubmits();
                    
                    let handled = false;
                    if (typeof onComplete === 'function') {
                        handled = onComplete('paid');
                    }

                    if (!no_redirect) {
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
                    }
                } else if (status === 'failed') {
                    stopPolling();
                    InlinePaymentManager.close();
                    restoreSubmits();
                    if (typeof onComplete === 'function') onComplete('failed');
                    if (!no_redirect) {
                        alert('Payment failed. Please try another method.');
                    }
                } else if (status === 'timeout') {
                    stopPolling();
                    InlinePaymentManager.close();
                    restoreSubmits();
                    if (typeof onComplete === 'function') onComplete('timeout');
                    if (!no_redirect) {
                        alert('Payment confirmation timed out. Please check your payment provider or try again.');
                    }
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
