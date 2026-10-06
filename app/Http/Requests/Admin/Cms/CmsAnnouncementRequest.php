<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\Cms\CmsAnnouncement;
use App\Support\Cms\CmsLink;
use Illuminate\Validation\Rule;

class CmsAnnouncementRequest extends CmsRequest
{
    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::in(array_keys(CmsAnnouncement::AUDIENCES))],
            'type' => ['required', Rule::in(array_keys(CmsAnnouncement::TYPES))],
            'title' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:600'],
            'link_url' => ['nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                if ($value && ($reason = CmsLink::validate($value))) {
                    $fail($reason);
                }
            }],
            'link_text' => ['nullable', 'string', 'max:60', 'required_with:link_url'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = ['is_active' => $this->boolean('is_active')];
        foreach (['message', 'link_url', 'link_text', 'starts_at', 'ends_at'] as $key) {
            if (trim((string) $this->input($key)) === '') {
                $merge[$key] = null;
            }
        }
        $this->merge($merge);
    }
}
