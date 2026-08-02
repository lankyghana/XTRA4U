<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\Setting;
use App\Support\WhatsAppLink;
use App\Mail\VendorPasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

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
			$contactNumber = trim((string) Setting::get('vendor_approval_contact_number', ''));
			$message = $contactNumber !== ''
				? "Your vendor account has not been approved yet. Please contact admin on {$contactNumber} for approval."
				: 'Your vendor account has not been approved yet. Please contact admin for approval.';

			$whatsappUrl = WhatsAppLink::url(
				$contactNumber,
				"Hi, I'm {$vendor->name}. My vendor account on XTRA4U has not been approved yet. Could you help approve it?"
			);

			if ($whatsappUrl) {
				session()->flash('vendor_whatsapp_url', $whatsappUrl);
			}

			throw ValidationException::withMessages([
				'email' => __($message),
			]);
		}

		Auth::guard('vendor')->login($vendor, $request->boolean('remember'));
		$request->session()->regenerate();

		return redirect()->intended(route('vendor.dashboard'));
	}

	public function logout(Request $request)
	{
		Auth::guard('vendor')->logout();
		$request->session()->invalidate();
		$request->session()->regenerateToken();

		return redirect()->route('vendor.login.form');
	}

	/**
	 * Show the forgot password form
	 */
	public function showForgotPasswordForm()
	{
		return view('vendor.forgot-password');
	}

	/**
	 * Send password reset link
	 */
	public function sendResetLink(Request $request)
	{
		$request->validate([
			'email' => ['required', 'email'],
		]);

		$vendor = Vendor::where('email', $request->email)->first();

		if (!$vendor) {
			// Don't reveal if email exists or not for security
			return back()->with('status', 'If an account exists with that email, you will receive a password reset link shortly.');
		}

		// Delete any existing tokens for this email
		DB::table('password_reset_tokens')->where('email', $request->email)->delete();

		// Generate a new token
		$token = Str::random(64);

		// Store the token
		DB::table('password_reset_tokens')->insert([
			'email' => $request->email,
			'token' => Hash::make($token),
			'created_at' => Carbon::now()
		]);

		// Send email
		try {
			Mail::to($request->email)->send(new VendorPasswordResetMail($vendor, $token));
		} catch (\Exception $e) {
			return back()->withErrors(['email' => 'Failed to send reset email. Please try again later.']);
		}

		return back()->with('status', 'If an account exists with that email, you will receive a password reset link shortly.');
	}

	/**
	 * Show reset password form
	 */
	public function showResetPasswordForm(Request $request, $token)
	{
		return view('vendor.reset-password', [
			'token' => $token,
			'email' => $request->email
		]);
	}

	/**
	 * Reset the password
	 */
	public function resetPassword(Request $request)
	{
		$request->validate([
			'token' => ['required'],
			'email' => ['required', 'email'],
			'password' => ['required', 'min:8', 'confirmed'],
		]);

		// Find the reset record
		$resetRecord = DB::table('password_reset_tokens')
			->where('email', $request->email)
			->first();

		if (!$resetRecord) {
			return back()->withErrors(['email' => 'Invalid password reset request.']);
		}

		// Check if token is valid
		if (!Hash::check($request->token, $resetRecord->token)) {
			return back()->withErrors(['email' => 'Invalid password reset token.']);
		}

		// Check if token has expired (60 minutes)
		if (Carbon::parse($resetRecord->created_at)->addMinutes(60)->isPast()) {
			DB::table('password_reset_tokens')->where('email', $request->email)->delete();
			return back()->withErrors(['email' => 'Password reset link has expired. Please request a new one.']);
		}

		// Find the vendor and update password
		$vendor = Vendor::where('email', $request->email)->first();

		if (!$vendor) {
			return back()->withErrors(['email' => 'No account found with that email address.']);
		}

		$vendor->password = Hash::make($request->password);
		$vendor->save();

		// Delete the reset token
		DB::table('password_reset_tokens')->where('email', $request->email)->delete();

		return redirect()->route('vendor.login.form')->with('success', 'Your password has been reset successfully. You can now log in with your new password.');
	}
}
