<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillPipeline;
use App\Support\AdminAccess;
use Illuminate\Http\Request;

/**
 * Admin view of every Utility Bill transaction, with recovery actions.
 * Recovery goes through UtilityBillFulfillmentService, which takes the row
 * lock, honours terminal states and reuses the persisted provider reference.
 */
class UtilityBillSalesController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'vendor_id' => ['nullable', 'string', 'max:12'],
            'biller' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'payment' => ['nullable', 'in:paid,unpaid,failed'],
            'fulfillment' => ['nullable', 'in:'.implode(',', FulfillmentStatus::ALL)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $query = UtilityBillOrder::query()->with(['order:id,payment_status,payment_integrity_status,payment_reference', 'vendor:id,name,vendor_code'])->latest('id');

        if (($filters['vendor_id'] ?? '') === 'direct') {
            $query->whereNull('vendor_id');
        } elseif (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }
        if (! empty($filters['biller'])) {
            $query->where('biller_key', $filters['biller']);
        }
        if (! empty($filters['payment'])) {
            $statuses = match ($filters['payment']) {
                'paid' => ['paid', 'completed'],
                'failed' => ['failed'],
                default => ['unpaid', 'pending'],
            };
            $query->whereHas('order', fn ($o) => $o->whereIn('payment_status', $statuses));
        }
        if (! empty($filters['fulfillment'])) {
            $query->where('fulfillment_status', $filters['fulfillment']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($q) use ($term) {
                $q->where('public_ref', 'like', "%{$term}%")
                    ->orWhere('provider_order_reference', 'like', "%{$term}%")
                    ->orWhere('provider_request_reference', 'like', "%{$term}%")
                    ->orWhere('account_number', 'like', "%{$term}%")
                    ->orWhere('account_name', 'like', "%{$term}%");
            });
        }

        return view('admin.utility_bills.sales', [
            'sales' => $query->paginate(30)->withQueryString(),
            'filters' => $filters,
            'vendors' => Vendor::query()->whereIn('id', UtilityBillOrder::query()->whereNotNull('vendor_id')->select('vendor_id'))->orderBy('name')->get(['id', 'name']),
            'billers' => UtilityBillOrder::query()->select('biller_key', 'biller_label')->distinct()->orderBy('biller_label')->get()->toBase()
                ->merge(UtilityBillerConfig::query()->get(['biller_key'])->map(fn ($c) => (object) ['biller_key' => $c->biller_key, 'biller_label' => $c->biller_key]))
                ->unique('biller_key')->values(),
            'attentionCount' => UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::ATTENTION)->count(),
            'pipeline' => app(UtilityBillPipeline::class)->snapshot(),
        ]);
    }

    public function show(UtilityBillOrder $order)
    {
        $order->load(['order', 'vendor:id,name,vendor_code', 'events']);

        return view('admin.utility_bills.show', [
            'sale' => $order,
            // Closed provider attempts (append-only), oldest first.
            'closedAttempts' => $order->events->where('kind', UtilityBillEvent::KIND_ATTEMPT_CLOSED)->values(),
        ]);
    }

    public function retry(UtilityBillOrder $order, UtilityBillFulfillmentService $fulfillment)
    {
        $result = $fulfillment->adminRetry($order->id, $this->actor());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function newAttempt(Request $request, UtilityBillOrder $order, UtilityBillFulfillmentService $fulfillment)
    {
        $data = $request->validate([
            'confirm' => ['accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $result = $fulfillment->adminRetry($order->id, $this->actor(), newAttempt: true, reason: $data['reason']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Read-only query of the EXISTING provider order (also for orders whose automatic polling stopped). */
    public function refresh(UtilityBillOrder $order, UtilityBillFulfillmentService $fulfillment)
    {
        $outcome = $fulfillment->syncStatus($order->id, 'admin:'.($this->actor()['id'] ?? '?'));
        $now = $order->fresh();

        return back()->with($outcome === 'updated' ? 'success' : 'error', match ($outcome) {
            'updated' => $now->fulfillment_status === FulfillmentStatus::PROVIDER_UNRESOLVED
                ? 'KiNG FLEXY still reports "'.($now->provider_status ?? 'unknown').'". The order stays unresolved; refresh again later or resume automatic checks.'
                : 'Status refreshed from the provider: '.FulfillmentStatus::label($now->fulfillment_status).'.',
            'rate_limited' => 'Provider rate limit reached; try again in a minute.',
            'skipped' => 'This order is not waiting on the provider.',
            default => 'Could not refresh the status ('.$outcome.').',
        });
    }

    public function resumePolling(UtilityBillOrder $order, UtilityBillFulfillmentService $fulfillment)
    {
        $result = $fulfillment->adminResumePolling($order->id, $this->actor());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** @return array{id:?int,email:?string} */
    private function actor(): array
    {
        $user = AdminAccess::resolve();

        return ['id' => $user?->id, 'email' => $user?->email];
    }
}
