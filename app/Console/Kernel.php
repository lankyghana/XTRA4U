<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\WalletTopup;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\WipeDatabaseNoTransaction::class,
        \App\Console\Commands\MigrateFreshNoTransaction::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Cleanup old wallet topups (consumed & abandoned initiated records)
        // Runs hourly to keep tables bounded and reconciliation clean.
        // Uses ->call() instead of ->command() to avoid proc_open (disabled on shared hosting).
        $schedule->call(function () {
            $days = 1;
            $cutoff = now()->subDays($days)->toDateTimeString();

            // Mark fully-consumed completed topups as archived
            WalletTopup::where('status', 'completed')
                ->whereColumn('amount', '<=', 'consumed')
                ->where('updated_at', '<', $cutoff)
                ->chunkById(200, function ($rows) {
                    $ids = $rows->pluck('id')->all();
                    WalletTopup::whereIn('id', $ids)->update(['status' => 'archived']);
                });

            // Mark abandoned initiated topups (>24 hours old) as expired
            $initiatedCutoff = now()->subDay()->toDateTimeString();
            WalletTopup::where('status', 'initiated')
                ->where('created_at', '<', $initiatedCutoff)
                ->chunkById(200, function ($rows) {
                    $ids = $rows->pluck('id')->all();
                    WalletTopup::whereIn('id', $ids)->update(['status' => 'expired']);
                });
        })->hourly()->name('wallet:cleanup-topups');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
