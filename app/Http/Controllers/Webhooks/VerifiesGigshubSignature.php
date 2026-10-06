<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shared-secret check for every GigsHub-originated webhook.
 *
 * Accepts either an HMAC-SHA256 signature of the raw body or the secret
 * presented verbatim (GigsHub's signing scheme is not documented here, so both
 * forms are allowed). When GIGSHUB_WEBHOOK_SECRET is unset the request is
 * accepted with a warning: these endpoints have always been unauthenticated and
 * rejecting callbacks on deploy would strand deliveries. Set the secret to
 * close them.
 */
trait VerifiesGigshubSignature
{
    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('services.gigshub.webhook_secret', '');

        if ($secret === '') {
            Log::warning('GigsHub webhook: GIGSHUB_WEBHOOK_SECRET not set; accepting unauthenticated callback.', [
                'path' => $request->path(),
            ]);

            return true;
        }

        $provided = (string) ($request->header('X-Gigshub-Signature')
            ?: $request->header('X-Webhook-Secret', ''));

        if ($provided === '') {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedHmac, $provided) || hash_equals($secret, $provided);
    }
}
