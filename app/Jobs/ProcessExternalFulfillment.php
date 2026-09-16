<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\Contracts\SupportsStatusPolling;
use App\Services\ExternalFulfillment\ExternalFulfillmentClientFactory;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use App\Services\ExternalFulfillment\ExternalFulfillmentStatusSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Submits a paid order to its external fulfillment provider, exactly once.
 *
 * Concurrency/idempotency model (three layers, provider idempotency is the
 * final backstop):
 *   1. {@see ShouldBeUnique} keeps a second copy of this job for the same
 *      order off the queue entirely while one is pending/executing.
 *   2. A non-blocking {@see Cache::lock()} short-circuits an overlapping
 *      run cheaply (e.g. a webhook-triggered retry racing a queue worker).
 *   3. The authoritative guard is {@see claimForSubmission()}: a single
 *      `lockForUpdate()` transaction that reads the order's current state
 *      and atomically transitions it to "processing" (or decides to skip/
 *      recover instead) before any HTTP call is made. Only the worker that
 *      wins this row lock may contact the provider.
 *
 * The external HTTP call itself is made outside any DB transaction (an
 * external API must never hold a database row lock for the duration of a
 * network round trip). Because of that, the result is written back with a
 * guarded conditional update (`applySubmissionResult()`/`guardedUpdate()`)
 * that only takes effect if the order is still in the "processing" state
 * this job put it in — if a webhook resolved the order while our request
 * was in flight, our late result is discarded rather than regressing a
 * state that has already moved on.
 */
