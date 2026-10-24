<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 120)->nullable();
            $table->string('username_normalized', 120)->nullable()->unique();
            $table->boolean('is_active')->default(false);
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('security_version')->default(1);
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
        Schema::create('panel_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('security_version')->nullable();
            $table->text('payload');
            $table->timestamp('last_seen_at');
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('idle_expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoke_reason', 50)->nullable();
            $table->timestamps();
        });
        Schema::create('panel_security_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 50);
            $table->string('request_id', 64);
            $table->string('outcome', 20);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->uuid('session_id')->nullable();
            $table->string('reason', 50)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_security_events');
        Schema::dropIfExists('panel_sessions');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['username', 'username_normalized', 'is_active', 'security_version', 'password_changed_at', 'last_login_at', 'deleted_at']);
        });
        Schema::dropIfExists('roles');
    }
};
