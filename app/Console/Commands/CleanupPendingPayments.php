<?php

namespace App\Console\Commands;

use App\Models\AfaRegistration;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupPendingPayments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:cleanup 
                            {--hours=24 : Hours after which pending payments are considered abandoned}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up orders and registrations with abandoned/incomplete payments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $cutoffTime = now()->subHours($hours);

        $this->info("Cleaning up pending payments older than {$hours} hours...");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No records will be deleted');
        }

        // Clean up abandoned AFA registrations
        $afaQuery = AfaRegistration::where('payment_status', 'pending')
            ->where('created_at', '<', $cutoffTime);
        
        $afaCount = $afaQuery->count();
        
        if (!$dryRun && $afaCount > 0) {
            $afaQuery->update([
                'status' => AfaRegistration::STATUS_CANCELLED,
                'admin_notes' => 'Auto-cancelled: Payment not completed within ' . $hours . ' hours',
            ]);
        }
        
        $this->info("AFA Registrations cancelled: {$afaCount}");

        // Clean up abandoned Orders
        $orderQuery = Order::where('payment_status', 'pending')
            ->where('created_at', '<', $cutoffTime);
        
        $orderCount = $orderQuery->count();
        
        if (!$dryRun && $orderCount > 0) {
            $orderQuery->update([
                'status' => 'Cancelled',
            ]);
        }
        
        $this->info("Orders cancelled: {$orderCount}");

        // Log the cleanup
        if (!$dryRun) {
            Log::info('Payment cleanup completed', [
                'afa_cancelled' => $afaCount,
                'orders_cancelled' => $orderCount,
                'cutoff_hours' => $hours,
            ]);
        }

        $this->info('Cleanup complete!');
        
        return Command::SUCCESS;
    }
}
