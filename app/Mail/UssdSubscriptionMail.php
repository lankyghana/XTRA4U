<?php

namespace App\Mail;

use App\Models\UssdSubscription;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One mailable for every USSD subscription lifecycle event.
 *
 * Deliberately not ShouldQueue: it is only ever sent from
 * SendUssdSubscriptionNotification, which is already a queued job. Queueing it
 * again would push a second job onto the queue for every email.
 */
class UssdSubscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    private const SUBJECTS = [
        'activated' => '✅ Your USSD service is live',
        'expiring' => '⏳ Your USSD subscription expires soon',
        'expired' => '⚠️ Your USSD subscription has expired',
        'usage_alert' => '📉 Your USSD sessions are running low',
        'payment_failed' => '❌ USSD subscription payment failed',
    ];

    public function __construct(
        public Vendor $vendor,
        public UssdSubscription $subscription,
        public string $type,
        public array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECTS[$this->type] ?? 'USSD Subscription Update');
    }

    public function content(): Content
    {
        // markdown, not view: these templates use @component('mail::message'),
        // whose `mail::` namespace only exists in the markdown renderer.
        return new Content(markdown: "emails.ussd.{$this->type}", with: [
            'vendor' => $this->vendor,
            'subscription' => $this->subscription,
            'context' => $this->context,
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
