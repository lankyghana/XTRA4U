<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Developers\RegisterDeveloperRequest;
use App\Models\Developer\Developer;
use App\Services\DeveloperApi\DeveloperAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Same session-guard login pattern as the vendor and admin realms, on the `developer` guard. */
class AuthController extends Controller
{
    public function landing()
    {
        return Auth::guard('developer')->check()
            ? redirect()->route('developers.dashboard')
            : view('developers.landing');
    }

    public function showRegister()
    {
        return view('developers.auth.register');
    }

    public function register(RegisterDeveloperRequest $request, DeveloperAuditLogger $audit)
    {
        $data = $request->validated();

        $developer = Developer::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'company' => $data['company'] ?? null,
            'password' => $data['password'],
        ]);

        $audit->log('developer:'.$developer->id, 'developer.registered');

        Auth::guard('developer')->login($developer);
        $request->session()->regenerate();

        return redirect()->route('developers.dashboard');
    }

    public function showLogin()
    {
        return view('developers.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // `status` is part of the credentials: a suspended developer can never authenticate.
        $ok = Auth::guard('developer')->attempt(
            ['email' => strtolower($credentials['email']), 'password' => $credentials['password'], 'status' => Developer::STATUS_ACTIVE],
            $request->boolean('remember')
        );

        if (! $ok) {
            // One message for unknown email, wrong password and suspended account.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('developers.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('developer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('developers.landing');
    }
}
