<?php

namespace Tests\Feature\UtilityBills;

use App\Models\User;
use App\Models\UtilityBillConfigAudit;
use App\Models\Vendor;
use App\Services\UtilityBills\UtilityBillSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Admin-uploaded image for the Utility Bills card on vendor storefronts. */
class UtilityBillStorefrontImageTest extends UtilityBillTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->fake([self::BASE.'/utilities/billers' => Http::response($this->billersBody())]);
    }

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_admin_uploads_replaces_and_removes_the_image(): void
    {
        $this->asAdmin();

        $this->post(route('admin.utility-bills.settings.image'), ['image' => UploadedFile::fake()->image('a.png', 64, 64)])
            ->assertRedirect(route('admin.utility-bills.settings'));
        $first = UtilityBillSettings::imagePath();
        $this->assertStringStartsWith('utility-bills/', $first);
        Storage::disk('public')->assertExists($first);

        $this->post(route('admin.utility-bills.settings.image'), ['image' => UploadedFile::fake()->image('b.jpg', 64, 64)]);
        $second = UtilityBillSettings::imagePath();
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->post(route('admin.utility-bills.settings.image'), ['action' => 'remove']);
        $this->assertSame('', UtilityBillSettings::imagePath());
        $this->assertNull(UtilityBillSettings::imageUrl());
        Storage::disk('public')->assertMissing($second);

        $this->assertSame(3, UtilityBillConfigAudit::query()->where('scope', 'image')->count());
        $this->get(route('admin.utility-bills.settings'))->assertOk()->assertSee('Storefront image removed');
    }

    public function test_non_image_upload_is_rejected_and_changes_nothing(): void
    {
        $this->asAdmin();

        $this->post(route('admin.utility-bills.settings.image'), ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('image');

        $this->assertSame('', UtilityBillSettings::imagePath());
    }

    public function test_non_admin_cannot_change_the_image(): void
    {
        $this->post(route('admin.utility-bills.settings.image'), ['image' => UploadedFile::fake()->image('a.png')])
            ->assertRedirect();

        $this->assertSame('', UtilityBillSettings::imagePath());
    }

    public function test_storefront_card_uses_the_uploaded_image(): void
    {
        $this->openService(['ecg']);
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        UtilityBillSettings::saveImagePath('utility-bills/card.png');

        $this->get('/store/'.$vendor->vendor_code)->assertOk()
            ->assertSee('storage\/utility-bills\/card.png', false);
    }
}
