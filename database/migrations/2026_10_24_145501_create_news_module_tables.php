<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_normalized', 240)->unique();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('news_items', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('title_normalized', 400);
            $table->string('slug', 200);
            $table->text('description')->default('');
            $table->string('cover_image', 2048)->default('');
            $table->longText('content');
            $table->foreignId('category_id')->nullable()->constrained('news_categories')->restrictOnDelete();
            $table->unsignedSmallInteger('reading_minutes');
            $table->string('meta_description', 500)->default('');
            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedInteger('schedule_generation')->default(0);
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('author_name_normalized', 500)->default('');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'id']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['author_name_normalized', 'created_at']);
        });

        Schema::create('news_slug_ownership', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->foreignId('news_item_id')->constrained('news_items')->restrictOnDelete();
            $table->boolean('was_public')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['news_item_id', 'was_public']);
        });

        Schema::create('news_schedule_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->timestamp('due_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['news_item_id', 'generation']);
            $table->index(['processed_at', 'available_at', 'due_at']);
        });

        Schema::create('news_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_item_id')->nullable()->constrained('news_items')->restrictOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['delivered_at', 'available_at', 'created_at']);
        });

        Schema::create('news_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('event', 60);
            $table->string('request_id', 80);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        Schema::table('blog_content_imports', function (Blueprint $table): void {
            $table->foreignId('news_item_id')->nullable()->after('article_id')->constrained('news_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blog_content_imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('news_item_id');
        });
        Schema::dropIfExists('news_audit_events');
        Schema::dropIfExists('news_outbox');
        Schema::dropIfExists('news_schedule_tasks');
        Schema::dropIfExists('news_slug_ownership');
        Schema::dropIfExists('news_items');
        Schema::dropIfExists('news_categories');
    }
};
