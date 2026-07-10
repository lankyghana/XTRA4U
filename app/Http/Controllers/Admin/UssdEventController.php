<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithAdminGate;
use App\Http\Controllers\Controller;
use App\Models\UssdPlan;
use App\Models\UssdSubscriptionEvent;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UssdEventController extends Controller
{
    use InteractsWithAdminGate;

    public function index(Request $request)
    {
        $this->authorizeAdmin('viewAny', UssdPlan::class);

        $filters = $request->validate([
            'event' => ['nullable', 'string', Rule::in($this->eventOptions())],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
        ]);

        $events = UssdSubscriptionEvent::query()
            ->with('vendor:id,name,vendor_code', 'plan:id,name')
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($filters['vendor_id'] ?? null, fn ($q, $id) => $q->where('vendor_id', $id))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.ussd-events.index', [
            'events' => $events,
            'eventOptions' => $this->eventOptions(),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name', 'vendor_code']),
            'filters' => $filters,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function eventOptions(): array
    {
        return [
            UssdSubscriptionEvent::PLAN_PURCHASE_INITIATED,
            UssdSubscriptionEvent::PAYMENT_VERIFIED,
            UssdSubscriptionEvent::PAYMENT_FAILED,
            UssdSubscriptionEvent::SUBSCRIPTION_ACTIVATED,
            UssdSubscriptionEvent::SUBSCRIPTION_RENEWED,
            UssdSubscriptionEvent::SUBSCRIPTION_UPGRADED,
            UssdSubscriptionEvent::SUBSCRIPTION_EXPIRED,
            UssdSubscriptionEvent::SUBSCRIPTION_SUSPENDED,
            UssdSubscriptionEvent::SUBSCRIPTION_CANCELLED,
            UssdSubscriptionEvent::USSD_CODE_GENERATED,
            UssdSubscriptionEvent::SESSION_CONSUMED,
            UssdSubscriptionEvent::SESSIONS_EXHAUSTED,
            UssdSubscriptionEvent::INVALID_USSD_REQUEST,
            UssdSubscriptionEvent::SECURITY_EVENT,
            UssdSubscriptionEvent::ADMIN_PLAN_CREATED,
            UssdSubscriptionEvent::ADMIN_PLAN_UPDATED,
            UssdSubscriptionEvent::ADMIN_PLAN_DELETED,
            UssdSubscriptionEvent::ADMIN_SETTINGS_UPDATED,
        ];
    }
}
