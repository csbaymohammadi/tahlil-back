<?php

namespace Tests\Feature;

use App\Media\MediaAccess;
use App\Models\BlogArticle;
use App\Models\Media;
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

class WordImportTest extends PanelTestCase
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
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('article.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key]);
        $response->assertCreated()
            ->assertJsonPath('data.content_type', 'blog')
            ->assertJsonPath('data.record.title', 'A complete imported title')
            ->assertJsonPath('data.record.status', 'draft')
            ->assertJsonPath('data.record.reading_minutes', 5)
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('data.record.author.id', $actor->id)
            ->assertJsonPath('data.record.version', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
        $articleId = $response->json('data.record.id');
        $this->assertDatabaseCount('blog_articles', 1);
        $this->assertStringContainsString('متن کامل مقاله', BlogArticle::findOrFail($articleId)->content);

        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('article-copy.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.record.id', $articleId);
        $this->assertDatabaseCount('blog_articles', 1);
        $this->delete('/api/v1/admin/blog/articles/'.$articleId.'?expected_version=1')->assertNoContent();
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('article-copy.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertNotFound()->assertJsonPath('error.code', 'IMPORT_RESULT_NOT_FOUND');
    }

    public function test_invalid_blog_docx_returns_persian_json_without_a_report_and_replays_issues(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $key = 'a8dfd733-f8b6-4d65-a945-66156a55bd1b';
        $upload = UploadedFile::fake()->createWithContent('broken.docx', 'not a zip archive');
        $response = $this->post('/api/v1/admin/content-imports', ['content_type' => 'blog', 'file' => $upload], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key]);
        $response->assertUnprocessable()->assertJsonPath('error.code', 'IMPORT_VALIDATION_FAILED')
            ->assertJsonPath('error.issues.0.field', 'file')
            ->assertJsonPath('error.message', 'فایل یا ساختار سند قابل‌پردازش نیست؛ جزئیات فارسی هر بخش را بررسی کنید.')
            ->assertJsonMissingPath('error.report');
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $response->json('error.message'));
        $this->assertArrayNotHasKey('report', $response->json('error'));
        $this->assertDatabaseCount('blog_import_reports', 0);
        $this->assertDatabaseCount('blog_articles', 0);
        $replay = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'not a zip archive'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable()
            ->assertJsonMissingPath('error.report');
        $this->assertSame($response->json('error.issues'), $replay->json('error.issues'));
        $this->assertDatabaseCount('blog_import_reports', 0);
    }

    public function test_persian_template_allows_empty_optional_fields_and_replays_warnings(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $bytes = $this->completedPersianTemplate([
            'title' => 'پیش‌نویس فارسی', 'slug' => 'persian-draft',
        ]);
        $key = '4512e105-58f3-4c1b-9a84-16d3bc8bcb80';

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('مقاله.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.record.title', 'پیش‌نویس فارسی')
            ->assertJsonPath('data.record.slug', 'persian-draft')
            ->assertJsonPath('data.record.description', '')
            ->assertJsonPath('data.record.cover_image', '')
            ->assertJsonPath('data.record.content', '')
            ->assertJsonPath('data.record.meta_description', '')
            ->assertJsonPath('data.record.category_id', null)
            ->assertJsonPath('data.record.reading_minutes', null)
            ->assertJsonPath('warnings', []);

        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('مقاله.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.record.id', $response->json('data.record.id'))
            ->assertJsonPath('warnings', []);
        $this->assertDatabaseCount('blog_articles', 1);
    }

    public function test_unusable_optional_word_values_become_persian_warnings_instead_of_errors(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $bytes = $this->completedPersianTemplate([
            'title' => 'پیش‌نویس با هشدار', 'slug' => 'draft-with-warnings',
            'description' => 'خلاصه', 'category_name' => 'دسته موجود نیست', 'reading_minutes' => 'نامشخص',
            'meta_description' => 'شرح متا',
        ], 'محتوای تصویر خراب');
        $key = 'a879d213-715f-4eaa-8e2e-b02c10b5032a';

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('draft.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key]);
        $response->assertCreated()
            ->assertJsonPath('data.record.category_id', null)
            ->assertJsonPath('data.record.reading_minutes', null)
            ->assertJsonPath('data.record.cover_image', '');
        $warnings = $response->json('warnings');
        $this->assertSame(['cover_image', 'category_id', 'reading_minutes'], array_column($warnings, 'field'));
        foreach ($warnings as $warning) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $warning['message']);
        }

        $replay = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('draft.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertCreated();
        $this->assertSame($warnings, $replay->json('warnings'));
        $this->assertDatabaseCount('blog_articles', 1);
    }

    public function test_invalid_title_and_english_title_are_reported_in_persian_without_time_or_file_report(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $bytes = $this->completedPersianTemplate(['title' => '', 'slug' => 'BAD Slug']);
        $key = '61d0dcbe-bced-45bb-9a66-65f767ab5401';
        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('article.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable()
            ->assertJsonPath('error.code', 'IMPORT_VALIDATION_FAILED')
            ->assertJsonPath('error.fields.title.0', 'عنوان مقاله الزامی است و حداکثر می‌تواند ۲۰۰ نویسه باشد.')
            ->assertJsonPath('error.fields.slug.0', 'عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید؛ طول آن حداکثر ۲۰۰ نویسه باشد.')
            ->assertJsonMissingPath('error.report');
        $json = json_encode($response->json('error'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $visibleText = [$response->json('error.message')];
        foreach ($response->json('error.fields') as $messages) {
            $visibleText = [...$visibleText, ...$messages];
        }
        foreach ($response->json('error.issues') as $issue) {
            $visibleText[] = $issue['label'];
            $visibleText[] = $issue['location'];
            $visibleText[] = $issue['message'];
        }
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', implode(' ', $visibleText));
        $this->assertStringNotContainsString('زمان', $json);
        $this->assertArrayNotHasKey('created_at', $response->json('error'));
        $this->assertArrayNotHasKey('updated_at', $response->json('error'));
        $this->assertArrayNotHasKey('report', $response->json('error'));
        $this->assertDatabaseCount('blog_articles', 0);
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('article.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable()
            ->assertJsonMissingPath('error.report');
    }

    public function test_embedded_cover_and_body_image_are_normalized_and_attached_atomically(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $image = UploadedFile::fake()->image('embedded.png', 20, 20);
        $bytes = $this->completedPersianTemplate([
            'title' => 'Article with imported image', 'slug' => 'article-with-imported-image',
            'description' => '', 'category_name' => '', 'reading_minutes' => '4',
            'meta_description' => '', 'content' => 'Image demonstration',
        ], file_get_contents($image->getPathname()), withBodyImage: true);

        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('article-images.docx', $bytes),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '97abdd89-6caa-4a81-9c12-a13b18adecf5']);
        $response->assertCreated();

        $article = BlogArticle::findOrFail($response->json('data.record.id'));
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
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'first bytes'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertUnprocessable();
        $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'different bytes'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
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
            'content_type' => 'blog',
            'file' => UploadedFile::fake()->createWithContent('legacy.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => 'bfc7da02-fd3a-4a14-a90f-9db69ae43ab1']);

        $response->assertServiceUnavailable()->assertJsonPath('error.code', 'DOC_CONVERTER_SANDBOX_UNAVAILABLE');
        $this->assertDatabaseCount('blog_articles', 0);
    }

    public function test_invalid_blog_import_keys_are_pruned_without_generating_report_files(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $response = $this->post('/api/v1/admin/content-imports', [
            'content_type' => 'blog', 'file' => UploadedFile::fake()->createWithContent('broken.docx', 'not a zip archive'),
        ], ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => '70587bad-e20e-4385-a332-d3852f204974'])->assertUnprocessable();
        $this->assertArrayNotHasKey('report', $response->json('error'));
        $this->assertDatabaseCount('blog_import_reports', 0);

        $this->travel(25)->hours();
        Artisan::call('blog:prune');

        $this->assertDatabaseCount('blog_content_imports', 0);
        $this->assertDatabaseCount('blog_import_reports', 0);
    }

    /** @param array<string, string> $values */
    private function completedPersianTemplate(array $values, ?string $imageBytes = null, bool $withBodyImage = false): string
    {
        $template = public_path('tahlilpanel/templates/blog-import-v2.docx');
        $temporary = tempnam(sys_get_temp_dir(), 'blog-persian-word-test-');
        $this->assertIsString($temporary);
        copy($template, $temporary);
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
        $valueParagraphs = [];
        foreach ($sections as $field => $label) {
            $heading = $xpath->query('//w:p[normalize-space(string(.))="'.$label.'"]')->item(0);
            $this->assertNotNull($heading, 'Missing section '.$label);
            $valueParagraph = $heading->nextSibling;
            while ($valueParagraph !== null && ($valueParagraph->nodeType !== XML_ELEMENT_NODE || $valueParagraph->localName !== 'p')) {
                $valueParagraph = $valueParagraph->nextSibling;
            }
            $this->assertNotNull($valueParagraph, 'Missing value paragraph for '.$label);
            $valueParagraphs[$field] = $valueParagraph;
            $value = $values[$field] ?? '';
            if ($value !== '') {
                $run = $document->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                $text = $document->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $text->appendChild($document->createTextNode($value));
                $run->appendChild($text);
                $valueParagraph->appendChild($run);
            }
        }
        if ($imageBytes !== null) {
            $valueParagraphs['cover_image']->appendChild($this->imageRun($document));
            if ($withBodyImage) {
                $valueParagraphs['content']->appendChild($this->imageRun($document));
            }
            $relationships = $archive->getFromName('word/_rels/document.xml.rels');
            $this->assertIsString($relationships);
            $relationships = str_replace('</Relationships>', '<Relationship Id="rIdImage1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/import.png"/></Relationships>', $relationships);
            $archive->addFromString('word/_rels/document.xml.rels', $relationships);
            $contentTypes = $archive->getFromName('[Content_Types].xml');
            $this->assertIsString($contentTypes);
            if (! str_contains($contentTypes, 'Extension="png"')) {
                $contentTypes = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $contentTypes);
            }
            $archive->addFromString('[Content_Types].xml', $contentTypes);
            $archive->addFromString('word/media/import.png', $imageBytes);
        }
        $archive->addFromString('word/document.xml', $document->saveXML());
        $archive->close();
        $bytes = file_get_contents($temporary);
        unlink($temporary);
        $this->assertIsString($bytes);

        return $bytes;
    }

    /** @param array<string, string> $values */
    private function completedTemplate(array $values, ?string $imageBytes = null): string
    {
        $template = public_path('tahlilpanel/templates/blog-import-v1.docx');
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
