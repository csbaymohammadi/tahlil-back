<?php

namespace Tests\Feature;

use App\Media\MediaAccess;
use App\Models\Media;
use App\Models\NewsItem;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\PanelTestCase;
use ZipArchive;

class NewsWordImportTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_docx_import_creates_draft_and_replays_same_canonical_result(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create(['name' => 'نویسنده نمونه']);
        $this->panel($actor);
        $bytes = $this->completedTemplate([
            'title' => 'A complete imported title', 'slug' => 'complete-imported-title',
            'description' => 'خلاصهٔ مقاله', 'category_name' => '', 'reading_minutes' => '۵',
            'meta_description' => 'توضیحات متا', 'content' => 'متن کامل مقاله برای ورود آزمایشی',
        ]);
        $key = 'd2c8b59b-879a-4cc5-b9b5-81d2ee388d65';

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('article.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key]);
        $response->assertCreated()
            ->assertJsonPath('data.content_type', 'news')
            ->assertJsonPath('data.record.title', 'A complete imported title')
            ->assertJsonPath('data.record.status', 'draft')
            ->assertJsonPath('data.record.author.id', $actor->id)
            ->assertJsonPath('data.record.version', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
        $articleId = $response->json('data.record.id');
        $this->assertDatabaseCount('news_items', 1);
        $this->assertStringContainsString('متن کامل مقاله', NewsItem::findOrFail($articleId)->content);

        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('article-copy.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.record.id', $articleId);
        $this->assertDatabaseCount('news_items', 1);
        $this->delete('/api/v1/admin/news/items/'.$articleId.'?expected_version=1')->assertNoContent();
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('article-copy.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertNotFound()->assertJsonPath('error.code', 'IMPORT_RESULT_NOT_FOUND');
    }

    public function test_invalid_docx_returns_persian_json_without_a_report_and_replays_issues(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $key = 'a8dfd733-f8b6-4d65-a945-66156a55bd1b';
        $upload = UploadedFile::fake()->createWithContent('broken.docx', 'not a zip archive');
        $response = $this->post('/api/v1/admin/content-imports', ['content_type' => 'news', 'file' => $upload], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key]);
        $response->assertUnprocessable()->assertJsonPath('error.code', 'IMPORT_VALIDATION_FAILED')
            ->assertJsonPath('error.issues.0.field', 'file')
            ->assertJsonPath('error.message', 'فایل یا ساختار سند قابل‌پردازش نیست؛ جزئیات فارسی هر بخش را بررسی کنید.')
            ->assertJsonMissingPath('error.report');
        $this->assertDatabaseCount('blog_import_reports', 0);
        $this->assertDatabaseCount('news_items', 0);
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'not a zip archive'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable()->assertJsonMissingPath('error.report');
        $this->assertDatabaseCount('blog_import_reports', 0);
    }

    public function test_embedded_cover_and_body_image_are_normalized_and_attached_atomically(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $image = UploadedFile::fake()->image('embedded.png', 20, 20);
        $bytes = $this->completedTemplate([
            'title' => 'Article with imported image', 'slug' => 'article-with-imported-image',
            'description' => '', 'category_name' => '', 'reading_minutes' => '4',
            'meta_description' => '', 'content' => 'Image demonstration',
        ], file_get_contents($image->getPathname()));

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('article-images.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '97abdd89-6caa-4a81-9c12-a13b18adecf5']);
        $response->assertCreated();

        $article = NewsItem::findOrFail($response->json('data.record.id'));
        $this->assertMatchesRegularExpression('~^/media/[1-9][0-9]*/editor-image\.png$~', $article->cover_image);
        $this->assertStringContainsString('<img', $article->content);
        $this->assertSame(1, DB::table('media')->where('status', 'ready')->count());
        $this->assertSame(2, DB::table('media_references')->count());
        $media = Media::query()->firstOrFail();
        $this->assertFalse(app(MediaAccess::class)->public($media));
    }

    public function test_import_rejects_reusing_an_idempotency_key_for_different_bytes(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $key = '5a8bf4aa-1215-4d76-9251-1846dfeb4624';
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'first bytes'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable();
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'different bytes'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_idempotency_key_cannot_be_reused_across_blog_and_news_modules(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $key = '59da4c23-9ec6-42a3-9f2d-a8b755e871d1';
        $bytes = 'not a DOCX package';

        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('broken.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable();

        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('broken.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('blog_content_imports', 1);
        $this->assertDatabaseCount('news_items', 0);
        $this->assertDatabaseCount('blog_articles', 0);
    }

    public function test_legacy_doc_without_isolated_conversion_prerequisites_fails_with_controlled_service_error(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        config([
            'blog.word_python' => 'missing-word-python-for-test',
            'blog.libreoffice' => 'missing-libreoffice-for-test',
        ]);

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news',
            'file' => UploadedFile::fake()->createWithContent('legacy.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => 'bfc7da02-fd3a-4a14-a90f-9db69ae43ab1']);

        $response->assertServiceUnavailable()->assertJsonPath('error.code', 'DOC_CONVERTER_SANDBOX_UNAVAILABLE');
        $this->assertDatabaseCount('news_items', 0);
    }

    public function test_expired_import_keys_are_pruned_without_generating_word_reports(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'not a zip archive'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '70587bad-e20e-4385-a332-d3852f204974'])->assertUnprocessable();
        $response->assertJsonMissingPath('error.report');
        $this->assertDatabaseCount('blog_import_reports', 0);

        $this->travel(25)->hours();
        Artisan::call('blog:prune');

        $this->assertDatabaseCount('blog_content_imports', 0);
        $this->assertDatabaseCount('blog_import_reports', 0);
    }

    public function test_persian_news_template_accepts_empty_optional_fields_and_null_reading_time(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $bytes = $this->completedPersianTemplate(['title' => 'خبر آزمایشی', 'slug' => 'sample-news']);

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('خبر.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '2c12a5f8-8d40-46e8-b076-0b5f790bd130'])->assertCreated()
            ->assertJsonPath('data.record.title', 'خبر آزمایشی')
            ->assertJsonPath('data.record.slug', 'sample-news')
            ->assertJsonPath('data.record.description', '')
            ->assertJsonPath('data.record.cover_image', '')
            ->assertJsonPath('data.record.content', '')
            ->assertJsonPath('data.record.meta_description', '')
            ->assertJsonPath('data.record.category_id', null)
            ->assertJsonPath('data.record.reading_minutes', null)
            ->assertJsonPath('data.record.status', 'draft')
            ->assertJsonPath('warnings', []);

        $this->assertDatabaseCount('news_items', 1);
    }

    public function test_provided_persian_news_sample_creates_a_draft(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $samplePath = base_path('doc/samples/news-word-import-sample-fa.docx');
        $sample = file_get_contents($samplePath);
        $this->assertIsString($sample);

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('نمونه-خبر.docx', $sample),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '04e6c184-6cf6-4ac7-8f66-2c8013d641cb'])->assertCreated()
            ->assertJsonPath('data.record.title', 'راهنمای خواندن خبرهای بازار با نگاه دقیق‌تر')
            ->assertJsonPath('data.record.slug', 'reading-market-news-with-context')
            ->assertJsonPath('data.record.status', 'draft');

        $this->assertMatchesRegularExpression('/<h2(?:\s|>)/', $response->json('data.record.content'));
        $this->assertMatchesRegularExpression('/<p(?:\s|>)/', $response->json('data.record.content'));
        $this->assertDatabaseCount('news_items', 1);
    }

    public function test_invalid_optional_news_values_are_ignored_with_persian_warnings(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $bytes = $this->completedPersianTemplate([
            'title' => 'خبر با هشدار', 'slug' => 'news-with-warnings',
            'description' => str_repeat('توضیح', 500), 'category_name' => 'دسته ناموجود',
            'reading_minutes' => 'نامعتبر', 'meta_description' => str_repeat('متا', 300),
        ]);

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('خبر.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '52061baf-1b02-4a92-9873-d86db4ab8cb7'])->assertCreated()
            ->assertJsonPath('data.record.description', '')
            ->assertJsonPath('data.record.meta_description', '')
            ->assertJsonPath('data.record.category_id', null)
            ->assertJsonPath('data.record.reading_minutes', null);

        $this->assertCount(4, $response->json('warnings'));
        foreach ($response->json('warnings') as $warning) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $warning['message']);
        }
    }

    public function test_news_title_and_english_title_remain_required_with_fully_persian_issues(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $bytes = $this->completedPersianTemplate([]);

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'news', 'file' => UploadedFile::fake()->createWithContent('خبر.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => 'b6349dc1-cb1b-4822-9eb9-907d275d51cf'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'IMPORT_VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'عنوان و عنوان انگلیسی سند را بررسی و اصلاح کنید.')
            ->assertJsonMissingPath('error.report');

        $this->assertNotEmpty($response->json('error.issues'));
        foreach ($response->json('error.issues') as $issue) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $issue['label'].$issue['location'].$issue['message']);
        }
        $this->assertDatabaseCount('news_items', 0);
    }

    /** @param array<string, string> $values */
    private function completedTemplate(array $values, ?string $imageBytes = null): string
    {
        $template = public_path('tahlilpanel/templates/news-import-v1.docx');
        $temporary = tempnam(sys_get_temp_dir(), 'blog-word-test-');
        $this->assertIsString($temporary);
        copy($template, $temporary);
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($temporary));
        $xml = $archive->getFromName('word/document.xml');
        $this->assertIsString($xml);
        foreach (['title', 'slug', 'description', 'category_name', 'reading_minutes', 'meta_description'] as $field) {
            $xml = str_replace('[['.$field.']]', htmlspecialchars($values[$field], ENT_XML1 | ENT_QUOTES, 'UTF-8'), $xml);
        }
        if ($imageBytes === null) {
            $xml = str_replace('[[cover_image]]', '', $xml);
        }
        $xml = str_replace('[[content]]', htmlspecialchars($values['content'], ENT_XML1 | ENT_QUOTES, 'UTF-8'), $xml);
        if ($imageBytes !== null) {
            $document = new DOMDocument('1.0', 'UTF-8');
            $this->assertTrue($document->loadXML($xml));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $cover = $xpath->query('//w:p[.//w:t[text()="[[cover_image]]"]]')->item(0);
            $body = $xpath->query('//w:p[.//w:t[text()="[content]"]]/following-sibling::w:p[1]')->item(0);
            $this->assertNotNull($cover);
            $this->assertNotNull($body);
            $cover->appendChild($this->imageRun($document));
            $body->appendChild($this->imageRun($document));
            $xml = $document->saveXML();
            $relationships = $archive->getFromName('word/_rels/document.xml.rels');
            $this->assertIsString($relationships);
            $relationships = str_replace('</Relationships>', '<Relationship Id="rIdImage1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/import.png"/></Relationships>', $relationships);
            $archive->addFromString('word/_rels/document.xml.rels', $relationships);
            $contentTypes = $archive->getFromName('[Content_Types].xml');
            $this->assertIsString($contentTypes);
            $archive->addFromString('[Content_Types].xml', str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $contentTypes));
            $archive->addFromString('word/media/import.png', $imageBytes);
        }
        $archive->addFromString('word/document.xml', $xml);
        $archive->close();
        $bytes = file_get_contents($temporary);
        unlink($temporary);
        $this->assertIsString($bytes);

        return $bytes;
    }

    /** @param array<string, string> $values */
    private function completedPersianTemplate(array $values): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'news-persian-word-test-');
        $this->assertIsString($temporary);
        copy(config('news.template_path'), $temporary);
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($temporary));
        $xml = $archive->getFromName('word/document.xml');
        $this->assertIsString($xml);
        $document = new DOMDocument('1.0', 'UTF-8');
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $sections = [
            'title' => 'عنوان', 'slug' => 'عنوان انگلیسی', 'description' => 'توضیحات',
            'cover_image' => 'تصویر', 'content' => 'محتوا', 'meta_description' => 'توضیحات متا',
            'category_name' => 'دسته‌بندی', 'reading_minutes' => 'زمان مطالعه',
        ];
        foreach ($sections as $field => $label) {
            $heading = $xpath->query('//w:p[normalize-space(string(.))="'.$label.'"]')->item(0);
            $this->assertNotNull($heading, 'Missing section '.$label);
            $valueParagraph = $heading->nextSibling;
            while ($valueParagraph !== null && ($valueParagraph->nodeType !== XML_ELEMENT_NODE || $valueParagraph->localName !== 'p')) {
                $valueParagraph = $valueParagraph->nextSibling;
            }
            $this->assertNotNull($valueParagraph, 'Missing value paragraph for '.$label);
            $value = $values[$field] ?? '';
            if ($value !== '') {
                $run = $document->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                $text = $document->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $text->appendChild($document->createTextNode($value));
                $run->appendChild($text);
                $valueParagraph->appendChild($run);
            }
        }
        $archive->addFromString('word/document.xml', $document->saveXML());
        $archive->close();
        $bytes = file_get_contents($temporary);
        unlink($temporary);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function imageRun(DOMDocument $document): \DOMElement
    {
        $word = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $drawing = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $relationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $run = $document->createElementNS($word, 'w:r');
        $drawingNode = $document->createElementNS($word, 'w:drawing');
        $blip = $document->createElementNS($drawing, 'a:blip');
        $blip->setAttributeNS($relationships, 'r:embed', 'rIdImage1');
        $drawingNode->appendChild($blip);
        $run->appendChild($drawingNode);

        return $run;
    }
}
