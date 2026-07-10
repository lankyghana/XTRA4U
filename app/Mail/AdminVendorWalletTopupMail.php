<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminVendorWalletTopupMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public $vendor, public float $amount, public string $reference)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vendor Wallet Top-up Notification');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.wallet.topup_admin', with: [
            'vendor' => $this->vendor,
            'amount' => $this->amount,
            'reference' => $this->reference,
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
