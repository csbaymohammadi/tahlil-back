<?php

namespace Tests\Feature;

use App\Actions\Demo\SeedDemoContent;
use App\Models\BlogArticle;
use App\Models\PageSetting;
use App\Models\PanelSession;
use App\Models\User;
use App\Support\PublicContentIndex;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\PanelTestCase;

class DemoContentTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_seeder_publishes_eight_domains_and_populates_empty_homepage_sections(): void
    {
        $actor = User::factory()->panel()->create();
        $this->seed(DemoContentSeeder::class);
        foreach (PublicContentIndex::TYPES as $type => $model) {
            if ($type === 'tutorials') {
                $this->assertDatabaseCount('tutorials', 0);

                continue;
            }
            $record = $model::where('slug', 'tahlil-demo-'.$type.'-001')->firstOrFail();
            $this->assertSame('published', $record->status);
            $this->assertSame($actor->id, $record->created_by);
            $this->assertStringContainsString('آزمایشی', $record->content);
            $this->assertStringContainsString('<table>', $record->content);
            $path = '/api/v1/'.match ($type) {
                'articles' => 'blog/articles', 'news' => 'news/articles', default => $type
            }.'/'.$record->slug;
            $response = $this->get($path)->assertOk()->assertJsonPath('data.id', $record->id);
            $this->assertStringContainsString(SeedDemoContent::IMAGE, $response->json('data.content'));
            $this->assertNotEmpty($response->json('data.category_id'));
        }
        $home = $this->get('/api/v1/page-settings')->assertOk();
        foreach ($home->json('data.carousels') as $carousel) {
            $this->assertCount(in_array($carousel['key'], ['aparat', 'videos'], true) ? 0 : 1, $carousel['items']);
        }
        foreach ($home->json('data.menu') as $menu) {
            $this->assertCount(1, $menu['items']);
        }
        $this->get('/api/v1/search')->assertOk()->assertJsonPath('meta.total', 8);
        $this->assertDatabaseCount('panel_sessions', 0);
    }

    public function test_reruns_do_not_duplicate_or_overwrite_editorial_changes(): void
    {
        $actor = User::factory()->panel()->create();
        $seed = app(SeedDemoContent::class);
        $seed->handle($actor);
        $article = BlogArticle::firstOrFail();
        $article->forceFill(['title' => 'ویرایش دستی محفوظ'])->save();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $settings = PageSetting::current()->getAttributes();
        $counts = DB::table('blog_audit_events')->count();
        $result = $seed->handle($actor);
        $this->assertSame(array_fill(0, 8, 'kept'), array_column($result, 'result'));
        $this->assertSame('ویرایش دستی محفوظ', $article->fresh()->title);
        $this->assertDatabaseCount('blog_articles', 1);
        $this->assertDatabaseCount('blog_audit_events', $counts);
        $this->assertSame($settings, PageSetting::current()->getAttributes());
        $this->assertDatabaseCount('panel_sessions', 0);
    }

    public function test_existing_homepage_selections_and_sessions_are_preserved(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $existing = BlogArticle::factory()->create(['status' => 'published']);
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][5]['selected_ids'] = [$existing->id];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        app(SeedDemoContent::class)->handle($actor);
        $this->assertSame([$existing->id], PageSetting::current()->snapshot['carousels'][5]['selected_ids']);
        $this->assertSame(1, PanelSession::whereNull('revoked_at')->count());
    }

    public function test_failures_roll_back_every_domain_and_the_temporary_session(): void
    {
        $actor = User::factory()->panel()->create();
        $settings = PageSetting::current()->getAttributes();
        $index = \Mockery::mock(PublicContentIndex::class);
        $index->shouldReceive('sync')->andReturnNull();
        $index->shouldReceive('refreshType')->andReturnNull();
        $index->shouldReceive('rebuild')->andThrow(new RuntimeException('Index unavailable'));
        $this->app->instance(PublicContentIndex::class, $index);
        try {
            app(SeedDemoContent::class)->handle($actor);
            $this->fail('The failed index rebuild must roll back the seed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Index unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('blog_articles', 0);
        $this->assertDatabaseCount('brokers', 0);
        $this->assertDatabaseCount('bonuses', 0);
        $this->assertDatabaseCount('panel_sessions', 0);
        $this->assertSame($settings, PageSetting::current()->getAttributes());
    }

    public function test_seeder_does_not_create_an_account_when_no_author_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Create an active panel administrator');
        $this->seed(DemoContentSeeder::class);
    }
}
