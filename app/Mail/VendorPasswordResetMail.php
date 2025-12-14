<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public Vendor $vendor;
    public string $token;
    public string $resetUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(Vendor $vendor, string $token)
    {
        $this->vendor = $vendor;
        $this->token = $token;
        $this->resetUrl = route('vendor.password.reset', [
            'token' => $token,
            'email' => $vendor->email
        ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your XTRA4U Vendor Password',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor-password-reset',
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
