<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AfaRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'reseller_vendor_id',
        'full_name',
        'id_number',
        'id_type',
        'date_of_birth',
        'phone_number',
        'location',
        'region',
        'occupation',
        'amount',
        'vendor_price',
        'platform_commission',
        'vendor_earning',
        'reseller_earning',
        'is_reseller_order',
        'payment_reference',
        'payment_gateway',
        'payment_status',
        'payment_completed_at',
        'status',
        'admin_notes',
        'rejection_reason',
        'reference',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'amount' => 'decimal:2',
        'vendor_price' => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'vendor_earning' => 'decimal:2',
        'reseller_earning' => 'decimal:2',
        'is_reseller_order' => 'boolean',
        'payment_completed_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_APPROVED = 'approved';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    // Payment status constants
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_COMPLETED = 'completed';
    const PAYMENT_FAILED = 'failed';
    const PAYMENT_REFUNDED = 'refunded';

    // ID Type constants
    const ID_GHANA_CARD = 'ghana_card';
    const ID_DRIVERS_LICENSE = 'drivers_license';
    const ID_VOTERS_ID = 'voters_id';

    /**
     * Get the vendor that owns this registration
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the reseller vendor if this is a reseller order
     */
    public function resellerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'reseller_vendor_id');
    }

    /**
     * Polymorphic relationship: transactions for this AFA registration
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    /**
     * Scope for pending registrations
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for processing registrations
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope for completed registrations
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for paid registrations
     */
    public function scopePaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_COMPLETED);
    }

    /**
     * Get human-readable status label
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending Review',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI
     */
    public function getStatusColorAttribute(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'],
            self::STATUS_PROCESSING => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800'],
            self::STATUS_APPROVED => ['bg' => 'bg-green-100', 'text' => 'text-green-800'],
            self::STATUS_COMPLETED => ['bg' => 'bg-green-100', 'text' => 'text-green-800'],
            self::STATUS_REJECTED => ['bg' => 'bg-red-100', 'text' => 'text-red-800'],
            self::STATUS_CANCELLED => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'],
            default => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'],
        };
    }

    /**
     * Get ID type label
     */
    public function getIdTypeLabelAttribute(): string
    {
        return match ($this->id_type) {
            self::ID_GHANA_CARD => 'Ghana Card',
            self::ID_DRIVERS_LICENSE => "Driver's License",
            self::ID_VOTERS_ID => 'Voters ID',
            default => ucfirst(str_replace('_', ' ', $this->id_type)),
        };
    }

    /**
     * Get available ID types
     */
    public static function getIdTypes(): array
    {
        return [
            self::ID_GHANA_CARD => 'Ghana Card',
            self::ID_DRIVERS_LICENSE => "Driver's License",
            self::ID_VOTERS_ID => 'Voters ID',
        ];
    }

    /**
     * Get Ghana regions
     */
    public static function getRegions(): array
    {
        return [
            'Greater Accra',
            'Ashanti',
            'Western',
            'Eastern',
            'Central',
            'Northern',
            'Volta',
            'Upper East',
            'Upper West',
            'Bono',
            'Bono East',
            'Ahafo',
            'Western North',
            'Oti',
            'North East',
            'Savannah',
        ];
    }

    /**
     * Generate unique reference
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'AFA-' . strtoupper(substr(uniqid(), -8)) . '-' . rand(100, 999);
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark as approved
     */
    public function markAsApproved(?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Mark as rejected
     */
    public function markAsRejected(string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
    }
}
