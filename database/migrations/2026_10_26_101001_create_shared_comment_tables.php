<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_comments', function (Blueprint $table): void {
            $table->id();
            $table->string('section', 32);
            $table->unsignedBigInteger('parent_id');
            $table->string('page_title_snapshot', 200);
            $table->string('name', 80);
            $table->text('email');
            $table->text('text');
            $table->string('visibility', 20)->default('visible');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['section', 'parent_id', 'visibility', 'deleted_at'], 'comment_parent_visible_idx');
            $table->index(['deleted_at', 'created_at', 'id'], 'comment_inbox_idx');
            $table->index(['section', 'deleted_at', 'created_at', 'id'], 'comment_section_inbox_idx');
        });
        Schema::create('comment_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->unique('comment_single_reply_uq')->constrained('site_comments')->restrictOnDelete();
            $table->text('text');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('author_name', 120);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('comment_idempotency', function (Blueprint $table): void {
            $table->uuid('key')->primary();
            $table->string('section', 32);
            $table->unsignedBigInteger('parent_id');
            $table->char('fingerprint', 64);
            $table->foreignId('comment_id')->constrained('site_comments')->restrictOnDelete();
            $table->timestamp('expires_at')->index('comment_idempotency_expiry_idx');
            $table->timestamp('created_at')->useCurrent();
        });
        foreach (['comment_audit_events', 'comment_outbox'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                if ($name === 'comment_audit_events') {
                    $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                    $table->string('target_type', 30)->default('comment');
                    $table->unsignedBigInteger('target_id');
                    $table->string('request_id', 80);
                    $table->json('details')->nullable();
                } else {
                    $table->foreignId('comment_id')->nullable()->constrained('site_comments')->restrictOnDelete();
                    $table->json('payload');
                    $table->unsignedInteger('attempts')->default(0);
                    $table->timestamp('available_at')->nullable();
                    $table->timestamp('delivered_at')->nullable();
                    $table->text('last_error')->nullable();
                    $table->index(['delivered_at', 'available_at'], 'comment_outbox_pending_idx');
                }
                $table->string('event', 80);
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['comment_outbox', 'comment_audit_events', 'comment_idempotency', 'comment_replies', 'site_comments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
