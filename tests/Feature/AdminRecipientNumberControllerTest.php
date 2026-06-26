<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminRecipientNumberControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        return $admin;
    }

    private function insertLog(array $overrides = []): void
    {
        DB::table('recipient_number_logs')->insert(array_merge([
            'phone_number'  => '+233240000001',
            'vendor_id'     => 1,
            'order_id'      => null,
            'service_type'  => 'MTN_DATA_1GB',
            'used_at'       => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_index_requires_admin_auth(): void
    {
        $this->get(route('admin.recipient-numbers.index'))->assertRedirect();
    }

    public function test_index_returns_200_for_admin(): void
    {
        $this->actingAdmin();
        $this->get(route('admin.recipient-numbers.index'))->assertOk();
    }

    public function test_index_displays_log_rows(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233240000099']);

        $this->get(route('admin.recipient-numbers.index'))
            ->assertOk()
            ->assertSee('+233240000099');
    }

    public function test_phone_filter_matches_partial(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233244111111']);
        $this->insertLog(['phone_number' => '+233200999999', 'service_type' => 'WAEC_2024', 'vendor_id' => 1]);

        // Partial search on "244" should match only the first
        $response = $this->get(route('admin.recipient-numbers.index', ['phone' => '244']));
        $response->assertOk()->assertSee('+233244111111')->assertDontSee('+233200999999');
    }

    public function test_service_type_filter_matches_partial(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233244111111', 'service_type' => 'MTN_DATA_1GB']);
        $this->insertLog(['phone_number' => '+233200999999', 'service_type' => 'WAEC_2024', 'vendor_id' => 1]);

        // Partial match — "DATA" should match MTN_DATA_1GB but not WAEC_2024
        $response = $this->get(route('admin.recipient-numbers.index', ['service_type' => 'DATA']));
        $response->assertOk()->assertSee('+233244111111')->assertDontSee('+233200999999');
    }

    public function test_vendor_id_filter_scopes_rows(): void
    {
        $this->actingAdmin();
        $vendor = Vendor::factory()->create();
        $this->insertLog(['phone_number' => '+233244111111', 'vendor_id' => $vendor->id]);
        $this->insertLog(['phone_number' => '+233200999999', 'vendor_id' => $vendor->id + 99]);

        $response = $this->get(route('admin.recipient-numbers.index', ['vendor_id' => $vendor->id]));
        $response->assertOk()->assertSee('+233244111111')->assertDontSee('+233200999999');
    }

    // -------------------------------------------------------------------------
    // Export — without distinct (may contain repeated phone numbers)
    // -------------------------------------------------------------------------

    public function test_export_txt_streams_all_rows(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233240000001', 'vendor_id' => 1]);
        $this->insertLog(['phone_number' => '+233240000001', 'vendor_id' => 2, 'service_type' => 'MTN_DATA_2GB']);

        $response = $this->get(route('admin.recipient-numbers.export', ['format' => 'txt']));
        $response->assertOk();

        $body = $response->streamedContent();
        $this->assertStringContainsString('+233240000001', $body);
        // Without distinct both rows appear — phone appears twice
        $this->assertSame(2, substr_count($body, '+233240000001'));
    }

    public function test_export_csv_includes_header_and_all_rows(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233244555555']);

        $response = $this->get(route('admin.recipient-numbers.export', ['format' => 'csv']));
        $response->assertOk();

        $body = $response->streamedContent();
        $this->assertStringContainsString('phone_number', $body);
        $this->assertStringContainsString('+233244555555', $body);
    }

    // -------------------------------------------------------------------------
    // Export — distinct=1 deduplicates phone numbers
    // -------------------------------------------------------------------------

    public function test_export_txt_distinct_outputs_each_phone_once(): void
    {
        $this->actingAdmin();
        // Same phone, two different vendors → two rows in the table
        $this->insertLog(['phone_number' => '+233240000001', 'vendor_id' => 1]);
        $this->insertLog(['phone_number' => '+233240000001', 'vendor_id' => 2, 'service_type' => 'MTN_DATA_2GB']);

        $response = $this->get(route('admin.recipient-numbers.export', ['format' => 'txt', 'distinct' => '1']));
        $response->assertOk();

        $lines = array_filter(explode("\n", trim($response->streamedContent())));
        $this->assertCount(1, $lines);
        $this->assertSame('+233240000001', trim($lines[array_key_first($lines)]));
    }

    public function test_export_csv_distinct_outputs_each_phone_once(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233240000001', 'vendor_id' => 1]);
        $this->insertLog(['phone_number' => '+233240000001', 'vendor_id' => 2, 'service_type' => 'MTN_DATA_2GB']);
        $this->insertLog(['phone_number' => '+233244999999', 'vendor_id' => 1]);

        $response = $this->get(route('admin.recipient-numbers.export', ['format' => 'csv', 'distinct' => '1']));
        $response->assertOk();

        $body = $response->streamedContent();
        $this->assertSame(1, substr_count($body, '+233240000001'));
        $this->assertSame(1, substr_count($body, '+233244999999'));
    }

    public function test_export_distinct_filename_reflects_distinct_mode(): void
    {
        $this->actingAdmin();
        $response = $this->get(route('admin.recipient-numbers.export', ['format' => 'txt', 'distinct' => '1']));
        $response->assertOk();
        $this->assertStringContainsString('distinct', $response->headers->get('content-disposition', ''));
    }

    // -------------------------------------------------------------------------
    // Copy
    // -------------------------------------------------------------------------

    public function test_copy_streams_all_rows_without_distinct(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233244777777', 'vendor_id' => 1]);
        $this->insertLog(['phone_number' => '+233244777777', 'vendor_id' => 2, 'service_type' => 'MTN_DATA_2GB']);

        $response = $this->get(route('admin.recipient-numbers.copy'));
        $response->assertOk();
        $this->assertSame(2, substr_count($response->streamedContent(), '+233244777777'));
    }

    public function test_copy_distinct_deduplicates(): void
    {
        $this->actingAdmin();
        $this->insertLog(['phone_number' => '+233244777777', 'vendor_id' => 1]);
        $this->insertLog(['phone_number' => '+233244777777', 'vendor_id' => 2, 'service_type' => 'MTN_DATA_2GB']);

        $response = $this->get(route('admin.recipient-numbers.copy', ['distinct' => '1']));
        $response->assertOk();
        $this->assertSame(1, substr_count($response->streamedContent(), '+233244777777'));
    }
}
