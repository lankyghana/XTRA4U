<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPage extends Model
{
    use SoftDeletes, StampsActor;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** Fields an admin edits. They live in the live columns and, while pending, in `draft`. */
    public const EDITABLE = ['title', 'icon', 'body', 'seo_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'og_image_media_id', 'robots_noindex'];

    /** Slugs that would shadow application routes or look like them. */
    public const RESERVED_SLUGS = ['admin', 'api', 'about', 'privacy', 'terms', 'home', 'faq', 'store', 'vendor', 'checkout', 'storage', 'login', 'register', 'p', 'order-status', 'services', 'payment', 'purchase', 'webhooks', 'results-checkers', 'results-checker', 'result-checkers'];

    // Status, kind, is_system, published_* and actors are set explicitly by the service, never mass-assigned.
    protected $fillable = ['slug', 'title', 'icon', 'body', 'seo_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'og_image_media_id', 'robots_noindex'];

    protected $casts = [
        'is_system' => 'boolean',
        'robots_noindex' => 'boolean',
        'draft' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        $forget = function (self $page) {
            CmsCache::forgetPage($page->slug, (string) $page->getOriginal('slug'));
        };
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CmsSection::class, 'page_id')->orderBy('sort_order');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CmsRevision::class, 'page_id')->orderByDesc('version');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isStructured(): bool
    {
        return in_array($this->kind, ['home', 'about'], true);
    }

    public function hasDraft(): bool
    {
        return $this->draft !== null && $this->draft !== [];
    }

    /** Value shown in the editor: the pending draft if one exists, else live. */
    public function editorValue(string $field): mixed
    {
        if ($this->hasDraft() && array_key_exists($field, $this->draft)) {
            return $this->draft[$field];
        }

        return $this->{$field};
    }

    public function publicUrl(): string
    {
        return match ($this->slug) {
            'home' => url('/'),
            'about' => route('about'),
            'privacy' => route('privacy'),
            'terms' => route('terms'),
            default => route('cms.page', $this->slug),
        };
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
