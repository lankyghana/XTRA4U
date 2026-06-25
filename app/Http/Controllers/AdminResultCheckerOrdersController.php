<?php

namespace App\Http\Controllers;

use App\Jobs\RetryResultCheckerOrder;
use App\Models\NetworkService;
use App\Models\ResultCheckerOrder;
use App\Services\ResultCheckerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminResultCheckerOrdersController extends Controller
{
    public function __construct(
        protected ResultCheckerService $resultCheckerService,
    ) {}

    /**
     * Legal status transitions an admin may make manually.
     * Keyed by current status → array of statuses reachable from it.
     * 'completed' is intentionally absent as a target because the system
     * must allocate PINs before marking an order complete.
     */
    private const ALLOWED_TRANSITIONS = [
        'pending_payment' => ['failed'],
        'pending_stock'   => ['failed'],
        'processing'      => ['pending_stock', 'failed'],
        'completed'       => [],           // terminal — no manual override
        'failed'          => ['pending_payment', 'pending_stock'],
    ];

    public function index(Request $request)
    {
        $services = NetworkService::resultsChecker()
            ->orderBy('name')
            ->get();

        $ordersQuery = ResultCheckerOrder::query()
            ->with(['service', 'vendor', 'pins'])
            ->latest();

        if ($request->filled('service_id')) {
            $ordersQuery->where('service_id', $request->input('service_id'));
        }

        if ($request->filled('status')) {
            $ordersQuery->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $ordersQuery->where(function ($query) use ($q) {
                $query->where('customer_phone', 'like', "%{$q}%")
                    ->orWhere('payment_reference', 'like', "%{$q}%");

                if (is_numeric($q)) {
                    $query->orWhere('id', (int) $q);
                }
            });
        }

        $orders = $ordersQuery->paginate(50)->withQueryString();

        return view('admin.result_checkers.orders', [
            'orders'          => $orders,
            'services'        => $services,
            'selectedService' => $request->input('service_id'),
            'selectedStatus'  => $request->input('status'),
            'searchTerm'      => $request->input('q'),
        ]);
    }

    public function show(ResultCheckerOrder $order)
    {
        $order->load(['service', 'vendor', 'pins']);

        return view('admin.result_checkers.order_show', [
            'order' => $order,
        ]);
    }

    public function retry(ResultCheckerOrder $order)
    {
        if (! $order->paid_at) {
            return back()->with('error', 'Cannot retry an unpaid order — payment has not been received. Re-initiate checkout to collect payment.');
        }

        dispatch(new RetryResultCheckerOrder($order->id));

        return back()->with('success', 'Retry job queued for this order.');
    }

    public function markFailed(ResultCheckerOrder $order)
    {
        try {
            $result = $this->resultCheckerService->adminMarkFailed($order);
        } catch (\Exception $e) {
            Log::error('AdminResultCheckerOrdersController: Failed to mark order as failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            return back()->with('error', 'An error occurred while failing the order. Please try again.');
        }

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        $suffix = $result['pins_released'] > 0
            ? ' ' . $result['pins_released'] . ' pin(s) released back to stock.'
            : '';

        return back()->with('success', 'Order #' . $order->id . ' marked as failed.' . $suffix);
    }

    /**
     * Manually override an order's status.
     *
     * Rules enforced:
     *  - Only transitions listed in ALLOWED_TRANSITIONS are accepted.
     *  - The 'completed' status can NEVER be set manually; use the Retry
     *    button to trigger PIN allocation which sets 'completed' automatically.
     *  - Re-opening a failed order back to 'pending_stock' or 'pending_payment'
     *    is allowed so a retry can be queued afterwards.
     */
    public function updateStatus(Request $request, ResultCheckerOrder $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending_payment,pending_stock,processing,completed,failed',
        ]);

        $newStatus = $validated['status'];

        // Block 'completed' from being set manually under any circumstance.
        // PIN allocation (via Retry / FulfillPendingOrdersJob) is the only
        // authorised path to 'completed', ensuring delivered_pins_json is always set.
        if ($newStatus === 'completed') {
            return back()->with(
                'error',
                'Orders cannot be manually marked as completed. Use the "Retry Fulfillment" button to allocate PINs; the order will be marked completed automatically.'
            );
        }

        // Enforce the transition matrix.
        $error = $this->validateTransition($order->status, $newStatus);
        if ($error) {
            return back()->with('error', $error);
        }

        $order->update(['status' => $newStatus]);

        return back()->with('success', 'Order status updated to "' . str_replace('_', ' ', $newStatus) . '".');
    }

    /**
     * Validate a requested status transition against the allowed matrix.
     *
     * @return string|null  Error message, or null if the transition is valid.
     */
    private function validateTransition(string $from, string $to): ?string
    {
        if ($from === $to) {
            return 'Order is already in "' . str_replace('_', ' ', $from) . '" status.';
        }

        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        if (!in_array($to, $allowed, true)) {
            $allowedList = empty($allowed)
                ? 'none (terminal status)'
                : implode(', ', array_map(fn ($s) => '"' . str_replace('_', ' ', $s) . '"', $allowed));

            return sprintf(
                'Cannot transition from "%s" to "%s". Allowed transitions: %s.',
                str_replace('_', ' ', $from),
                str_replace('_', ' ', $to),
                $allowedList
            );
        }

        return null;
    }
}

