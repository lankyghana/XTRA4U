<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CmsAnnouncement extends Model
{
    use StampsActor;

    public const AUDIENCES = ['public' => 'Public website', 'vendor' => 'Vendor dashboard'];

    public const TYPES = ['info' => 'Information', 'success' => 'Good news', 'warning' => 'Warning'];

    protected $fillable = ['audience', 'type', 'title', 'message', 'link_url', 'link_text', 'is_active', 'starts_at', 'ends_at'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saved(fn () => CmsCache::forget(CmsCache::ANNOUNCEMENTS));
        static::deleted(fn () => CmsCache::forget(CmsCache::ANNOUNCEMENTS));
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
