<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeveloperWebhook extends Model
{
    use SoftDeletes;

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    protected $table = 'developer_webhooks';

    protected $guarded = [];

    // The signing secret is encrypted at rest and never serialised.
    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'secret_rotated_at' => 'datetime',
        'last_delivery_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(DeveloperApplication::class, 'application_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DeveloperWebhookDelivery::class, 'webhook_id');
    }

    public function subscribesTo(string $type): bool
    {
        return in_array($type, (array) $this->events, true);
    }

    /** Host only, for display; the path/query may carry a developer's own tokens. */
    public function displayHost(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
