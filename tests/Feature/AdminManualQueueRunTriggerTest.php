<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminManualQueueRunTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_request_manual_queue_run(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->actingAs($admin);

        $response = $this->post(route('admin.queue.run'));

        $response->assertRedirect();

        $this->assertTrue((bool) Cache::get('queue_manual_run_requested'));
        $this->assertNotEmpty((string) Cache::get('queue_manual_run_requested_at'));
        $this->assertSame($admin->id, Cache::get('queue_manual_run_requested_by'));
    }
}
