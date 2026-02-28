<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\Auth;

class AdminOnly
{
    public function handle($request, Closure $next)
    {
        // Accept either the dedicated admin guard or a web user with role=admin
        $user = Auth::guard('admin')->user() ?: Auth::user();

        if (! $user) {
            return redirect()->route('admin.login');
        }

        if (($user->role ?? 'admin') !== 'admin') {
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
