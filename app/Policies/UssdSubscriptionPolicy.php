<?php

namespace App\Policies;

use App\Models\UssdSubscription;
use App\Models\Vendor;

/**
 * A vendor may only ever see or act on their own subscriptions.
 *
 * The actor is a Vendor (the `vendor` session guard), never a User, so these
 * checks must be reached via Gate::forUser(Auth::guard('vendor')->user()).
 * Admins reviewing subscriptions go through the admin surfaces instead.
 */
class UssdSubscriptionPolicy
{
    public function viewAny(Vendor $vendor): bool
    {
        return $vendor->is_approved === true;
    }

    public function view(Vendor $vendor, UssdSubscription $subscription): bool
    {
        return $this->owns($vendor, $subscription);
    }

    public function purchase(Vendor $vendor): bool
    {
        return $vendor->is_approved === true;
    }

    public function cancel(Vendor $vendor, UssdSubscription $subscription): bool
    {
        return $this->owns($vendor, $subscription) && $subscription->occupiesSlot();
    }

    private function owns(Vendor $vendor, UssdSubscription $subscription): bool
    {
        return $vendor->is_approved === true
            && (int) $subscription->vendor_id === (int) $vendor->id;
    }
}
