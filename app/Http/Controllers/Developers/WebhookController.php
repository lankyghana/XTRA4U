<?php

namespace App\Http\Controllers\Developers;

use App\Http\Requests\Developers\StoreWebhookRequest;
use App\Http\Requests\Developers\UpdateWebhookRequest;
use App\Models\Developer\DeveloperWebhookDelivery;
use App\Services\DeveloperApi\Webhooks\WebhookService;

class WebhookController extends DeveloperController
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function index(int $application)
    {
        $app = $this->application($application);

        return view('developers.webhooks.index', [
            'app' => $app,
            'webhooks' => $app->hasMany(\App\Models\Developer\DeveloperWebhook::class, 'application_id')->latest('id')->get(),
            'events' => config('developer_api.webhooks.events'),
        ]);
    }

    /** Secret page is returned directly (never flashed to the session) and is not cacheable. */
    public function store(StoreWebhookRequest $request, int $application)
    {
        $app = $this->application($application);
        $data = $request->validated();

        $created = $this->webhooks->create($app, $this->actor(), $data['url'], $data['events'], $data['description'] ?? null);

        return $this->noStore(response()->view('developers.webhooks.secret', [
            'app' => $app, 'webhook' => $created['webhook'], 'secret' => $created['secret'], 'verb' => 'created',
        ]));
    }

    public function update(UpdateWebhookRequest $request, int $application, int $webhook)
    {
        $hook = $this->hook($application, $webhook);
        $this->webhooks->update($hook, $this->actor(), $request->validated());

        return back()->with('status', 'Webhook updated.');
    }

    public function toggle(int $application, int $webhook)
    {
        $hook = $this->hook($application, $webhook);
        $this->webhooks->setActive($hook, $this->actor(), ! $hook->is_active);

        return back()->with('status', $hook->fresh()->is_active ? 'Webhook enabled.' : 'Webhook disabled.');
    }

    public function rotateSecret(int $application, int $webhook)
    {
        $app = $this->application($application);
        $hook = $this->hook($application, $webhook);
        $secret = $this->webhooks->rotateSecret($hook, $this->actor());

        return $this->noStore(response()->view('developers.webhooks.secret', [
            'app' => $app, 'webhook' => $hook, 'secret' => $secret, 'verb' => 'rotated',
        ]));
    }

    public function destroy(int $application, int $webhook)
    {
        $this->webhooks->delete($this->hook($application, $webhook), $this->actor());

        return redirect()->route('developers.webhooks.index', $application)->with('status', 'Webhook deleted.');
    }

    public function deliveries(int $application, int $webhook)
    {
        $app = $this->application($application);
        $hook = $this->hook($application, $webhook);

        return view('developers.webhooks.deliveries', [
            'app' => $app,
            'webhook' => $hook,
            'deliveries' => DeveloperWebhookDelivery::query()
                ->where('webhook_id', $hook->id)
                ->with(['event:id,event_id,type,occurred_at', 'attemptLog'])
                ->latest('id')->paginate(25),
        ]);
    }

    public function retry(int $application, int $webhook, int $delivery)
    {
        $hook = $this->hook($application, $webhook);
        // Scoped to THIS webhook, which is scoped to THIS developer's application.
        $d = DeveloperWebhookDelivery::query()->where('webhook_id', $hook->id)->whereKey($delivery)->firstOrFail();

        $this->webhooks->retry($hook, $d, $this->actor());

        return back()->with('status', 'Delivery queued for retry.');
    }

    /** Webhook loaded through the application, which is loaded through the developer. */
    private function hook(int $application, int $webhook): \App\Models\Developer\DeveloperWebhook
    {
        $app = $this->application($application);

        return $app->hasMany(\App\Models\Developer\DeveloperWebhook::class, 'application_id')->whereKey($webhook)->firstOrFail();
    }
}
