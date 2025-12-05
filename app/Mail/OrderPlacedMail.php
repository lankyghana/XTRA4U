<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPlacedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Order $order;
    public Vendor $vendor;
    public string $vendorRole; // 'owner', 'reseller', or 'direct'
    public float $vendorEarning;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, Vendor $vendor, string $vendorRole, float $vendorEarning)
    {
        $this->order = $order;
        $this->vendor = $vendor;
        $this->vendorRole = $vendorRole;
        $this->vendorEarning = $vendorEarning;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = match($this->vendorRole) {
            'owner' => '🎉 New Affiliate Sale - Your Product Was Sold!',
            'reseller' => '🛒 New Order Received - Reseller Sale',
            default => '🛒 New Order Received!',
        };

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.placed',
            with: [
                'order' => $this->order,
                'vendor' => $this->vendor,
                'vendorRole' => $this->vendorRole,
                'vendorEarning' => $this->vendorEarning,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
