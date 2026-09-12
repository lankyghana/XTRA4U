<?php

namespace App\Console\Commands;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 payment-reconciliation hardening.
 *
 * Phase 2 gave this command its own inline provider-aware verify/classify/
 * lock logic. Now that PaymentReconciliationService exists as the single
 * source of truth for "what does SUCCESS/FAILED/PENDING/UNKNOWN mean and
 * what do we do about it", that logic has been removed from here — this
 * command no longer contains any GatewayManager/PaymentVerificationState
 * code of its own. It calls the same service `payments:reconcile` calls.
 *
 * What's left for `payments:cleanup` to do, now that `payments:reconcile`
 * runs frequently on its own backoff schedule (see routes/console.php):
 * act as a wide, infrequent safety net. Its candidate selection is
 * deliberately simpler and wider than payments:reconcile's — plain age
 * since creation, no next_reconciliation_at bookkeeping — so that if the
 * scheduler is ever down, misconfigured, or a record's reconciliation
 * bookkeeping gets into a state that excludes it from payments:reconcile's
 * due-query, this command still eventually reaches it. Both commands funnel
 * through the identical PaymentReconciliationService::reconcile() call, so
 * running both is defense-in-depth, never two different answers to the same
 * question.
 *
 * The one rule that has not changed since Phase 2: age alone is NEVER
 * evidence of failure. This command still cannot cancel anything except via
 * the service's own authoritative-terminal-failure rule.
 */
class CleanupPendingPayments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:cleanup
                            {--hours=24 : Hours after which pending payments become eligible for this safety-net reconciliation pass}
                            {--limit=200 : Maximum candidate records to check per run, per payable type}';

    /**
     * The console command description.
     */
    protected $description = 'Wide safety-net reconciliation pass for old pending payments — delegates to PaymentReconciliationService, never cancels on age alone';

    public function __construct(private PaymentReconciliationService $reconciliationService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $limit = max(1, (int) $this->option('limit'));
        $cutoffTime = now()->subHours($hours);

        $this->info("Safety-net reconciliation pass for pending payments older than {$hours} hours (limit {$limit} per payable type)...");

        $orderStats = $this->reconcileBatch(
            Order::whereIn('payment_status', ['pending', 'unpaid'])
                ->where('created_at', '<', $cutoffTime)
                ->orderBy('id')
                ->limit($limit)
        );

        $afaStats = $this->reconcileBatch(
            AfaRegistration::where('payment_status', 'pending')
                ->where('created_at', '<', $cutoffTime)
                ->orderBy('id')
                ->limit($limit)
        );

        $this->reportStats('Orders', $orderStats);
        $this->reportStats('AFA registrations', $afaStats);

        Log::info('payments:cleanup safety-net pass completed', [
            'cutoff_hours' => $hours,
            'orders' => $orderStats,
            'afa_registrations' => $afaStats,
        ]);

        $this->info('Safety-net pass complete.');

        return Command::SUCCESS;
    }

    private function reconcileBatch($query): array
    {
        $stats = [
            'completed' => 0,
            'cancelled' => 0,
            'left_pending' => 0,
            'no_gateway' => 0,
            'integrity_mismatch' => 0,
            'manual_review' => 0,
            'exception' => 0,
        ];

        // Phase 5 fix: see ReconcilePendingPayments::processBatch() for why
        // one payable throwing must never abort the rest of this safety-net
        // pass. Never logs the raw provider payload (may carry secrets).
        $query->get()->each(function ($payable) use (&$stats) {
            try {
                $outcome = $this->reconciliationService->reconcile($payable);
                $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
            } catch (\Throwable $e) {
                $stats['exception']++;
                Log::error('payments:cleanup — unhandled exception reconciling one payable, continuing with the rest of the batch', [
                    'payable_type' => class_basename($payable),
                    'payable_id' => $payable->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $stats;
    }

    private function reportStats(string $label, array $stats): void
    {
        $this->info(sprintf(
            '%s — completed: %d, cancelled: %d, left pending: %d, no gateway: %d, integrity mismatch: %d, parked for manual review: %d, exceptions: %d',
            $label,
            $stats['completed'],
            $stats['cancelled'],
            $stats['left_pending'],
            $stats['no_gateway'],
            $stats['integrity_mismatch'],
            $stats['manual_review'],
            $stats['exception'] ?? 0,
        ));
    }
}
