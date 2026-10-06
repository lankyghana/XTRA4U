<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;

class CmsFaq extends Model
{
    use StampsActor;

    protected $fillable = ['category', 'question', 'answer', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::saved(fn () => CmsCache::forget(CmsCache::FAQS));
        static::deleted(fn () => CmsCache::forget(CmsCache::FAQS));
    }
}
