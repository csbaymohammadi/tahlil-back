<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_normalized', 240)->unique();
            $table->string('public_name_normalized', 240);
            $table->string('key', 100)->nullable()->unique();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('exchanges', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('title_normalized', 400);
            $table->string('title_public_normalized', 400);
            $table->string('english_name', 200);
            $table->string('english_name_normalized', 400);
            $table->string('english_public_normalized', 400);
            $table->string('slug', 200)->unique();
            $table->text('description')->default('');
            $table->text('description_normalized');
            $table->text('description_public_normalized');
            $table->string('logo', 2048);
            $table->string('website_url', 2048);
            $table->char('founded_year', 4)->default('');
            $table->string('platform_type', 1500)->default('');
            $table->string('markets', 1500)->default('');
            $table->string('access', 1500)->default('');
            $table->string('support', 1500)->default('');
            $table->json('specifications');
            $table->longText('content');
            $table->string('meta_description', 500)->default('');
            $table->foreignId('category_id')->nullable()->constrained('exchange_categories')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->binary('title_sort_key')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'id']);
            $table->index(['category_id', 'status', 'deleted_at']);
            $table->index(['status', 'published_at', 'id']);
            $table->index(['title_normalized', 'created_at']);
            $table->index(['english_name_normalized', 'created_at']);
        });

        Schema::create('exchange_slug_ownership', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->foreignId('exchange_id')->constrained('exchanges')->restrictOnDelete();
            $table->boolean('was_public')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['exchange_id', 'was_public']);
        });

        Schema::create('exchange_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exchange_id')->nullable()->constrained('exchanges')->restrictOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['delivered_at', 'available_at', 'created_at']);
        });

        Schema::create('exchange_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('event', 60);
            $table->string('request_id', 80);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_audit_events');
        Schema::dropIfExists('exchange_outbox');
        Schema::dropIfExists('exchange_slug_ownership');
        Schema::dropIfExists('exchanges');
        Schema::dropIfExists('exchange_categories');
    }
};
