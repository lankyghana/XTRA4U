<?php

namespace App\Mail;

use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorWithdrawalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public VendorWithdrawal $withdrawal,
        public Vendor $vendor,
        public string $type, // requested|paid|failed
        public ?string $details = null
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->type) {
            'paid' => '✅ Withdrawal Paid Successfully',
            'failed' => '⚠️ Withdrawal Failed (Refunded)',
            default => '🧾 Withdrawal Request Received',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.withdrawals.status',
            with: [
                'withdrawal' => $this->withdrawal,
                'vendor' => $this->vendor,
                'type' => $this->type,
                'details' => $this->details,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
