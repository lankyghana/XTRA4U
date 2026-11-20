<?php

namespace App\Http\Controllers\Developers;

use App\Http\Requests\Developers\StoreApplicationRequest;
use App\Http\Requests\Developers\UpdateApplicationRequest;
use App\Models\Developer\DeveloperApiRequest;
use App\Models\Developer\DeveloperApplication;
use App\Services\DeveloperApi\DeveloperApplicationService;

class ApplicationController extends DeveloperController
{
    public function __construct(private readonly DeveloperApplicationService $apps) {}

    public function index()
    {
        return view('developers.applications.index', [
            'applications' => DeveloperApplication::query()->where('vendor_id', $this->vendor()->id)->withCount([
                'apiKeys as active_keys_count' => fn ($q) => $q->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            ])->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('developers.applications.create');
    }

    public function store(StoreApplicationRequest $request)
    {
        $app = $this->apps->create($this->vendor(), $request->validated());

        return redirect()->route('developers.applications.show', $app)->with('status', 'Application created. It belongs to your store and can only use your own products.');
    }

    public function show(int $application)
    {
        $app = $this->application($application);
        $app->load(['vendor:id,name,vendor_code', 'plan']);

        return view('developers.applications.show', [
            'app' => $app,
            'keys' => $app->apiKeys()->latest('id')->get(),
            'scopes' => \App\Services\DeveloperApi\ApiKeyService::availableScopes(),
            'webhookCount' => $app->webhooks()->count(),
            'sandbox' => app(\App\Services\DeveloperApi\DeveloperDashboardService::class)->sandbox([$app->id]),
            'recentRequests' => DeveloperApiRequest::query()->where('application_id', $app->id)->latest('id')->limit(15)->get(),
        ]);
    }

    public function requests(int $application)
    {
        $app = $this->application($application);

        return view('developers.applications.requests', [
            'app' => $app,
            'requests' => DeveloperApiRequest::query()->where('application_id', $app->id)->latest('id')->paginate(50),
        ]);
    }

    public function update(UpdateApplicationRequest $request, int $application)
    {
        $app = $this->application($application);
        $this->apps->update($app, $this->vendor(), $request->validated());

        return back()->with('status', 'Application updated.');
    }

    public function destroy(int $application)
    {
        $app = $this->application($application);
        $this->apps->revoke($app, $this->actor());

        return redirect()->route('developers.applications.index')->with('status', 'Application revoked. All its keys and webhooks were disabled.');
    }
}
