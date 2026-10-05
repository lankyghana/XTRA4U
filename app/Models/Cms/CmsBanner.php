<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CmsBanner extends Model
{
    use StampsActor;

    /** Placements that exist on the public site today. */
    public const PLACEMENTS = ['home_hero' => 'Homepage hero slideshow'];

    protected $fillable = ['placement', 'title', 'link_url', 'image_media_id', 'is_active', 'starts_at', 'ends_at', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::saved(fn () => CmsCache::forget(CmsCache::BANNERS));
        static::deleted(fn () => CmsCache::forget(CmsCache::BANNERS));
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(CmsMedia::class, 'image_media_id');
    }

    /** live | scheduled | expired | inactive. Same rule the public query applies. */
    public function statusAt(?Carbon $now = null): string
    {
        $now ??= now();

        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->starts_at && $this->starts_at->gt($now)) {
            return 'scheduled';
        }
        if ($this->ends_at && $this->ends_at->lte($now)) {
            return 'expired';
        }

        return 'live';
    }
}
