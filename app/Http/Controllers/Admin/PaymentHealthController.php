<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\ResultCheckerOrder;
use App\Models\UssdSubscription;
use App\Models\WalletTopup;
use App\Services\Admin\PaymentHealthService;
use App\Services\PaymentReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Read-only payment operations dashboard, plus exactly one mutating action
 * (recheck()) that does nothing more than ask PaymentReconciliationService
 * to re-verify one already-stored payable against its own already-stored
 * gateway and reference. See recheck() for the full list of things this
 * deliberately cannot do.
 */
class PaymentHealthController extends Controller
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const MODELS = [
        'order' => Order::class,
        'afa' => AfaRegistration::class,
        'result_checker' => ResultCheckerOrder::class,
        'ussd' => UssdSubscription::class,
        'wallet_topup' => WalletTopup::class,
    ];

    public function __construct(private PaymentHealthService $paymentHealthService) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(PaymentHealthService::TYPES)],
            'gateway' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['pending', 'failed', 'manual_review', 'missing_gateway'])],
            'reason' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', Rule::in(['under_30m', '30m_to_24h', 'over_24h'])],
            'reference' => ['nullable', 'string', 'max:191'],
        ]);

        $summary = $this->paymentHealthService->summary();
        $providerHealth = $this->paymentHealthService->providerHealth();
        $schedulerHealth = $this->paymentHealthService->schedulerHealth();
        $queueHealth = $this->paymentHealthService->queueHealth();
        $alerts = $this->paymentHealthService->alerts($summary, $schedulerHealth, $queueHealth);

        $queue = $this->paymentHealthService->manualReviewQueue(array_merge($validated, [
            'query' => $request->query(),
        ]));

        return view('admin.payment_health.index', [
            'summary' => $summary,
            'providerHealth' => $providerHealth,
            'schedulerHealth' => $schedulerHealth,
            'queueHealth' => $queueHealth,
            'alerts' => $alerts,
            'queue' => $queue,
            'filters' => $validated,
            'labels' => PaymentHealthService::LABELS,
        ]);
    }

    /**
     * "Recheck Payment" — the only mutating action on this page.
     *
     * Security model (see task brief section 5):
     *  - CSRF: standard Blade @csrf form + the global VerifyCsrfToken
     *    middleware (not excluded for this route).
     *  - AuthZ: sits inside the existing `admin.only` route group — the same
     *    single-role admin gate every other admin controller in this app
     *    relies on (there is no admin sub-role to further scope against).
     *  - Rate limited: `throttle:20,1` on the route (see routes/web.php).
     *  - No arbitrary gateway/amount/reference input: the request supplies
     *    only `payable_type` (a closed enum) + `payable_id` (an integer).
     *    The gateway, reference, and amount used are *exclusively* whatever
     *    is already stored on that exact row — never anything from the
     *    request body.
     *  - No new charge, no "mark paid", no forced SUCCESS: this calls
     *    PaymentReconciliationService::reconcile($payable) verbatim, the
     *    same method the scheduled `payments:reconcile` command calls, and
     *    returns whatever short outcome string it produces. That service
     *    is untouched by this feature.
     *  - No raw provider errors reach the browser: reconcile() itself never
     *    returns raw provider payloads (only a short outcome constant), so
     *    there is nothing to redact here.
     */
    public function recheck(Request $request, PaymentReconciliationService $reconciliationService): RedirectResponse
    {
        $validated = $request->validate([
            'payable_type' => ['required', Rule::in(array_keys(self::MODELS))],
            'payable_id' => ['required', 'integer', 'min:1'],
        ]);

        $modelClass = self::MODELS[$validated['payable_type']];
        $payable = $modelClass::find($validated['payable_id']);

        if (! $payable) {
            return back()->with('error', 'That record could not be found.');
        }

        try {
            $outcome = $reconciliationService->reconcile($payable);
        } catch (\Throwable $e) {
            Log::error('Admin manual recheck — unhandled exception', [
                'payable_type' => $validated['payable_type'],
                'payable_id' => $validated['payable_id'],
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Recheck failed unexpectedly. See logs for details.');
        }

        return back()->with('success', 'Recheck complete — outcome: '.$this->normalizeOutcomeForDisplay($outcome));
    }

    private function normalizeOutcomeForDisplay(string $outcome): string
    {
        return match ($outcome) {
            PaymentReconciliationService::OUTCOME_COMPLETED => 'SUCCESS',
            PaymentReconciliationService::OUTCOME_CANCELLED => 'FAILED',
            PaymentReconciliationService::OUTCOME_LEFT_PENDING => 'PENDING / UNKNOWN',
            PaymentReconciliationService::OUTCOME_NO_GATEWAY,
            PaymentReconciliationService::OUTCOME_INTEGRITY_MISMATCH,
            PaymentReconciliationService::OUTCOME_MAX_WINDOW_EXCEEDED => 'MANUAL REVIEW',
            default => 'MANUAL REVIEW',
        };
    }
}
