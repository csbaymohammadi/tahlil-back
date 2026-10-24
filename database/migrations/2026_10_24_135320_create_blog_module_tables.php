<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('name_normalized', 240)->unique();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('blog_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('title_normalized', 400);
            $table->string('slug', 200);
            $table->text('description')->default('');
            $table->string('cover_image', 2048)->default('');
            $table->longText('content');
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->restrictOnDelete();
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

        Schema::create('blog_slug_ownership', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->foreignId('article_id')->constrained('blog_articles')->restrictOnDelete();
            $table->boolean('was_public')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['article_id', 'was_public']);
        });

        Schema::create('blog_schedule_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('blog_articles')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->timestamp('due_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['article_id', 'generation']);
            $table->index(['processed_at', 'available_at', 'due_at']);
        });

        Schema::create('blog_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained('blog_articles')->restrictOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['delivered_at', 'available_at', 'created_at']);
        });

        Schema::create('blog_audit_events', function (Blueprint $table): void {
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

        Schema::table('users', function (Blueprint $table): void {
            $table->string('blog_name_normalized', 500)->default('');
        });
        foreach (DB::table('users')->select(['id', 'name'])->orderBy('id')->cursor() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'blog_name_normalized' => self::normalize((string) $user->name),
            ]);
        }

        Schema::create('blog_content_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('checksum', 64);
            $table->string('content_type', 20);
            $table->string('status', 20);
            $table->foreignId('article_id')->nullable()->constrained('blog_articles')->restrictOnDelete();
            $table->json('response')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('blog_import_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_id')->constrained('blog_content_imports')->restrictOnDelete();
            $table->string('storage_key')->unique();
            $table->string('original_filename', 180);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_import_reports');
        Schema::dropIfExists('blog_content_imports');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('blog_name_normalized'));
        Schema::dropIfExists('blog_audit_events');
        Schema::dropIfExists('blog_outbox');
        Schema::dropIfExists('blog_schedule_tasks');
        Schema::dropIfExists('blog_slug_ownership');
        Schema::dropIfExists('blog_articles');
        Schema::dropIfExists('blog_categories');
    }

    private static function normalize(string $value): string
    {
        $value = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }
};
