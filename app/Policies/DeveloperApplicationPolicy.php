<?php

namespace App\Policies;

use App\Models\Developer\DeveloperApplication;
use App\Models\Vendor;

/**
 * An application, its keys, webhooks and delivery history belong to exactly one vendor, and may be
 * managed only by that vendor while it is approved. Routes additionally load applications THROUGH
 * `vendor_id = <signed-in vendor>`, so another vendor's application is a 404 (not even confirmed to
 * exist); this policy is the second layer.
 */
class DeveloperApplicationPolicy
{
    public function manage(Vendor $vendor, DeveloperApplication $application): bool
    {
        return $vendor->is_approved === true && (int) $application->vendor_id === (int) $vendor->id;
    }
}
