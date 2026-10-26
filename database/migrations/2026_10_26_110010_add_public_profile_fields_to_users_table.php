<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('linkedin_url', 2048)->default('');
            $table->text('bio')->default('');
        });
        Schema::table('panel_security_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('record_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('panel_security_events', fn (Blueprint $table) => $table->dropColumn('record_version'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['linkedin_url', 'bio']));
    }
};
