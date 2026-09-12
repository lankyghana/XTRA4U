<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\WalletTopup;
use App\Services\Payments\CheckoutIntentGuard;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\PaymentVerificationState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class VendorWalletController extends Controller
{
    protected PaymentService $paymentService;

    protected WalletService $walletService;

    protected CheckoutIntentGuard $intentGuard;

    public function __construct(?PaymentService $paymentService = null, ?WalletService $walletService = null, ?CheckoutIntentGuard $intentGuard = null)
    {
        $this->paymentService = $paymentService ?? new PaymentService;
        $this->walletService = $walletService ?? new WalletService;
        $this->intentGuard = $intentGuard ?? app(CheckoutIntentGuard::class);
    }

    private function topupIsSuccess(WalletTopup $t): bool
    {
        return $t->status === 'completed';
    }

    private function topupIsTerminalFailure(WalletTopup $t): bool
    {
        return $t->status === 'failed';
    }

    private function topupHasGateway(WalletTopup $t): bool
    {
        return (bool) $t->payment_gateway && (bool) $t->reference;
    }

    // Initiate top-up via existing gateway; callback_url will point to `wallet.topup.callback`
    // SECURITY: Only the authenticated vendor can initiate top-ups for their own wallet.
    // We never accept vendor_id from the request - it is derived solely from the authenticated user.
    public function initiateTopup(Request $request)
    {
        $vendor = $this->resolveAuthenticatedVendor();
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'return_url' => 'nullable|url',
            'payer_phone' => 'nullable|string',
            'network' => 'nullable|string',
            // Phase 4: identifies one payment ATTEMPT for one top-up intent.
            // Optional for backward compatibility — see CheckoutIntentGuard.
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $vendorId = (int) $vendor->id;
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $intentScope = CheckoutIntentGuard::scopeForVendor($vendorId);

        $intentDecision = $this->intentGuard->evaluate(
            WalletTopup::class,
            $intentScope,
            $idempotencyKey,
            fn (WalletTopup $t) => $this->topupIsSuccess($t),
            fn (WalletTopup $t) => $this->topupIsTerminalFailure($t),
            fn (WalletTopup $t) => $this->topupHasGateway($t),
        );

        if ($intentDecision['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
            $existing = $intentDecision['payable'];

            return response()->json([
                'success' => true,
                'status' => 'completed',
                'message' => 'Top-up already processed.',
                'reference' => $existing->reference,
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::STILL_PENDING) {
            $existing = $intentDecision['payable'];

            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous top-up. Please don't pay again yet.",
                'reference' => $existing->reference,
                'payment_type' => 'wallet_topup',
            ]);
        }

        if ($intentDecision['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE && $idempotencyKey) {
            $idempotencyKey = $this->intentGuard->freshKeyAfterFailure($idempotencyKey);
        }

        $reference = Str::uuid()->toString();

        // Persist a short-lived mapping so callbacks that don't return metadata
        // (some gateways don't echo back custom metadata) can still be reconciled.
        Cache::put("wallet_topup:{$reference}", [
            'vendor_id' => $vendorId,
            'amount' => (float) $validated['amount'],
        ], now()->addHours(6));

        // Also persist a DB record for stronger auditability and reconciliation.
        // Race-safe: two concurrent top-up submissions for the same intent can
        // create at most one row here — see CheckoutIntentGuard::createOrReuse().
        $creationResult = $this->intentGuard->createOrReuse(
            WalletTopup::class,
            $intentScope,
            $idempotencyKey,
            fn (?string $key) => WalletTopup::create([
                'reference' => $reference,
                'vendor_id' => $vendorId,
                'amount' => (float) $validated['amount'],
                'metadata' => ['purpose' => 'wallet_topup'],
                'status' => 'initiated',
                'idempotency_scope' => $key ? $intentScope : null,
                'idempotency_key' => $key,
            ]),
            fn (WalletTopup $t) => $this->topupIsSuccess($t),
            fn (WalletTopup $t) => $this->topupIsTerminalFailure($t),
            fn (WalletTopup $t) => $this->topupHasGateway($t),
        );

        if ($creationResult['action'] === CheckoutIntentGuard::ALREADY_SUCCEEDED) {
            Cache::forget("wallet_topup:{$reference}");
            $existing = $creationResult['payable'];

            return response()->json([
                'success' => true,
                'status' => 'completed',
                'message' => 'Top-up already processed.',
                'reference' => $existing->reference,
            ]);
        }

        if ($creationResult['action'] === CheckoutIntentGuard::STILL_PENDING) {
            Cache::forget("wallet_topup:{$reference}");
            $existing = $creationResult['payable'];

            return response()->json([
                'success' => true,
                'status' => 'confirming',
                'message' => "We're still confirming your previous top-up. Please don't pay again yet.",
                'reference' => $existing->reference,
                'payment_type' => 'wallet_topup',
            ]);
        }

        if ($creationResult['action'] === CheckoutIntentGuard::RETRY_AFTER_FAILURE) {
            Cache::forget("wallet_topup:{$reference}");

            return response()->json(['success' => false, 'message' => 'Please try again.'], 409);
        }

        $callbackUrl = route('vendor.wallet.topup.callback', ['reference' => $reference]);

        // Include vendor phone when available so inline gateways that require
        // a MoMo number (e.g. BulkClix) can initiate direct MoMo prompts.
        $metadata = [
            'vendor_id' => $vendorId,
            'purpose' => 'wallet_topup',
        ];

        // Allow caller to provide an overriding payer phone (useful when vendor has no phone on file)
        $payerPhone = $request->input('payer_phone') ?? $request->input('phone') ?? null;
        if ($payerPhone && is_string($payerPhone) && trim($payerPhone) !== '') {
            $metadata['phone_number'] = trim((string) $payerPhone);
        } elseif ($vendor && ! empty(trim((string) $vendor->phone_number))) {
            $metadata['phone_number'] = trim((string) $vendor->phone_number);
        }

        // Allow caller to hint the network (MTN/TELECEL/AIRTELTIGO). Gateways may
        // still attempt to infer network if this is missing.
        $network = $request->input('network') ?? null;
        if ($network && is_string($network) && trim($network) !== '') {
            $metadata['network'] = strtoupper(trim((string) $network));
        }

        $result = $this->paymentService->initiateGenericPayment($vendor->email ?? 'noreply@xtra4u.com', (float) $validated['amount'], $callbackUrl, $reference, $metadata);

        if (! $result['success']) {
            // Clean up the DB record and cache if gateway rejected the initiation
            WalletTopup::where('reference', $reference)->delete();
            Cache::forget("wallet_topup:{$reference}");

            return response()->json(['success' => false, 'message' => $result['message'] ?? 'Failed to initiate top-up'], 400);
        }

        // The gateway may return a different reference (e.g. BulkClix generates its own transaction ID
        // like XTRA4U-BCX-xxx from our UUID). If so, update the DB record and cache to use the gateway's
        // reference so that status polling and callbacks resolve correctly.
        $gatewayReference = $result['reference'] ?? null;
        if ($gatewayReference && $gatewayReference !== $reference) {
            // Migrate the WalletTopup record to the gateway reference
            WalletTopup::where('reference', $reference)->update(['reference' => $gatewayReference]);
            // Migrate the cache mapping
            $cached = Cache::get("wallet_topup:{$reference}");
            Cache::forget("wallet_topup:{$reference}");
            if (is_array($cached)) {
                Cache::put("wallet_topup:{$gatewayReference}", $cached, now()->addHours(6));
            }
            $reference = $gatewayReference;
        }

        // Persist which gateway actually created this top-up so it can always
        // be verified against that gateway specifically — never whichever
        // gateway happens to be the platform default later. GatewayManager::
        // genericPayment() sets 'gateway_name' from the config it resolved at
        // initiation time (see PaymentReconciliationService audit's wallet-
        // topup schema gap).
        WalletTopup::where('reference', $reference)->update([
            'payment_gateway' => $result['gateway_name'] ?? null,
        ]);

        // Auto-detect flow_type: gateways like BulkClix/Moolre set it explicitly to 'inline'.
        // Paystack (redirect) returns an authorization_url but never sets flow_type, so we
        // infer 'redirect' from the presence of an authorization_url. Only fall back to
        // 'inline' when neither is available (pure polling / prompt-based flows).
        $authorizationUrl = $result['authorization_url'] ?? null;
        $flowType = $result['flow_type'] ?? ($authorizationUrl ? 'redirect' : 'inline');

        return response()->json([
            'success' => true,
            'reference' => $reference,
            'authorization_url' => $authorizationUrl,
            'flow_type' => $flowType,
            'gateway_name' => $result['gateway_name'] ?? null,
            'payment_type' => 'wallet_topup',
        ]);
    }

    // Callback/capture endpoint for top-up (gateway should call or redirect here)
    public function topupCallback(Request $request, string $reference)
    {
        // Look up the record BEFORE verifying, so we can verify against the
        // gateway that actually created this top-up rather than whichever
        // gateway is currently the platform default.
        $topupRecord = WalletTopup::where('reference', $reference)->first();

        $status = $this->verifyWalletTopupReference($reference, $topupRecord);

        if ($status === null) {
            // No stored gateway to verify against — either a legacy top-up
            // predating the payment_gateway column, or (rare) no record found
            // at all. Per the reconciliation-hardening audit: never guess the
            // gateway and never fall back to whichever gateway is currently
            // default. Leave it unresolved for manual review rather than
            // reporting a false outcome either way.
            Log::warning('Wallet topup callback: no reliable gateway to verify against, leaving unresolved', [
                'reference' => $reference,
                'topup_id' => $topupRecord?->id,
            ]);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'status' => 'pending', 'message' => 'Payment pending confirmation.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Payment pending confirmation.');
        }

        $verificationState = PaymentVerificationState::from($status);

        if ($verificationState === PaymentVerificationState::FAILED) {
            // Authoritative terminal failure from the gateway. Idempotent: lock
            // the row and never overwrite an already-completed top-up (a
            // failure verification racing a just-credited success must lose).
            DB::transaction(function () use ($topupRecord, $status) {
                $locked = WalletTopup::whereKey($topupRecord->id)->lockForUpdate()->first();
                if ($locked && $locked->status !== 'completed') {
                    $locked->update(['status' => 'failed', 'gateway_response' => $status]);
                }
            });

            Log::info('Wallet topup: payment failed', ['reference' => $reference, 'status' => $status]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'status' => 'failed', 'message' => 'Payment failed.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Payment failed.');
        }

        if ($verificationState !== PaymentVerificationState::SUCCESS) {
            // PENDING (gateway says still processing) or UNKNOWN (the verify
            // call itself failed — network/timeout/malformed response).
            // Neither is proof the vendor wasn't charged: leave the record
            // untouched rather than reporting a failure that may not be true.
            Log::info('Wallet topup: verification unresolved, leaving pending', ['reference' => $reference, 'status' => $status]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'status' => 'pending', 'message' => 'Payment pending confirmation.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Payment pending confirmation.');
        }

        // $topupRecord is guaranteed non-null with a known payment_gateway
        // here — verifyWalletTopupReference() returns null otherwise, and
        // that case already returned above.
        $data = $status['data'] ?? ($status['response'] ?? []);
        $meta = $data['metadata'] ?? ($status['metadata'] ?? []);
        $meta = array_merge($meta ?? [], [
            'vendor_id' => $topupRecord->vendor_id,
            'purpose' => $meta['purpose'] ?? ($topupRecord->metadata['purpose'] ?? 'wallet_topup'),
        ]);
        $data['amount'] = $data['amount'] ?? $topupRecord->amount;

        // Ensure this was initiated as a wallet topup and vendor_id present

        if (($meta['purpose'] ?? null) !== 'wallet_topup' || empty($meta['vendor_id'])) {
            Log::warning('Wallet topup callback missing vendor metadata', ['reference' => $reference, 'meta' => $meta, 'status' => $status]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Missing vendor metadata.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Missing vendor metadata.');
        }

        $vendorId = (int) ($meta['vendor_id'] ?? 0);
        $amount = (float) ($data['amount'] ?? ($status['amount'] ?? 0));

        if ($amount <= 0 || $vendorId <= 0) {
            Log::warning('Wallet topup callback invalid data', ['reference' => $reference, 'status' => $status]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Invalid top-up data.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Invalid top-up data.');
        }

        // Idempotency fast-path (non-authoritative; the authoritative check
        // happens under lock inside the transaction below, to close the race
        // between this read and the credit).
        if ($topupRecord->status === 'completed') {
            Log::info('Wallet topup callback already processed', ['reference' => $reference]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => 'Top-up already processed.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Top-up already processed.');
        }

        // Single authoritative completion pipeline — shared with topupStatus()
        // and the automatic PaymentReconciliationService. It re-derives the
        // credited amount from the locked WalletTopup row itself (never from
        // $status) and re-applies the amount-mismatch guard, lock, and
        // idempotency check internally, so a racing duplicate call can never
        // credit twice.
        $credited = $this->walletService->completeTopup($topupRecord, $status);

        if (! $credited) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Failed to credit wallet.']);
            }

            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Failed to credit wallet.');
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Wallet topped up.']);
        }

        return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Wallet topped up.');
    }

    // Wallet ledger view (authenticated vendor only, no URL parameters)
    // SECURITY: Only the authenticated vendor can view their own wallet ledger.
    // The vendor_id is derived solely from authentication, preventing cross-vendor access.
    public function ledger(Request $request)
    {
        $vendor = $this->resolveAuthenticatedVendor();
        $vendorId = (int) $vendor->id;

        $entries = \App\Models\WalletLedger::where('vendor_id', $vendorId)->orderByDesc('id')->limit(200)->get();

        $walletService = app(\App\Services\WalletService::class);
        $withdrawable = $walletService->getWithdrawableBalance($vendorId);
        $topupsTotal = $walletService->getVendorTopupsTotal($vendorId);

        return response()->json([
            'success' => true,
            'entries' => $entries,
            'balance' => $vendor?->wallet_balance ?? 0.0,
            'withdrawable_balance' => $withdrawable,
            'vendor_topups_total' => $topupsTotal,
            'last_updated' => $vendor?->updated_at?->toDateTimeString() ?? null,
        ]);
    }

    // Return a small JSON payload with current topup total and wallet balance (cached briefly)
    public function balance(Request $request)
    {
        $vendor = auth('vendor')->user();
        if (! $vendor) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $vendorId = $vendor->id;

        $topupsTotal = \Illuminate\Support\Facades\Cache::remember("vendor:{$vendorId}:topups_available", 10, function () use ($vendorId) {
            $available = WalletTopup::where('vendor_id', $vendorId)
                ->where('status', 'completed')
                ->selectRaw('SUM(amount - COALESCE(consumed, 0)) as available')
                ->value('available');

            return max(0.0, (float) $available);
        });

        return response()->json([
            'success' => true,
            'vendor_id' => $vendorId,
            'wallet_balance' => (float) $vendor->wallet_balance,
            'vendor_topups_total' => (float) $topupsTotal,
        ]);
    }

    // Polling endpoint for client-side verification of a top-up by reference.
    public function topupStatus(Request $request, string $reference)
    {
        $vendor = $this->resolveAuthenticatedVendor();

        // Quick DB check: if we have a completed record, short-circuit
        $topup = WalletTopup::where('reference', $reference)->first();
        if ($topup && (int) $topup->vendor_id !== (int) $vendor->id) {
            Log::warning('Wallet topup status cross-vendor access blocked', [
                'authenticated_vendor_id' => $vendor->id,
                'topup_vendor_id' => (int) $topup->vendor_id,
                'reference' => $reference,
                'ip' => $request->ip(),
            ]);

            return response()->json(['success' => false, 'message' => 'Top-up not found.'], 404);
        }

        if (! $topup) {
            $cached = Cache::get("wallet_topup:{$reference}");
            if (! is_array($cached) || (int) ($cached['vendor_id'] ?? 0) !== (int) $vendor->id) {
                Log::warning('Wallet topup status unknown reference access blocked', [
                    'authenticated_vendor_id' => $vendor->id,
                    'reference' => $reference,
                    'ip' => $request->ip(),
                ]);

                return response()->json(['success' => false, 'message' => 'Top-up not found.'], 404);
            }
        }

        if ($topup && $topup->status === 'completed') {
            return response()->json([
                'success' => true,
                'status' => 'completed',
                'reference' => $reference,
                'vendor_id' => $topup->vendor_id,
                'amount' => (float) $topup->amount,
            ]);
        }

        if (! $topup || ! $topup->payment_gateway) {
            // No reliable gateway to verify against — either a legacy top-up
            // predating the payment_gateway column, or a reference we've
            // never recorded. Never guess the gateway and never fall back to
            // whichever gateway is currently default: leave it pending for
            // manual review rather than reporting a possibly-false outcome.
            Log::warning('Wallet topup status: no reliable gateway to verify against, leaving unresolved', [
                'reference' => $reference,
                'topup_id' => $topup?->id,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'pending',
                'reference' => $reference,
            ]);
        }

        // Cache gateway verification for 10 seconds to reduce API calls during polling.
        // Multiple concurrent polls for the same reference will use cached result,
        // dramatically reducing load on payment gateway during frontend polling loops.
        try {
            $result = Cache::remember(
                "topup_status:{$reference}",
                now()->addSeconds(10),
                fn () => $this->verifyWalletTopupReference($reference, $topup)
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Verification failed', 'error' => $e->getMessage()], 500);
        }

        // Normalize status. This used to substring-match the raw message text
        // (falling back to $result['message'] when no explicit status field was
        // present) for words like "fail"/"error" — which meant a plain network
        // timeout, whose message is literally "Error verifying payment.", was
        // reported to the polling UI as a failed top-up. PaymentVerificationState
        // never looks at message text: only an explicit, authoritative terminal
        // status from the gateway can produce 'failed'/'cancelled'. Anything
        // else — including the verify call itself throwing — stays 'pending'.
        $verificationState = PaymentVerificationState::from($result);

        if ($verificationState === PaymentVerificationState::SUCCESS) {
            $normalized = 'completed';
        } elseif ($verificationState === PaymentVerificationState::FAILED) {
            // Preserve the existing 'cancelled' vs 'failed' distinction the UI
            // shows different copy for, without going back to matching on
            // arbitrary message text.
            $rawStatus = strtolower((string) data_get($result, 'data.status', ''));
            $normalized = str_contains($rawStatus, 'cancel') ? 'cancelled' : 'failed';

            // Persist the authoritative failure — idempotent: lock and never
            // overwrite an already-completed top-up.
            DB::transaction(function () use ($topup, $result) {
                $locked = WalletTopup::whereKey($topup->id)->lockForUpdate()->first();
                if ($locked && $locked->status !== 'completed') {
                    $locked->update(['status' => 'failed', 'gateway_response' => $result]);
                }
            });
        } else {
            $normalized = 'pending';
        }

        // If gateway indicates the payment is completed, credit the vendor now
        // via the single authoritative completion pipeline — shared with
        // topupCallback() and the automatic PaymentReconciliationService. This
        // makes client-side polling useful: the UI can detect completed state
        // and the DB reflects the credited top-up immediately.
        if ($normalized === 'completed') {
            $this->walletService->completeTopup($topup, $result);
        }

        return response()->json([
            'success' => true,
            'status' => $normalized,
            'raw' => $result,
            'reference' => $reference,
        ]);
    }

    /**
     * Verify a wallet top-up reference against the gateway that actually
     * created it. Returns null (never guesses a gateway, never falls back to
     * the current platform default) when the record has no reliably known
     * gateway — a legacy row from before the payment_gateway column existed,
     * or no record found at all.
     */
    private function verifyWalletTopupReference(string $reference, ?WalletTopup $topupRecord): ?array
    {
        $gatewayName = $topupRecord?->payment_gateway;

        if (! $gatewayName) {
            return null;
        }

        return $this->paymentService->checkPaymentStatusForGateway($reference, $gatewayName);
    }

    private function resolveAuthenticatedVendor(): Vendor
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();

        $vendor = $vendorGuardUser instanceof Vendor
            ? $vendorGuardUser
            : ($user instanceof Vendor ? $user : null);

        abort_unless($vendor, 403, 'Vendor account required.');

        return $vendor;
    }
}
