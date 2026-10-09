<?php

namespace App\Services\UtilityBills;

use App\Models\Order;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\WalletLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY place a Utility Bill vendor commission is credited.
 *
 * Earned exclusively when the provider order is COMPLETED, on a trusted-paid
 * order, and exactly once. Safe to call any number of times, from any worker,
 * poll, retry or admin action: the utility order row is locked, the credit and
 * the ledger entry commit together with the `credited` marker, and the ledger
 * id is UNIQUE on the order as a database-level backstop.
 *
 * The amount comes only from the terms frozen on the order at creation; later
 * admin changes cannot touch it. The wallet is credited through the same
 * `wallet_balance` + `wallet_ledgers` pair every other vendor credit uses, in
 * exact decimal arithmetic.
 */
class UtilityBillCommissionService
{
    public const CREDITED = 'credited';

    public const ALREADY = 'already_credited';

    public const NOT_EARNED = 'not_earned';

    public const NOT_APPLICABLE = 'not_applicable';

    public const NOT_PAID = 'not_paid';

    public const VENDOR_MISSING = 'vendor_missing';

    public const MISSING = 'missing';

    public function settle(int $utilityBillOrderId): string
    {
        $outcome = DB::transaction(function () use ($utilityBillOrderId) {
            /** @var UtilityBillOrder|null $u */
            $u = UtilityBillOrder::query()->whereKey($utilityBillOrderId)->lockForUpdate()->first();

            if (! $u) {
                return self::MISSING;
            }

            if ($u->commission_status === UtilityBillOrder::COMMISSION_CREDITED || $u->commission_wallet_ledger_id !== null) {
                return self::ALREADY;
            }

            // Pending, processing, failed and refunded all earn nothing.
            if ($u->fulfillment_status !== FulfillmentStatus::COMPLETED) {
                return self::NOT_EARNED;
            }

            if ($u->vendor_id === null
                || $u->commission_status !== UtilityBillOrder::COMMISSION_PENDING
                || ! Money::isPositive($u->commission_amount)) {
                return self::NOT_APPLICABLE;
            }

            // Belt and braces: never pay out on an order whose customer payment is not proven.
            $order = Order::query()->whereKey($u->order_id)->first();
            if (! $order || ! in_array($order->payment_status, ['paid', 'completed'], true) || ! $order->allowsSettlement()) {
                Log::error('utility_bills.commission.refused_unpaid', ['utility_bill_order_id' => $u->id]);

                return self::NOT_PAID;
            }

            $vendor = Vendor::query()->whereKey($u->vendor_id)->lockForUpdate()->first();
            if (! $vendor) {
                Log::error('utility_bills.commission.vendor_missing', ['utility_bill_order_id' => $u->id]);

                return self::VENDOR_MISSING;
            }

            $amount = Money::toDecimalString($u->commission_amount);
            $newBalance = Money::sum($vendor->wallet_balance, $amount);

            // Plain UPDATE under the row lock above: no model events/hooks, exact decimal value.
            Vendor::query()->whereKey($vendor->id)->update(['wallet_balance' => $newBalance]);

            $ledger = WalletLedger::create([
                'vendor_id' => $vendor->id,
                'type' => 'credit',
                'source' => 'utility_bill_commission',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'metadata' => [
                    'purpose' => 'utility_bill_commission',
                    'utility_bill_order_id' => $u->id,
                    'public_ref' => $u->public_ref,
                    'biller' => $u->biller_key,
                    'commission_type' => $u->commission_type,
                    'commission_value' => (string) $u->commission_value,
                    'commission_basis' => $u->commission_basis,
                    'commission_basis_amount' => (string) $u->commission_basis_amount,
                ],
            ]);

            $u->forceFill([
                'commission_status' => UtilityBillOrder::COMMISSION_CREDITED,
                'commission_credited_at' => now(),
                'commission_wallet_ledger_id' => $ledger->id,
            ])->save();

            $u->events()->create([
                'kind' => 'commission_credited',
                'detail' => 'GHS '.$amount.' to vendor '.$vendor->id.' (ledger '.$ledger->id.')',
                'actor' => 'system',
            ]);

            VendorNotification::create([
                'vendor_id' => $vendor->id,
                'type' => 'utility_bill_commission',
                'title' => 'Utility Bill commission earned',
                'message' => 'You earned GHS '.$amount.' commission on a '.$u->biller_label.' bill ('.$u->public_ref.').',
                'data' => ['public_ref' => $u->public_ref, 'commission' => $amount, 'biller' => $u->biller_key],
            ]);

            return self::CREDITED;
        });

        if ($outcome === self::CREDITED) {
            Log::info('utility_bills.commission.credited', ['utility_bill_order_id' => $utilityBillOrderId]);
        }

        return $outcome;
    }
}
