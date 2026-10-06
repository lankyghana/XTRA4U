<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;

class CmsSetting extends Model
{
    use StampsActor;

    protected array $actorStamps = ['updated_by'];

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        static::saved(fn () => CmsCache::forget(CmsCache::SETTINGS));
        static::deleted(fn () => CmsCache::forget(CmsCache::SETTINGS));
    }
}
