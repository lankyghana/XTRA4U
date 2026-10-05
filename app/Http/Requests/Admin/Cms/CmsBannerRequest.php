<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\Cms\CmsBanner;
use Illuminate\Validation\Rule;

class CmsBannerRequest extends CmsRequest
{
    public function rules(): array
    {
        return [
            'placement' => ['required', Rule::in(array_keys(CmsBanner::PLACEMENTS))],
            'title' => ['required', 'string', 'max:160'],
            'image_media_id' => ['required', 'integer', 'exists:cms_media,id'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    public function attributes(): array
    {
        return ['image_media_id' => 'image', 'title' => 'title (also used as the image description)'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
        foreach (['starts_at', 'ends_at'] as $key) {
            if (trim((string) $this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }
    }
}
