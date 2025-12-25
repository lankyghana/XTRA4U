<?php

namespace App\Mail;

use App\Models\AfaRegistration;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AfaOrderPlacedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AfaRegistration $registration,
        public Vendor $vendor,
        public string $vendorRole, // 'owner', 'reseller', or 'direct'
        public float $vendorEarning
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->vendorRole) {
            'owner' => '🧾 New AFA Order (Affiliate Sale) - Payment Confirmed',
            'reseller' => '🧾 New AFA Order (Reseller Sale) - Payment Confirmed',
            default => '🧾 New AFA Order - Payment Confirmed',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.afa.order_paid',
            with: [
                'registration' => $this->registration,
                'vendor' => $this->vendor,
                'vendorRole' => $this->vendorRole,
                'vendorEarning' => $this->vendorEarning,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
