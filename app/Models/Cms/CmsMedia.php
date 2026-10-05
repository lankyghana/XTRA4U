<?php

namespace App\Models\Cms;

use App\Models\Cms\Concerns\StampsActor;
use App\Support\Cms\CmsRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CmsMedia extends Model
{
    use StampsActor;

    public const DISK_UPLOAD = 'public';

    public const DISK_BUNDLED = 'bundled';

    protected $table = 'cms_media';

    protected array $actorStamps = ['created_by'];

    // Only the alt text is editable after upload; file facts are written by MediaService.
    protected $fillable = ['alt_text'];

    public function isBundled(): bool
    {
        return $this->disk === self::DISK_BUNDLED;
    }

    public function url(): string
    {
        return $this->isBundled()
            ? asset($this->path)
            : Storage::disk(self::DISK_UPLOAD)->url($this->path);
    }

    /**
     * Resolve a stored image reference (a media id, or a "bundled:path"
     * default) to a URL, or null if it cannot be resolved.
     */
    public static function urlFor(int|string|null $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        if (is_string($ref) && str_starts_with($ref, CmsRegistry::BUNDLED_PREFIX)) {
            $path = substr($ref, strlen(CmsRegistry::BUNDLED_PREFIX));

            return preg_match('#^images/[A-Za-z0-9_./-]+$#', $path) && ! str_contains($path, '..') ? asset($path) : null;
        }

        return self::find((int) $ref)?->url();
    }
}
