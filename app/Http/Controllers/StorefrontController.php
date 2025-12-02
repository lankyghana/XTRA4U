<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
	public function index()
	{
		return view('storefront.index');
	}

	public function showVendorStore(Vendor $vendor)
	{
		$vendor->load('products');

		return view('vendor_store', [
			'vendor' => $vendor,
			'products' => $vendor->products,
		]);
	}
}
