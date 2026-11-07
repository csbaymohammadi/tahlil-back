<?php

namespace Tests\Feature;

use App\Models\Bonus;
use App\Models\Exchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BonusCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_campaign_migration_preserves_ids_slugs_categories_versions_and_votes(): void
    {
        $exchange = Exchange::factory()->published()->create(['title' => 'Public provider']);
        $bonus = Bonus::factory()->published()->create(['slug' => 'old-offer', 'description' => 'Old description']);
        DB::table('bonuses')->where('id', $bonus->id)->update(['display_fields' => null]);
        DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->update([
            'exchange_id' => $exchange->id, 'amount_display' => 'Old amount', 'state' => 'ended',
        ]);
        DB::table('bonus_rating_aggregates')->where('bonus_id', $bonus->id)->update(['vote_sum' => 5, 'rating_count' => 1, 'rating_revision' => 1]);
        $migration = require database_path('migrations/2026_11_07_214340_add_free_text_fields_to_bonuses.php');
        $migration->up();
        $migration->up();
        $bonus->refresh();
        $this->assertSame('Public provider', $bonus->display_fields['provider_name']);
        $this->assertSame('Old amount', $bonus->display_fields['amount']);
        $this->assertSame('ended', $bonus->display_fields['display_status']);
        $this->assertSame('old-offer', $bonus->slug);
        $this->assertSame(1, $bonus->version);
        $this->assertSame(1, $bonus->ratingAggregate->rating_count);
        $this->get('/api/v1/bonuses/old-offer')->assertOk()->assertJsonPath('data.rating', 5);
    }
}
