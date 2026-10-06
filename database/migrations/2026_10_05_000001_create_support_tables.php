<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor <-> Admin support chat. New tables only; no existing table is altered.
 *
 * support_conversations.vendor_id is intentionally NOT a foreign key: deleting a
 * vendor must neither be blocked by, nor silently cascade over, support history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->json('related_types')->nullable();
            $table->json('common_issues')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->foreignId('category_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->string('subject', 150);
            $table->string('status', 20);
            $table->string('client_token', 64)->nullable();

            $table->string('related_type', 40)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('related_label', 150)->nullable();

            $table->timestamp('first_vendor_message_at')->nullable();
            $table->timestamp('last_vendor_message_at')->nullable();
            $table->timestamp('last_admin_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();
            $table->string('last_message_sender', 10)->nullable();
            $table->unsignedBigInteger('last_vendor_message_id')->default(0);
            $table->unsignedBigInteger('last_admin_message_id')->default(0);
            $table->boolean('has_image')->default(false);
            $table->boolean('has_voice')->default(false);

            // Set only on the transition INTO waiting_admin; null otherwise.
            $table->timestamp('waiting_since')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by_guard', 10)->nullable();
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_guard', 10)->nullable();
            $table->unsignedBigInteger('closed_by_id')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'client_token'], 'support_conv_vendor_token_unique');
            $table->index(['status', 'waiting_since'], 'support_conv_queue_idx');
            $table->index(['status', 'last_message_at'], 'support_conv_status_activity_idx');
            $table->index(['vendor_id', 'last_message_at'], 'support_conv_vendor_activity_idx');
            $table->index('category_id');
            $table->index(['related_type', 'related_id'], 'support_conv_related_idx');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            // sender_type is the role (vendor|admin); sender_guard says which
            // identity table sender_id points into (vendor|admin|web).
            $table->string('sender_type', 10);
            $table->string('sender_guard', 10);
            $table->unsignedBigInteger('sender_id');
            $table->text('body')->nullable();
            $table->string('message_type', 10);
            $table->string('client_token', 64)->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'support_msg_conv_id_idx');
            $table->unique(['conversation_id', 'client_token'], 'support_msg_token_unique');
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('disk', 20);
            $table->string('storage_path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('message_id');
        });

        Schema::create('support_conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('actor_type', 10);
            $table->string('actor_guard', 10)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id'], 'support_events_conv_idx');
        });

        // Per-reader read position. reader_guard disambiguates the identity table
        // (vendor | admin | web) so an Admin #3 and a User #3 never share a row.
        Schema::create('support_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('reader_guard', 10);
            $table->unsignedBigInteger('reader_id');
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'reader_guard', 'reader_id'], 'support_reads_reader_unique');
            $table->index(['reader_guard', 'reader_id'], 'support_reads_reader_idx');
        });

        Schema::create('support_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_quick_replies');
        Schema::dropIfExists('support_reads');
        Schema::dropIfExists('support_conversation_events');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
        Schema::dropIfExists('support_categories');
    }
};
