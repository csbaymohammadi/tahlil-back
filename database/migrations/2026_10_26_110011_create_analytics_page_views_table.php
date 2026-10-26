<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_page_views', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('path', 2048);
            $table->string('page_type', 40);
            $table->unsignedBigInteger('content_id')->nullable();
            $table->string('device', 24);
            $table->timestamp('viewed_at', 6);
            $table->index(['viewed_at', 'device']);
            $table->index(['page_type', 'content_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_page_views');
    }
};
