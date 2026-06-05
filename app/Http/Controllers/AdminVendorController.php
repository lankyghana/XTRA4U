<?php

namespace App\Http\Controllers;

use App\Mail\VendorApprovedMail;
use App\Models\Vendor;
use App\Models\WalletLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminVendorController extends Controller
{
	public function index(Request $request): View
	{
		$statusFilter = $request->query('status');
		$search = trim((string) $request->query('q', ''));

		$vendors = Vendor::query()
			->with('affiliateVendor')
			->withCount('affiliates')
			->when($search !== '', function ($query) use ($search) {
				$query->where(function ($q) use ($search) {
					$q->where('name', 'like', '%' . $search . '%')
						->orWhere('email', 'like', '%' . $search . '%')
						->orWhere('phone_number', 'like', '%' . $search . '%')
						->orWhere('vendor_code', 'like', '%' . $search . '%');
				});
			})
			->when($statusFilter === 'approved', fn ($query) => $query->where('is_approved', true))
			->when($statusFilter === 'pending', fn ($query) => $query->where('is_approved', false))
			->latest()
			->paginate(15)
			->withQueryString();

		$stats = [
			'total' => Vendor::count(),
			'approved' => Vendor::where('is_approved', true)->count(),
			'pending' => Vendor::where('is_approved', false)->count(),
		];

		return view('admin.vendors', compact('vendors', 'stats', 'statusFilter', 'search'));
	}

	public function update(Request $request, Vendor $vendor): RedirectResponse
	{
		$data = $request->validate([
			'name'         => ['sometimes', 'string', 'max:255'],
			'email'        => ['sometimes', 'email', 'max:255', 'unique:vendors,email,' . $vendor->id],
			'phone_number' => ['sometimes', 'nullable', 'string', 'max:40'],
		]);

		// Trim whitespace from string fields before persisting.
		foreach (['name', 'email', 'phone_number'] as $field) {
			if (isset($data[$field])) {
				$data[$field] = trim($data[$field]);
			}
		}

		$vendor->update(array_filter($data, fn ($v) => $v !== null));

		Log::info('Admin updated vendor contact details', [
			'admin_id'  => auth()->id(),
			'vendor_id' => $vendor->id,
			'fields'    => array_keys($data),
		]);

		return back()->with('status', 'Vendor contact details updated successfully.');
	}

	public function destroy(Vendor $vendor): RedirectResponse
	{
		$vendor->delete();

		return redirect()->route('admin.vendors.index')->with('status', 'Vendor removed successfully.');
	}

	public function approve(Vendor $vendor): RedirectResponse
	{
		$wasApproved = (bool) $vendor->is_approved;
		if (! $wasApproved) {
			$vendor->forceFill(['is_approved' => true])->save();

			if ($vendor->email) {
				try {
					Mail::to($vendor->email)->queue(new VendorApprovedMail($vendor));
				} catch (\Throwable $e) {
					// Approval should still succeed even if email fails.
				}
			}
		}

		return back()->with('status', 'Vendor approved successfully.');
	}

	public function reject(Vendor $vendor): RedirectResponse
	{
		if ($vendor->is_approved) {
			$vendor->forceFill(['is_approved' => false])->save();
		}

		return back()->with('status', 'Vendor marked as pending/suspended.');
	}

	public function disableAffiliate(Vendor $vendor): RedirectResponse
	{
		if (is_null($vendor->affiliate_vendor_id)) {
			return back()->with('status', 'This vendor is not currently affiliated to anyone.');
		}

		$vendor->forceFill(['affiliate_vendor_id' => null])->save();

		return back()->with('status', 'Affiliate relationship disabled successfully.');
	}

	public function adjustBalance(Request $request, Vendor $vendor): RedirectResponse
	{
		$data = $request->validate([
			'amount' => ['required', 'numeric', 'min:0.01'],
			'reason' => ['required', 'string', 'min:5'],
		]);

		DB::transaction(function () use ($vendor, $data, $request) {
			$lockedVendor = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
			$amount = (float) $data['amount'];

			if ($lockedVendor->wallet_balance < $amount) {
				throw ValidationException::withMessages([
					'amount' => 'Insufficient vendor balance.',
				]);
			}

			$lockedVendor->decrement('wallet_balance', $amount);
			$lockedVendor->refresh();

			WalletLedger::create([
				'vendor_id' => $lockedVendor->id,
				'type' => 'debit',
				'source' => 'admin_adjustment',
				'amount' => $amount,
				'balance_after' => (float) $lockedVendor->wallet_balance,
				'metadata' => [
					'reason' => $data['reason'],
					'admin_id' => auth()->id(),
					'ip_address' => $request->ip(),
					'action' => 'admin_debit',
				],
			]);
		});

		Log::info('Admin adjusted vendor wallet balance', [
			'admin_id' => auth()->id(),
			'admin_ip' => $request->ip(),
			'vendor_id' => $vendor->id,
			'amount' => $data['amount'],
			'reason' => $data['reason'],
		]);

		return back()->with('status', 'Balance adjusted successfully.');
	}
}
