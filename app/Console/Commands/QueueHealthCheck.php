<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Detects a stalled queue (worker cron not running) before paid orders sit
 * undelivered. Runs from the scheduler; see routes/console.php.
 *
 * Healthy runs ping QUEUE_HEALTH_PING_URL (e.g. a healthchecks.io check) as a
 * dead-man's switch: if the scheduler cron itself dies, this command stops
 * running and the external service alerts, which in-app alerts cannot do.
 */
class QueueHealthCheck extends Command
{
    protected $signature = 'queue:health-check {--minutes= : Alert when the oldest ready job is older than this (default config queue.health.stale_minutes)}';

    protected $description = 'Alert admins when queued jobs are not being processed';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('queue.health.stale_minutes', 15));
        $connection = config('queue.default');

        if ($connection !== 'database') {
            $this->info("Queue connection [{$connection}] is not database-backed; nothing to check.");

            return self::SUCCESS;
        }

        $table = config('queue.connections.database.table', 'jobs');
        $cutoff = now()->subMinutes($minutes)->getTimestamp();

        // Jobs that are due, not reserved by a worker, and older than the cutoff.
        $stale = DB::table($table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $cutoff)
            ->selectRaw('count(*) as total, min(available_at) as oldest')
            ->first();

        // Jobs reserved for far longer than any job should run (worker died mid-job).
        $stuck = DB::table($table)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', now()->subMinutes(max($minutes, 60))->getTimestamp())
            ->count();

        if ((int) $stale->total === 0 && $stuck === 0) {
            $this->info('Queue healthy.');
            $this->ping();

            return self::SUCCESS;
        }

        $oldestAge = $stale->oldest ? (int) floor((now()->getTimestamp() - (int) $stale->oldest) / 60) : null;
        $context = [
            'stale_jobs' => (int) $stale->total,
            'oldest_ready_job_age_minutes' => $oldestAge,
            'stuck_reserved_jobs' => $stuck,
            'threshold_minutes' => $minutes,
        ];

        Log::critical('Queue stalled: jobs are not being processed. Check the queue:work cron.', $context);
        $this->error('Queue stalled: '.json_encode($context));

        // One in-app alert per hour so a long outage doesn't flood the inbox.
        if (Cache::add('queue_health_alert_sent', true, now()->addHour())) {
            try {
                AdminNotification::create([
                    'type' => 'queue_stalled',
                    'title' => 'Queue is not being processed',
                    'message' => "{$context['stale_jobs']} job(s) waiting"
                        .($oldestAge !== null ? ", oldest {$oldestAge} min old" : '')
                        .". Orders, payouts and SMS are delayed. Check the queue:work cron.",
                    'data' => $context,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Queue health: could not create admin notification', ['error' => $e->getMessage()]);
            }
        }

        // Do not ping the dead-man's switch: let the external monitor alert too.
        return self::FAILURE;
    }

    private function ping(): void
    {
        $url = config('queue.health.ping_url');
        if (! $url) {
            return;
        }

        try {
            Http::timeout(5)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Queue health: heartbeat ping failed', ['error' => $e->getMessage()]);
        }
    }
}
