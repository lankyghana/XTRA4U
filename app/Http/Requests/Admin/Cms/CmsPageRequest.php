<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\Cms\CmsPage;
use App\Support\Cms\CmsLink;
use App\Support\Cms\CmsRegistry;
use Illuminate\Validation\Rule;

class CmsPageRequest extends CmsRequest
{
    public function rules(): array
    {
        /** @var CmsPage|null $page */
        $page = $this->route('page');
        $editingSystem = $page?->is_system ?? false;

        $rules = [
            'title' => ['required', 'string', 'max:160'],
            'icon' => ['nullable', Rule::in(CmsRegistry::ICONS)],
            'body' => ['nullable', 'string', 'max:100000'],
        ] + self::seoRules();

        if (! $editingSystem) {
            $rules['slug'] = [
                'required', 'string', 'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(CmsPage::RESERVED_SLUGS),
                Rule::unique('cms_pages', 'slug')->ignore($page?->id),
            ];
        }

        return $rules;
    }

    /** Shared with the structured-page SEO form. */
    public static function seoRules(): array
    {
        $link = function ($attr, $value, $fail) {
            if ($value !== null && $value !== '' && ($reason = CmsLink::validate($value))) {
                $fail($reason);
            }
        };

        return [
            'seo_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'string', 'max:255', $link, function ($attr, $value, $fail) {
                if ($value && ! str_starts_with($value, '/') && ! str_starts_with($value, 'https://')) {
                    $fail('The canonical address must be a /path or an https:// link.');
                }
            }],
            'og_title' => ['nullable', 'string', 'max:160'],
            'og_description' => ['nullable', 'string', 'max:320'],
            'og_image_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
            'robots_noindex' => ['nullable', 'boolean'],
        ];
    }

    /** Empty strings become null so SEO fallbacks (title -> seo title -> og title) work. */
    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['icon', 'seo_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'og_image_media_id'] as $key) {
            if ($this->has($key) && trim((string) $this->input($key)) === '') {
                $merge[$key] = null;
            }
        }
        $merge['robots_noindex'] = $this->boolean('robots_noindex');
        if ($this->has('slug')) {
            $merge['slug'] = strtolower(trim((string) $this->input('slug')));
        }
        $this->merge($merge);
    }
}
