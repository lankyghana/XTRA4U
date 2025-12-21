<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupPendingOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cleanup-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete pending orders older than 24 hours (unsuccessful payments not recorded)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cleanup of pending orders...');

        // Find pending orders older than 24 hours
        $cutoffTime = now()->subHours(24);
        
        $pendingOrders = Order::where('payment_status', 'pending')
            ->where('created_at', '<', $cutoffTime)
            ->get();

        if ($pendingOrders->isEmpty()) {
            $this->info('No pending orders to cleanup.');
            return Command::SUCCESS;
        }

        $count = $pendingOrders->count();
        $this->info("Found {$count} pending orders older than 24 hours.");

        foreach ($pendingOrders as $order) {
            $this->line("Deleting order #{$order->id} - {$order->payment_reference}");

            // Delete transactions
            $transactionCount = Transaction::where('order_id', $order->id)->delete();
            
            // Delete order
            $order->delete();

            Log::info('Cleanup: Deleted abandoned order', [
                'order_id' => $order->id,
                'payment_reference' => $order->payment_reference,
                'transactions_deleted' => $transactionCount,
                'age_hours' => $order->created_at->diffInHours(now()),
            ]);
        }

        $this->info("✓ Successfully deleted {$count} pending orders and their transactions.");

        return Command::SUCCESS;
    }
}
