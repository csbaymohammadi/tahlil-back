<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Media\MediaAccess;
use App\Models\Advertisement;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class AdvertisementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/advertisements';

    protected function setUp(): void
    {
        parent::setUp();
        config(['presentation.allowed_origins' => ['http://localhost']]);
    }

    private function multipart(string $path, array $data): TestResponse
    {
        return $this->post($path, $data, ['Content-Type' => 'multipart/form-data; boundary=test']);
    }

    private function createAdvertisement(array $overrides = []): TestResponse
    {
        return $this->multipart(self::BASE, ['img' => UploadedFile::fake()->image('card.png', 320, 240),
            'title' => 'عنوان تبلیغ', 'description' => 'توضیح تبلیغ', 'show' => '1', ...$overrides]);
    }

    public function test_create_lists_and_details_return_saved_fields_and_shared_media(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create(['role_id' => null]);
        $this->panel($actor);
        $snapshot = PageSetting::current()->snapshot;
        $response = $this->createAdvertisement()->assertCreated()->assertJsonPath('data.show', true)
            ->assertJsonPath('data.link', '')->assertJsonPath('data.position', 1)->assertJsonPath('data.version', 1)->assertJsonPath('data.title', 'عنوان تبلیغ');
        $advertisement = Advertisement::sole();
        $this->assertSame($actor->id, $advertisement->created_by);
        $this->assertSame('banner', $advertisement->image->purpose);
        $this->assertSame($advertisement->image->canonicalUrl(), $response->json('data.img'));
        $this->assertSame(self::BASE.'/'.$advertisement->id, $response->headers->get('Location'));
        Storage::disk('media')->assertExists($advertisement->image->storage_key);
        $this->assertDatabaseHas('media_references', ['consumer_type' => 'advertisement', 'consumer_id' => $advertisement->id, 'media_id' => $advertisement->image_media_id]);
        $this->get(self::BASE)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $advertisement->id);
        $this->get(self::BASE.'/'.$advertisement->id)->assertOk()->assertJsonPath('data.description', 'توضیح تبلیغ');
        $this->assertSame($snapshot, PageSetting::current()->snapshot);
        $this->assertDatabaseHas('panel_security_events', ['event' => 'advertisement_created', 'target_id' => $advertisement->id]);
        $delivery = DB::table('public_change_deliveries')->where('payload', 'like', '%advertisement_created%')->sole();
        $this->assertSame(['homepage', 'advertisements'], json_decode($delivery->payload, true)['areas']);
    }

    public function test_public_list_returns_an_empty_array_before_any_advertisement_is_created(): void
    {
        Storage::fake('media');
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonPath('data', [])->assertJsonStructure(['request_id']);
    }

    public function test_reorder_includes_hidden_cards_and_public_returns_only_active_cards(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $first = $this->createAdvertisement()->assertCreated()->json('data');
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.version')->assertJsonMissingPath('data.0.position');
        $hidden = $this->createAdvertisement(['show' => '0'])->assertCreated()->json('data');
        $last = $this->createAdvertisement()->assertCreated()->json('data');
        $this->putJson(self::BASE.'/order', ['items' => [
            ['id' => $last['id'], 'expected_version' => 1], ['id' => $hidden['id'], 'expected_version' => 1], ['id' => $first['id'], 'expected_version' => 1],
        ]])->assertOk()->assertJsonPath('data.0.id', $last['id'])->assertJsonPath('data.0.position', 1)->assertJsonPath('data.0.version', 2)->assertJsonPath('data.1.show', false);
        $this->withUnencryptedCookie(PanelSessions::COOKIE, '');
        $response = $this->get('/api/v1/advertisements')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $last['id'])->assertJsonPath('data.1.id', $first['id'])->assertJsonMissingPath('data.0.version');
        $this->assertSame(['id', 'img', 'title', 'description', 'link', 'show'], array_keys($response->json('data.0')));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_update_keeps_image_and_hiding_revokes_guest_image_access(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $data = $this->createAdvertisement()->assertCreated()->json('data');
        $this->withUnencryptedCookie(PanelSessions::COOKIE, '');
        $this->get($data['img'])->assertOk();
        $this->panel($actor);
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'show' => '0', 'title' => 'ویرایش'])
            ->assertOk()->assertJsonPath('data.show', false)->assertJsonPath('data.img', $data['img'])->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('advertisements', ['id' => $data['id'], 'show' => false, 'title' => 'ویرایش']);
        $this->withUnencryptedCookie(PanelSessions::COOKIE, '');
        $this->get($data['img'])->assertNotFound();
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonPath('data', []);
        $this->panel($actor);
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '2', 'show' => '1'])->assertOk()->assertJsonPath('data.version', 3);
        $this->withUnencryptedCookie(PanelSessions::COOKIE, '');
        $this->get($data['img'])->assertOk();
    }

    public function test_replacing_image_and_deleting_advertisement_detach_without_deleting_shared_media(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $data = $this->createAdvertisement()->assertCreated()->json('data');
        $advertisement = Advertisement::sole();
        $old = $advertisement->image;
        DB::table('media_references')->insert(['media_id' => $old->id, 'consumer_type' => 'page-settings', 'consumer_id' => 1, 'field' => 'shared', 'created_at' => now(), 'updated_at' => now()]);
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'img' => UploadedFile::fake()->image('replacement.png')])
            ->assertOk()->assertJsonPath('data.version', 2);
        $replacement = $advertisement->fresh()->image;
        $this->assertNotSame($old->id, $replacement->id);
        $this->assertDatabaseMissing('media_references', ['consumer_type' => 'advertisement', 'media_id' => $old->id]);
        $this->assertNull($old->fresh()->unreferenced_at);
        $this->delete(self::BASE.'/'.$data['id'].'?expected_version=2')->assertNoContent();
        $this->assertSoftDeleted($advertisement);
        $this->assertDatabaseMissing('media_references', ['consumer_type' => 'advertisement', 'consumer_id' => $data['id']]);
        $this->assertNotNull($replacement->fresh()->unreferenced_at);
        $this->assertNull($replacement->fresh()->deleted_at);
        $this->assertTrue(app(MediaAccess::class)->public($old->fresh()));
        Storage::disk('media')->assertExists($old->storage_key);
        $this->get(self::BASE.'/'.$data['id'])->assertNotFound();
        $this->get(self::BASE)->assertOk()->assertJsonPath('data', []);
    }

    public function test_stale_update_delete_and_reorder_do_not_change_records(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $first = $this->createAdvertisement()->assertCreated()->json('data');
        $second = $this->createAdvertisement()->assertCreated()->json('data');
        $before = Advertisement::ordered()->get()->toArray();
        $this->multipart(self::BASE.'/'.$first['id'], ['expected_version' => '9', 'title' => 'Stale'])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->delete(self::BASE.'/'.$first['id'].'?expected_version=9')->assertConflict();
        $this->putJson(self::BASE.'/order', ['items' => [['id' => $second['id'], 'expected_version' => 1]]])->assertConflict();
        $this->putJson(self::BASE.'/order', ['items' => [['id' => $second['id'], 'expected_version' => 1], ['id' => $first['id'], 'expected_version' => 9]]])->assertConflict();
        $this->assertSame($before, Advertisement::ordered()->get()->toArray());
    }

    public function test_noop_update_and_reorder_preserve_versions_and_do_not_emit_changes(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $data = $this->createAdvertisement()->assertCreated()->json('data');
        $events = DB::table('public_change_deliveries')->count();
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'show' => '1'])->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson(self::BASE.'/order', ['items' => [['id' => $data['id'], 'expected_version' => 1]]])->assertOk()->assertJsonPath('data.0.version', 1);
        $this->assertSame($events, DB::table('public_change_deliveries')->count());
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_creation_is_rejected_before_uploading(string $field, mixed $value): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $this->createAdvertisement([$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field, 'error.fields');
        $this->assertDatabaseCount('advertisements', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public static function invalidFields(): array
    {
        return ['empty title' => ['title', ' '], 'long title' => ['title', str_repeat('x', 201)],
            'HTML title' => ['title', '<script>alert(1)</script>'], 'HTML description' => ['description', '<b>text</b>'],
            'empty description' => ['description', ''], 'long description' => ['description', str_repeat('x', 2001)],
            'text boolean' => ['show', 'true'], 'numeric boolean' => ['show', 1], 'unknown field' => ['position', '1']];
    }

    public function test_invalid_files_and_multipart_are_rejected(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $this->createAdvertisement(['img' => UploadedFile::fake()->create('huge.png', 5121)])->assertStatus(413);
        $this->createAdvertisement(['img' => UploadedFile::fake()->createWithContent('fake.png', '<svg><script>alert(1)</script></svg>')])->assertStatus(415);
        $valid = UploadedFile::fake()->image('valid.png');
        $this->createAdvertisement(['img' => UploadedFile::fake()->createWithContent('broken.png', substr(file_get_contents($valid->getPathname()), 0, 50))])->assertUnprocessable();
        $this->createAdvertisement(['img' => ''])->assertBadRequest()->assertJsonPath('error.code', 'INVALID_MULTIPART');
        $this->createAdvertisement(['another' => UploadedFile::fake()->image('extra.png')])->assertBadRequest();
        $this->postJson(self::BASE, ['title' => 'JSON'])->assertBadRequest()->assertJsonPath('error.code', 'INVALID_MULTIPART');
        $this->assertDatabaseCount('advertisements', 0);
        $this->assertCount(0, Storage::disk('media')->allFiles());
    }

    public function test_authentication_csrf_and_transport_are_enforced(): void
    {
        Storage::fake('media');
        $this->get(self::BASE)->assertUnauthorized();
        $this->createAdvertisement()->assertUnauthorized();
        $this->putJson(self::BASE.'/order', ['items' => []])->assertUnauthorized();
        $this->delete(self::BASE.'/1?expected_version=1')->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'bad');
        $this->createAdvertisement()->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->putJson(self::BASE.'/order', ['items' => []])->assertForbidden();
        $this->delete(self::BASE.'/1?expected_version=1')->assertForbidden();
        $this->assertDatabaseCount('advertisements', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_unknown_queries_duplicate_ids_and_invalid_versions_are_rejected(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $data = $this->createAdvertisement()->assertCreated()->json('data');
        $this->get(self::BASE.'?unexpected=1')->assertUnprocessable();
        $this->get('/api/v1/advertisements?show=0')->assertUnprocessable();
        $this->get('/api/v1/advertisements?x=1&x=2')->assertUnprocessable();
        $this->putJson(self::BASE.'/order', ['items' => [['id' => $data['id'], 'expected_version' => 1], ['id' => $data['id'], 'expected_version' => 1]]])->assertUnprocessable();
        $this->putJson(self::BASE.'/order', ['items' => [['id' => (string) $data['id'], 'expected_version' => 1]]])->assertUnprocessable();
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '01', 'show' => '0'])->assertUnprocessable();
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1'])->assertUnprocessable();
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'img' => ''])->assertUnprocessable();
        $this->deleteJson(self::BASE.'/'.$data['id'].'?expected_version=1', ['expected_version' => 1])->assertUnprocessable();
        $this->assertSame(1, Advertisement::sole()->version);
    }

    public function test_audit_failure_rolls_back_the_advertisement_and_media_reference_changes(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $data = $this->createAdvertisement()->assertCreated()->json('data');
        $before = Advertisement::sole()->getAttributes();
        $references = DB::table('media_references')->get()->toArray();
        DB::statement("CREATE TRIGGER deny_advertisement_audit BEFORE INSERT ON panel_security_events WHEN NEW.target_type = 'advertisement' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'show' => '0', 'img' => UploadedFile::fake()->image('new.png')])->assertStatus(503);
        $this->assertSame($before, Advertisement::sole()->getAttributes());
        $this->assertEquals($references, DB::table('media_references')->get()->toArray());
        $this->assertSame(0, DB::table('public_change_deliveries')->where('payload', 'like', '%advertisement_updated%')->count());
        $this->delete(self::BASE.'/'.$data['id'].'?expected_version=1')->assertStatus(503);
        $this->assertSame($before, Advertisement::sole()->getAttributes());
        $this->assertEquals($references, DB::table('media_references')->get()->toArray());
    }

    public function test_reorder_audit_failure_rolls_back_all_positions_and_versions(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $first = $this->createAdvertisement()->assertCreated()->json('data');
        $second = $this->createAdvertisement()->assertCreated()->json('data');
        $before = Advertisement::ordered()->get()->toArray();
        DB::statement("CREATE TRIGGER deny_reorder_audit BEFORE INSERT ON panel_security_events WHEN NEW.event = 'advertisement_reordered' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->putJson(self::BASE.'/order', ['items' => [['id' => $second['id'], 'expected_version' => 1], ['id' => $first['id'], 'expected_version' => 1]]])->assertStatus(503);
        $this->assertSame($before, Advertisement::ordered()->get()->toArray());
        $this->assertSame(0, DB::table('public_change_deliveries')->where('payload', 'like', '%advertisements_reordered%')->count());
    }

    public function test_create_audit_failure_does_not_publish_or_attach_the_uploaded_file(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        DB::statement("CREATE TRIGGER deny_create_audit BEFORE INSERT ON panel_security_events WHEN NEW.event = 'advertisement_created' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->createAdvertisement()->assertStatus(503);
        $this->assertDatabaseCount('advertisements', 0);
        $this->assertDatabaseMissing('media_references', ['consumer_type' => 'advertisement']);
        $media = Media::sole();
        $this->assertNotNull($media->unreferenced_at);
        $this->assertFalse(app(MediaAccess::class)->public($media));
        $this->assertSame(0, DB::table('public_change_deliveries')->where('payload', 'like', '%advertisement_created%')->count());
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonPath('data', []);
    }

    public function test_unavailable_image_is_excluded_and_public_transport_and_rate_limit_are_enforced(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $this->createAdvertisement()->assertCreated();
        Advertisement::sole()->image->forceFill(['status' => 'deleted', 'deleted_at' => now()])->save();
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonPath('data', []);
        $this->call('GET', '/api/v1/advertisements', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnprocessable();
        $this->get('/api/v1/advertisements?show=0')->assertUnprocessable()->assertJsonStructure(['error' => ['code', 'message', 'fields'], 'request_id'])->assertHeader('Cache-Control', 'no-store, private');
        $this->options('/api/v1/advertisements', [], ['Origin' => 'http://localhost', 'Access-Control-Request-Method' => 'GET'])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost');
        config(['presentation.public_read_rate_limit' => 1]);
        $this->get('/api/v1/advertisements')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_new_cards_append_after_reorder_and_deleted_cards_cannot_be_edited(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $first = $this->createAdvertisement()->assertCreated()->json('data');
        $second = $this->createAdvertisement()->assertCreated()->json('data');
        $this->putJson(self::BASE.'/order', ['items' => [['id' => $second['id'], 'expected_version' => 1], ['id' => $first['id'], 'expected_version' => 1]]])->assertOk();
        $this->createAdvertisement()->assertCreated()->assertJsonPath('data.position', 3);
        $this->delete(self::BASE.'/'.$first['id'].'?expected_version=2')->assertNoContent();
        $this->multipart(self::BASE.'/'.$first['id'], ['expected_version' => '3', 'show' => '1'])->assertNotFound()->assertJsonPath('error.code', 'ADVERTISEMENT_NOT_FOUND');
        $this->delete(self::BASE.'/'.$first['id'].'?expected_version=3')->assertNotFound();
        $this->get(self::BASE)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second['id']);
    }

    public function test_link_is_saved_returned_and_updated_without_url_validation(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $link = '  arbitrary destination /مسیر?x=1  ';
        $data = $this->createAdvertisement(['link' => $link])->assertCreated()->assertJsonPath('data.link', $link)->json('data');
        $this->assertDatabaseHas('advertisements', ['id' => $data['id'], 'link' => $link]);
        $this->get(self::BASE)->assertOk()->assertJsonPath('data.0.link', $link);
        $this->get(self::BASE.'/'.$data['id'])->assertOk()->assertJsonPath('data.link', $link);
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonPath('data.0.link', $link);
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'link' => 'custom:value'])
            ->assertOk()->assertJsonPath('data.link', 'custom:value')->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('advertisements', ['id' => $data['id'], 'link' => 'custom:value', 'version' => 2]);
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '2', 'title' => 'Changed'])
            ->assertOk()->assertJsonPath('data.link', 'custom:value')->assertJsonPath('data.version', 3);
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '3', 'link' => ''])
            ->assertOk()->assertJsonPath('data.link', '')->assertJsonPath('data.version', 4);
        $this->assertDatabaseHas('advertisements', ['id' => $data['id'], 'link' => '', 'version' => 4]);
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonPath('data.0.link', '');
    }

    public function test_non_string_link_is_rejected_without_changing_the_advertisement(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $data = $this->createAdvertisement(['link' => 'original'])->assertCreated()->json('data');
        $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'link' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('link', 'error.fields');
        $this->assertDatabaseHas('advertisements', ['id' => $data['id'], 'link' => 'original', 'version' => 1]);
    }

    public function test_optional_button_label_is_returned_only_when_set_and_can_be_cleared(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $link = '/offer';
        $label = 'مشاهده پیشنهاد';
        $data = $this->createAdvertisement(['link' => $link])->assertCreated()
            ->assertJsonMissingPath('data.link_bt')->json('data');
        $path = self::BASE.'/'.$data['id'];
        $this->get('/api/v1/advertisements')->assertOk()->assertJsonMissingPath('data.0.link_bt');

        $this->multipart($path, ['expected_version' => '1', 'link_bt' => ' '.$label.' '])
            ->assertOk()->assertJsonPath('data.link_bt', $label)->assertJsonPath('data.version', 2);
        $this->get($path)->assertOk()->assertJsonPath('data.link_bt', $label);
        $this->get('/api/v1/advertisements')->assertOk()
            ->assertJsonPath('data.0.link', $link)->assertJsonPath('data.0.link_bt', $label);
        $this->multipart($path, ['expected_version' => '2', 'title' => 'ویرایش'])
            ->assertOk()->assertJsonPath('data.link_bt', $label)->assertJsonPath('data.version', 3);
        $this->multipart($path, ['expected_version' => '3', 'link_bt' => ''])
            ->assertOk()->assertJsonMissingPath('data.link_bt')->assertJsonPath('data.version', 4);
        $this->get('/api/v1/advertisements')->assertOk()
            ->assertJsonPath('data.0.link', $link)->assertJsonMissingPath('data.0.link_bt');
    }

    public function test_invalid_button_label_is_rejected_without_updating_the_card(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $data = $this->createAdvertisement(['link_bt' => 'Details'])->assertCreated()
            ->assertJsonPath('data.link_bt', 'Details')->json('data');

        foreach ([['Details'], '<b>Details</b>', str_repeat('a', 201)] as $value) {
            $this->multipart(self::BASE.'/'.$data['id'], ['expected_version' => '1', 'link_bt' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors('link_bt', 'error.fields');
        }
        $this->assertDatabaseHas('advertisements', ['id' => $data['id'], 'link_bt' => 'Details', 'version' => 1]);
    }
}
