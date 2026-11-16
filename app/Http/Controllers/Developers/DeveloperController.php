<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Models\Developer\Developer;
use App\Models\Developer\DeveloperApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/** Shared helpers: who is the developer, and load an application ONLY through their own relationship. */
abstract class DeveloperController extends Controller
{
    protected function developer(): Developer
    {
        /** @var Developer $developer */
        $developer = Auth::guard('developer')->user();

        return $developer;
    }

    protected function actor(): string
    {
        return 'developer:'.$this->developer()->id;
    }

    /** 404 for anything not owned (never confirms that another developer's application exists). */
    protected function application(int|string $id): DeveloperApplication
    {
        $app = $this->developer()->applications()->whereKey($id)->firstOrFail();

        Gate::forUser($this->developer())->authorize('manage', $app);

        return $app;
    }

    /** A response that carries a one-time secret: never cacheable. */
    protected function noStore($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    protected function redirectBack(Request $request, string $route, array $params = [])
    {
        return redirect()->route($route, $params);
    }
}
