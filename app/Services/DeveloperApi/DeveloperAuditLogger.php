<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperAuditEvent;
use Illuminate\Support\Facades\Log;

/** Append-only audit trail. Callers must never pass secrets, keys or key hashes in $meta. */
class DeveloperAuditLogger
{
    private const FORBIDDEN = ['secret', 'key', 'hash', 'password', 'token'];

    public function log(string $actor, string $action, ?int $applicationId = null, array $meta = []): void
    {
        try {
            DeveloperAuditEvent::create([
                'actor' => $actor,
                'application_id' => $applicationId,
                'action' => $action,
                'meta' => $this->scrub($meta) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Developer audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    private function scrub(array $meta): array
    {
        foreach ($meta as $k => $v) {
            foreach (self::FORBIDDEN as $needle) {
                if (is_string($k) && str_contains(strtolower($k), $needle) && $k !== 'key_prefix' && $k !== 'key_name') {
                    unset($meta[$k]);
                    continue 2;
                }
            }
        }

        return $meta;
    }
}
