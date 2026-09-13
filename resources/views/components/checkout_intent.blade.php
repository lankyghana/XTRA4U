<script>
/* XtraCheckoutIntent: Phase 4 duplicate-charge prevention helper.
 *
 * Generates and caches one idempotency key per distinct checkout attempt so
 * a double-click, a duplicate form submit, or a page refresh WHILE a payment
 * is still unresolved all resubmit the SAME key — letting the server
 * recognise "this is a retry of the same attempt" instead of creating a
 * second charge. The server (CheckoutIntentGuard) is the actual source of
 * truth: this cache is only a convenience so the browser hands back the
 * same key across those retries, never the authority on what counts as a
 * duplicate.
 *
 * `fingerprint` should describe the SPECIFIC purchase being attempted (e.g.
 * vendor+product+recipient, or a plan id) — NOT anything that stays constant
 * across genuinely different purchases. Call clear() as soon as a terminal
 * outcome (paid OR failed) is observed for that fingerprint, so the NEXT
 * distinct purchase attempt — even of the identical product/amount — always
 * gets a brand new key. Without that, a stale cached key would make a
 * genuinely new purchase look like a reuse of the old (already-settled)
 * intent and the server would just replay its old result.
 */
(function (window) {
    if (window.XtraCheckoutIntent) return;

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Fallback for older browsers: not cryptographically strong, but
        // only ever used as a convenience cache key, never as a secret.
        return 'xid-' + Date.now() + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }

    function storageKey(fingerprint) {
        return 'xtra_checkout_intent:' + fingerprint;
    }

    window.XtraCheckoutIntent = {
        getKey(fingerprint) {
            try {
                const key = storageKey(fingerprint);
                let value = window.sessionStorage.getItem(key);
                if (!value) {
                    value = uuid();
                    window.sessionStorage.setItem(key, value);
                }
                return value;
            } catch (e) {
                // Private browsing / storage disabled — degrade to a
                // one-shot key. Double-click protection on THIS click still
                // works (same in-memory value used for the whole submit),
                // just without surviving a page refresh.
                return uuid();
            }
        },

        // Call after any TERMINAL outcome (paid or confirmed-failed) so the
        // next distinct attempt for this fingerprint gets a fresh key.
        clear(fingerprint) {
            try {
                window.sessionStorage.removeItem(storageKey(fingerprint));
            } catch (e) {
                // ignore
            }
        },
    };
})(window);
</script>
