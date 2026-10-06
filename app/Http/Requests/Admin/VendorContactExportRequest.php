<?php

namespace App\Http\Requests\Admin;

use App\Services\VendorContactExport\VendorContactExporter;
use App\Services\VendorContactExport\VendorContactWriter;
use App\Support\Cms\CmsAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates every export/preview request server-side. Only whitelisted keys survive validated(), and every
 * enumerated value is checked against a fixed list — the browser's values are never trusted.
 */
class VendorContactExportRequest extends FormRequest
{
    public const MAX_CUSTOM_LIMIT = 1000000;

    public const PRESET_LIMITS = ['100', '500', '1000', '5000', '10000'];

    /** Defence in depth on top of the admin.only route middleware: explicit admin identity, no default-to-admin. */
    public function authorize(): bool
    {
        return CmsAdmin::check();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'remove_duplicates' => $this->boolean('remove_duplicates'),
            'valid_only' => $this->boolean('valid_only'),
        ]);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['all', 'approved', 'pending'])],
            'tier_id' => ['nullable', 'integer', 'exists:vendor_tiers,id'],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date', 'after_or_equal:registered_from'],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['required', Rule::in([...self::PRESET_LIMITS, 'all', 'custom'])],
            'custom_limit' => ['required_if:limit,custom', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CUSTOM_LIMIT],
            'order' => ['nullable', Rule::in(array_keys(VendorContactExporter::ORDERS))],
            'remove_duplicates' => ['boolean'],
            'valid_only' => ['boolean'],
            'format' => ['required', Rule::in(array_keys(VendorContactWriter::FORMATS))],
            'fields' => ['nullable', 'array', 'max:'.count(VendorContactExporter::FIELDS)],
            'fields.*' => ['string', Rule::in(array_keys(VendorContactExporter::FIELDS))],
            'txt_style' => ['nullable', Rule::in(['phones', 'name_phone'])],
            'phone_format' => ['nullable', Rule::in(['local', 'international'])],
            'vcf_name' => ['nullable', Rule::in(['vendor_name', 'xtra4u_vendor_name'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (in_array($this->input('format'), ['csv', 'xlsx'], true) && count((array) $this->input('fields', [])) === 0) {
                $v->errors()->add('fields', 'Select at least one field to include.');
            }
        });
    }

    /** Normalised options for the exporter/writer (limit resolved to int|null). */
    public function options(): array
    {
        $d = $this->validated();

        $limit = match ($d['limit']) {
            'all' => null,
            'custom' => (int) $d['custom_limit'],
            default => (int) $d['limit'],
        };

        return [
            'status' => $d['status'] ?? 'all',
            'tier_id' => $d['tier_id'] ?? null,
            'registered_from' => $d['registered_from'] ?? null,
            'registered_to' => $d['registered_to'] ?? null,
            'q' => trim((string) ($d['q'] ?? '')),
            'limit' => $limit,
            'order' => $d['order'] ?? 'newest',
            'remove_duplicates' => (bool) $d['remove_duplicates'],
            'valid_only' => (bool) $d['valid_only'],
            'format' => $d['format'],
            'fields' => array_values(array_unique($d['fields'] ?? ['name', 'phone'])),
            'txt_style' => $d['txt_style'] ?? 'phones',
            'phone_format' => $d['phone_format'] ?? 'local',
            'vcf_name' => $d['vcf_name'] ?? 'xtra4u_vendor_name',
        ];
    }
}
