<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('kind', 10)->nullable();
            $table->string('status', 20)->default('staging');
            $table->string('disk', 100);
            $table->string('storage_key')->unique();
            $table->string('canonical_name', 60)->nullable();
            $table->string('original_name', 180);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->double('duration_seconds')->nullable();
            $table->boolean('is_animated')->default(false);
            $table->timestamp('unreferenced_at')->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('media_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('consumer_type', 50);
            $table->unsignedBigInteger('consumer_id');
            $table->string('field', 80);
            $table->timestamps();
            $table->unique(['consumer_type', 'consumer_id', 'field', 'media_id'], 'media_reference_unique');
            $table->index(['consumer_type', 'consumer_id']);
        });
        Schema::create('media_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('checksum', 64);
            $table->string('purpose', 30);
            $table->foreignId('media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('avatar_media_id')->nullable()->constrained('media')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('avatar_media_id'));
        Schema::dropIfExists('media_uploads');
        Schema::dropIfExists('media_references');
        Schema::dropIfExists('media');
    }
};
