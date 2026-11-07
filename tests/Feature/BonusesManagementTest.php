<?php

namespace Tests\Feature;

use App\Actions\Bonuses\SubmitBonusRating;
use App\Bonuses\BonusRatingContexts;
use App\Bonuses\ResolveBonusArchive;
use App\Bonuses\ResolveBonusSelections;
use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Models\User;
use Database\Seeders\BonusCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PanelTestCase;

class BonusesManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/bonuses';

    public function test_admin_search_uses_current_normalized_author_name_and_paginates_before_returning(): void
    {
        $author = User::factory()->panel()->create(['name' => 'كاتب يكي']);
        $this->panel($author);
        $this->postJson(self::BASE.'/items', $this->payload())->assertCreated();
        Bonus::factory()->count(10)->create();
        $this->get(self::BASE.'/items')->assertOk()->assertJsonPath('meta.total', 11)->assertJsonPath('meta.total_pages', 2)->assertJsonCount(10, 'data');
        $this->get(self::BASE.'/items?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->get(self::BASE.'/items?q='.urlencode('کاتب یکی'))->assertOk()->assertJsonPath('meta.total', 1);
        $author->name = 'New Author';
        $author->save();
        $this->get(self::BASE.'/items?q=new%20author')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get(self::BASE.'/items?q='.urlencode('کاتب یکی'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->get(self::BASE.'/items?page=99')->assertOk()->assertJsonPath('meta.total', 11)->assertJsonCount(0, 'data');
        $this->get(self::BASE.'/items?per_page=9')->assertUnprocessable();
        $this->get(self::BASE.'/items?provider=1')->assertUnprocessable();
    }

    public function test_category_merge_and_delete_preserve_campaign_and_votes_and_reject_stale_versions(): void
    {
        $this->panel(User::factory()->panel()->create());
        $source = BonusCategory::factory()->create();
        $target = BonusCategory::factory()->create();
        $draft = Bonus::factory()->create(['category_id' => $source->id]);
        $live = Bonus::factory()->published()->create(['category_id' => $source->id]);
        $deleted = Bonus::factory()->create(['category_id' => $source->id]);
        $deleted->delete();
        DB::table('bonus_campaigns')->where('bonus_id', $live->id)->update(['amount_display' => 'USD 50 conditional', 'state' => 'ended']);
        foreach ([4, 5] as $value) {
            $context = app(BonusRatingContexts::class)->create()['context'];
            app(SubmitBonusRating::class)->handle($live->id, $context, $value, 'req_category_vote');
        }
        $votesBefore = DB::table('bonus_votes')->orderBy('id')->get()->map(fn (object $vote): array => (array) $vote)->all();
        $before = $live->fresh()->getAttributes();
        $merge = ['expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 2];
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', $merge)->assertConflict();
        $this->assertSame($before, $live->fresh()->getAttributes());
        $merge['target_expected_version'] = 1;
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', $merge)->assertOk()->assertJsonPath('data.moved_bonus_count', 2);
        $this->assertSame($source->id, $deleted->fresh()->category_id);
        $this->get(self::BASE.'/items/'.$live->id)->assertOk()->assertJsonPath('data.rating', 4.5);
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', $merge)->assertNotFound();
        $this->delete(self::BASE.'/categories/'.$target->id.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_bonus_count', 2);
        $this->assertNull($draft->fresh()->category_id);
        $this->assertSame(3, $live->fresh()->version);
        $this->assertSame($votesBefore, DB::table('bonus_votes')->orderBy('id')->get()->map(fn (object $vote): array => (array) $vote)->all());
        $this->assertDatabaseHas('bonus_rating_aggregates', ['bonus_id' => $live->id, 'vote_sum' => 9, 'rating_count' => 2, 'rating_revision' => 2]);
        foreach (['author_id', 'created_at', 'published_at', 'status', 'content'] as $field) {
            $this->assertSame($before[$field], $live->fresh()->getAttributes()[$field]);
        }
    }

    public function test_categories_have_reserved_normalized_names_dynamic_keys_and_repeatable_seed_keys(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->seed(BonusCategorySeeder::class);
        $this->seed(BonusCategorySeeder::class);
        $this->assertSame(['bitunix', 'lbank', 'ourbit'], BonusCategory::orderBy('key')->pluck('key')->all());
        $created = $this->postJson(self::BASE.'/categories', ['name' => '  شركت يك  '])->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('category-'.$id, $created->json('data.key'));
        $this->postJson(self::BASE.'/categories', ['name' => 'شرکت یک'])->assertConflict();
        $this->postJson(self::BASE.'/categories', ['name' => 'Other', 'key' => 'forged'])->assertUnprocessable();
        BonusCategory::factory()->count(51)->create();
        $this->get(self::BASE.'/categories')->assertOk()->assertJsonCount(55, 'data');
        $this->get(self::BASE.'/categories/'.$id)->assertOk()->assertJsonPath('data.bonus_count', 0);
        $this->delete(self::BASE.'/categories/'.$id.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_bonus_count', 0);
        $this->postJson(self::BASE.'/categories', ['name' => 'شرکت یک'])->assertConflict();
    }

    public function test_public_archive_combines_safe_campaign_search_category_counts_order_and_pagination(): void
    {
        $category = BonusCategory::factory()->create();
        $older = Bonus::factory()->published()->create(['published_at' => '2026-01-01', 'category_id' => $category->id]);
        $newer = Bonus::factory()->published()->create(['published_at' => '2026-02-01']);
        $draft = Bonus::factory()->create();
        $older->forceFill(['display_fields' => array_replace($older->display_fields, ['provider_name' => 'شركت يكي', 'amount' => 'USD 120', 'display_status' => 'ended'])])->save();
        $archive = app(ResolveBonusArchive::class)->handle(search: 'شرکتیکی', category: $category->key);
        $this->assertSame(1, $archive['meta']['total']);
        $this->assertSame(1, $archive['category_options'][0]['count']);
        $this->assertSame('ended', $archive['items'][0]['display_status']);
        $this->assertSame(1, app(ResolveBonusArchive::class)->handle(search: 'usd120')['meta']['total']);
        $all = app(ResolveBonusArchive::class)->handle(category: 'invalid-category');
        $this->assertSame([$newer->id, $older->id], array_column($all['items'], 'id'));
        $this->assertSame('all', $all['meta']['selected_category_key']);
        config(['bonuses.archive_ids' => [$older->id], 'bonuses.featured_ids' => [$draft->id, $older->id, $newer->id, $older->id]]);
        $this->assertSame($older->id, app(ResolveBonusArchive::class)->handle()['items'][0]['id']);
        $this->assertSame([$older->id, $newer->id], app(ResolveBonusSelections::class)->featured()->pluck('id')->all());
        $this->assertSame([$newer->id], app(ResolveBonusSelections::class)->latest($older->id)->pluck('id')->all());
        Bonus::factory()->count(9)->published()->create();
        $this->assertCount(2, app(ResolveBonusArchive::class)->handle(page: 2)['items']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['provider_name' => 'Example Campaign', 'category_id' => [],
            'content' => '<p>Read conditions.</p>', 'is_published' => false], $overrides);
    }
}
