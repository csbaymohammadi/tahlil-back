<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_voter_contexts')) {
            Schema::create('platform_voter_contexts', function (Blueprint $table): void {
                $table->id();
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at')->index();
                $table->timestamp('created_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('platform_rating_aggregates')) {
            Schema::create('platform_rating_aggregates', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 32);
                $table->unsignedBigInteger('platform_id');
                $table->unsignedBigInteger('vote_sum')->default(0);
                $table->unsignedBigInteger('rating_count')->default(0);
                $table->unsignedBigInteger('rating_revision')->default(0);
                $table->unique(['kind', 'platform_id'], 'platform_rating_target_uq');
            });
        }
        if (! Schema::hasTable('platform_votes')) {
            Schema::create('platform_votes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('aggregate_id')->constrained('platform_rating_aggregates')->restrictOnDelete();
                $table->foreignId('voter_context_id')->constrained('platform_voter_contexts')->restrictOnDelete();
                $table->unsignedTinyInteger('value');
                $table->timestamps();
                $table->unique(['aggregate_id', 'voter_context_id'], 'platform_voter_vote_uq');
            });
        }
    }

    public function down(): void
    {
        // Rating history is retained when rolling back application code.
    }
};
