<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsSection extends Model
{
    use StampsActor;

    protected array $actorStamps = ['updated_by'];

    // page_id/key identify the row (set by the service); content (data / draft_data) is written
    // only through CmsPageService after validation.
    protected $fillable = ['page_id', 'key', 'is_visible', 'sort_order'];

    protected $casts = ['data' => 'array', 'draft_data' => 'array', 'is_visible' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        $forget = function (self $section) {
            $slug = CmsPage::withTrashed()->whereKey($section->page_id)->value('slug');
            if ($slug) {
                CmsCache::forgetPage($slug);
            }
        };
        static::saved($forget);
        static::deleted($forget);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'page_id');
    }

    public function hasDraft(): bool
    {
        return $this->draft_data !== null;
    }
}
