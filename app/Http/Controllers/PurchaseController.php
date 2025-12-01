<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    // Show checkout form
    public function showCheckout()
    {
        return view('checkout');
    }

    // Handle purchase request
    public function purchase(Request $request)
    {
        return $this->store($request);
    }

    // Store purchase (main method)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'recipient_phone_number' => 'required|string',
            'mobile_money_number' => 'required|string',
            'vendor_id' => 'required|exists:vendors,id',
            'vendor_service_id' => 'required|exists:products,id',
        ]);

        $product = Product::query()
            ->where('id', $validated['vendor_service_id'])
            ->where('vendor_id', $validated['vendor_id'])
            ->where('is_active', true)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'vendor_service_id' => 'The selected vendor service is unavailable.',
            ]);
        }

        $paymentStatus = $paymentSuccess ? 'completed' : 'failed';

        $payload = array_merge($validated, [
            'service_purchased' => $product->name,
            'amount_paid' => number_format((float) $product->price, 2, '.', ''),
            'payment_status' => $paymentStatus,
        ]);

        // Simulate payment processing (placeholder)
        // $paymentSuccess currently always true; replace with gateway result.

        if ($paymentSuccess) {
            $token = (string) Str::uuid();
            session()->put("purchase.tokens.$token", [
                'payload' => $payload,
                'expires_at' => Carbon::now()->addMinutes(10),
            ]);

            // Redirect to callback route with secure token
            return redirect()->route('purchase.callback', ['token' => $token]);
        } else {
            return back()->withErrors(['payment' => 'Payment failed. Please try again.']);
        }
    }

    // Payment callback (simulated)
    public function paymentCallback(Request $request, string $token)
    {
        $stored = session()->pull("purchase.tokens.$token");

        if (! $stored) {
            abort(410, 'Purchase session expired or invalid.');
        }

        if (isset($stored['expires_at']) && Carbon::now()->greaterThan(Carbon::parse($stored['expires_at']))) {
            abort(410, 'Purchase session expired.');
        }

        $payload = $stored['payload'] ?? [];

        $validated = validator($payload, [
            'recipient_phone_number' => 'required|string',
            'mobile_money_number' => 'required|string',
            'vendor_id' => 'required|exists:vendors,id',
            'vendor_service_id' => 'required|exists:products,id',
            'service_purchased' => 'required|string',
            'amount_paid' => 'required|numeric',
            'payment_status' => 'required|in:completed,failed',
        ])->validate();

                // Simulate payment processing (placeholder)
                $paymentSuccess = true; // Replace with actual payment API integration

                $paymentStatus = $paymentSuccess ? 'completed' : 'failed';

                $payload = array_merge($validated, [
                    'service_purchased' => $product->name,
                    'amount_paid' => number_format((float) $product->price, 2, '.', ''),
                    'payment_status' => $paymentStatus,
                ]);

        $serviceName = $product->name;
        $amountPaid = (float) $product->price;

        DB::transaction(function () use ($validated, $serviceName, $amountPaid, $product) {
            // Create order
            $order = Order::create([
                'recipient_phone_number' => $validated['recipient_phone_number'],
                'mobile_money_number' => $validated['mobile_money_number'],
                'service_purchased' => $serviceName,
                'amount_paid' => $amountPaid,
                'vendor_id' => $product->vendor_id,
                'vendor_service_id' => $product->id,
                'status' => $validated['payment_status'] === 'completed' ? 'Completed' : 'Failed',
            ]);

            // Calculate commission and vendor earnings
            $commission = round($amountPaid * 0.01, 2);
            $vendorEarnings = round($amountPaid - $commission, 2);

            // Log transaction
            Transaction::create([
                'order_id' => $order->id,
                'vendor_id' => $product->vendor_id,
                'recipient_phone' => $validated['recipient_phone_number'],
                'amount' => $amountPaid,
                'commission_amount' => $commission,
                'vendor_earning' => $vendorEarnings,
                'payment_status' => $validated['payment_status'],
            ]);

            // Log for audit
            Log::info('Order created and transaction logged', [
                'order_id' => $order->id,
                'commission' => $commission,
                'vendor_earnings' => $vendorEarnings,
            ]);
        });

        return view('checkout_success');
    }
}
