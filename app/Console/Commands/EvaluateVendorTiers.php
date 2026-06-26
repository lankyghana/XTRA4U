<?php

namespace App\Console\Commands;

use App\Services\VendorTierQualificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EvaluateVendorTiers extends Command
{
    protected $signature = 'xtra4u:evaluate-vendor-tiers
                            {--dry-run : Show what would happen without persisting changes}';

    protected $description = 'Evaluate all approved vendors against tier qualification rules and mark eligible vendors for promotion.';

    public function handle(VendorTierQualificationService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('Starting vendor tier evaluation...');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        try {
            if ($isDryRun) {
                $this->performDryRun($service);
                return Command::SUCCESS;
            }

            $result = $service->evaluateAll();

            $this->info("Evaluated : {$result['evaluated']} vendors");
            $this->info("Newly eligible : {$result['newlyEligible']} vendors");

            Log::info('xtra4u:evaluate-vendor-tiers completed', $result);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Evaluation failed: ' . $e->getMessage());
            Log::error('xtra4u:evaluate-vendor-tiers failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }

    private function performDryRun(VendorTierQualificationService $service): void
    {
        $this->table(
            ['Vendor', 'Current Tier', 'Would Become Eligible For'],
            \App\Models\Vendor::where('is_approved', true)
                ->with(['tier', 'eligibleTier'])
                ->get()
                ->map(function ($vendor) use ($service) {
                    $nextTier = $service->findNextEligibleTier($vendor);
                    return [
                        $vendor->name . ' (' . $vendor->vendor_code . ')',
                        $vendor->tier?->name ?? 'None',
                        $nextTier?->name ?? '—',
                    ];
                })
                ->all()
        );
    }
}
