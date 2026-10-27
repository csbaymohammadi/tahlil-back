<?php

namespace Tests\Feature;

use App\Bonuses\BonusRatingContexts;
use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\PropFirm;
use App\Models\SiteComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PublicGoldPropBonusTest extends PanelTestCase
{
    use RefreshDatabase;

    public static function directories(): array
    {
        return [
            ['gold-platforms', GoldPlatform::class, 'gold_platform_slug_ownership', 'gold_platform_id', 'gold-platform', 'GOLD_PLATFORM_NOT_FOUND', 24],
            ['prop-firms', PropFirm::class, 'prop_firm_slug_ownership', 'prop_firm_id', 'prop-firm', 'PROP_FIRM_NOT_FOUND', 23],
        ];
    }

    #[DataProvider('directories')]
    public function test_public_directory_contract_search_alias_and_visibility(string $type, string $class, string $table, string $foreign, string $prefix, string $notFound, int $specifications): void
    {
        $record = $class::factory()->published()->create(['title' => 'كارى موسى', 'english_name' => 'Literal 100%_!', 'content' => '<h1>Overview</h1><h2>Overview</h2><p>Review</p>', 'created_at' => now()->subDay()]);
        $other = $class::factory()->published()->create();
        $class::factory()->create();
        SiteComment::factory()->create(['section' => $type, 'parent_id' => $record->id]);
        $this->get('/api/v1/'.$type.'?q='.urlencode('کاری موسی'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $record->id)->assertJsonMissingPath('facets');
        $this->get('/api/v1/'.$type.'?q=%25_')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/'.$type.'?category=x')->assertUnprocessable();
        $this->get('/api/v1/'.$type.'?page=999')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 2);
        $detail = $this->get('/api/v1/'.$type.'/'.$record->slug)->assertOk()->assertJsonPath('data.kind', $type)->assertJsonPath('data.comment_count', 1)->assertJsonPath('data.rating_average', null)->assertJsonPath('data.rating_count', 0)->assertJsonMissingPath('data.author');
        $this->assertCount($specifications, $detail->json('data.specifications'));
        $this->assertSame([$prefix.'-heading-1', $prefix.'-heading-2'], array_column($detail->json('data.outline'), 'id'));
        $this->assertStringNotContainsString('<h1', $detail->json('data.content'));
        $this->get('/api/v1/'.$type.'/'.$record->slug.'/others')->assertOk()->assertJsonPath('data.0.id', $other->id);
        DB::table($table)->insert(['slug' => 'old-name', $foreign => $record->id, 'was_public' => true, 'created_at' => now()]);
        $this->get('/api/v1/'.$type.'/old-name/others')->assertStatus(301)->assertHeader('Location', '/api/v1/'.$type.'/'.$record->slug.'/others');
        $record->forceFill(['status' => 'draft'])->save();
        $this->get('/api/v1/'.$type.'/old-name')->assertNotFound()->assertJsonPath('error.code', $notFound);
    }

    public function test_bonus_archive_facets_detail_latest_alias_and_private_rating_contract(): void
    {
        $category = BonusCategory::factory()->create(['name' => 'Bonus category']);
        $empty = BonusCategory::factory()->create();
        $bonus = Bonus::factory()->published()->create(['slug' => '123', 'title' => 'پاداش موسى 100%_', 'category_id' => $category->id, 'description' => '', 'content' => '<h1>Conditions</h1><p>Public terms</p>', 'published_at' => now()->subDay()]);
        $other = Bonus::factory()->published()->create(['title' => 'Other bonus', 'published_at' => now()]);
        Bonus::factory()->create();
        SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id]);
        $list = $this->get('/api/v1/bonuses?q='.urlencode('پاداش موسی').'&category='.$category->key)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('facets.all_count', 1)->assertJsonPath('data.0.id', $bonus->id)->assertJsonPath('data.0.rating_revision', 0)->assertJsonMissingPath('data.0.my_rating');
        $this->assertSame(0, collect($list->json('facets.categories'))->firstWhere('id', $empty->id)['count']);
        $this->get('/api/v1/bonuses?q=%25_')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/bonuses?category='.$category->key.'&category=bad')->assertOk()->assertJsonPath('filters.category', null)->assertJsonPath('meta.total', 2);
        $this->get('/api/v1/bonuses?sort=default')->assertUnprocessable();
        $this->get('/api/v1/bonuses?page=999')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 2);
        $detail = $this->get('/api/v1/bonuses/123')->assertOk()->assertJsonPath('data.id', $bonus->id)->assertJsonPath('data.comment_count', 1)->assertJsonPath('data.cover_image', '')->assertJsonPath('data.reading_minutes', 5)->assertJsonMissingPath('data.my_rating');
        $this->assertStringNotContainsString('<h1', $detail->json('data.content'));
        $this->get('/api/v1/bonuses/123/latest')->assertOk()->assertJsonPath('data.0.id', $other->id);
        $rating = $this->get('/api/v1/bonuses/'.$bonus->id.'/rating')->assertOk()->assertJsonPath('data.rating_revision', 0)->assertJsonStructure(['data' => ['csrf_token']]);
        $this->assertStringContainsString('no-store', $rating->headers->get('Cache-Control'));
        $this->assertContains('Cookie', $rating->getVary());
        DB::table('bonus_slug_ownership')->insert(['slug' => 'old-bonus', 'bonus_id' => $bonus->id, 'was_public' => true, 'created_at' => now()]);
        $this->get('/api/v1/bonuses/old-bonus/latest')->assertStatus(301)->assertHeader('Location', '/api/v1/bonuses/123/latest');
        $bonus->forceFill(['status' => 'draft'])->save();
        $this->get('/api/v1/bonuses/old-bonus')->assertNotFound()->assertJsonPath('error.code', 'BONUS_NOT_FOUND');
    }

    public function test_bonus_provider_visibility_media_and_fieldwise_search(): void
    {
        $exchange = Exchange::factory()->published()->create(['title' => 'Public Provider', 'english_name' => 'Sample Network']);
        $bonus = Bonus::factory()->published()->create(['title' => 'Unique', 'description' => 'alpha', 'cover_image' => '']);
        DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->update(['exchange_id' => $exchange->id, 'amount_display' => '100 USDT']);
        $this->get('/api/v1/bonuses?q='.urlencode('public provider'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.campaign.provider.exchange_id', $exchange->id);
        $this->get('/api/v1/bonuses?q='.urlencode('alpha public'))->assertOk()->assertJsonPath('meta.total', 0);
        $exchange->forceFill(['status' => 'draft'])->save();
        $this->get('/api/v1/bonuses/'.$bonus->slug)->assertOk()->assertJsonPath('data.campaign.provider', null)->assertDontSee('Public Provider');
        $this->get('/api/v1/bonuses?q='.urlencode('public provider'))->assertOk()->assertJsonPath('meta.total', 0);
        $media = Media::factory()->create(['purpose' => 'bonus']);
        $bonus->forceFill(['cover_image' => $media->canonicalUrl()])->save();
        $this->get('/api/v1/bonuses/'.$bonus->slug)->assertOk()->assertJsonPath('data.cover_image', '');
    }

    public function test_rating_changes_refresh_public_summary_and_homepage_etag(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][3]['selected_ids'] = [$bonus->id];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $home = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.3.items.0.rating_revision', 0);
        $etag = $home->headers->get('ETag');
        $rating = $this->get('/api/v1/bonuses/'.$bonus->id.'/rating')->assertOk();
        $this->withCredentials()->withUnencryptedCookie(BonusRatingContexts::COOKIE, $rating->getCookie(BonusRatingContexts::COOKIE, false)->getValue())->withHeader('X-CSRF-Token', $rating->json('data.csrf_token'));
        $this->putJson('/api/v1/bonuses/'.$bonus->id.'/rating', ['value' => 5])->assertOk()->assertJsonPath('data.rating_revision', 1);
        $this->get('/api/v1/bonuses')->assertOk()->assertJsonPath('data.0.rating', 5)->assertJsonPath('data.0.rating_revision', 1);
        $changed = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.3.items.0.rating_revision', 1);
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $this->putJson('/api/v1/bonuses/'.$bonus->id.'/rating', ['value' => 5])->assertOk()->assertJsonPath('data.rating_revision', 1);
        $this->get('/api/v1/page-settings')->assertHeader('ETag', $changed->headers->get('ETag'));
    }

    public function test_bonus_missing_aggregate_and_unsafe_content_fail_closed(): void
    {
        $bonus = Bonus::factory()->published()->create();
        DB::table('bonus_rating_aggregates')->where('bonus_id', $bonus->id)->delete();
        $this->get('/api/v1/bonuses')->assertServiceUnavailable()->assertJsonPath('error.code', 'BONUSES_UNAVAILABLE');
        $this->get('/api/v1/bonuses/'.$bonus->slug)->assertServiceUnavailable()->assertJsonPath('error.code', 'BONUSES_UNAVAILABLE');
        DB::table('bonus_rating_aggregates')->insert(['bonus_id' => $bonus->id]);
        $bonus->forceFill(['content' => '<script>alert(1)</script>'])->save();
        $this->get('/api/v1/bonuses/'.$bonus->slug)->assertServiceUnavailable()->assertJsonPath('error.code', 'BONUSES_UNAVAILABLE');
    }
}
