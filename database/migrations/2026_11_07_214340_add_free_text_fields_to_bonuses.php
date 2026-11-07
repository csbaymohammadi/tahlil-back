<?php

use App\Support\PlatformText;
use App\Support\PublicText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bonuses', 'display_fields')) {
            Schema::table('bonuses', function (Blueprint $table): void {
                $table->json('display_fields')->nullable();
                $table->longText('provider_search')->nullable();
                $table->longText('amount_search')->nullable();
                $table->longText('public_search')->nullable();
            });
        }
        if (! in_array(Schema::getColumnType('bonuses', 'cover_image'), ['text', 'longtext'], true)) {
            Schema::table('bonuses', function (Blueprint $table): void {
                $table->longText('cover_image')->change();
            });
        }
        DB::table('bonuses')->whereNull('display_fields')->orderBy('id')->chunkById(100, function ($bonuses): void {
            foreach ($bonuses as $bonus) {
                $campaign = DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->first();
                $terms = json_decode($campaign?->terms ?? '{}', true);
                $provider = json_decode($campaign?->provider ?? '{}', true);
                $linkedId = $campaign?->provider_id ?? $campaign?->exchange_id;
                $table = ['exchanges' => 'exchanges', 'brokers' => 'brokers', 'stock-brokers' => 'stock_brokerages',
                    'gold-platforms' => 'gold_platforms', 'prop-firms' => 'prop_firms'][$campaign?->provider_kind ?? 'exchanges'] ?? null;
                if ($linkedId && $table) {
                    $linkedName = DB::table($table)->where('id', $linkedId)->whereNull('deleted_at')->where('status', 'published')->value('title');
                }
                $fields = [
                    'provider_name' => $linkedName ?? ($provider['name'] ?? ''), 'display_status' => $campaign?->state ?? '',
                    'amount' => $campaign?->amount_display ?? '', 'start_date' => $campaign?->starts_at ?? '', 'end_date' => $campaign?->ends_at ?? '',
                    'short_description' => $bonus->description, 'identity_verification' => '',
                    'iranian_users' => '', 'minimum_deposit' => $terms['eligibility']['minimum_deposit'] ?? '',
                    'website_url' => $terms['redemption']['url'] ?? '',
                ];
                if (($terms['reward']['kind'] ?? null) !== null) {
                    $fields['amount'] = $this->legacyAmount($terms['reward']);
                }
                DB::table('bonuses')->where('id', $bonus->id)->update(['display_fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'public_search' => PublicText::normalize($fields['provider_name'].' '.$fields['amount'].' '.$fields['short_description'].' '.PublicText::plain($bonus->content, 2_097_152)), 'provider_search' => PlatformText::panel($fields['provider_name']), 'amount_search' => PlatformText::panel($fields['amount'])]);
                unset($linkedName);
            }
        });
        DB::table('bonus_campaigns')->update(['next_transition_at' => null]);
    }

    /** @param array<string, mixed> $reward */
    private function legacyAmount(array $reward): string
    {
        return match ($reward['kind']) {
            'fixed' => ($reward['qualifier'] === 'up_to' ? 'تا ' : '').($reward['amount'] ?? '').' '.($reward['currency'] ?? ''),
            'percentage' => ($reward['percentage'] ?? '').'٪'.(($reward['maximum_amount'] ?? null) === null ? ''
                : ' تا سقف '.$reward['maximum_amount'].' '.($reward['currency'] ?? '')),
            'custom' => $reward['custom_text'] ?? '',
            default => '',
        };
    }

    public function down(): void {}
};
