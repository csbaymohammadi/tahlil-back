<?php

namespace Tests\Feature;

use App\Models\Bonus;
use App\Models\BonusCampaign;
use App\Models\Broker;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\BonusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class BonusCampaignTest extends PanelTestCase
{
    use RefreshDatabase;

    private const ITEMS = '/api/v1/admin/bonuses/items';

    public function test_minimal_draft_and_publication_requirements(): void
    {
        $this->panel(User::factory()->panel()->create());
        $draft = $this->postJson(self::ITEMS, ['title' => 'Minimal', 'slug' => 'minimal'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.reading_minutes', null)
            ->assertJsonPath('data.category_id', [])->assertJsonPath('data.content', '')
            ->assertJsonPath('data.campaign.provider', null);
        $path = self::ITEMS.'/'.$draft->json('data.id');
        $this->patchJson($path, ['expected_version' => 1, 'campaign' => (object) []])->assertOk()->assertJsonPath('data.version', 1);
        $response = $this->patchJson($path, ['expected_version' => 1, 'status' => 'published'])->assertUnprocessable();
        foreach (['description', 'content', 'campaign.type', 'campaign.provider', 'campaign.reward.kind', 'campaign.redemption.url', 'campaign.source_url'] as $field) {
            $this->assertArrayHasKey($field, $response->json('error.fields'));
        }
        $this->patchJson($path, ['expected_version' => 1, 'status' => 'published', 'content' => '<p>&nbsp;</p>', 'description' => 'Summary',
            'campaign' => BonusFactory::campaignInput()])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['content']]]);
        $this->assertDatabaseCount('bonus_outbox', 0);
        $this->get($path)->assertOk()->assertJsonPath('data.version', 1);
        $this->get('/api/v1/bonuses/minimal')->assertNotFound();
    }

    public function test_snapshot_replacement_clears_draft_fields_and_legacy_bonus_remains_readable(): void
    {
        $this->panel(User::factory()->panel()->create());
        $id = $this->postJson(self::ITEMS, ['title' => 'Draft', 'slug' => 'draft', 'campaign' => BonusFactory::campaignInput()])
            ->assertCreated()->json('data.id');
        $this->patchJson(self::ITEMS.'/'.$id, ['expected_version' => 1, 'campaign' => (object) []])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.campaign.provider', null)
            ->assertJsonPath('data.campaign.source_url', '')->assertJsonPath('data.campaign.reward.amount', null);
        $legacy = Bonus::factory()->published()->create();
        DB::table('bonus_campaigns')->where('bonus_id', $legacy->id)->update(['state' => 'ended', 'amount_display' => 'Legacy reward']);
        $this->get('/api/v1/bonuses/'.$legacy->slug)->assertOk()->assertJsonPath('data.campaign.state', 'ended')
            ->assertJsonPath('data.campaign.amount_display', 'Legacy reward')->assertJsonPath('data.campaign.reward.amount', null);
        $this->patchJson(self::ITEMS.'/'.$legacy->id, ['expected_version' => 1, 'title' => 'Edit legacy'])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['campaign.source_url', 'campaign.redemption.url']]]);
        $this->patchJson(self::ITEMS.'/'.$legacy->id, ['expected_version' => 1, 'status' => 'draft'])->assertOk()
            ->assertJsonPath('data.campaign.state', 'ended');
    }

    #[DataProvider('rewards')]
    public function test_reward_variants_round_trip_with_consistent_summaries(array $reward, string $display): void
    {
        $this->panel(User::factory()->panel()->create());
        $campaign = array_replace(BonusFactory::campaignInput(), [
            'reward' => $reward, 'eligibility' => ['minimum_deposit' => '0.00', 'deposit_currency' => 'USDT', 'kyc_required' => false],
            'conditions' => ['reward_withdrawable' => false], 'redemption' => ['url' => 'https://example.test/claim', 'steps' => ['Read', 'Claim']],
            'reviewed_at' => '2026-10-01T00:00:00Z',
        ]);
        $id = $this->postJson(self::ITEMS, $this->published($campaign))->assertCreated()
            ->assertJsonPath('data.campaign.amount_display', $display)
            ->assertJsonPath('data.campaign.eligibility.kyc_required', false)
            ->assertJsonPath('data.campaign.eligibility.minimum_deposit', '0')
            ->assertJsonPath('data.campaign.conditions.reward_withdrawable', false)
            ->assertJsonPath('data.campaign.conditions.profit_withdrawable', null)->json('data.id');
        $path = self::ITEMS.'/'.$id;
        $this->patchJson($path, ['expected_version' => 1, 'campaign' => $campaign])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson($path, ['expected_version' => 1, 'description' => 'New summary'])->assertOk()
            ->assertJsonPath('data.campaign.reviewed_at', '2026-10-01T00:00:00.000000Z')->assertJsonPath('data.version', 2);
        $this->get(self::ITEMS.'?q=Sample%20provider')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get(self::ITEMS.'?q='.rawurlencode($display))->assertOk()->assertJsonPath('data.0.campaign.amount_display', $display);
        $summary = $this->get('/api/v1/bonuses')->assertOk()->assertJsonPath('data.0.campaign.amount_display', $display)->json('data.0.campaign');
        $this->assertArrayNotHasKey('redemption', $summary);
        $this->get('/api/v1/bonuses/campaign')->assertOk()->assertJsonPath('data.campaign.redemption.steps', ['Read', 'Claim']);
        $anchor = Bonus::factory()->published()->create();
        $this->get('/api/v1/bonuses/'.$anchor->slug.'/latest')->assertOk()->assertJsonPath('data.0.campaign', $summary);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->get('/api/v1/search?q='.rawurlencode($display))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.campaign', $summary);
    }

    public static function rewards(): array
    {
        return [
            'fixed' => [['kind' => 'fixed', 'amount' => '100.000', 'currency' => 'USDT'], '100 USDT'],
            'up to' => [['kind' => 'fixed', 'amount' => '999999999999999999.12345678', 'currency' => 'IRR', 'qualifier' => 'up_to'], 'تا 999999999999999999.12345678 IRR'],
            'percentage' => [['kind' => 'percentage', 'percentage' => '100.00'], '100٪'],
            'percentage with cap' => [['kind' => 'percentage', 'percentage' => '20', 'maximum_amount' => '100', 'currency' => 'USD'], '20٪ تا سقف 100 USD'],
            'custom' => [['kind' => 'custom', 'custom_text' => '۳۰ روز تخفیف کارمزد'], '۳۰ روز تخفیف کارمزد'],
        ];
    }

    public function test_typed_providers_with_the_same_id_remain_independent(): void
    {
        $this->panel(User::factory()->panel()->create());
        foreach (BonusCampaign::PROVIDERS as $kind => [$model]) {
            $provider = $model::factory()->published()->create(['id' => 31, 'title' => $kind.' provider']);
            $campaign = array_replace(BonusFactory::campaignInput(), ['provider' => ['kind' => $kind, 'id' => 31]]);
            $this->postJson(self::ITEMS, $this->published($campaign, $kind))->assertCreated()
                ->assertJsonPath('data.campaign.provider.kind', $kind)->assertJsonPath('data.campaign.provider.id', 31);
            $this->get('/api/v1/bonuses/'.$kind)->assertOk()
                ->assertJsonPath('data.campaign.provider.name', $kind.' provider')
                ->assertJsonPath('data.campaign.provider.href', '/'.$kind.'/'.$provider->slug)
                ->assertJsonPath('data.campaign.provider.exchange_id', $kind === 'exchanges' ? 31 : null);
        }
        $this->get(self::ITEMS.'?q=gold-platforms%20provider')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_provider_unpublication_and_deletion_preserve_only_public_snapshot(): void
    {
        $this->panel(User::factory()->panel()->create());
        $provider = Broker::factory()->create(['title' => 'Private provider']);
        $campaign = array_replace(BonusFactory::campaignInput(), ['provider' => ['kind' => 'brokers', 'id' => $provider->id]]);
        $this->postJson(self::ITEMS, $this->published($campaign))->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['campaign.provider.id']]]);
        $this->postJson(self::ITEMS, ['title' => 'Draft', 'slug' => 'draft', 'campaign' => $campaign])->assertCreated()
            ->assertJsonPath('data.campaign.provider.name', 'Private provider');
        $this->patchJson('/api/v1/admin/brokers/items/'.$provider->id, ['expected_version' => 1, 'title' => 'Public brand', 'status' => 'published'])->assertOk();
        $this->postJson(self::ITEMS, $this->published($campaign))->assertCreated();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->patchJson('/api/v1/admin/brokers/items/'.$provider->id, ['expected_version' => 2, 'title' => 'Secret rebrand', 'status' => 'draft'])->assertOk();
        $this->get('/api/v1/bonuses/campaign')->assertOk()->assertJsonPath('data.campaign.provider.name', 'Public brand')
            ->assertJsonPath('data.campaign.provider.id', null)->assertJsonPath('data.campaign.provider.href', null)->assertDontSee('Secret rebrand');
        $this->get('/api/v1/search?q=Public%20brand')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.campaign.provider.name', 'Public brand');
        $this->get('/api/v1/search?q=Secret%20rebrand')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('bonus_outbox', ['event' => 'bonus_provider_changed']);
        $this->delete('/api/v1/admin/brokers/items/'.$provider->id.'?expected_version=3')->assertNoContent();
        $this->get('/api/v1/bonuses/campaign')->assertOk()->assertJsonPath('data.campaign.provider.name', 'Public brand');
    }

    public function test_clock_boundaries_change_home_revision_without_staling_search(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T00:00:00Z'));
        $this->panel(User::factory()->panel()->create());
        $campaign = array_replace(BonusFactory::campaignInput(), [
            'availability' => 'automatic', 'starts_at' => '2026-11-05T00:01:00Z', 'ends_at' => '2026-11-05T00:02:00Z',
        ]);
        $id = $this->postJson(self::ITEMS, $this->published($campaign))->assertCreated()->assertJsonPath('data.campaign.state', 'upcoming')->json('data.id');
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][3]['selected_ids'] = [$id];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $upcoming = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.3.items.0.campaign.state', 'upcoming');
        $this->travel(1)->minute();
        $active = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.3.items.0.campaign.state', 'active');
        $this->assertNotSame($upcoming->headers->get('ETag'), $active->headers->get('ETag'));
        $this->get('/api/v1/search?q=Summary')->assertOk()->assertJsonPath('data.0.campaign.state', 'active');
        $this->artisan('bonuses:advance-campaigns')->assertSuccessful();
        $this->artisan('bonuses:advance-campaigns')->assertSuccessful();
        $this->assertSame(1, DB::table('bonus_outbox')->where('event', 'bonus_campaign_state_changed')->count());
        $this->travel(1)->minute();
        $this->get('/api/v1/bonuses/campaign')->assertOk()->assertJsonPath('data.campaign.state', 'ended');
        $this->get('/api/v1/search?q=Summary')->assertOk()->assertJsonPath('data.0.campaign.state', 'ended');
        $this->artisan('bonuses:advance-campaigns')->assertSuccessful();
        $this->assertSame(2, DB::table('bonus_outbox')->where('event', 'bonus_campaign_state_changed')->count());
        $this->assertDatabaseHas('bonuses', ['id' => $id, 'version' => 1]);
    }

    public function test_rejected_campaigns_and_logo_failures_leave_no_partial_changes(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $campaign = BonusFactory::campaignInput();
        foreach ([
            'campaign.state' => ['state' => 'active'],
            'campaign.provider.name' => ['provider' => ['kind' => 'brokers', 'id' => 1, 'name' => 'Override']],
            'campaign.reward.amount' => ['reward' => ['kind' => 'fixed', 'amount' => 10, 'currency' => 'USD']],
            'campaign.reward.percentage' => ['reward' => ['kind' => 'percentage', 'percentage' => '100.00000001']],
            'campaign.eligibility.minimum_deposit' => ['type' => 'no_deposit', 'eligibility' => ['minimum_deposit' => '1', 'deposit_currency' => 'USD']],
            'campaign.redemption.url' => ['redemption' => ['url' => 'https://user:pass@example.test/']],
            'campaign.ends_at' => ['starts_at' => '2026-11-06T00:00:00Z', 'ends_at' => '2026-11-05T00:00:00Z'],
            'campaign.source_url' => ['availability' => 'ended', 'source_url' => ''],
            'campaign.redemption.steps' => ['redemption' => ['url' => 'https://example.test', 'steps' => (object) []]],
        ] as $field => $overrides) {
            $this->postJson(self::ITEMS, $this->published(array_replace($campaign, $overrides)))->assertUnprocessable()
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }
        $id = $this->postJson(self::ITEMS, $this->published($campaign))->assertCreated()->json('data.id');
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $campaign['provider']['logo'] = $media->canonicalUrl();
        $this->patchJson(self::ITEMS.'/'.$id, ['expected_version' => 2, 'campaign' => $campaign])->assertConflict();
        $this->assertDatabaseCount('media_references', 0);
        $campaign['provider']['logo'] = '/media/999999/missing.png';
        $this->patchJson(self::ITEMS.'/'.$id, ['expected_version' => 1, 'campaign' => $campaign])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['campaign.provider.logo']]]);
        $this->assertDatabaseHas('bonuses', ['id' => $id, 'version' => 1]);
        $this->assertDatabaseCount('bonus_outbox', 1);
        $this->assertDatabaseCount('media_references', 0);
        $campaign['provider']['logo'] = $media->canonicalUrl();
        $this->patchJson(self::ITEMS.'/'.$id, ['expected_version' => 1, 'campaign' => $campaign])->assertOk();
        $this->assertDatabaseHas('media_references', ['consumer_type' => 'bonus', 'consumer_id' => $id, 'field' => 'provider_logo', 'media_id' => $media->id]);
        $this->get('/api/v1/bonuses/campaign')->assertOk()->assertJsonPath('data.campaign.provider.logo', $media->canonicalUrl());
    }

    /** @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function published(array $campaign, string $slug = 'campaign'): array
    {
        return ['title' => 'Campaign', 'slug' => $slug, 'description' => 'Summary', 'content' => '<p>Terms</p>',
            'status' => 'published', 'campaign' => $campaign];
    }
}
