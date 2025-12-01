<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VendorAuthController extends Controller
{
	public function showLoginForm()
	{
		return view('vendor.login');
	}

	public function login(Request $request)
	{
		$credentials = $request->validate([
			'email' => ['required', 'email'],
			'password' => ['required'],
		]);

		$vendor = Vendor::where('email', $credentials['email'])->first();

		if (! $vendor || ! Hash::check($credentials['password'], $vendor->password)) {
			throw ValidationException::withMessages([
				'email' => __('auth.failed'),
			]);
		}

		if (! $vendor->is_approved) {
			throw ValidationException::withMessages([
				'email' => __('Your vendor account has not been approved yet.'),
			]);
		}

		Auth::guard('vendor')->login($vendor, $request->boolean('remember'));
		$request->session()->regenerate();

		return redirect()->intended(route('vendor.dashboard'));
	}
}
