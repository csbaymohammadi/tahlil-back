<?php

namespace Tests\Feature;

use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class ContentHtmlContractTest extends PanelTestCase
{
    use RefreshDatabase;

    private function button(string $label = 'مشاهده سایت'): string
    {
        return '<a data-content-button="true" data-button-background="#008BC5" data-button-foreground="#ffffff" data-button-size="medium" data-button-weight="bold" data-button-align="center" href="/services">'.$label.'</a>';
    }

    public static function modules(): array
    {
        return array_map(fn (string $module): array => [$module], ['blog', 'news', 'exchanges', 'brokers', 'stock-brokers', 'gold-platforms', 'prop-firms', 'bonuses']);
    }

    #[DataProvider('modules')]
    public function test_buttons_round_trip_through_each_api(string $module): void
    {
        $this->panel(User::factory()->panel()->create());
        $base = '/api/v1/admin/'.$module.'/'.($module === 'blog' ? 'articles' : 'items');
        $payload = ['title' => 'Contract', 'slug' => 'contract', 'content' => '<h1 style="text-align: right">Heading</h1>'.$this->button().str_replace(['medium', 'center', '#008BC5'], ['large', 'left', '#112233'], $this->button('دکمه دوم')), 'status' => 'published', 'category_id' => []];
        if (! in_array($module, ['blog', 'news', 'bonuses'], true)) {
            $payload += ['english_name' => 'Contract', 'logo' => '/images/example.png', 'website_url' => 'https://example.com'];
        }
        if ($module === 'bonuses') {
            $payload['reading_minutes'] = 1;
        }
        $created = $this->postJson($base, $payload)->assertCreated();
        $id = $created->json('data.id');
        $canonical = $this->get($base.'/'.$id)->assertOk()->json('data.content');
        $this->assertStringContainsString('background-color:#008bc5', $canonical);
        $this->assertStringContainsString('font-size:20px', $canonical);
        $this->assertStringContainsString('<h1', $canonical);
        $this->patchJson($base.'/'.$id, ['expected_version' => 1, 'content' => $canonical])->assertOk()->assertJsonPath('data.version', 1);
        $public = '/api/v1/'.match ($module) {
            'blog' => 'blog/articles', 'news' => 'news/articles', default => $module
        }.'/contract';
        $body = $this->get($public)->assertOk()->json('data.content');
        $this->assertStringNotContainsString('<h1', $body);
        $this->assertSame(2, substr_count($body, 'data-content-button="true"'));
        $bad = str_replace('data-button-size="medium"', '', $canonical);
        $this->patchJson($base.'/'.$id, ['expected_version' => 1, 'content' => $bad])->assertUnprocessable();
        $this->get($base.'/'.$id)->assertOk()->assertJsonPath('data.content', $canonical)->assertJsonPath('data.version', 1);
    }

    public function test_label_count_trim_and_untrusted_styles(): void
    {
        $sanitizer = app(BlogHtmlSanitizer::class);
        $html = $this->button("\u{FEFF}".str_repeat('😀', 120)."\u{00a0}");
        $canonical = $sanitizer->sanitize(str_replace(' href=', ' style="background:url(javascript:bad)" href=', $html));
        $this->assertStringNotContainsString('javascript', $canonical);
        $this->assertStringNotContainsString('FEFF', $canonical);
        $this->assertSame($canonical, $sanitizer->sanitize($canonical));
        $this->expectException(PanelAuthException::class);
        $sanitizer->sanitize($this->button(str_repeat('😀', 121)));
    }

    public static function invalidHtml(): array
    {
        return array_map(fn (string $html): array => [$html], [
            '<p onclick="bad()">Text</p>', '<p style="text-align:right;color:red">Text</p>',
            '<a data-button-size="large" href="https://example.com">Text</a>',
            '<iframe src="https://youtube.com/embed/dQw4w9WgXcQ?start=1&amp;start=2"></iframe>',
            '<iframe src="https://youtube.com:444/embed/dQw4w9WgXcQ"></iframe>',
            '<table><tr><td rowspan="2">Text</td></tr></table>',
            '<table><tr><td colspan="51">Text</td></tr></table>',
        ]);
    }

    #[DataProvider('invalidHtml')]
    public function test_invalid_html_is_rejected(string $html): void
    {
        $this->expectException(PanelAuthException::class);
        app(BlogHtmlSanitizer::class)->sanitize($html);
    }

    public function test_defensive_read_preserves_safe_neighbors(): void
    {
        $sanitizer = app(BlogHtmlSanitizer::class);
        $html = '<script>secret()</script><p onclick="bad()">Safe</p>'.$this->button().str_replace('medium', 'invalid', $this->button('Legacy'));
        $body = $sanitizer->sanitizeForPublic($html);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringContainsString('<p>Safe</p>', $body);
        $this->assertSame(1, substr_count($body, 'data-content-button'));
        $this->assertStringContainsString('Legacy', $body);
        $this->assertSame($body, $sanitizer->sanitizeForPublic($body));
    }

    public function test_byte_and_depth_boundaries(): void
    {
        $sanitizer = app(BlogHtmlSanitizer::class);
        $this->assertSame(1048576, strlen($sanitizer->sanitize('<p>'.str_repeat('a', 1048569).'</p>')));
        $this->assertStringContainsString('Text', $sanitizer->sanitize(str_repeat('<div>', 32).'Text'.str_repeat('</div>', 32)));
        foreach (['<p>'.str_repeat('a', 1048570).'</p>', str_repeat('<div>', 33).'Text'.str_repeat('</div>', 33), str_repeat('<br>', 20001)] as $html) {
            try {
                $sanitizer->sanitize($html);
                $this->fail('The resource bound must reject this content.');
            } catch (PanelAuthException $exception) {
                $this->assertArrayHasKey('content', $exception->fields);
            }
        }
    }

    public function test_missing_media_does_not_mutate_article_or_outbox(): void
    {
        $this->panel(User::factory()->panel()->create());
        $base = '/api/v1/admin/blog/articles';
        $created = $this->postJson($base, ['category_id' => [], 'title' => 'Atomic', 'slug' => 'atomic', 'content' => $this->button(), 'status' => 'published'])->assertCreated();
        $id = $created->json('data.id');
        $old = $this->get($base.'/'.$id)->json('data.content');
        $outbox = DB::table('blog_outbox')->count();
        $this->patchJson($base.'/'.$id, ['expected_version' => 1, 'content' => '<img src="https://service.tahlilex.com/media/999999/missing.png">'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['content']]]);
        $this->get($base.'/'.$id)->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.content', $old);
        $this->assertDatabaseCount('blog_outbox', $outbox);
        $this->assertDatabaseCount('media_references', 0);
    }

    public static function unsafeDestinations(): array
    {
        return array_map(fn (string $url): array => [$url], ['//evil.test', '/%252e%252e/secret', '/%5cevil', '#', '#a b', 'javascript:alert(1)', 'https://user:pass@example.com', '/broken%xx']);
    }

    #[DataProvider('unsafeDestinations')]
    public function test_button_destinations_are_strict(string $url): void
    {
        $this->expectException(PanelAuthException::class);
        app(BlogHtmlSanitizer::class)->sanitize(str_replace('/services', $url, $this->button()));
    }
}
