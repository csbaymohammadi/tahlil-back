<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $normalizeRole = static fn (string $name): string => mb_convert_case(trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $name))), MB_CASE_FOLD, 'UTF-8');
        foreach (['users' => 'email', 'roles' => 'name'] as $table => $field) {
            $seen = [];
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                $key = $table === 'roles' ? $normalizeRole($row->$field) : mb_strtolower(trim($row->$field));
                if (isset($seen[$key])) {
                    throw new RuntimeException('Normalization conflicts in '.$table.' between IDs '.$seen[$key].' and '.$row->id.'. Resolve before migrating.');
                }
                $seen[$key] = $row->id;
            }
        }
        Schema::create('panel_mutation_lock', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('panel_mutation_lock')->insert(['id' => 1, 'revision' => 0]);
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(1);
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('name_normalized', 240)->nullable()->unique();
            $table->unsignedBigInteger('version')->default(1);
            $table->softDeletes();
        });
        foreach (DB::table('users')->orderBy('id')->cursor() as $row) {
            DB::table('users')->where('id', $row->id)->update(['email' => mb_strtolower(trim($row->email))]);
        }
        foreach (DB::table('roles')->orderBy('id')->cursor() as $row) {
            DB::table('roles')->where('id', $row->id)->update(['name_normalized' => $normalizeRole($row->name)]);
        }
        Schema::table('panel_security_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('target_type', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('changed_fields')->nullable();
            $table->unsignedInteger('affected_users_count')->nullable();
            $table->string('purpose', 30)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('panel_security_events', function (Blueprint $table): void {
            $table->dropColumn(['actor_id', 'target_type', 'target_id', 'changed_fields', 'affected_users_count', 'purpose', 'size_bytes']);
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_name_normalized_unique');
            $table->dropColumn(['name_normalized', 'version', 'deleted_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::dropIfExists('panel_mutation_lock');
    }
};
