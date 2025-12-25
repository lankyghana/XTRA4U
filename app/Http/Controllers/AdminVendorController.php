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

		$vendors = Vendor::query()
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

		return view('admin.vendors', compact('vendors', 'stats', 'statusFilter'));
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
}
