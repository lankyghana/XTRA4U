<?php

namespace App\Http\Requests\Admin\Cms;

use App\Support\Cms\CmsLink;
use App\Support\Cms\CmsRegistry;

class CmsNavigationRequest extends CmsRequest
{
    public function rules(): array
    {
        $rules = ['items' => ['nullable', 'array']];

        foreach (array_keys(CmsRegistry::NAV_LOCATIONS) as $location) {
            $max = $location === 'header' ? 8 : 10;
            $rules["items.$location"] = ['nullable', 'array', 'max:'.$max];
            $rules["items.$location.*.label"] = ['required', 'string', 'max:60'];
            $rules["items.$location.*.url"] = ['required', 'string', 'max:255', function ($attr, $value, $fail) {
                if ($reason = CmsLink::validate($value)) {
                    $fail($reason);
                }
            }];
            $rules["items.$location.*.visible"] = ['nullable', 'boolean'];
        }

        // Locations other than the whitelist are not accepted at all.
        $rules['items.*'] = [function ($attr, $value, $fail) {
            $key = substr($attr, strlen('items.'));
            if (! array_key_exists($key, CmsRegistry::NAV_LOCATIONS)) {
                $fail('Unknown menu.');
            }
        }];

        return $rules;
    }

    public function attributes(): array
    {
        return ['items.*.*.label' => 'label', 'items.*.*.url' => 'link'];
    }
}
