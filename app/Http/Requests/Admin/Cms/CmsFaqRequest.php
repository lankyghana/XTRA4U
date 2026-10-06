<?php

namespace App\Http\Requests\Admin\Cms;

class CmsFaqRequest extends CmsRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:60'],
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'category' => trim((string) $this->input('category')) ?: 'General',
        ]);
    }
}
