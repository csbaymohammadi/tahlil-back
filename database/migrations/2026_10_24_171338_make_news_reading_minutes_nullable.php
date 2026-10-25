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
        Schema::table('news_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reading_minutes')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('news_items')->whereNull('reading_minutes')->update(['reading_minutes' => 0]);

        Schema::table('news_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reading_minutes')->nullable(false)->change();
        });
    }
};
