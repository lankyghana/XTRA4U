<?php

use App\Support\Cms\CmsDefaults;
use Illuminate\Database\Migrations\Migration;

/*
 * Copies the previously hardcoded public-site content into the CMS tables so
 * the live site is unchanged until an admin edits something. Idempotent; the
 * deployment's `php artisan migrate --force` is all that is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        CmsDefaults::install();
    }

    public function down(): void
    {
        // Content is intentionally preserved; dropping the tables is the previous migration's down().
    }
};
