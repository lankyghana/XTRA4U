<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Records THAT an export happened (who/when/filters/counts) - never the exported rows themselves.
        Schema::create('admin_export_audits', function (Blueprint $table) {
            $table->id();
            $table->string('export_type', 50)->index();
            $table->string('actor_guard', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('format', 10);
            $table->json('filters');
            $table->json('fields')->nullable();
            $table->unsignedInteger('requested_limit')->nullable();
            $table->unsignedInteger('matching_count')->default(0);
            $table->unsignedInteger('exported_count')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            $table->unsignedInteger('duplicates_removed')->default(0);
            $table->boolean('completed')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['actor_guard', 'actor_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_export_audits');
    }
};
