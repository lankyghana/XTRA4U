<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueueHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
    }

    private function insertJob(int $availableAt, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt,
        ]);
    }

    public function test_healthy_queue_pings_heartbeat_and_succeeds(): void
    {
        Http::fake();
        config(['queue.health.ping_url' => 'https://hc.example/ping']);
        $this->insertJob(now()->getTimestamp());

        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === 'https://hc.example/ping');
        $this->assertSame(0, AdminNotification::count());
    }

    public function test_stale_jobs_alert_admins_once_and_skip_heartbeat(): void
    {
        Http::fake();
        config(['queue.health.ping_url' => 'https://hc.example/ping']);
        $this->insertJob(now()->subMinutes(30)->getTimestamp());

        $this->artisan('queue:health-check')->assertFailed();
        $this->artisan('queue:health-check')->assertFailed();

        $this->assertSame(1, AdminNotification::where('type', 'queue_stalled')->count());
        Http::assertNothingSent();
    }

    public function test_job_held_by_a_dead_worker_is_reported(): void
    {
        $this->insertJob(now()->subHours(3)->getTimestamp(), now()->subHours(2)->getTimestamp());

        $this->artisan('queue:health-check')->assertFailed();
    }
}
