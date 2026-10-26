<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_content_entries', function (Blueprint $table): void {
            $table->string('type', 32);
            $table->unsignedBigInteger('record_id');
            $table->string('title');
            $table->string('english_name')->default('');
            $table->string('slug');
            $table->string('path', 1024);
            $table->text('description');
            $table->text('image');
            $table->text('title_normalized');
            $table->text('english_normalized');
            $table->text('description_normalized');
            $table->text('category_normalized');
            $table->text('provider_normalized');
            $table->text('provider_english_normalized');
            $table->text('amount_normalized');
            $table->char('source_stamp', 64);
            $table->char('content_fingerprint', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_modified_at', 6)->nullable();
            $table->primary(['type', 'record_id']);
            $table->index(['published_at', 'type', 'record_id'], 'public_content_publication_order');
        });
        Schema::create('public_content_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('ready')->default(false);
        });
        DB::table('public_content_state')->insert(['id' => 1, 'ready' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('public_content_entries');
        Schema::dropIfExists('public_content_state');
    }
};
