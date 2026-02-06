<?php

namespace App\Http\Controllers;

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
    public function initiateTopup(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'amount' => 'required|numeric|min:0.01',
            'return_url' => 'nullable|url',
        ]);

        $reference = Str::uuid()->toString();

        // Persist a short-lived mapping so callbacks that don't return metadata
        // (some gateways don't echo back custom metadata) can still be reconciled.
        Cache::put("wallet_topup:{$reference}", [
            'vendor_id' => $validated['vendor_id'],
            'amount' => (float) $validated['amount'],
        ], now()->addHours(6));

        // Also persist a DB record for stronger auditability and reconciliation.
        WalletTopup::create([
            'reference' => $reference,
            'vendor_id' => $validated['vendor_id'],
            'amount' => (float) $validated['amount'],
            'metadata' => ['purpose' => 'wallet_topup'],
            'status' => 'initiated',
        ]);

        $callbackUrl = route('vendor.wallet.topup.callback', ['reference' => $reference]);

        $result = $this->paymentService->initiateGenericPayment($request->user()?->email ?? 'noreply@xtra4u.com', (float) $validated['amount'], $callbackUrl, $reference, [
            'vendor_id' => $validated['vendor_id'],
            'purpose' => 'wallet_topup',
        ]);

        if (! $result['success']) {
            return response()->json(['success' => false, 'message' => $result['message'] ?? 'Failed to initiate top-up'], 400);
        }

        return response()->json([
            'success' => true,
            'reference' => $result['reference'] ?? $reference,
            'authorization_url' => $result['authorization_url'] ?? null,
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

    // Basic ledger view (vendor-only)
    public function ledger(Request $request, int $vendorId)
    {
        // Auth checks can be added; this is a minimal endpoint
        $entries = \App\Models\WalletLedger::where('vendor_id', $vendorId)->orderByDesc('id')->limit(200)->get();
        $vendor = \App\Models\Vendor::find($vendorId);

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
}
