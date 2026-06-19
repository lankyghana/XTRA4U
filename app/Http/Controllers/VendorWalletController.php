<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\DB;

class VendorWalletController extends Controller
{
    protected PaymentService $paymentService;
    protected WalletService $walletService;

    public function __construct(?PaymentService $paymentService = null, ?WalletService $walletService = null)
    {
        $this->paymentService = $paymentService ?? new PaymentService();
        $this->walletService = $walletService ?? new WalletService();
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
        ]);

        $vendorId = (int) $vendor->id;
        $reference = Str::uuid()->toString();

        // Persist a short-lived mapping so callbacks that don't return metadata
        // (some gateways don't echo back custom metadata) can still be reconciled.
        Cache::put("wallet_topup:{$reference}", [
            'vendor_id' => $vendorId,
            'amount' => (float) $validated['amount'],
        ], now()->addHours(6));

        // Also persist a DB record for stronger auditability and reconciliation.
        WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $vendorId,
            'amount' => (float) $validated['amount'],
            'metadata' => ['purpose' => 'wallet_topup'],
            'status' => 'initiated',
        ]);

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
        } elseif ($vendor && !empty(trim((string) $vendor->phone_number))) {
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
        // Check payment status via PaymentService
        $status = $this->paymentService->checkPaymentStatus($reference);

        if (! ($status['success'] ?? false)) {
            Log::info('Wallet topup: payment not successful', ['reference' => $reference, 'status' => $status]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Payment not successful.']);
            }
            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Payment not successful.');
        }

        $data = $status['data'] ?? ($status['response'] ?? []);
        $meta = $data['metadata'] ?? ($status['metadata'] ?? []);

        // If metadata is missing, try our DB record first, then cache mapping.
        $topupRecord = WalletTopup::where('reference', $reference)->first();
        if ($topupRecord) {
            $meta = array_merge($meta ?? [], [
                'vendor_id' => $topupRecord->vendor_id,
                'purpose' => $meta['purpose'] ?? ($topupRecord->metadata['purpose'] ?? 'wallet_topup'),
            ]);
            $data['amount'] = $data['amount'] ?? $topupRecord->amount;
        } else {
            $cached = Cache::get("wallet_topup:{$reference}");
            if ($cached && is_array($cached)) {
                $meta = array_merge($meta ?? [], [
                    'vendor_id' => $cached['vendor_id'],
                    'purpose' => $meta['purpose'] ?? 'wallet_topup',
                ]);
                $data['amount'] = $data['amount'] ?? $cached['amount'];
            }
        }

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

        // SECURITY GUARDS: ensure the initiation record exists and is unused
        if (! $topupRecord) {
            // If gateway provided authoritative metadata, create an audit record on-the-fly
            $gwMeta = $status['data']['metadata'] ?? null;
            if (is_array($gwMeta) && ! empty($gwMeta['vendor_id']) && ($gwMeta['purpose'] ?? null) === 'wallet_topup') {
                $topupRecord = WalletTopup::create([
                    'reference' => $reference,
                    'vendor_id' => $gwMeta['vendor_id'],
                    'amount' => $amount,
                    'status' => 'initiated',
                    'metadata' => $gwMeta,
                ]);
                Log::info('Created fallback WalletTopup from gateway metadata', ['reference' => $reference, 'topup_id' => $topupRecord->id]);
                } else {
                    Log::warning('Wallet topup callback reference not found in DB', ['reference' => $reference, 'status' => $status]);
                    if ($request->wantsJson() || $request->ajax()) {
                        return response()->json(['success' => false, 'message' => 'Unknown top-up reference.']);
                    }
                    return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Unknown top-up reference.');
                }
        }

        // Idempotency: if already completed, respond success (no double-credit)

        if ($topupRecord->status === 'completed') {
            Log::info('Wallet topup callback already processed', ['reference' => $reference]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => 'Top-up already processed.']);
            }
            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Top-up already processed.');
        }

        // Amount mismatch protection: gateway amount must match initiation amount
        $initiatedAmount = (float) $topupRecord->amount;
        // compare rounded to 2 decimals to avoid floating point noise

        if (round($initiatedAmount, 2) !== round($amount, 2)) {
            Log::error('Wallet topup amount mismatch', ['reference' => $reference, 'initiated' => $initiatedAmount, 'reported' => $amount, 'status' => $status]);
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Amount mismatch.']);
            }
            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Amount mismatch.');
        }

        // Credit vendor wallet and ledger in a transaction and mark topup completed.
        $credited = false;
        DB::transaction(function () use ($vendorId, $amount, $reference, &$credited) {
            $credited = $this->walletService->creditVendor($vendorId, $amount, ['reference' => $reference]);
            if ($credited) {
                // update DB topup record if present
                $topupRecord = WalletTopup::where('reference', $reference)->first();
                if ($topupRecord) {
                    $topupRecord->update([
                        'status' => 'completed',
                        'gateway_response' => $this->paymentService->checkPaymentStatus($reference),
                    ]);
                }
                // remove cached mapping
                Cache::forget("wallet_topup:{$reference}");
                // Invalidate the balance cache so the next call to balance() returns fresh data
                Cache::forget("vendor:{$vendorId}:topups_available");
            }
        });

        if (! $credited) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Failed to credit wallet.']);
            }
            return redirect(url('/vendor/wallet?tab=topups'))->with('status', 'Failed to credit wallet.');
        }

        // Create vendor in-app notification and admin notification + send emails
        try {
            $vendor = \App\Models\Vendor::find($vendorId);
            if ($vendor) {
                // Vendor in-app notification (DB)
                \App\Models\VendorNotification::create([
                    'vendor_id' => $vendor->id,
                    'type' => 'wallet_topup',
                    'title' => 'Wallet topped up',
                    'message' => "Your wallet was topped up with GHS " . number_format($amount, 2) . ". Reference: {$reference}",
                    'data' => [
                        'amount' => $amount,
                        'reference' => $reference,
                    ],
                ]);

                // Admin in-app notification
                \App\Models\AdminNotification::create([
                    'type' => 'vendor_wallet_topup',
                    'title' => 'Vendor wallet topped up',
                    'message' => "Vendor {$vendor->name} topped up wallet with GHS " . number_format($amount, 2) . ". Reference: {$reference}",
                    'vendor_id' => $vendor->id,
                    'data' => [
                        'vendor_id' => $vendor->id,
                        'amount' => $amount,
                        'reference' => $reference,
                    ],
                ]);

                // Send emails via existing mail system
                try {
                    \Illuminate\Support\Facades\Mail::to($vendor->email)->send(new \App\Mail\VendorWalletTopupMail($vendor, $amount, $reference));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send vendor topup email', ['err' => $e->getMessage(), 'vendor_id' => $vendor->id]);
                }

                // Notify admins by email - find any admin emails from config or env
                $adminEmail = config('mail.admin_email') ?? env('ADMIN_EMAIL');
                if ($adminEmail) {
                    try {
                        \Illuminate\Support\Facades\Mail::to($adminEmail)->send(new \App\Mail\AdminVendorWalletTopupMail($vendor, $amount, $reference));
                    } catch (\Throwable $e) {
                        Log::warning('Failed to send admin topup email', ['err' => $e->getMessage()]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to create notifications for wallet topup', ['err' => $e->getMessage(), 'reference' => $reference]);
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

        // Cache gateway verification for 10 seconds to reduce API calls during polling.
        // Multiple concurrent polls for the same reference will use cached result,
        // dramatically reducing load on payment gateway during frontend polling loops.
        try {
            $result = Cache::remember(
                "topup_status:{$reference}",
                now()->addSeconds(10),
                fn() => $this->paymentService->checkPaymentStatus($reference)
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Verification failed', 'error' => $e->getMessage()], 500);
        }

        // Normalize status field
        $rawStatus = null;
        if (is_array($result)) {
            $rawStatus = $result['status'] ?? $result['data']['status'] ?? $result['data']['payment_status'] ?? $result['message'] ?? null;
        }

        $normalized = 'pending';
        $low = is_string($rawStatus) ? strtolower($rawStatus) : '';
        if (strpos($low, 'complete') !== false || strpos($low, 'success') !== false) {
            $normalized = 'completed';
        } elseif (strpos($low, 'cancel') !== false) {
            $normalized = 'cancelled';
        } elseif (strpos($low, 'fail') !== false || strpos($low, 'error') !== false) {
            $normalized = 'failed';
        }

        // If gateway indicates the payment is completed, attempt to reconcile
        // and credit the vendor now (idempotent). This makes client-side
        // polling useful: the UI can detect completed state and the DB will
        // reflect the credited top-up so balance endpoints return the new value.
        if ($normalized === 'completed') {
            $data = $result['data'] ?? ($result['response'] ?? []);
            $meta = $data['metadata'] ?? ($result['metadata'] ?? []);

            // Try to find existing DB record or cached mapping
            $topupRecord = WalletTopup::where('reference', $reference)->first();
            if (! $topupRecord) {
                $cached = Cache::get("wallet_topup:{$reference}");
                if ($cached && is_array($cached) && (int) ($cached['vendor_id'] ?? 0) === (int) $vendor->id) {
                    $topupRecord = WalletTopup::create([
                        'reference' => $reference,
                        'vendor_id' => $cached['vendor_id'],
                        'amount' => $cached['amount'],
                        'status' => 'initiated',
                        'metadata' => ['purpose' => 'wallet_topup'],
                    ]);
                } elseif (is_array($meta) && ! empty($meta['vendor_id']) && (int) $meta['vendor_id'] === (int) $vendor->id) {
                    // If gateway provided metadata, create a DB record from it
                    $topupRecord = WalletTopup::create([
                        'reference' => $reference,
                        'vendor_id' => (int) $meta['vendor_id'],
                        'amount' => (float) ($data['amount'] ?? ($result['amount'] ?? 0)),
                        'status' => 'initiated',
                        'metadata' => $meta,
                    ]);
                }
            }

            // If we have a record and it's not completed, attempt to credit
            if ($topupRecord && $topupRecord->status !== 'completed') {
                $vendorId = (int) $topupRecord->vendor_id;
                $amount = (float) ($data['amount'] ?? ($topupRecord->amount ?? 0));

                if ($vendorId > 0 && $amount > 0) {
                    $credited = false;
                    DB::transaction(function () use ($vendorId, $amount, $reference, &$credited, $result) {
                        $credited = $this->walletService->creditVendor($vendorId, $amount, ['reference' => $reference]);
                        if ($credited) {
                            $tr = WalletTopup::where('reference', $reference)->first();
                            if ($tr) {
                                $tr->update([
                                    'status' => 'completed',
                                    'gateway_response' => $result,
                                ]);
                            }
                            Cache::forget("wallet_topup:{$reference}");
                        }
                    });
                    if ($credited) {
                        // Recompute available topups briefly in cache
                        Cache::forget("vendor:{$topupRecord->vendor_id}:topups_available");
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'status' => $normalized,
            'raw' => $result,
            'reference' => $reference,
        ]);
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
