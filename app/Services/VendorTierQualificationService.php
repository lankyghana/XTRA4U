<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Vendor;
use App\Models\VendorTier;
use App\Models\VendorTierHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorTierQualificationService
{
    /**
     * Compute the performance snapshot for a vendor.
     * All monetary values in GHS.
     */
    public function computePerformance(Vendor $vendor): array
    {
        $totalOrders = Order::where(function ($q) use ($vendor) {
            $q->where('vendor_id', $vendor->id)
                ->orWhere('reseller_vendor_id', $vendor->id);
        })->count();

        $successfulOrders = Order::where(function ($q) use ($vendor) {
            $q->where('vendor_id', $vendor->id)
                ->orWhere('reseller_vendor_id', $vendor->id);
        })->where('status', 'completed')->count();

        $affiliateSales = (float) Order::where(function ($q) use ($vendor) {
            $q->where('vendor_id', $vendor->id)
                ->orWhere('reseller_vendor_id', $vendor->id);
        })->where('status', 'completed')->sum('amount_paid');

        $successRate = $totalOrders > 0
            ? round(($successfulOrders / $totalOrders) * 100, 2)
            : 0.0;

        $accountAgeDays = (int) Carbon::parse($vendor->created_at)->diffInDays(now());

        $walletVolume = (float) DB::table('wallet_ledgers')
            ->where('vendor_id', $vendor->id)
            ->where('type', 'credit')
            ->sum('amount');

        return [
            'min_affiliate_sales' => $affiliateSales,
            'min_affiliate_orders' => $totalOrders,
            'min_successful_orders' => $successfulOrders,
            'min_success_rate' => $successRate,
            'min_account_age_days' => $accountAgeDays,
            'max_refund_rate' => 0.0,
            'max_complaint_rate' => 0.0,
            'min_wallet_volume' => $walletVolume,
        ];
    }

    /**
     * Evaluate a vendor against all active tiers and return the highest tier
     * they qualify for that is above their current tier. Returns null if no upgrade available.
     */
    public function findNextEligibleTier(Vendor $vendor): ?VendorTier
    {
        $performance = $this->computePerformance($vendor);
        $currentPriority = $vendor->tier ? (int) $vendor->tier->priority : -1;

        $candidates = VendorTier::active()
            ->where('priority', '>', $currentPriority)
            ->with('rules')
            ->ordered()
            ->get();

        $highestQualified = null;

        foreach ($candidates as $tier) {
            if ($tier->rules->isEmpty()) {
                continue;
            }

            if ($this->vendorMeetsAllRules($performance, $tier->rules)) {
                $highestQualified = $tier;
            }
        }

        return $highestQualified;
    }

    /**
     * Evaluate one vendor and update their eligibility status.
     * Does NOT change the vendor's current tier.
     */
    public function evaluateVendor(Vendor $vendor): void
    {
        if (! $vendor->is_approved) {
            return;
        }

        try {
            $eligibleTier = $this->findNextEligibleTier($vendor);

            if ($eligibleTier) {
                if ((int) $vendor->eligible_for_tier_id !== (int) $eligibleTier->id || ! $vendor->is_tier_eligible) {
                    $vendor->update([
                        'eligible_for_tier_id' => $eligibleTier->id,
                        'is_tier_eligible' => true,
                        'tier_qualified_at' => now(),
                    ]);
                }
            } else {
                // Clear eligibility if vendor no longer qualifies
                if ($vendor->is_tier_eligible) {
                    $vendor->update([
                        'eligible_for_tier_id' => null,
                        'is_tier_eligible' => false,
                        'tier_qualified_at' => null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('VendorTierQualificationService: evaluation failed for vendor', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run evaluation across all approved vendors in chunks.
     * Returns ['evaluated' => int, 'newly_eligible' => int].
     */
    public function evaluateAll(): array
    {
        $evaluated = 0;
        $newlyEligible = 0;

        Vendor::where('is_approved', true)
            ->with(['tier', 'eligibleTier'])
            ->chunkById(100, function (Collection $vendors) use (&$evaluated, &$newlyEligible) {
                foreach ($vendors as $vendor) {
                    $wasBefore = $vendor->is_tier_eligible;
                    $this->evaluateVendor($vendor);
                    $vendor->refresh();
                    if (! $wasBefore && $vendor->is_tier_eligible) {
                        $newlyEligible++;
                    }
                    $evaluated++;
                }
            });

        return compact('evaluated', 'newlyEligible');
    }

    /**
     * Apply a promotion: update vendor's tier and record history.
     */
    public function approvePromotion(Vendor $vendor, int $adminId, ?string $notes = null): bool
    {
        if (! $vendor->is_tier_eligible || ! $vendor->eligible_for_tier_id) {
            return false;
        }

        $previousTierId = $vendor->tier_id;
        $newTierId = $vendor->eligible_for_tier_id;

        return DB::transaction(function () use ($vendor, $previousTierId, $newTierId, $adminId, $notes) {
            $vendor->update([
                'tier_id' => $newTierId,
                'eligible_for_tier_id' => null,
                'is_tier_eligible' => false,
                'tier_reviewed_at' => now(),
                'tier_reviewed_by' => $adminId,
            ]);

            VendorTierHistory::create([
                'vendor_id' => $vendor->id,
                'previous_tier_id' => $previousTierId,
                'new_tier_id' => $newTierId,
                'action' => 'promoted',
                'admin_id' => $adminId,
                'notes' => $notes,
            ]);

            return true;
        });
    }

    /**
     * Reject a promotion: keep current tier, clear eligibility, record history.
     */
    public function rejectPromotion(Vendor $vendor, int $adminId, ?string $notes = null): bool
    {
        if (! $vendor->is_tier_eligible) {
            return false;
        }

        $eligibleTierId = $vendor->eligible_for_tier_id;

        return DB::transaction(function () use ($vendor, $eligibleTierId, $adminId, $notes) {
            $vendor->update([
                'eligible_for_tier_id' => null,
                'is_tier_eligible' => false,
                'tier_reviewed_at' => now(),
                'tier_reviewed_by' => $adminId,
            ]);

            VendorTierHistory::create([
                'vendor_id' => $vendor->id,
                'previous_tier_id' => $vendor->tier_id,
                'new_tier_id' => $eligibleTierId,
                'action' => 'rejected',
                'admin_id' => $adminId,
                'notes' => $notes,
            ]);

            return true;
        });
    }

    /**
     * Manually set a vendor's tier, bypassing the qualification queue.
     * Records the change in history (as promoted/demoted based on direction)
     * and clears any pending eligibility. Returns false if the tier is unchanged.
     */
    public function manuallyAssignTier(Vendor $vendor, VendorTier $tier, int $adminId, ?string $notes = null): bool
    {
        $previousTierId = $vendor->tier_id;

        if ((int) $previousTierId === (int) $tier->id) {
            return false;
        }

        $previousPriority = $vendor->tier ? (int) $vendor->tier->priority : -1;
        $action = (int) $tier->priority >= $previousPriority ? 'promoted' : 'demoted';

        return DB::transaction(function () use ($vendor, $tier, $previousTierId, $adminId, $notes, $action) {
            $vendor->update([
                'tier_id' => $tier->id,
                'eligible_for_tier_id' => null,
                'is_tier_eligible' => false,
                'tier_reviewed_at' => now(),
                'tier_reviewed_by' => $adminId,
            ]);

            VendorTierHistory::create([
                'vendor_id' => $vendor->id,
                'previous_tier_id' => $previousTierId,
                'new_tier_id' => $tier->id,
                'action' => $action,
                'admin_id' => $adminId,
                'notes' => $notes,
            ]);

            return true;
        });
    }

    /**
     * Assign the Regular tier to a newly approved vendor.
     */
    public function assignBaseTier(Vendor $vendor): void
    {
        $regularTier = VendorTier::where('slug', 'regular')->first();
        if (! $regularTier || $vendor->tier_id) {
            return;
        }

        $vendor->update(['tier_id' => $regularTier->id]);

        VendorTierHistory::create([
            'vendor_id' => $vendor->id,
            'previous_tier_id' => null,
            'new_tier_id' => $regularTier->id,
            'action' => 'assigned',
        ]);
    }

    private function vendorMeetsAllRules(array $performance, Collection $rules): bool
    {
        foreach ($rules as $rule) {
            $actual = $performance[$rule->rule_key] ?? null;
            if ($actual === null) {
                return false;
            }

            if (! $this->evaluateRule((float) $actual, $rule->operator, (float) $rule->value)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateRule(float $actual, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>=' => $actual >= $threshold,
            '<=' => $actual <= $threshold,
            '>' => $actual > $threshold,
            '<' => $actual < $threshold,
            '=' => abs($actual - $threshold) < 0.001,
            default => false,
        };
    }
}
