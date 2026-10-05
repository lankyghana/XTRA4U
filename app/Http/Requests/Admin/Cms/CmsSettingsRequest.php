<?php

namespace App\Http\Requests\Admin\Cms;

use App\Support\Cms\CmsLink;
use App\Support\Cms\CmsRegistry;

class CmsSettingsRequest extends CmsRequest
{
    /** Hosts a social link may point at, so a typo cannot publish an unrelated address. */
    private const SOCIAL_HOSTS = [
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'instagram' => ['instagram.com'],
        'x' => ['x.com', 'twitter.com'],
        'tiktok' => ['tiktok.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'linkedin' => ['linkedin.com'],
    ];

    /**
     * Form field name for a setting key. Dots would be read as array nesting by
     * the validator, so they are swapped for a double underscore.
     */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (CmsRegistry::settings() as $key => $def) {
            $rules['settings.'.self::field($key)] = match ($def['type']) {
                'image' => ['nullable', 'integer', 'exists:cms_media,id'],
                'email' => ['nullable', 'email:rfc', 'max:'.$def['max']],
                'phone' => ['nullable', 'string', 'max:'.$def['max'], 'regex:/^[0-9+()\-\s.]{5,}$/'],
                'url' => ['nullable', 'string', 'max:'.$def['max'], $this->urlRule($key)],
                default => ['nullable', 'string', 'max:'.$def['max']],
            };
        }

        return $rules;
    }

    public function attributes(): array
    {
        $labels = [];
        foreach (CmsRegistry::settings() as $key => $def) {
            $labels['settings.'.self::field($key)] = strtolower($def['label']);
        }

        return $labels;
    }

    /** @return array<string, string> whitelisted key => cleaned value */
    public function validatedSettings(): array
    {
        $input = (array) $this->validated()['settings'];
        $out = [];
        foreach (array_keys(CmsRegistry::settings()) as $key) {
            $field = self::field($key);
            if (array_key_exists($field, $input)) {
                $out[$key] = trim((string) ($input[$field] ?? ''));
            }
        }

        return $out;
    }

    private function urlRule(string $key): \Closure
    {
        return function ($attr, $value, $fail) use ($key) {
            if ($value === null || trim($value) === '') {
                return;
            }
            if ($reason = CmsLink::validate($value)) {
                $fail($reason);

                return;
            }
            if (! CmsLink::isExternal($value)) {
                $fail('Use a full https:// address.');

                return;
            }

            $host = strtolower((string) parse_url($value, PHP_URL_HOST));
            $allowed = str_starts_with($key, 'social.')
                ? (self::SOCIAL_HOSTS[substr($key, 7)] ?? [])
                : ($key === 'contact.whatsapp_channel_url' ? ['whatsapp.com', 'wa.me'] : []);

            if ($allowed && ! collect($allowed)->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d))) {
                $fail('That address does not look like a '.implode(' / ', $allowed).' link.');
            }
        };
    }
}
