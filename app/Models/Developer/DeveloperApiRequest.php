<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

/** One row per API call. Metadata only — never headers, bodies or secrets. */
class DeveloperApiRequest extends Model
{
    public $timestamps = false;

    protected $table = 'developer_api_requests';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
