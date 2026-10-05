<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Content management for the PUBLIC website only. Nothing here references
 * orders, payments, wallets, vendor money or fulfilment.
 *
 * Actor columns (created_by / updated_by / published_by) hold "guard:id"
 * (e.g. "admin:3", "web:7"): Admin and User ids live in different tables and
 * can collide, so a bare integer would be ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 16)->default('public');      // public | bundled (ships inside public/)
            $table->string('path', 255)->unique();
            $table->string('filename', 120);
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 64);
            $table->unsignedInteger('size')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->string('created_by', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('kind', 12)->default('rich');         // home | about | rich
            $table->string('title', 160);
            $table->string('icon', 24)->nullable();              // hero glyph on rich pages
            $table->longText('body')->nullable();                // markdown, live
            $table->string('status', 12)->default('draft');      // draft | published
            $table->boolean('is_system')->default(false);        // fixed slug, cannot be archived
            $table->string('seo_title', 160)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('canonical_url', 255)->nullable();
            $table->string('og_title', 160)->nullable();
            $table->string('og_description', 320)->nullable();
            $table->foreignId('og_image_media_id')->nullable()->constrained('cms_media')->nullOnDelete();
            $table->boolean('robots_noindex')->default(false);
            $table->json('draft')->nullable();                   // unpublished edits layered over the live columns
            $table->timestamp('published_at')->nullable();
            $table->string('published_by', 40)->nullable();
            $table->string('created_by', 40)->nullable();
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'kind']);
        });

        Schema::create('cms_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->string('key', 40);
            $table->json('data')->nullable();                    // live
            $table->json('draft_data')->nullable();              // pending, promoted on publish
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'key']);
        });

        Schema::create('cms_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 16)->default('published');
            $table->json('snapshot');
            $table->string('created_by', 40)->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['page_id', 'version']);
        });

        Schema::create('cms_banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 32)->default('home_hero');
            $table->string('title', 160);                        // also the image alt text
            $table->string('link_url', 255)->nullable();
            $table->foreignId('image_media_id')->constrained('cms_media')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('created_by', 40)->nullable();
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();

            $table->index(['placement', 'is_active', 'sort_order']);
        });

        Schema::create('cms_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 12);                      // public | vendor
            $table->string('type', 12)->default('info');         // info | success | warning
            $table->string('title', 160);
            $table->string('message', 600)->nullable();
            $table->string('link_url', 255)->nullable();
            $table->string('link_text', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('created_by', 40)->nullable();
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();

            $table->index(['audience', 'is_active']);
        });

        Schema::create('cms_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60)->default('General');
            $table->string('question', 255);
            $table->text('answer');                              // markdown
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('created_by', 40)->nullable();
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'category', 'sort_order']);
        });

        Schema::create('cms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('cms_navigation_items', function (Blueprint $table) {
            $table->id();
            $table->string('location', 24);                      // header | footer_quick | footer_legal
            $table->string('label', 60);
            $table->string('url', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->string('updated_by', 40)->nullable();
            $table->timestamps();

            $table->index(['location', 'is_visible', 'sort_order']);
        });
    }

    public function down(): void
    {
        foreach (['cms_navigation_items', 'cms_settings', 'cms_faqs', 'cms_announcements', 'cms_banners', 'cms_revisions', 'cms_sections', 'cms_pages', 'cms_media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
