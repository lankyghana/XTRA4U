<?php

namespace App\Policies;

use App\Models\Developer\Developer;
use App\Models\Developer\DeveloperApplication;

/**
 * An application, its keys, webhooks and delivery history belong to exactly one developer.
 * Routes additionally load applications THROUGH the developer's own relationship, so another
 * developer's application is a 404 (not even confirmed to exist); this is the second layer.
 */
class DeveloperApplicationPolicy
{
    public function manage(Developer $developer, DeveloperApplication $application): bool
    {
        return $developer->isActive() && (int) $application->developer_id === (int) $developer->id;
    }
}
