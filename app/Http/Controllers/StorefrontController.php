<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class StorefrontController extends Controller {
	public function index()
	{
		// Return a view or response
		return view('storefront.index');
	}
}
