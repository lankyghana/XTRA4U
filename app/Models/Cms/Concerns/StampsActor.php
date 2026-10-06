<?php

namespace App\Models\Cms\Concerns;

use App\Support\Cms\CmsAdmin;

/**
 * Records which administrator created / last changed a row. The values come
 * from the authenticated session, never from request input, and the columns
 * are deliberately absent from every model's $fillable.
 *
 * A model lists the stamp columns its table actually has in $actorStamps.
 */
trait StampsActor
{
    protected static function bootStampsActor(): void
    {
        static::creating(function ($model) {
            $actor = CmsAdmin::actor();
            if ($actor === null) {
                return;
            }
            foreach ($model->actorStamps ?? ['created_by', 'updated_by'] as $column) {
                if ($model->{$column} === null) {
                    $model->{$column} = $actor;
                }
            }
        });

        static::updating(function ($model) {
            $actor = CmsAdmin::actor();
            if ($actor !== null && in_array('updated_by', $model->actorStamps ?? ['created_by', 'updated_by'], true)) {
                $model->updated_by = $actor;
            }
        });
    }
}
