<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminExportAudit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTier;
use App\Services\VendorContactExport\VendorContactWriter;
use App\Support\ContactPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class VendorContactExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function vendor(array $o = []): Vendor
    {
        return Vendor::factory()->create(array_merge(['phone_number' => '0241'.random_int(100000, 999999)], $o));
    }

    private function params(array $o = []): array
    {
        return array_merge([
            'limit' => 'all', 'format' => 'txt', 'remove_duplicates' => 1, 'valid_only' => 1,
            'order' => 'oldest', 'fields' => ['name', 'phone'],
        ], $o);
    }

    private function download(array $o = []): TestResponse
    {
        return $this->post(route('admin.vendors.export.download'), $this->params($o));
    }

    private function body(TestResponse $r): string
    {
        return $r->streamedContent();
    }

    // ---------------------------------------------------------------- authorization

    public function test_anonymous_is_denied_everywhere(): void
    {
        $this->get(route('admin.vendors.export'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.vendors.export.preview'), $this->params())->assertRedirect(route('admin.login'));
        $this->post(route('admin.vendors.export.download'), $this->params())->assertRedirect(route('admin.login'));
    }

    public function test_vendor_is_denied_everywhere(): void
    {
        $this->actingAs($this->vendor(), 'vendor');

        $this->get(route('admin.vendors.export'))->assertForbidden();
        $this->post(route('admin.vendors.export.preview'), $this->params())->assertForbidden();
        $this->post(route('admin.vendors.export.download'), $this->params())->assertForbidden();
        $this->assertSame(0, AdminExportAudit::count());
    }

    public function test_normal_user_is_denied_everywhere(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->get(route('admin.vendors.export'))->assertForbidden();
        $this->post(route('admin.vendors.export.preview'), $this->params())->assertForbidden();
        $this->post(route('admin.vendors.export.download'), $this->params())->assertForbidden();
        $this->assertSame(0, AdminExportAudit::count());
    }

    public function test_admin_guard_and_role_admin_user_are_allowed(): void
    {
        $this->admin();
        $this->get(route('admin.vendors.export'))->assertOk()->assertSee('Vendor Contact Export');
        $this->post(route('admin.vendors.export.preview'), $this->params())->assertOk();

        $this->app['auth']->guard('admin')->logout();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        $this->post(route('admin.vendors.export.download'), $this->params())->assertOk();
    }

    public function test_vendors_page_links_to_export_with_current_filters(): void
    {
        $this->admin();
        $this->get(route('admin.vendors.index', ['status' => 'approved', 'q' => 'abc']))
            ->assertOk()
            ->assertSee('Export Contacts')
            ->assertSee(e(route('admin.vendors.export', ['status' => 'approved', 'q' => 'abc'])), false);
    }

    // ---------------------------------------------------------------- filters

    public function test_status_filter(): void
    {
        $this->admin();
        $this->vendor(['name' => 'Ok', 'phone_number' => '0240000001', 'is_approved' => true]);
        $this->vendor(['name' => 'Wait', 'phone_number' => '0240000002', 'is_approved' => false]);

        $this->assertSame("0240000001\n", $this->body($this->download(['status' => 'approved'])));
        $this->assertSame("0240000002\n", $this->body($this->download(['status' => 'pending'])));
        $this->assertCount(2, array_filter(explode("\n", $this->body($this->download(['status' => 'all'])))));
    }

    public function test_date_range_filter(): void
    {
        $this->admin();
        $this->vendor(['phone_number' => '0240000001', 'created_at' => '2026-01-10 08:00:00']);
        $this->vendor(['phone_number' => '0240000002', 'created_at' => '2026-02-15 23:30:00']);
        $this->vendor(['phone_number' => '0240000003', 'created_at' => '2026-03-20 08:00:00']);

        $body = $this->body($this->download(['registered_from' => '2026-02-01', 'registered_to' => '2026-02-15']));
        $this->assertSame("0240000002\n", $body);

        $this->download(['registered_from' => '2026-03-01', 'registered_to' => '2026-02-01'])->assertSessionHasErrors('registered_to');
    }

    public function test_search_filter_and_like_wildcards_are_literal(): void
    {
        $this->admin();
        $this->vendor(['name' => 'Ama Services', 'phone_number' => '0240000001']);
        $this->vendor(['name' => 'Kofi Traders', 'phone_number' => '0240000002', 'email' => 'kofi@example.com']);

        $this->assertSame("0240000001\n", $this->body($this->download(['q' => 'ama'])));
        $this->assertSame("0240000002\n", $this->body($this->download(['q' => 'kofi@example'])));
        $this->assertSame('', $this->body($this->download(['q' => '%'])));
    }

    public function test_tier_filter(): void
    {
        $this->admin();
        $tier = VendorTier::create(['name' => 'Export Test Tier', 'slug' => 'export-test-tier', 'priority' => 1, 'discount_type' => 'percentage', 'discount_value' => 0, 'is_active' => true]);
        $this->vendor(['phone_number' => '0240000001', 'tier_id' => $tier->id]);
        $this->vendor(['phone_number' => '0240000002']);

        $this->assertSame("0240000001\n", $this->body($this->download(['tier_id' => $tier->id])));
        $this->download(['tier_id' => 99999])->assertSessionHasErrors('tier_id');
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->admin();
        foreach ([
            ['status' => 'deleted'], ['order' => 'random'], ['format' => 'pdf'], ['limit' => '7'],
            ['fields' => ['password']], ['fields' => ['remember_token', 'name']], ['txt_style' => 'x'],
            ['vcf_name' => 'x'], ['phone_format' => 'x'], ['registered_from' => 'not-a-date'],
        ] as $bad) {
            $this->post(route('admin.vendors.export.download'), $this->params($bad))->assertSessionHasErrors();
        }
        $this->assertSame(0, AdminExportAudit::count());
    }

    // ---------------------------------------------------------------- limit / order

    public function test_preset_and_custom_limits(): void
    {
        $this->admin();
        foreach (range(1, 120) as $i) {
            $this->vendor(['phone_number' => sprintf('0241%06d', $i)]);
        }

        $this->assertCount(100, array_filter(explode("\n", $this->body($this->download(['limit' => '100'])))));
        $this->assertCount(7, array_filter(explode("\n", $this->body($this->download(['limit' => 'custom', 'custom_limit' => 7])))));
        $this->assertCount(120, array_filter(explode("\n", $this->body($this->download(['limit' => 'all'])))));
    }

    public function test_bad_limits_are_rejected(): void
    {
        $this->admin();
        $this->download(['limit' => 'custom', 'custom_limit' => -5])->assertSessionHasErrors('custom_limit');
        $this->download(['limit' => 'custom', 'custom_limit' => 0])->assertSessionHasErrors('custom_limit');
        $this->download(['limit' => 'custom', 'custom_limit' => 'abc'])->assertSessionHasErrors('custom_limit');
        $this->download(['limit' => 'custom'])->assertSessionHasErrors('custom_limit');
        $this->download(['limit' => 'custom', 'custom_limit' => 999999999999])->assertSessionHasErrors('custom_limit');
    }

    public function test_ordering_is_deterministic(): void
    {
        $this->admin();
        $this->vendor(['name' => 'Bravo', 'phone_number' => '0240000001', 'created_at' => '2026-01-01 00:00:00']);
        $this->vendor(['name' => 'Alpha', 'phone_number' => '0240000002', 'created_at' => '2026-02-01 00:00:00']);
        $this->vendor(['name' => 'Charlie', 'phone_number' => '0240000003', 'created_at' => '2026-03-01 00:00:00']);

        $first = fn ($order) => trim($this->body($this->download(['order' => $order, 'limit' => 'custom', 'custom_limit' => 1])));
        $this->assertSame('0240000003', $first('newest'));
        $this->assertSame('0240000001', $first('oldest'));
        $this->assertSame('0240000002', $first('name_asc'));
        $this->assertSame('0240000003', $first('name_desc'));
    }

    // ---------------------------------------------------------------- phone handling

    public function test_phone_normalisation_rules(): void
    {
        $this->assertSame('0241234567', ContactPhone::parse('024 123 4567')['local']);
        $this->assertSame('0241234567', ContactPhone::parse('+233241234567')['local']);
        $this->assertSame('+233241234567', ContactPhone::parse('0241234567')['international']);
        $this->assertSame(ContactPhone::parse('0241234567')['key'], ContactPhone::parse('233241234567')['key']);
        $this->assertSame(ContactPhone::parse('0241234567')['key'], ContactPhone::parse('241234567')['key']);
        $this->assertSame(ContactPhone::parse('0241234567')['key'], ContactPhone::parse('00233241234567')['key']);
        // International numbers are preserved, never rewritten to 233.
        $this->assertSame('+447911123456', ContactPhone::parse('+44 7911 123456')['local']);
        $this->assertNull(ContactPhone::parse(''));
        $this->assertNull(ContactPhone::parse('abc'));
        $this->assertNull(ContactPhone::parse('12345'));
        $this->assertNull(ContactPhone::parse('0141234567'));
    }

    public function test_duplicates_removed_by_normalised_number(): void
    {
        $this->admin();
        $this->vendor(['phone_number' => '0241234567', 'created_at' => '2026-01-01']);
        $this->vendor(['phone_number' => '233241234567', 'created_at' => '2026-01-02']);
        $this->vendor(['phone_number' => '+233 24 123 4567', 'created_at' => '2026-01-03']);
        $this->vendor(['phone_number' => '0551234567', 'created_at' => '2026-01-04']);
        $this->vendor(['phone_number' => '+447911123456', 'created_at' => '2026-01-05']);

        $this->assertSame("0241234567\n0551234567\n+447911123456\n", $this->body($this->download()));
        $this->assertCount(5, array_filter(explode("\n", $this->body($this->download(['remove_duplicates' => 0])))));

        $summary = $this->post(route('admin.vendors.export.preview'), $this->params())->assertOk()->json();
        $this->assertSame(5, $summary['matching']);
        $this->assertSame(3, $summary['exported']);
        $this->assertSame(2, $summary['duplicates_removed']);
        $this->assertSame(0, $summary['skipped_invalid']);
    }

    public function test_invalid_numbers_skipped_and_reported(): void
    {
        $this->admin();
        $this->vendor(['phone_number' => '0241234567']);
        $this->vendor(['phone_number' => 'n/a']);
        $this->vendor(['phone_number' => '']);

        $this->assertSame("0241234567\n", $this->body($this->download()));
        $summary = $this->post(route('admin.vendors.export.preview'), $this->params())->json();
        $this->assertSame(2, $summary['skipped_invalid']);
        $this->assertSame(1, $summary['exported']);

        $this->assertStringContainsString('n/a', $this->body($this->download(['valid_only' => 0])));
    }

    public function test_limit_applies_after_dedup_and_validity(): void
    {
        $this->admin();
        $this->vendor(['phone_number' => 'bad', 'created_at' => '2026-01-01']);
        $this->vendor(['phone_number' => '0241234567', 'created_at' => '2026-01-02']);
        $this->vendor(['phone_number' => '233241234567', 'created_at' => '2026-01-03']);
        $this->vendor(['phone_number' => '0551234567', 'created_at' => '2026-01-04']);

        $this->assertSame("0241234567\n0551234567\n", $this->body($this->download(['limit' => 'custom', 'custom_limit' => 2])));
    }

    // ---------------------------------------------------------------- formats

    public function test_txt_phones_only_and_name_phone(): void
    {
        $this->admin();
        $this->vendor(['name' => "John\nMensah", 'phone_number' => '0241234567', 'created_at' => '2026-01-01']);
        $this->vendor(['name' => 'Ama Services', 'phone_number' => '0551234567', 'created_at' => '2026-01-02']);

        $r = $this->download();
        $r->assertOk();
        $this->assertStringStartsWith('text/plain', $r->headers->get('Content-Type'));
        $this->assertSame("0241234567\n0551234567\n", $this->body($r));
        $this->assertSame("John Mensah - 0241234567\nAma Services - 0551234567\n", $this->body($this->download(['txt_style' => 'name_phone'])));
        $this->assertSame("+233241234567\n+233551234567\n", $this->body($this->download(['phone_format' => 'international'])));
    }

    public function test_filenames_are_safe_and_dated(): void
    {
        $this->admin();
        $date = now()->format('Y-m-d');

        $this->assertStringContainsString("xtra4u-vendor-phones-{$date}.txt", $this->download()->headers->get('Content-Disposition'));
        $this->assertStringContainsString("xtra4u-vendor-contacts-{$date}.csv", $this->download(['format' => 'csv'])->headers->get('Content-Disposition'));
        $this->assertStringContainsString("xtra4u-approved-vendors-{$date}.vcf", $this->download(['format' => 'vcf', 'status' => 'approved'])->headers->get('Content-Disposition'));
        $this->assertStringContainsString("xtra4u-vendor-contacts-{$date}.xlsx", $this->download(['format' => 'xlsx'])->headers->get('Content-Disposition'));
    }

    public function test_csv_headers_selected_fields_and_escaping(): void
    {
        $this->admin();
        $this->vendor(['name' => 'Smith, "Bob" & Co', 'phone_number' => '0241234567', 'email' => 'bob@example.com', 'vendor_code' => 'VND001AA']);
        $this->vendor(['name' => '=HYPERLINK("http://evil")', 'phone_number' => '0551234567']);

        $r = $this->download(['format' => 'csv', 'fields' => ['name', 'phone']]);
        $this->assertStringStartsWith('text/csv', $r->headers->get('Content-Type'));
        $body = $this->body($r);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($body, 3))));
        $this->assertSame(['Vendor Name', 'Phone'], $rows[0]);
        $this->assertSame(['Smith, "Bob" & Co', '0241234567'], $rows[1]);
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $rows[2][0]); // formula neutralised
        $this->assertStringNotContainsString('bob@example.com', $body);
        $this->assertStringNotContainsString('VND001AA', $body);

        $all = $this->body($this->download(['format' => 'csv', 'fields' => ['email', 'vendor_code', 'status', 'registered_at', 'id', 'phone', 'name', 'tier']]));
        $this->assertSame(
            ['Vendor Name', 'Phone', 'Email', 'Vendor Code', 'Status', 'Tier', 'Registration Date', 'Vendor ID'],
            str_getcsv(strtok(substr($all, 3), '
'), ',', '"', '')
        );
        $this->assertStringContainsString('bob@example.com', $all);
    }

    public function test_csv_requires_at_least_one_field(): void
    {
        $this->admin();
        $this->download(['format' => 'csv', 'fields' => []])->assertSessionHasErrors('fields');
        $this->download(['format' => 'xlsx', 'fields' => []])->assertSessionHasErrors('fields');
    }

    public function test_xlsx_is_a_valid_workbook_with_selected_fields(): void
    {
        $this->admin();
        $this->vendor(['name' => 'A & <B>', 'phone_number' => '0241234567', 'email' => 'a@example.com']);
        $this->vendor(['name' => '=1+1', 'phone_number' => '0551234567', 'email' => 'b@example.com']);

        $r = $this->download(['format' => 'xlsx', 'fields' => ['name', 'phone', 'email']]);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $r->headers->get('Content-Type'));
        $file = tempnam(sys_get_temp_dir(), 'tst');
        file_put_contents($file, $this->body($r));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertNotFalse($zip->locateName($part), "missing {$part}");
            $this->assertNotFalse(simplexml_load_string($zip->getFromName($part)), "{$part} is not well-formed XML");
        }
        $this->assertStringContainsString('name="Vendor Contacts"', $zip->getFromName('xl/workbook.xml'));

        $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rows[] = array_map(fn ($c) => (string) $c->is->t, iterator_to_array($row->c, false));
        }
        $zip->close();
        @unlink($file);

        $this->assertSame(['Vendor Name', 'Phone', 'Email'], $rows[0]);
        $this->assertCount(3, $rows); // header + 2 vendors
        $this->assertContains(['A & <B>', '0241234567', 'a@example.com'], $rows);
        $this->assertContains(['=1+1', '0551234567', 'b@example.com'], $rows); // stored as text, not a formula
    }

    public function test_vcf_structure_names_and_escaping(): void
    {
        $this->admin();
        $this->vendor(['name' => 'John; Mensah, Jr\\', 'phone_number' => '0241234567', 'email' => 'john@example.com', 'vendor_code' => 'VND9', 'created_at' => '2026-01-01']);
        $this->vendor(['name' => 'Ama', 'phone_number' => '0551234567', 'email' => 'ama@example.com', 'created_at' => '2026-01-02']);

        $r = $this->download(['format' => 'vcf', 'fields' => ['email'], 'vcf_name' => 'xtra4u_vendor_name', 'phone_format' => 'international']);
        $this->assertStringStartsWith('text/vcard', $r->headers->get('Content-Type'));
        $body = $this->body($r);

        $this->assertSame(2, substr_count($body, "BEGIN:VCARD\r\n"));
        $this->assertSame(2, substr_count($body, "END:VCARD\r\n"));
        $this->assertStringContainsString("VERSION:3.0\r\n", $body);
        $this->assertStringContainsString("FN:XTRA4U - John\\; Mensah\\, Jr\\\\\r\n", $body);
        $this->assertStringContainsString("TEL;TYPE=CELL:+233241234567\r\n", $body);
        $this->assertStringContainsString("EMAIL;TYPE=INTERNET:john@example.com\r\n", $body);
        $this->assertStringNotContainsString('VND9', $body);

        $plain = $this->body($this->download(['format' => 'vcf', 'vcf_name' => 'vendor_name']));
        $this->assertStringContainsString("FN:Ama\r\n", $plain);
        $this->assertStringNotContainsString('EMAIL', $plain);
        $this->assertStringContainsString("TEL;TYPE=CELL:0551234567\r\n", $plain);
    }

    public function test_vcf_name_fallback_and_folding(): void
    {
        $this->admin();
        $this->vendor(['name' => '', 'vendor_code' => 'VND7', 'phone_number' => '0241234567']);

        $this->assertStringContainsString("FN:XTRA4U - VND7\r\n", $this->body($this->download(['format' => 'vcf'])));

        $folded = VendorContactWriter::vcfFold('FN:'.str_repeat('é', 100));
        foreach (explode("\r\n", $folded) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $this->assertSame('FN:'.str_repeat('é', 100), str_replace("\r\n ", '', $folded));
    }

    // ---------------------------------------------------------------- privacy

    public function test_secrets_never_appear_in_any_format(): void
    {
        $this->admin();
        $v = $this->vendor(['phone_number' => '0241234567']);
        $v->forceFill(['remember_token' => 'REMEMBER-SECRET-TOKEN', 'wallet_balance' => 98765.43])->save();
        $hash = $v->fresh()->password;

        foreach (['txt', 'csv', 'xlsx', 'vcf'] as $format) {
            $body = $this->body($this->download(['format' => $format, 'fields' => ['name', 'phone', 'email', 'vendor_code', 'status', 'tier', 'registered_at', 'id']]));
            if ($format === 'xlsx') {
                $file = tempnam(sys_get_temp_dir(), 'tst');
                file_put_contents($file, $body);
                $zip = new ZipArchive;
                $zip->open($file);
                $body = $zip->getFromName('xl/worksheets/sheet1.xml');
                $zip->close();
                @unlink($file);
            }
            $this->assertStringNotContainsString($hash, $body);
            $this->assertStringNotContainsString('REMEMBER-SECRET-TOKEN', $body);
            $this->assertStringNotContainsString('98765', $body);
        }
    }

    public function test_exporter_never_selects_secret_columns(): void
    {
        $this->admin();
        $this->vendor();

        \DB::enableQueryLog();
        $this->body($this->download(['format' => 'csv', 'fields' => ['name', 'phone', 'email', 'vendor_code', 'status', 'tier', 'registered_at', 'id']]));
        $this->post(route('admin.vendors.export.preview'), $this->params());
        $exportSql = collect(\DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'from "vendors"'))->implode(' ');

        $this->assertNotSame('', $exportSql);
        foreach (['password', 'remember_token', 'balance', 'afa_', 'select *', '"vendors".*'] as $secret) {
            $this->assertStringNotContainsString($secret, $exportSql);
        }
        $this->assertStringContainsString('limit 1000', $exportSql); // chunked, never an unbounded get()/all()
    }

    public function test_download_is_not_cacheable_and_nothing_is_stored_publicly(): void
    {
        $this->admin();
        $this->vendor();
        $r = $this->download();

        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame([], glob(public_path('storage/*vendor*')) ?: []);
    }

    // ---------------------------------------------------------------- audit

    public function test_download_creates_audit_without_contact_data(): void
    {
        $admin = $this->admin();
        $this->vendor(['phone_number' => '0241234567', 'email' => 'secret.person@example.com']);
        $this->vendor(['phone_number' => '233241234567']);
        $this->vendor(['phone_number' => 'junk']);

        $this->body($this->download(['format' => 'csv', 'status' => 'approved', 'limit' => '500', 'fields' => ['name', 'phone']]));

        $audit = AdminExportAudit::sole();
        $this->assertSame('vendor_contacts', $audit->export_type);
        $this->assertSame('admin', $audit->actor_guard);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($admin->email, $audit->actor_email);
        $this->assertSame('csv', $audit->format);
        $this->assertSame('approved', $audit->filters['status']);
        $this->assertSame(['name', 'phone'], $audit->fields);
        $this->assertSame(500, $audit->requested_limit);
        $this->assertSame(3, $audit->matching_count);
        $this->assertSame(1, $audit->exported_count);
        $this->assertSame(1, $audit->duplicates_removed);
        $this->assertSame(1, $audit->skipped_invalid);
        $this->assertTrue($audit->completed);

        $raw = json_encode($audit->getAttributes());
        $this->assertStringNotContainsString('0241234567', $raw);
        $this->assertStringNotContainsString('secret.person', $raw);
    }

    public function test_preview_does_not_create_an_audit(): void
    {
        $this->admin();
        $this->vendor();
        $this->post(route('admin.vendors.export.preview'), $this->params())->assertOk();
        $this->assertSame(0, AdminExportAudit::count());
    }

    // ---------------------------------------------------------------- scale / query behaviour

    public function test_large_export_streams_in_chunks_with_constant_query_count(): void
    {
        $this->admin();
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = [
                'name' => "Vendor {$i}", 'email' => "v{$i}@example.com", 'phone_number' => sprintf('0241%06d', $i),
                'password' => 'x', 'is_approved' => true, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            \DB::table('vendors')->insert($chunk);
        }

        \DB::enableQueryLog();
        $body = $this->body($this->download(['format' => 'csv']));
        $queries = \DB::getQueryLog();

        $this->assertCount(2501, array_filter(explode("\n", substr($body, 3)))); // header + 2500
        // 1 count + ceil(2500 / 1000) chunk reads (+ audit insert / auth lookups): never one query per vendor.
        $this->assertLessThan(15, count($queries));
    }

    public function test_show_page_whitelists_incoming_filters(): void
    {
        $this->admin();
        $this->get(route('admin.vendors.export', ['status' => 'approved', 'q' => 'abc', 'registered_from' => '2026-01-01']))
            ->assertOk()
            ->assertSee('value="abc"', false)
            ->assertSee('value="2026-01-01"', false);

        // Hostile / unknown values never reach the form or the query.
        $this->get(route('admin.vendors.export', ['status' => "x' OR 1=1", 'order' => 'password', 'q' => ['a']]))->assertOk();
    }
}