class ProcessExternalFulfillment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Matches the manual Cache::lock TTL below; keeps a duplicate dispatch off the queue for as long as a run could plausibly take. */
    public int $uniqueFor = 600;

    private const RECIPIENT_CONFLICT_RETRY_SECONDS = 600;

    // Rechecks run every 10 minutes, so 144 attempts gives a conflict-held
    // order ~24 hours to clear before it may be marked failed. Orders the
    // provider has actually accepted are never failed automatically.
    private const MAX_RECIPIENT_CONFLICT_ATTEMPTS = 144;

    /**
     * Sentinel written to external_fulfillment_remote_reference when a
     * provider confirms a duplicate/already-submitted order but gives us no
     * identifier to check it with. Excluded from SyncExternalFulfillmentStatuses'
     * polling query (no identifier to poll) and never treated as a genuine
     * remote reference on a later retry.
     */
    private const DUPLICATE_UNVERIFIED_REFERENCE = 'duplicate-order-detected';

    /**
     * Shared marker written by `fulfillment:close-manual-legacy` and
     * `fulfillment:close-paid-legacy`. An order carrying it was
     * administratively stamped 'succeeded' and must never be resubmitted,
     * regardless of what external_fulfillment_status reads.
     */
    private const ADMINISTRATIVE_CLOSURE_NOTE_PREFIX = 'Fulfillment closed administratively';

    public function __construct(public int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $lockKey = sprintf('lock:external-fulfillment:%d', $this->orderId);
        $lock = Cache::lock($lockKey, 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $claim = $this->claimForSubmission();

            if ($claim === null || $claim['action'] === 'skip') {
                return;
            }

            $synchronizer = new ExternalFulfillmentStatusSynchronizer;

            if ($claim['action'] === 'recover') {
                $this->syncFromProvider(
                    $claim['order_id'],
                    $claim['provider'],
                    $claim['remote_reference'],
                    $claim['config'],
                    $synchronizer,
                    'retry-recovery'
                );

                return;
            }

            $this->submit($claim, $synchronizer);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Atomically decide what this attempt should do, and — for a fresh
     * submission — claim the order by transitioning it to "processing"
     * before releasing the row lock. This is the single place that may
     * decide to contact the provider; everything after this returns
     * without further gating.
     *
     * @return array{action:string,...}|null null when the order does not exist.
     */
    private function claimForSubmission(): ?array
    {
        return DB::transaction(function () {
            /** @var Order|null $order */
            $order = Order::whereKey($this->orderId)->lockForUpdate()->first();

            if (! $order) {
                return null;
            }

            if (! in_array($order->payment_status, ['paid', 'completed'], true)) {
                return ['action' => 'skip'];
            }

            // payment_status alone is NOT sufficient to deliver real goods.
            // An order must also carry proof that a trusted payment source
            // satisfied its immutable financial terms — see PaymentIntegrity.
            // This is the control that stops an order whose payment was never
            // (or cannot be) verified from reaching a provider, however it came
            // to be marked paid.
            if (! $order->allowsFulfillment()) {
                Log::warning('External fulfillment skipped: payment integrity not established for this order', [
                    'order_id' => $order->id,
                    'payment_status' => $order->payment_status,
                    'payment_integrity_status' => $order->payment_integrity_status,
                    'payment_integrity_note' => $order->payment_integrity_note,
                ]);

                return ['action' => 'skip'];
            }

            // Administratively-closed historical orders must never be
            // resubmitted or re-checked, regardless of external_fulfillment_status.
            if (str_starts_with((string) $order->reconciliation_note, self::ADMINISTRATIVE_CLOSURE_NOTE_PREFIX)) {
                return ['action' => 'skip'];
            }

            // A fulfillment that already succeeded/delivered must never be repeated.
            if (in_array($order->external_fulfillment_status, ['succeeded', 'delivered'], true)) {
                return ['action' => 'skip'];
            }

            $targetVendorId = (int) ($order->owner_vendor_id ?: $order->vendor_id);
            if ($targetVendorId <= 0) {
                return ['action' => 'skip'];
            }

            $vendor = Vendor::query()->find($targetVendorId);
            if (! $vendor) {
                return ['action' => 'skip'];
            }

            $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);

            // The provider has already accepted THIS order (a genuine remote
            // reference is on file) — recover/verify instead of resubmitting.
            $hasGenuineRemoteReference = $order->external_fulfillment_status === 'processing'
                && is_string($order->external_fulfillment_remote_reference)
                && $order->external_fulfillment_remote_reference !== ''
                && $order->external_fulfillment_remote_reference !== self::DUPLICATE_UNVERIFIED_REFERENCE;

            if ($hasGenuineRemoteReference) {
                $provider = (string) $order->external_fulfillment_provider_used;

                if ($provider === '' || ! $config->isProviderReady($provider)) {
                    return ['action' => 'skip'];
                }

                return [
                    'action' => 'recover',
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'remote_reference' => (string) $order->external_fulfillment_remote_reference,
                    'config' => $config,
                ];
            }

            if (! $config->isReady()) {
                return ['action' => 'skip'];
            }

            [$providerUsed, $providerNetwork] = $this->resolveProviderAndNetwork($config, $order);

            if (! $config->isProviderReady($providerUsed)) {
                Log::warning('External fulfillment resolved provider is not ready', [
                    'order_id' => $order->id,
                    'provider' => $providerUsed,
                ]);

                return ['action' => 'skip'];
            }

            // Stable across every retry: reuse whatever key is already on
            // file, and only ever generate the deterministic fallback once
            // (it is derived purely from the order id, so recomputing it
            // later is guaranteed to produce the same value).
            $idempotencyKey = (string) ($order->external_fulfillment_idempotency_key ?: ('order-'.$order->id));
            $attempts = (int) ($order->external_fulfillment_attempts ?? 0) + 1;

            Order::whereKey($order->id)->update([
                'external_fulfillment_idempotency_key' => $idempotencyKey,
                'external_fulfillment_status' => 'processing',
                'external_fulfillment_attempts' => $attempts,
                'external_fulfillment_last_attempt_at' => now(),
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            return [
                'action' => 'submit',
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'provider' => $providerUsed,
                'provider_network' => $providerNetwork,
                'attempts' => $attempts,
                'networks' => config("external_fulfillment.providers.{$providerUsed}.networks", []),
                'config' => $config,
            ];
        });
    }

    private function submit(array $claim, ExternalFulfillmentStatusSynchronizer $synchronizer): void
    {
        $order = Order::query()->find($claim['order_id']);
        if (! $order) {
            return;
        }

        $order->setAttribute('external_provider', $claim['provider']);
        $order->setAttribute('external_provider_network', $claim['provider_network']);
        $order->setAttribute('external_provider_networks', $claim['networks']);

        $client = ExternalFulfillmentClientFactory::make($claim['config'], $claim['provider']);
        $result = $client->sendOrder($order, $claim['idempotency_key']);

        $this->applySubmissionResult($claim, $result, $synchronizer);
    }

    private function applySubmissionResult(array $claim, array $result, ExternalFulfillmentStatusSynchronizer $synchronizer): void
    {
        $orderId = $claim['order_id'];
        $providerUsed = $claim['provider'];

        $status = strtolower((string) ($result['status'] ?? ''));
        $success = (bool) ($result['success'] ?? false);
        $remoteReference = $result['external_reference'] ?? null;
        $message = (string) ($result['message'] ?? '');
        $httpStatus = $result['http_status'] ?? null;

        if ($success || in_array($status, ['success', 'completed'], true)) {
            $this->guardedUpdate($orderId, [
                'external_fulfillment_status' => 'succeeded',
                'external_fulfillment_completed_at' => now(),
                'external_fulfillment_remote_reference' => $remoteReference,
                'external_fulfillment_last_error' => null,
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            return;
        }

        if (in_array($status, ['pending', 'processing'], true)) {
            $this->guardedUpdate($orderId, [
                'external_fulfillment_status' => 'processing',
                'external_fulfillment_remote_reference' => $remoteReference,
                'external_fulfillment_last_error' => null,
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            return;
        }

        // Confirmed duplicate: the provider is telling us a submission for
        // this exact order/reference already exists. An earlier attempt was
        // therefore accepted — recover instead of treating this as a fresh
        // failure, and never resubmit again.
        if (str_contains(strtolower($message), 'already exists')) {
            $this->recoverConfirmedDuplicate($claim, $result, $synchronizer);

            return;
        }

        // The provider has an unrelated in-flight order for the same
        // recipient phone number (possibly a different local order). Hold
        // this order and recheck later — it may still need delivering once
        // the provider's existing order clears.
        if ($this->isRecipientConflict($message)) {
            $this->holdForRecipientConflict($claim, $message);

            return;
        }

        // An HTTP 409 whose body matches none of the duplicate/conflict
        // signals we actually recognise is not safe to guess at. Fail it
        // for manual review WITHOUT throwing: a queue retry would just
        // resubmit the identical request and, most likely, hit the same
        // 409 again — which is exactly the production incident this
        // hardening exists to prevent.
        if ((int) $httpStatus === 409) {
            $applied = $this->guardedUpdate($orderId, [
                'external_fulfillment_status' => 'failed',
                'external_fulfillment_last_error' => 'Unrecognized 409 Conflict from provider (manual review required): '
                    .($message !== '' ? $message : 'no message body'),
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            if ($applied) {
                Log::error('External fulfillment failed: unrecognized 409 conflict response', [
                    'order_id' => $orderId,
                    'provider' => $providerUsed,
                    'message' => $message,
                ]);
            }

            return;
        }

        $errorMessage = $message !== '' ? $message : 'External fulfillment failed';

        $applied = $this->guardedUpdate($orderId, [
            'external_fulfillment_status' => 'failed',
            'external_fulfillment_last_error' => $errorMessage,
            'external_fulfillment_provider_used' => $providerUsed,
        ]);

        if (! $applied) {
            // Order state moved on (e.g. a webhook resolved it) while our
            // request was in flight — our failure is stale, discard it.
            return;
        }

        Log::warning('External fulfillment failed', [
            'order_id' => $orderId,
            'status' => $status,
        ]);

        throw new \RuntimeException($errorMessage);
    }

    /**
     * The provider confirmed this exact order/reference was already
     * submitted. Mark it processing (not blindly succeeded — we do not
     * actually know the delivery outcome yet) and, when we have a genuine
     * identifier and the provider supports status polling, resolve the
     * real state immediately instead of waiting for the next poll cycle.
     */
    private function recoverConfirmedDuplicate(array $claim, array $result, ExternalFulfillmentStatusSynchronizer $synchronizer): void
    {
        $orderId = $claim['order_id'];
        $providerUsed = $claim['provider'];

        $extracted = $result['external_reference'] ?? null;
        $reference = is_string($extracted) && $extracted !== ''
            ? $extracted
            : self::DUPLICATE_UNVERIFIED_REFERENCE;

        $applied = $this->guardedUpdate($orderId, [
            'external_fulfillment_status' => 'processing',
            'external_fulfillment_remote_reference' => $reference,
            'external_fulfillment_last_error' => null,
            'external_fulfillment_provider_used' => $providerUsed,
        ]);

        Log::info('External fulfillment: provider reported this order already submitted; synchronizing instead of resubmitting', [
            'order_id' => $orderId,
            'provider' => $providerUsed,
            'remote_reference' => $reference,
        ]);

        if (! $applied || $reference === self::DUPLICATE_UNVERIFIED_REFERENCE) {
            return;
        }

        $this->syncFromProvider($orderId, $providerUsed, $reference, $claim['config'], $synchronizer, 'duplicate-recovery');
    }

    private function holdForRecipientConflict(array $claim, string $message): void
    {
        $orderId = $claim['order_id'];
        $providerUsed = $claim['provider'];
        $attempts = $claim['attempts'];

        if ($attempts < self::MAX_RECIPIENT_CONFLICT_ATTEMPTS) {
            $applied = $this->guardedUpdate($orderId, [
                'external_fulfillment_status' => 'processing',
                'external_fulfillment_last_error' => $message,
                'external_fulfillment_provider_used' => $providerUsed,
            ]);

            if (! $applied) {
                return;
            }

            Log::info('External fulfillment held: provider has an in-flight order for this recipient', [
                'order_id' => $orderId,
                'provider' => $providerUsed,
                'attempts' => $attempts,
            ]);

            self::dispatch($this->orderId)
                ->delay(now()->addSeconds(self::RECIPIENT_CONFLICT_RETRY_SECONDS));

            return;
        }

        // Recheck budget exhausted: record the failure without throwing, since
        // the queue's short backoffs cannot outlast the provider conflict.
        $applied = $this->guardedUpdate($orderId, [
            'external_fulfillment_status' => 'failed',
            'external_fulfillment_last_error' => $message,
            'external_fulfillment_provider_used' => $providerUsed,
        ]);

        if ($applied) {
            Log::warning('External fulfillment failed: recipient conflict did not clear', [
                'order_id' => $orderId,
                'provider' => $providerUsed,
                'attempts' => $attempts,
            ]);
        }
    }

    /**
     * Write the result of this attempt, but only if the order is still in
     * the "processing" state this job claimed it into. If it is not (a
     * webhook or another sync path already resolved it while our HTTP
     * request was in flight), the update is skipped so a late/failed
     * attempt can never regress an already-resolved state.
     *
     * @return bool whether the update was applied.
     */
    private function guardedUpdate(int $orderId, array $updates): bool
    {
        $affected = Order::whereKey($orderId)
            ->where('external_fulfillment_status', 'processing')
            ->update($updates);

        if ($affected === 0) {
            Log::info('External fulfillment result discarded: order state changed concurrently', [
                'order_id' => $orderId,
            ]);
        }

        return $affected > 0;
    }

    /**
     * Ask the provider what it knows about a remote order and apply that
     * through ExternalFulfillmentStatusSynchronizer — the single place that
     * turns a provider status into local state, with its own locking and
     * regression guards. Used both when recovering a confirmed duplicate
     * with a genuine identifier and when a retry finds an order the
     * provider already accepted.
     */
    private function syncFromProvider(
        int $orderId,
        string $provider,
        string $reference,
        ExternalFulfillmentConfig $config,
        ExternalFulfillmentStatusSynchronizer $synchronizer,
        string $source
    ): void {
        if ($provider === '' || $reference === '') {
            return;
        }

        $client = ExternalFulfillmentClientFactory::make($config, $provider);

        if (! $client instanceof SupportsStatusPolling) {
            Log::info('External fulfillment: order already accepted by provider; no status polling available for this provider, awaiting webhook', [
                'order_id' => $orderId,
                'provider' => $provider,
            ]);

            return;
        }

        $result = $client->fetchRemoteStatus($reference);

        if (! ($result['success'] ?? false) || ($result['status'] ?? null) === null) {
            Log::info('External fulfillment: status check for existing remote order returned no usable status', [
                'order_id' => $orderId,
                'provider' => $provider,
                'message' => $result['message'] ?? null,
            ]);

            return;
        }

        $synchronizer->apply($orderId, $provider, (string) $result['status'], [
            'source' => $source,
            'remote_reference' => $reference,
        ]);
    }

    private function isRecipientConflict(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'already pending')
            || str_contains($normalized, 'already processing');
    }

    private function resolveProviderAndNetwork(ExternalFulfillmentConfig $config, Order $order): array
    {
        $product = null;
        $metadata = [];

        if (! empty($order->vendor_service_id)) {
            $product = Product::query()->find((int) $order->vendor_service_id);
            $metadata = is_array($product?->decoded_description) ? $product->decoded_description : [];
        }

        // 1. Check specific provider mappings first
        foreach (['datafyhub', 'xpresportal', 'gigshub', 'skdataplug'] as $p) {
            if ($config->isProviderReady($p)) {
                $networkFromMappings = data_get($metadata, "external_mappings.{$p}.network");
                if (is_string($networkFromMappings) && $networkFromMappings !== '') {
                    return [$p, $networkFromMappings];
                }
            }
        }

        // 2. Fallback to generic or legacy mappings using the first ready provider
        $fallbackProvider = $config->provider;
        if (! $fallbackProvider || ! $config->isProviderReady($fallbackProvider)) {
            $fallbackProvider = null;
            foreach (['datafyhub', 'xpresportal', 'gigshub', 'skdataplug'] as $p) {
                if ($config->isProviderReady($p)) {
                    $fallbackProvider = $p;
                    break;
                }
            }
        }

        if ($fallbackProvider) {
            $genericNetwork = data_get($metadata, 'external_network');
            if (is_string($genericNetwork) && $genericNetwork !== '') {
                return [$fallbackProvider, $genericNetwork];
            }

            $legacyDatafyhub = data_get($metadata, 'datafyhub_network');
            if (is_string($legacyDatafyhub) && $legacyDatafyhub !== '') {
                return [$fallbackProvider, $legacyDatafyhub];
            }
        }

        return [$fallbackProvider ?? 'datafyhub', null];
    }
}
