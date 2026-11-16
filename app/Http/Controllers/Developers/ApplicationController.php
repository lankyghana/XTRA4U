<?php

namespace App\Http\Controllers\Developers;

use App\Http\Requests\Developers\StoreApplicationRequest;
use App\Http\Requests\Developers\UpdateApplicationRequest;
use App\Models\Developer\DeveloperApiRequest;
use App\Services\DeveloperApi\DeveloperApplicationService;

class ApplicationController extends DeveloperController
{
    public function __construct(private readonly DeveloperApplicationService $apps) {}

    public function index()
    {
        return view('developers.applications.index', [
            'applications' => $this->developer()->applications()->withCount([
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
        $app = $this->apps->create($this->developer(), $request->validated());

        return redirect()->route('developers.applications.show', $app)->with('status', 'Application created. It starts in the sandbox.');
    }

    public function show(int $application)
    {
        $app = $this->application($application);
        $app->load(['vendor:id,name,vendor_code', 'plan']);

        return view('developers.applications.show', [
            'app' => $app,
            'keys' => $app->apiKeys()->latest('id')->get(),
            'scopes' => config('developer_api.scopes'),
            'recentRequests' => DeveloperApiRequest::query()->where('application_id', $app->id)->latest('id')->limit(15)->get(),
        ]);
    }

    public function update(UpdateApplicationRequest $request, int $application)
    {
        $app = $this->application($application);
        $this->apps->update($app, $this->developer(), $request->validated());

        return back()->with('status', 'Application updated.');
    }

    public function requestProduction(int $application)
    {
        $app = $this->application($application);
        $this->apps->requestProduction($app, $this->developer());

        return back()->with('status', 'Production access requested. An administrator will review it.');
    }

    public function destroy(int $application)
    {
        $app = $this->application($application);
        $this->apps->revoke($app, $this->actor());

        return redirect()->route('developers.applications.index')->with('status', 'Application revoked. All its keys and webhooks were disabled.');
    }
}
