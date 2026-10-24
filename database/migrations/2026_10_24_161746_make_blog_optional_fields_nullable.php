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
        Schema::table('blog_articles', function (Blueprint $table): void {
            $table->mediumText('description')->default('')->change();
            $table->mediumText('cover_image')->default('')->change();
            $table->unsignedSmallInteger('reading_minutes')->nullable()->default(null)->change();
            $table->mediumText('meta_description')->default('')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasLongOptionalText = DB::table('blog_articles')
            ->whereRaw('length(description) > ?', [2000])
            ->orWhereRaw('length(cover_image) > ?', [2048])
            ->orWhereRaw('length(meta_description) > ?', [500])
            ->exists();
        if ($hasLongOptionalText) {
            throw new RuntimeException('Cannot roll back optional article fields without truncating saved data.');
        }

        DB::table('blog_articles')->whereNull('reading_minutes')->update(['reading_minutes' => 0]);
        Schema::table('blog_articles', function (Blueprint $table): void {
            $table->text('description')->default('')->change();
            $table->string('cover_image', 2048)->default('')->change();
            $table->unsignedSmallInteger('reading_minutes')->default(0)->change();
            $table->string('meta_description', 500)->default('')->change();
        });
    }
};
