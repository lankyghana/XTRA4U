<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\AdminNotification;
use App\Models\Setting;
use App\Support\WhatsAppLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class VendorController extends Controller
{
    // Show vendor request form
    public function showRequestForm()
    {
        return view('vendor_request');
    }

    // Handle vendor request submission
    public function submitRequest(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:vendors,email',
            'phone_number' => 'required|string|max:20',
            'password' => 'required|string|min:6',
            'affiliate_vendor_code' => 'nullable|string|max:10',
        ]);

        // Validate affiliate vendor code (optional)
        $affiliateVendorId = null;
        if (!empty($validated['affiliate_vendor_code'])) {
            $affiliateVendor = Vendor::where('vendor_code', strtoupper($validated['affiliate_vendor_code']))->first();

            if (!$affiliateVendor) {
                return back()
                    ->withInput()
                    ->withErrors(['affiliate_vendor_code' => 'The affiliate vendor code does not exist. Please check and try again.']);
            }

            $affiliateVendorId = $affiliateVendor->id;
        }

        $vendorCode = Vendor::generateUniqueCode($validated['name']);

        $vendor = Vendor::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone_number' => $validated['phone_number'],
            'password' => Hash::make($validated['password']),
            'is_approved' => false,
            'vendor_code' => $vendorCode,
            'affiliate_vendor_id' => $affiliateVendorId,
        ]);

        // Notify admin about new vendor registration
        AdminNotification::notifyNewVendor($vendor);

        return redirect()->route('vendor.request.pending', ['name' => $vendor->name]);
    }

    // Confirmation page shown after a vendor submits a registration request
    public function requestPending(Request $request)
    {
        $contactNumber = trim((string) Setting::get('vendor_approval_contact_number', ''));
        $vendorName = $request->query('name');

        $whatsappMessage = $vendorName
            ? "Hi, I'm {$vendorName}. I just submitted a vendor registration request on XTRA4U and would like to get approved."
            : "Hi, I just submitted a vendor registration request on XTRA4U and would like to get approved.";

        return view('vendor_request_pending', [
            'contactNumber' => $contactNumber,
            'whatsappUrl' => WhatsAppLink::url($contactNumber, $whatsappMessage),
            'vendorName' => $vendorName,
        ]);
    }

    // Admin approves vendor
    public function approve($id)
    {
        $vendor = Vendor::findOrFail($id);
        $vendor->is_approved = true;
        $vendor->save();
        return redirect()->back()->with('success', 'Vendor approved.');
    }

    // Restrict login to approved vendors
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        $vendor = Vendor::where('email', $credentials['email'])->first();
        if ($vendor && $vendor->is_approved && Hash::check($credentials['password'], $vendor->password)) {
            Auth::login($vendor);
            return redirect()->route('vendor.dashboard');
        }
        return back()->withErrors(['login' => 'Invalid credentials or not approved.']);
    }

    // Vendor dashboard (only for approved vendors)
    public function dashboard()
    {
        /** @var Vendor $vendor */
        $vendor = Auth::user();
        if (!$vendor || !$vendor->is_approved) {
            return redirect()->route('vendor.request.form')->withErrors(['access' => 'Access denied.']);
        }

        // Sales summary: total sales (sum of amount_paid from completed orders only)
        $totalSales = $vendor->orders()
            ->whereNotIn('status', ['Cancelled', 'Failed'])
            ->sum('amount_paid');

        // Earnings summary: after 2% commission (sum of vendor_earning from successful transactions)
        $totalEarnings = Transaction::where('vendor_id', $vendor->id)
            ->whereIn('payment_status', ['completed', 'successful'])
            ->sum('vendor_earning');

        // Order list: paid orders only
        $orders = $vendor->orders()
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereIn('status', ['Processing', 'Completed'])
            ->with('service')
            ->latest()
            ->get();

        // Product management: all products for this vendor
        $products = $vendor->products()->get();

        // Unique store link
        $storeLink = route('vendor.store', ['vendorname' => $vendor->name]);

        return view('vendor_dashboard', compact('vendor', 'totalSales', 'totalEarnings', 'orders', 'products', 'storeLink'));
    }

}
