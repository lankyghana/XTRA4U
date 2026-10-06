<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;

class CmsNavigationItem extends Model
{
    use StampsActor;

    protected array $actorStamps = ['updated_by'];

    protected $fillable = ['location', 'label', 'url', 'sort_order', 'is_visible'];

    protected $casts = ['is_visible' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::saved(fn () => CmsCache::forget(CmsCache::NAV));
        static::deleted(fn () => CmsCache::forget(CmsCache::NAV));
    }
}
