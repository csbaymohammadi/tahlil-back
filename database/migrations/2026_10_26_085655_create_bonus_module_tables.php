<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_normalized', 240)->unique('bonus_cat_name_uq');
            $table->string('key', 80)->nullable()->unique('bonus_cat_key_uq');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('bonuses', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('title_normalized', 600);
            $table->string('title_public_normalized', 600);
            $table->string('author_name_normalized', 600);
            $table->string('slug', 200)->unique('bonus_slug_uq');
            $table->text('description');
            $table->text('description_public_normalized');
            $table->string('cover_image', 2048)->default('');
            $table->longText('content');
            $table->unsignedSmallInteger('reading_minutes');
            $table->string('meta_description', 500)->default('');
            $table->foreignId('category_id')->nullable()->constrained('bonus_categories')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            foreach (['author_id', 'created_by', 'updated_by'] as $column) {
                $table->foreignId($column)->constrained('users')->restrictOnDelete();
            }
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'id'], 'bonus_created_idx');
            $table->index(['status', 'deleted_at', 'published_at', 'id'], 'bonus_public_idx');
            $table->index(['category_id', 'status', 'deleted_at'], 'bonus_category_state_idx');
            $table->index('author_id', 'bonus_author_idx');
        });
        Schema::create('bonus_slug_ownership', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique('bonus_owned_slug_uq');
            $table->foreignId('bonus_id')->constrained('bonuses')->restrictOnDelete();
            $table->boolean('was_public')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('bonus_campaigns', function (Blueprint $table): void {
            $table->foreignId('bonus_id')->primary()->constrained('bonuses')->restrictOnDelete();
            $table->foreignId('exchange_id')->nullable()->constrained('exchanges')->restrictOnDelete();
            $table->json('provider')->nullable();
            $table->string('amount_display', 120)->default('');
            $table->string('state', 20)->default('unspecified');
        });
        Schema::create('bonus_rating_aggregates', function (Blueprint $table): void {
            $table->foreignId('bonus_id')->primary()->constrained('bonuses')->restrictOnDelete();
            $table->unsignedBigInteger('vote_sum')->default(0);
            $table->unsignedBigInteger('rating_count')->default(0);
            $table->unsignedBigInteger('rating_revision')->default(0);
        });
        Schema::create('bonus_voter_contexts', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique('bonus_voter_hash_uq');
            $table->timestamp('expires_at')->index('bonus_voter_expiry_idx');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('bonus_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bonus_id')->constrained('bonuses')->restrictOnDelete();
            $table->foreignId('voter_context_id')->constrained('bonus_voter_contexts')->restrictOnDelete();
            $table->unsignedTinyInteger('value');
            $table->timestamps();
            $table->unique(['bonus_id', 'voter_context_id'], 'bonus_voter_vote_uq');
        });
        Schema::create('bonus_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bonus_id')->nullable()->constrained('bonuses')->restrictOnDelete();
            $table->string('event', 80);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['delivered_at', 'available_at', 'created_at'], 'bonus_outbox_delivery_idx');
        });
        Schema::create('bonus_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('event', 80);
            $table->string('request_id', 80);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_type', 'target_id'], 'bonus_audit_target_idx');
            $table->index('created_at', 'bonus_audit_created_idx');
        });
    }

    public function down(): void
    {
        foreach (['bonus_audit_events', 'bonus_outbox', 'bonus_votes', 'bonus_voter_contexts', 'bonus_rating_aggregates', 'bonus_campaigns', 'bonus_slug_ownership', 'bonuses', 'bonus_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
