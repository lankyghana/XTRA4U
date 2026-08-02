<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\Vendor;
use App\Support\WhatsAppLink;
use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureVendorApproved
{
    public function handle($request, Closure $next)
    {
        $vendor = Auth::guard('vendor')->user();

        // Not logged in as vendor: send to vendor login.
        if (!($vendor instanceof Vendor)) {
            return redirect()->route('vendor.login.form');
        }

        // Logged in but not approved (e.g. suspended after login): force logout and return to login.
        if (!$vendor->is_approved) {
            Auth::guard('vendor')->logout();

            $contactNumber = trim((string) Setting::get('vendor_approval_contact_number', ''));
            $message = $contactNumber !== ''
                ? "Your vendor account is not approved. Please contact admin on {$contactNumber} for approval."
                : 'Your vendor account is not approved. Please contact admin for approval.';

            $whatsappUrl = WhatsAppLink::url(
                $contactNumber,
                "Hi, I'm {$vendor->name}. My vendor account on XTRA4U has not been approved yet. Could you help approve it?"
            );

            return redirect()
                ->route('vendor.login.form')
                ->withErrors(['access' => $message])
                ->with('vendor_whatsapp_url', $whatsappUrl);
        }

        return $next($request);
    }
}
