<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VendorContactExportRequest;
use App\Models\Admin;
use App\Models\AdminExportAudit;
use App\Models\VendorTier;
use App\Services\VendorContactExport\ExportStats;
use App\Services\VendorContactExport\VendorContactExporter;
use App\Services\VendorContactExport\VendorContactWriter;
use App\Support\Cms\CmsAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only vendor contact export. Routes sit behind admin.only, and VendorContactExportRequest
 * re-checks the admin identity. Exports are streamed straight to the client; nothing is written to disk
 * (apart from the XLSX temp file, which lives in the system temp dir and is deleted immediately).
 */
class VendorContactExportController extends Controller
{
    public function show(Request $request): View
    {
        return view('admin.vendor_export.index', [
            'initial' => $this->initialFilters($request),
            'tiers' => VendorTier::active()->ordered()->get(['id', 'name']),
            'fields' => VendorContactExporter::FIELDS,
            'orders' => VendorContactExporter::ORDERS,
            'maxCustomLimit' => VendorContactExportRequest::MAX_CUSTOM_LIMIT,
        ]);
    }

    public function preview(VendorContactExportRequest $request, VendorContactExporter $exporter): JsonResponse
    {
        return response()->json($exporter->summarize($request->options()))
            ->header('Cache-Control', 'no-store');
    }

    public function download(VendorContactExportRequest $request, VendorContactExporter $exporter, VendorContactWriter $writer): StreamedResponse
    {
        $options = $request->options();
        $format = $options['format'];
        $actor = $this->actor();
        $ip = $request->ip();

        @set_time_limit(300);

        $response = response()->streamDownload(function () use ($exporter, $writer, $options, $format, $actor, $ip) {
            $stats = new ExportStats;
            $completed = false;
            $matching = 0;

            try {
                $matching = $exporter->matchingCount($options);
                $out = fopen('php://output', 'wb');
                $writer->write($format, $exporter->contacts($options, $stats), $options, $out);
                fclose($out);
                $completed = true;
            } finally {
                $this->audit($actor, $ip, $options, $stats, $matching, $completed);
            }
        }, $this->filename($options), [
            'Content-Type' => VendorContactWriter::FORMATS[$format]['mime'],
        ]);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function filename(array $o): string
    {
        $slug = match (true) {
            $o['format'] === 'txt' && $o['txt_style'] === 'phones' => 'vendor-phones',
            $o['status'] === 'approved' => 'approved-vendors',
            default => 'vendor-contacts',
        };

        return 'xtra4u-'.$slug.'-'.now()->format('Y-m-d').'.'.VendorContactWriter::FORMATS[$o['format']]['ext'];
    }

    /** @return array{guard: string, id: int|null, email: string|null} */
    private function actor(): array
    {
        $who = CmsAdmin::resolve();

        return [
            'guard' => $who instanceof Admin ? CmsAdmin::GUARD_ADMIN : CmsAdmin::GUARD_WEB,
            'id' => $who?->getKey(),
            'email' => $who?->email,
        ];
    }

    private function audit(array $actor, ?string $ip, array $o, ExportStats $stats, int $matching, bool $completed): void
    {
        try {
            AdminExportAudit::create([
                'export_type' => 'vendor_contacts',
                'actor_guard' => $actor['guard'],
                'actor_id' => $actor['id'],
                'actor_email' => $actor['email'],
                'format' => $o['format'],
                'filters' => collect($o)->only([
                    'status', 'tier_id', 'registered_from', 'registered_to', 'q', 'order',
                    'remove_duplicates', 'valid_only', 'phone_format', 'txt_style', 'vcf_name',
                ])->all(),
                'fields' => $o['fields'],
                'requested_limit' => $o['limit'],
                'matching_count' => $matching,
                'exported_count' => $stats->exported,
                'skipped_invalid' => $stats->skippedInvalid,
                'duplicates_removed' => $stats->duplicatesRemoved,
                'completed' => $completed,
                'ip_address' => $ip,
            ]);
        } catch (\Throwable $e) {
            // An audit failure must be loud, but must not corrupt a download already in flight.
            Log::error('Vendor contact export audit failed', ['error' => $e->getMessage(), 'admin_id' => $actor['id']]);
        }
    }

    /** Starting values for the form, taken from the Vendors page query string through a strict whitelist. */
    private function initialFilters(Request $request): array
    {
        $rules = [
            'status' => ['nullable', 'in:all,approved,pending'],
            'tier_id' => ['nullable', 'integer', 'exists:vendor_tiers,id'],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
        $query = array_intersect_key($request->query(), $rules);
        $invalid = Validator::make($query, $rules)->errors()->keys();
        $clean = array_diff_key($query, array_flip($invalid));

        return [
            'status' => $clean['status'] ?? 'all',
            'tier_id' => $clean['tier_id'] ?? '',
            'registered_from' => $clean['registered_from'] ?? '',
            'registered_to' => $clean['registered_to'] ?? '',
            'q' => $clean['q'] ?? '',
        ];
    }
}
