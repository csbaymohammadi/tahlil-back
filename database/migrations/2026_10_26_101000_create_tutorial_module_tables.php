<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_normalized', 240)->unique('tutorial_cat_name_uq');
            $table->string('public_name_normalized', 240);
            $table->string('key', 80)->nullable()->unique('tutorial_cat_key_uq');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('tutorials', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('title_normalized', 600);
            $table->string('title_public_normalized', 600);
            $table->text('description');
            $table->text('description_public_normalized');
            $this->identity($table, 'slug', 200, true);
            $table->unique('slug', 'tutorial_slug_uq');
            $table->string('youtube_url', 2048);
            $this->identity($table, 'youtube_video_id', 11);
            $table->string('aparat_url', 2048)->default('');
            $this->identity($table, 'aparat_video_id', 32, true);
            $table->string('cover_image', 2048);
            $table->foreignId('category_id')->nullable()->constrained('tutorial_categories')->restrictOnDelete();
            foreach (['created_by', 'updated_by'] as $column) {
                $table->foreignId($column)->constrained('users')->restrictOnDelete();
            }
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'id'], 'tutorial_created_idx');
            $table->index(['deleted_at', 'published_at', 'id'], 'tutorial_public_idx');
            $table->index(['category_id', 'deleted_at'], 'tutorial_category_idx');
        });
        Schema::create('tutorial_slug_ownership', function (Blueprint $table): void {
            $table->id();
            $this->identity($table, 'slug', 200);
            $table->unique('slug', 'tutorial_owned_slug_uq');
            $table->foreignId('tutorial_id')->constrained('tutorials')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('tutorial_youtube_claims', function (Blueprint $table): void {
            $this->identity($table, 'video_id', 11);
            $table->primary('video_id');
            $table->foreignId('tutorial_id')->unique('tutorial_claim_owner_uq')->constrained('tutorials')->restrictOnDelete();
        });
        Schema::create('tutorial_durations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tutorial_id')->constrained('tutorials')->restrictOnDelete();
            $table->string('provider', 10);
            $this->identity($table, 'video_id', 32);
            $table->unsignedBigInteger('seconds');
            $table->timestamps();
            $table->unique(['tutorial_id', 'provider', 'video_id'], 'tutorial_duration_source_uq');
        });
        foreach (['tutorial_audit_events', 'tutorial_outbox'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                if ($name === 'tutorial_audit_events') {
                    $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                    $table->string('target_type', 30);
                    $table->unsignedBigInteger('target_id');
                    $table->string('request_id', 80);
                    $table->json('details')->nullable();
                } else {
                    $table->foreignId('tutorial_id')->nullable()->constrained('tutorials')->restrictOnDelete();
                    $table->json('payload');
                    $table->unsignedInteger('attempts')->default(0);
                    $table->timestamp('available_at')->nullable();
                    $table->timestamp('delivered_at')->nullable();
                    $table->text('last_error')->nullable();
                    $table->index(['delivered_at', 'available_at'], 'tutorial_outbox_pending_idx');
                }
                $table->string('event', 80);
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    private function identity(Blueprint $table, string $name, int $length, bool $nullable = false): void
    {
        $column = $table->string($name, $length)->nullable($nullable);
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }
    }

    public function down(): void
    {
        foreach (['tutorial_outbox', 'tutorial_audit_events', 'tutorial_durations', 'tutorial_youtube_claims', 'tutorial_slug_ownership', 'tutorials', 'tutorial_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
