<?php

namespace App\Http\Requests\Admin\Cms;

use App\Support\Cms\CmsAdmin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every CMS form. Authorization lives here as well as in the route
 * middleware, so the rule survives any future re-routing of an endpoint.
 */
abstract class CmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CmsAdmin::check();
    }
}
