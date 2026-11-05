<?php

use App\Support\PlatformText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonuses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reading_minutes')->nullable()->change();
        });
        Schema::table('bonus_campaigns', function (Blueprint $table): void {
            $table->string('provider_kind', 20)->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->json('terms')->nullable();
            $table->string('availability', 20)->nullable();
            $table->dateTime('starts_at', 6)->nullable();
            $table->dateTime('ends_at', 6)->nullable();
            $table->dateTime('next_transition_at', 6)->nullable()->index('bonus_campaigns_transition_idx');
            $table->text('provider_search')->nullable();
            $table->text('reward_search')->nullable();
            $table->index(['provider_kind', 'provider_id'], 'bonus_campaigns_provider_idx');
        });

        DB::table('bonus_campaigns')->select('bonus_id', 'provider', 'amount_display')->chunkById(200, function ($campaigns): void {
            foreach ($campaigns as $campaign) {
                $provider = json_decode($campaign->provider ?? '{}', true);
                DB::table('bonus_campaigns')->where('bonus_id', $campaign->bonus_id)->update([
                    'provider_search' => PlatformText::panel(implode(' ', array_filter([
                        $provider['name'] ?? null, $provider['english_name'] ?? null,
                    ], 'is_string'))),
                    'reward_search' => PlatformText::panel($campaign->amount_display),
                ]);
            }
        }, 'bonus_id');
    }

    public function down(): void
    {
        // Keep campaign data and nullable reading times when rolling back application code.
    }
};
