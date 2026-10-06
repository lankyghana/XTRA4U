<?php

namespace App\Support\Cms;

use App\Models\Cms\CmsMedia;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Builds validation from a section's field schema and returns ONLY schema
 * keys, cleaned. Anything not in the schema in the request is ignored, so an
 * attacker cannot smuggle extra keys (or script payloads under unknown keys)
 * into the stored JSON.
 */
final class CmsSectionInput
{
    /**
     * @param  array<string,array>  $fields
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $fields, array $input, string $errorPrefix = 'data'): array
    {
        $rules = [];
        $messages = [];
        self::rulesFor($fields, '', $rules);

        $validator = Validator::make($input, $rules, $messages);
        $validator->setAttributeNames(self::labels($fields));

        if ($validator->fails()) {
            // Re-key errors under the form's input name so they render beside the field.
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $msgs) {
                $errors[$errorPrefix.'.'.$key] = $msgs;
            }
            throw ValidationException::withMessages($errors);
        }

        return self::clean($fields, $input);
    }

    private static function rulesFor(array $fields, string $prefix, array &$rules): void
    {
        foreach ($fields as $name => $field) {
            $key = $prefix.$name;
            $max = $field['max'] ?? 255;

            switch ($field['type']) {
                case 'text':
                case 'textarea':
                    $rules[$key] = ['nullable', 'string', 'max:'.$max];
                    break;
                case 'link':
                    $rules[$key] = ['nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                        if ($reason = CmsLink::validate($value)) {
                            $fail($reason);
                        }
                    }];
                    break;
                case 'select':
                    $rules[$key] = ['nullable', 'in:'.implode(',', $field['options'])];
                    break;
                case 'number':
                    $rules[$key] = ['nullable', 'numeric', 'between:0,99999999'];
                    break;
                case 'image':
                    $rules[$key] = ['nullable', function ($attr, $value, $fail) use ($field) {
                        if ($value === null || $value === '') {
                            return;
                        }
                        if (is_numeric($value) && CmsMedia::whereKey((int) $value)->exists()) {
                            return;
                        }
                        // The shipped default may be re-submitted untouched.
                        if ($value === ($field['default'] ?? null) && str_starts_with((string) $value, CmsRegistry::BUNDLED_PREFIX)) {
                            return;
                        }
                        $fail('Choose an image from the media library.');
                    }];
                    break;
                case 'repeater':
                    $rules[$key] = ['nullable', 'array', 'max:'.$field['max']];
                    self::rulesFor($field['fields'], $key.'.*.', $rules);
                    break;
            }
        }
    }

    private static function labels(array $fields): array
    {
        $labels = [];
        foreach ($fields as $name => $field) {
            $labels[$name] = strtolower($field['label']);
            if ($field['type'] === 'repeater') {
                foreach ($field['fields'] as $sub => $subField) {
                    $labels[$name.'.*.'.$sub] = strtolower($field['label'].' '.$subField['label']);
                }
            }
        }

        return $labels;
    }

    private static function clean(array $fields, array $input): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            $value = $input[$name] ?? null;

            if ($field['type'] === 'repeater') {
                $rows = [];
                foreach ((array) $value as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $clean = self::clean($field['fields'], $row);
                    // Drop rows the editor left completely blank.
                    if (array_filter($clean, fn ($v) => $v !== '' && $v !== null) !== []) {
                        $rows[] = $clean;
                    }
                }
                $out[$name] = $rows;

                continue;
            }

            $out[$name] = is_string($value) ? trim($value) : ($value === null ? '' : (string) $value);
            if ($field['type'] === 'image' && is_numeric($out[$name])) {
                $out[$name] = (int) $out[$name];
            }
        }

        return $out;
    }

    /** Repeater minimums need the cleaned rows, so they are checked after cleaning. */
    public static function assertMinimums(array $fields, array $clean, string $errorPrefix = 'data'): void
    {
        $errors = [];
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'repeater' && count($clean[$name] ?? []) < ($field['min'] ?? 0)) {
                $errors[$errorPrefix.'.'.$name] = ['Add at least '.$field['min'].' '.strtolower($field['label']).'.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
