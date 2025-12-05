<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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

        // Validate affiliate vendor code if provided
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

        $vendorCode = $this->generateVendorCode($validated['name']);

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

        return redirect()->route('vendor.request.form')->with('success', 'Request submitted! Await admin approval.');
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
        $vendor = Auth::user();
        if (!$vendor || !$vendor->is_approved) {
            return redirect()->route('vendor.request.form')->withErrors(['access' => 'Access denied.']);
        }

        // Sales summary: total sales (sum of amount_paid from completed orders only)
        $totalSales = $vendor->orders()
            ->whereNotIn('status', ['Cancelled', 'Failed'])
            ->sum('amount_paid');

        // Earnings summary: after 1% commission (sum of vendor_earning from completed transactions)
        $totalEarnings = Transaction::where('vendor_id', $vendor->id)
            ->where('payment_status', 'completed')
            ->sum('vendor_earning');

        // Order list: all orders for this vendor
        $orders = $vendor->orders()->latest()->get();

        // Product management: all products for this vendor
        $products = $vendor->products()->get();

        // Unique store link
        $storeLink = route('vendor.store', ['vendorname' => $vendor->name]);

        return view('vendor_dashboard', compact('vendor', 'totalSales', 'totalEarnings', 'orders', 'products', 'storeLink'));
    }

    /**
     * Generate a unique vendor code based on business name + random alphanumeric
     * Format: First 3-4 letters of name + 6 random alphanumeric characters
     * Example: "Daniel Kwadwo Takyi" -> "DANI7X9K2L" or "MTN Ghana" -> "MTN4A8C9D2"
     */
    private function generateVendorCode(string $name): string
    {
        // Extract first letters of name (remove spaces, take first 3-4 chars, uppercase)
        $namePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 4));
        
        // Keep trying until we get a unique code
        do {
            // Generate random alphanumeric string (6 characters)
            $randomSuffix = strtoupper(Str::random(6));
            $vendorCode = $namePrefix . $randomSuffix;
            
            // Check if this code already exists
            $exists = Vendor::where('vendor_code', $vendorCode)->exists();
        } while ($exists);
        
        return $vendorCode;
    }
}

