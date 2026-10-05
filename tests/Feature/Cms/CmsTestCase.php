<?php

namespace Tests\Feature\Cms;

use App\Models\Admin;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class CmsTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
    }

    protected function actingAsAdminGuard(): Admin
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    protected function actingAsRoleAdminUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    protected function actingAsVendor(): Vendor
    {
        $vendor = Vendor::factory()->create(['is_approved' => true]);
        $this->actingAs($vendor, 'vendor');

        return $vendor;
    }
}
