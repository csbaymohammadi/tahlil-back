<?php

namespace Tests\Feature;

use App\Media\MediaAccess;
use App\Models\BlogArticle;
use App\Models\Media;
use App\Models\User;
use App\Profile\PublicAuthor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\Argon2IdHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PanelProfileTest extends PanelTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['presentation.allowed_origins' => ['http://localhost']]);
    }

    private const BASE = '/api/v1/admin/profile';

    public function test_roleless_profile_updates_same_identity_and_preserves_session(): void
    {
        $actor = User::factory()->panel()->create(['role_id' => null]);
        $session = $this->panel($actor)['record'];
        $this->get(self::BASE)->assertOk()->assertJsonPath('data.bio', '')->assertJsonPath('data.linkedin_url', '');
        $this->patchJson(self::BASE, ['expected_version' => 1, 'name' => ' نام جدید ', 'bio' => " خط اول\nخط دوم ", 'linkedin_url' => 'https://WWW.LinkedIn.com/in/editor?x=1'])
            ->assertOk()->assertJsonPath('data.name', 'نام جدید')->assertJsonPath('data.version', 2)->assertJsonPath('data.bio', "خط اول\nخط دوم")->assertJsonPath('data.linkedin_url', 'https://www.linkedin.com/in/editor?x=1')->assertJsonMissingPath('data.email');
        $this->get('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.user.name', 'نام جدید');
        $this->assertNull($session->fresh()->revoked_at);
        $this->assertSame(2, $actor->fresh()->version);
        $this->patchJson(self::BASE, ['expected_version' => 2, 'bio' => "خط اول\nخط دوم"])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_profile_uses_shared_user_version_and_rejects_privileged_fields(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $other = User::factory()->panel()->create();
        $this->patchJson('/api/v1/admin/users/'.$actor->id, ['expected_version' => 1, 'name' => 'Managed Name'])->assertOk();
        $this->patchJson(self::BASE, ['expected_version' => 1, 'bio' => 'stale'])->assertConflict();
        $this->patchJson(self::BASE, ['expected_version' => 2, 'id' => $other->id, 'is_active' => false, 'name' => 'Bad'])->assertUnprocessable()->assertJsonValidationErrors(['id', 'is_active'], 'error.fields');
        $this->assertSame('Managed Name', $actor->fresh()->name);
        $this->assertSame('', $actor->fresh()->bio);
    }

    public function test_avatar_reference_changes_are_atomic_and_public_only_for_public_author(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $photo = Media::factory()->create(['user_id' => $actor->id]);
        $this->patchJson(self::BASE, ['expected_version' => 1, 'avatar_media_id' => $photo->id])->assertOk()->assertJsonPath('data.avatar_media_id', $photo->id);
        $this->assertFalse(app(MediaAccess::class)->public($photo->fresh()));
        BlogArticle::factory()->create(['author_id' => $actor->id, 'status' => 'published']);
        $author = app(PublicAuthor::class)->record($actor->fresh('avatar'));
        $this->assertSame($photo->canonicalUrl(), $author['avatar_url']);
        $this->assertArrayNotHasKey('email', $author);
        $this->patchJson(self::BASE, ['expected_version' => 2, 'avatar_media_id' => null])->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertDatabaseMissing('media_references', ['consumer_type' => 'user', 'consumer_id' => $actor->id, 'media_id' => $photo->id]);
        $this->assertNull($photo->fresh()->deleted_at);
    }

    public function test_invalid_avatar_rolls_back_name_and_cannot_use_another_users_staged_asset(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $photo = Media::factory()->create();
        $this->patchJson(self::BASE, ['expected_version' => 1, 'name' => 'Partial', 'avatar_media_id' => $photo->id])->assertUnprocessable();
        $this->assertSame($actor->name, $actor->fresh()->name);
        $this->assertSame(1, $actor->fresh()->version);
    }

    public function test_profile_only_saves_keep_password_separate_and_password_change_revokes_all_sessions(): void
    {
        $actor = User::factory()->panel()->create(['password' => (new Argon2IdHasher(config('panel.argon')))->make('Correct horse battery staple')]);
        $session = $this->panel($actor)['record'];
        $this->patchJson(self::BASE, ['expected_version' => 1, 'bio' => 'Saved biography'])->assertOk();
        $this->postJson('/api/v1/admin/auth/password', ['current_password' => 'wrong password', 'new_password' => 'An entirely new private password'])->assertUnprocessable();
        $this->assertSame('Saved biography', $actor->fresh()->bio);
        $this->assertNull($session->fresh()->revoked_at);
        $this->postJson('/api/v1/admin/auth/password', ['current_password' => 'Correct horse battery staple', 'new_password' => 'An entirely new private password'])->assertNoContent();
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->get(self::BASE)->assertUnauthorized();
    }

    public static function invalidProfiles(): array
    {
        return ['null bio' => ['bio', null], 'empty name' => ['name', ' '], 'HTML bio' => ['bio', '<img src=x>'],
            'lookalike LinkedIn' => ['linkedin_url', 'https://linkedin.com.evil.example/a'], 'URL credentials' => ['linkedin_url', 'https://me:secret@linkedin.com/a'],
            'wrong protocol' => ['linkedin_url', '//linkedin.com/in/a'], 'unknown field' => ['role_id', 1]];
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_profile_returns_422_and_preserves_account(string $field, mixed $value): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $this->patchJson(self::BASE, ['expected_version' => 1, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field, 'error.fields');
        $this->assertSame(1, $actor->fresh()->version);
    }
}
