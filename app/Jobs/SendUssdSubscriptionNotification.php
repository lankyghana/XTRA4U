<?php

namespace App\Jobs;

use App\Mail\UssdSubscriptionMail;
use App\Models\UssdSubscription;
use App\Services\GatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendUssdSubscriptionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_ACTIVATED = 'activated';

    public const TYPE_EXPIRING = 'expiring';

    public const TYPE_EXPIRED = 'expired';

    public const TYPE_USAGE_ALERT = 'usage_alert';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public int $tries = 3;

    public function __construct(
        public int $subscriptionId,
        public string $type,
        public array $context = [],
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        // A usage alert is distinct per threshold; every other type fires once
        // per subscription. Including the discriminator keeps 80% and 90% from
        // suppressing one another.
        $discriminator = $this->context['threshold'] ?? '0';
        $lock = Cache::lock("lock:ussd-notify:{$this->subscriptionId}:{$this->type}:{$discriminator}", 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $subscription = UssdSubscription::with('vendor', 'plan')->find($this->subscriptionId);

            if (! $subscription || ! $subscription->vendor) {
                return;
            }

            $this->email($subscription);
            $this->sms($subscription);
        } finally {
            $lock->release();
        }
    }

    private function email(UssdSubscription $subscription): void
    {
        $vendor = $subscription->vendor;

        if (empty($vendor->email)) {
            return;
        }

        try {
            Mail::to($vendor->email)->send(
                new UssdSubscriptionMail($vendor, $subscription, $this->type, $this->context)
            );
        } catch (\Throwable $e) {
            // A failed email must not roll back the notification or retry the
            // SMS, which may already have gone out.
            Log::warning('Failed to send USSD subscription email', [
                'subscription_id' => $this->subscriptionId,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sms(UssdSubscription $subscription): void
    {
        $vendor = $subscription->vendor;
        $phone = trim((string) ($vendor->phone_number ?? ''));

        if ($phone === '') {
            return;
        }

        $message = $this->smsMessage($subscription);

        if ($message === null) {
            return;
        }

        try {
            $smsService = app(GatewayManager::class)->getSmsService();
            $smsService?->send($phone, $message);
        } catch (\Throwable $e) {
            Log::warning('Failed to send USSD subscription SMS', [
                'subscription_id' => $this->subscriptionId,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function smsMessage(UssdSubscription $subscription): ?string
    {
        $plan = $subscription->plan?->name ?? 'USSD';

        return match ($this->type) {
            self::TYPE_ACTIVATED => sprintf(
                'XTRA4U: Your %s USSD plan is active. Your code is %s. %s sessions until %s.',
                $plan,
                $subscription->ussd_code ?? '',
                number_format($subscription->remaining_sessions),
                $subscription->expires_at?->format('d M Y') ?? '',
            ),
            self::TYPE_EXPIRING => sprintf(
                'XTRA4U: Your %s USSD plan expires on %s. Renew to keep your code active.',
                $plan,
                $subscription->expires_at?->format('d M Y') ?? '',
            ),
            self::TYPE_EXPIRED => 'XTRA4U: Your USSD subscription has expired. Renew in your dashboard to restore service.',
            self::TYPE_USAGE_ALERT => sprintf(
                'XTRA4U: You have used %s%% of your USSD sessions. %s remaining.',
                $this->context['threshold'] ?? '',
                number_format($subscription->remaining_sessions),
            ),
            self::TYPE_PAYMENT_FAILED => 'XTRA4U: Your USSD subscription payment failed. No charge was made. Please try again.',
            default => null,
        };
    }
}
