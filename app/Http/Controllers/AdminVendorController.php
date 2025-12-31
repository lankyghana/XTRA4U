<?php

namespace App\Http\Controllers;

use App\Mail\VendorApprovedMail;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Mail;

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
			'name' => ['sometimes', 'string', 'max:255'],
			'email' => ['sometimes', 'email', 'max:255'],
			'phone_number' => ['sometimes', 'string', 'max:40'],
		]);

		$vendor->update(array_filter($data));

		return back()->with('status', 'Vendor details updated successfully.');
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
}
