<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\UtilityBillerConfig;
use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillPipeline;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin view of every Utility Bill transaction, with recovery actions.
 * Recovery goes through UtilityBillFulfillmentService, which takes the row
 * lock, honours terminal states and reuses the persisted provider reference.
 */
class UtilityBillSalesController extends Controller
{
    /** Searchable columns. Each has an index, so a "starts with" match is an index range read. */
    private const SEARCH_COLUMNS = ['public_ref', 'provider_order_reference', 'provider_request_reference', 'account_number', 'account_name'];

    /** Filters that need the envelope order (or a full scan) are counted only this far. */
    private const COUNT_CAP = 1000;

    private const PER_PAGE = 30;

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
            'match' => ['nullable', 'in:prefix,contains'],
        ]);
        $contains = ($filters['match'] ?? 'prefix') === 'contains';

        // Newest first. Every predicate below is sargable on utility_bill_orders' own indexes.
        $query = UtilityBillOrder::query()->with(['order:id,payment_status,payment_integrity_status,payment_reference', 'vendor:id,name,vendor_code'])
            ->orderByDesc('utility_bill_orders.id');

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
            // A correlated lookup by the envelope order's primary key, so MySQL walks the (much
            // smaller) utility table newest-first instead of scanning every marketplace order.
            $query->whereIn(DB::raw('(select o.payment_status from orders o where o.id = utility_bill_orders.order_id)'), $statuses);
        }
        if (! empty($filters['fulfillment'])) {
            $query->where('fulfillment_status', $filters['fulfillment']);
        }
        // Whole days, as before, but as index ranges rather than DATE(created_at).
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
        if (($term = trim((string) ($filters['q'] ?? ''))) !== '') {
            $this->applySearch($query, $term, $contains);
        }

        // Exact totals when the count is an index read; a capped count when the filter needs the
        // envelope order or a full scan (payment status, "match anywhere").
        $capped = ! empty($filters['payment']) || ($contains && $term !== '');
        [$sales, $total, $totalCapped] = $capped ? $this->cappedPage($request, $query) : $this->exactPage($query);

        return view('admin.utility_bills.sales', [
            'sales' => $sales,
            'total' => $total,
            'totalCapped' => $totalCapped,
            'filters' => $filters,
            ...$this->filterOptions(),
            'attentionCount' => UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::ATTENTION)->count(),
            'pipeline' => app(UtilityBillPipeline::class)->snapshot(),
        ]);
    }

    /**
     * Default: "starts with" on the indexed reference/account columns (MySQL serves the OR with an
     * index merge), plus an exact gateway payment reference. "Match anywhere" keeps the original
     * substring search on the same columns; it cannot use an index, so it scans the table.
     */
    private function applySearch($query, string $term, bool $contains): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $pattern = $contains ? '%'.$escaped.'%' : $escaped.'%';
        $paymentOrderId = $contains ? null : Order::query()->where('payment_reference', $term)->value('id');

        $query->where(function ($q) use ($pattern, $paymentOrderId) {
            foreach (self::SEARCH_COLUMNS as $column) {
                $q->orWhereRaw('utility_bill_orders.'.$column." like ? escape '!'", [$pattern]);
            }
            if ($paymentOrderId !== null) {
                $q->orWhere('utility_bill_orders.order_id', $paymentOrderId);
            }
        });
    }

    /** @return array{0: LengthAwarePaginator, 1: int, 2: bool} */
    private function exactPage($query): array
    {
        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return [$page, $page->total(), false];
    }

    /** @return array{0: LengthAwarePaginator, 1: int, 2: bool} */
    private function cappedPage(Request $request, $query): array
    {
        $total = DB::query()->fromSub((clone $query)->toBase()->select('utility_bill_orders.id')->limit(self::COUNT_CAP + 1), 'capped')->count();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = (clone $query)->forPage($page, self::PER_PAGE)->get();

        $paginator = (new LengthAwarePaginator($items, $total, self::PER_PAGE, $page, ['path' => $request->url()]))->withQueryString();

        return [$paginator, min($total, self::COUNT_CAP), $total > self::COUNT_CAP];
    }

    /**
     * Dropdown options, cached briefly: they change rarely and recomputing them on every page
     * load used to scan the whole table (SELECT DISTINCT ... ORDER BY).
     *
     * @return array{vendors: Collection, billers: Collection}
     */
    private function filterOptions(): array
    {
        $options = Cache::remember('utility_bills.admin.sales_filter_options', 600, function () {
            // GROUP BY on the biller_key index (a loose index scan), then one indexed lookup per key.
            $keys = UtilityBillOrder::query()->select('biller_key')->groupBy('biller_key')->pluck('biller_key')
                ->merge(UtilityBillerConfig::query()->pluck('biller_key'))->unique()->values();

            return [
                'vendors' => Vendor::query()->whereIn('id', UtilityBillOrder::query()->whereNotNull('vendor_id')->select('vendor_id'))
                    ->orderBy('name')->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->all(),
                'billers' => $keys->map(fn ($key) => [
                    'biller_key' => $key,
                    'biller_label' => UtilityBillOrder::query()->where('biller_key', $key)->value('biller_label') ?? $key,
                ])->sortBy('biller_label')->values()->all(),
            ];
        });

        return [
            'vendors' => collect($options['vendors'])->map(fn ($v) => (object) $v),
            'billers' => collect($options['billers'])->map(fn ($b) => (object) $b),
        ];
    }

    public function show(UtilityBillOrder $order)
    {
        $order->load(['order', 'vendor:id,name,vendor_code', 'events', 'incidents']);

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
