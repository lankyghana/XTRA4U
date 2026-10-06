<?php

namespace App\Services\Support;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\ResultCheckerOrder;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use App\Models\WalletTopup;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONLY way a conversation gets linked to an existing record.
 *
 * Every lookup is resolved inside a vendor-scoped query, so a record id that
 * merely exists (but belongs to someone else) is indistinguishable from one
 * that does not exist. The label stored on the conversation is generated here,
 * never taken from the browser.
 */
class SupportRelatedRecords
{
    public const ORDER = 'order';

    public const TRANSACTION = 'transaction';

    public const WALLET_TOPUP = 'wallet_topup';

    public const WITHDRAWAL = 'withdrawal';

    public const AFA = 'afa_registration';

    public const RESULT_CHECKER = 'result_checker_order';

    public const TYPES = [
        self::ORDER => 'Order',
        self::TRANSACTION => 'Transaction',
        self::WALLET_TOPUP => 'Wallet top-up',
        self::WITHDRAWAL => 'Withdrawal',
        self::AFA => 'AFA registration',
        self::RESULT_CHECKER => 'Result checker order',
    ];

    public static function isSupportedType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::TYPES);
    }

    /** @return array{type:string,id:int,label:string}|null */
    public function resolve(Vendor $vendor, ?string $type, mixed $id): ?array
    {
        if (! self::isSupportedType($type) || ! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $record = $this->scopedQuery($vendor, $type)->whereKey((int) $id)->first();

        return $record ? ['type' => $type, 'id' => (int) $record->getKey(), 'label' => $this->label($type, $record)] : null;
    }

    /** Recent records of $type the vendor may legitimately reference. */
    public function optionsFor(Vendor $vendor, string $type, int $limit = 25): array
    {
        if (! self::isSupportedType($type)) {
            return [];
        }

        return $this->scopedQuery($vendor, $type)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn ($record) => ['id' => (int) $record->getKey(), 'label' => $this->label($type, $record)])
            ->all();
    }

    public function adminUrl(?string $type, ?int $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return match ($type) {
            self::ORDER => route('admin.orders.show', $id),
            self::RESULT_CHECKER => route('admin.result-checkers.orders.show', $id),
            default => null,
        };
    }

    private function scopedQuery(Vendor $vendor, string $type): Builder
    {
        $vendorId = $vendor->getKey();

        return match ($type) {
            // Mirrors VendorDashboardController::orders(): the selling vendor, the
            // product owner on reseller orders, and the legacy reseller-product mapping.
            self::ORDER => Order::query()->where(function ($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId)
                    ->orWhere(fn ($q2) => $q2->where('owner_vendor_id', $vendorId)->where('is_reseller_order', true))
                    ->orWhere(fn ($q2) => $q2->where('is_reseller_order', true)
                        ->whereHas('resellerProduct', fn ($q3) => $q3->where('owner_vendor_id', $vendorId)));
            }),
            self::TRANSACTION => Transaction::query()->where('vendor_id', $vendorId),
            self::WALLET_TOPUP => WalletTopup::query()->where('vendor_id', $vendorId),
            self::WITHDRAWAL => VendorWithdrawal::query()->where('vendor_id', $vendorId),
            // Provider or reseller may view an AFA registration (VendorAfaController).
            self::AFA => AfaRegistration::query()->where(
                fn ($q) => $q->where('vendor_id', $vendorId)->orWhere('reseller_vendor_id', $vendorId)
            ),
            self::RESULT_CHECKER => ResultCheckerOrder::query()->where('vendor_id', $vendorId),
        };
    }

    private function label(string $type, $record): string
    {
        $label = match ($type) {
            self::ORDER => 'Order #'.$record->id.($record->service_purchased ? ' · '.$record->service_purchased : ''),
            self::TRANSACTION => 'Transaction #'.$record->id.' · GHS '.number_format((float) $record->amount, 2),
            self::WALLET_TOPUP => 'Wallet top-up '.$record->reference.' · GHS '.number_format((float) $record->amount, 2),
            self::WITHDRAWAL => 'Withdrawal '.($record->reference ?: '#'.$record->id).' · GHS '.number_format((float) $record->amount, 2),
            self::AFA => 'AFA registration #'.$record->id,
            self::RESULT_CHECKER => 'Result checker order #'.$record->id,
        };

        return mb_substr($label, 0, 150);
    }
}
