<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithAdminGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUssdPlanRequest;
use App\Http\Requests\Admin\UpdateUssdPlanRequest;
use App\Models\UssdPlan;
use App\Models\UssdSubscription;
use App\Models\UssdSubscriptionEvent;
use App\Services\Ussd\UssdConfig;
use Illuminate\Http\Request;

class UssdPlanController extends Controller
{
    use InteractsWithAdminGate;

    public function __construct(private readonly UssdConfig $config) {}

    public function index()
    {
        $this->authorizeAdmin('viewAny', UssdPlan::class);

        $plans = UssdPlan::query()
            ->withCount([
                'subscriptions as live_subscriptions_count' => fn ($q) => $q->whereIn('status', UssdSubscription::LIVE_STATUSES),
                'subscriptions as total_subscriptions_count',
            ])
            ->ordered()
            ->get();

        return view('admin.ussd-plans.index', [
            'plans' => $plans,
            'baseCode' => $this->config->baseCode(),
        ]);
    }

    public function create()
    {
        $this->authorizeAdmin('create', UssdPlan::class);

        return view('admin.ussd-plans.create', [
            'baseCode' => $this->config->baseCode(),
        ]);
    }

    public function store(StoreUssdPlanRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);
        $data['display_order'] = $data['display_order'] ?? 0;

        $plan = UssdPlan::create($data);

        $this->recordEvent($request, $plan, UssdSubscriptionEvent::ADMIN_PLAN_CREATED, "Plan \"{$plan->name}\" created.");

        return redirect()->route('admin.ussd-plans.index')
            ->with('success', "Plan \"{$plan->name}\" created successfully.");
    }

    public function edit(UssdPlan $ussd_plan)
    {
        $this->authorizeAdmin('update', $ussd_plan);

        return view('admin.ussd-plans.edit', [
            'plan' => $ussd_plan,
            'baseCode' => $this->config->baseCode(),
            'liveSubscriptions' => $ussd_plan->subscriptions()
                ->whereIn('status', UssdSubscription::LIVE_STATUSES)->count(),
        ]);
    }

    public function update(UpdateUssdPlanRequest $request, UssdPlan $ussd_plan)
    {
        $original = $ussd_plan->only(array_keys($request->validated()));

        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', false);
        $data['display_order'] = $data['display_order'] ?? 0;

        $ussd_plan->update($data);

        $this->recordEvent($request, $ussd_plan, UssdSubscriptionEvent::ADMIN_PLAN_UPDATED, "Plan \"{$ussd_plan->name}\" updated.", [
            'before' => $original,
            'after' => $ussd_plan->only(array_keys($data)),
        ]);

        return redirect()->route('admin.ussd-plans.index')
            ->with('success', "Plan \"{$ussd_plan->name}\" updated successfully.");
    }

    public function destroy(Request $request, UssdPlan $ussd_plan)
    {
        // The policy denies deletion outright when live subscriptions exist, but
        // a 403 is a poor explanation for a business rule. Check first, explain,
        // and only then authorize.
        if ($ussd_plan->hasLiveSubscriptions()) {
            return back()->withErrors([
                'error' => "Cannot delete \"{$ussd_plan->name}\" — vendors hold live subscriptions on this plan. Deactivate it instead to stop new purchases.",
            ]);
        }

        $this->authorizeAdmin('delete', $ussd_plan);

        $name = $ussd_plan->name;
        $this->recordEvent($request, $ussd_plan, UssdSubscriptionEvent::ADMIN_PLAN_DELETED, "Plan \"{$name}\" deleted.");

        $ussd_plan->delete();

        return redirect()->route('admin.ussd-plans.index')
            ->with('success', "Plan \"{$name}\" deleted.");
    }

    /**
     * Deactivating stops new purchases. Existing subscriptions are unaffected —
     * they were snapshotted at purchase time and keep running until they expire.
     */
    public function toggleActive(Request $request, UssdPlan $ussd_plan)
    {
        $this->authorizeAdmin('toggleStatus', $ussd_plan);

        $ussd_plan->update(['is_active' => ! $ussd_plan->is_active]);

        $state = $ussd_plan->is_active ? 'activated' : 'deactivated';

        $this->recordEvent($request, $ussd_plan, UssdSubscriptionEvent::ADMIN_PLAN_UPDATED, "Plan \"{$ussd_plan->name}\" {$state}.");

        return redirect()->route('admin.ussd-plans.index')
            ->with('success', "Plan \"{$ussd_plan->name}\" {$state}.");
    }

    private function recordEvent(Request $request, UssdPlan $plan, string $event, string $description, array $context = []): void
    {
        UssdSubscriptionEvent::create([
            'ussd_plan_id' => $plan->id,
            'event' => $event,
            'description' => $description,
            'context' => $context ?: null,
            'actor_type' => 'admin',
            'actor_id' => $this->adminActorId(),
            'ip_address' => $request->ip(),
        ]);
    }
}
