<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Controllers\Controller;
use App\Models\Developer\DeveloperApplication;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function application(Request $request): DeveloperApplication
    {
        return $request->attributes->get('api.application');
    }
}
