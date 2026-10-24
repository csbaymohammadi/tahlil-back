<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Models\PanelSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PanelAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/auth';

    private const PASSWORD = 'Original password 123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.rate_store' => 'database']);
        $this->withCredentials()->withHeader('Origin', 'http://localhost');
    }

    private function administrator(array $attributes = []): User
    {
        return User::factory()->panel()->create(['username' => 'editor.demo', 'password' => self::PASSWORD, ...$attributes]);
    }

    private function csrf(): string
    {
        $response = $this->get(self::BASE.'/csrf')->assertOk();
        if ($cookie = $response->getCookie(PanelSessions::CONTEXT_COOKIE, false)) {
            if ($cookie->getValue() !== '') {
                $this->withUnencryptedCookie(PanelSessions::CONTEXT_COOKIE, $cookie->getValue());
            }
        }
        $token = $response->json('data.csrf_token');
        $this->withHeader('X-CSRF-Token', $token);

        return $token;
    }

    private function login(string $username = 'editor.demo', string $password = self::PASSWORD): TestResponse
    {
        $this->csrf();
        $response = $this->postJson(self::BASE.'/login', compact('username', 'password'));
        if ($response->status() === 200) {
            $this->withUnencryptedCookie(PanelSessions::COOKIE, $response->getCookie(PanelSessions::COOKIE, false)->getValue());
            $this->withHeader('X-CSRF-Token', $response->json('data.csrf_token'));
        }

        return $response;
    }

    public function test_login_returns_authoritative_identity_and_only_a_hashed_token_is_stored(): void
    {
        $user = $this->administrator();
        $response = $this->login(' EDITOR.DEMO ')->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', null)->assertJsonPath('data.user.avatar_url', null);
        $cookie = $response->getCookie(PanelSessions::COOKIE, false);
        $session = PanelSession::findOrFail($response->json('data.session.id'));
        $this->assertSame(hash('sha256', $cookie->getValue()), $session->token_hash);
        $this->assertNotSame($session->id, $cookie->getValue());
        $this->assertStringNotContainsString($cookie->getValue(), $response->getContent());
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('request_id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertContains('Cookie', $response->headers->all('Vary'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->get(self::BASE.'/me')->assertOk()->assertJsonPath('data.user.username', 'editor.demo')->assertJsonMissingPath('data.csrf_token');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('panel_security_events', ['event' => 'login_succeeded', 'user_id' => $user->id]);
    }

    public function test_csrf_context_is_stable_across_tabs_and_expires_after_fifteen_minutes(): void
    {
        $this->freezeTime();
        $first = $this->csrf();
        $this->assertSame($first, $this->csrf());
        $this->travel(15)->minutes();
        $this->postJson(self::BASE.'/login', ['username' => 'editor.demo', 'password' => self::PASSWORD])
            ->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->assertNotSame($first, $this->csrf());
    }

    public function test_login_requires_token_even_when_fetch_metadata_says_same_origin(): void
    {
        $this->administrator();
        $this->csrf();
        $this->withHeader('X-CSRF-Token', 'wrong')->withHeader('Sec-Fetch-Site', 'same-origin');
        $this->postJson(self::BASE.'/login', ['username' => 'editor.demo', 'password' => self::PASSWORD])
            ->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->assertDatabaseCount('panel_sessions', 1);
    }

    public function test_anonymous_context_cannot_be_replayed_after_login(): void
    {
        $this->administrator();
        $token = $this->csrf();
        $this->postJson(self::BASE.'/login', ['username' => 'editor.demo', 'password' => self::PASSWORD])->assertOk();
        $this->withHeader('X-CSRF-Token', $token);
        $this->postJson(self::BASE.'/login', ['username' => 'editor.demo', 'password' => self::PASSWORD])->assertForbidden();
    }

    #[DataProvider('invalidAccounts')]
    public function test_invalid_accounts_have_the_same_generic_response(string $state): void
    {
        if ($state !== 'unknown') {
            $user = $this->administrator(['is_active' => $state !== 'inactive']);
            if ($state === 'deleted') {
                $user->delete();
            }
        }
        $this->login(password: $state === 'wrong' ? 'Wrong password 123' : self::PASSWORD)
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', 'The username or password is incorrect.');
        $this->assertDatabaseMissing('panel_sessions', ['user_id' => 1, 'revoked_at' => null]);
    }

    public static function invalidAccounts(): array
    {
        return [['unknown'], ['wrong'], ['inactive'], ['deleted']];
    }

    public function test_username_is_not_an_email_login_alias(): void
    {
        $user = $this->administrator();
        $this->login($user->email)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_password_whitespace_and_unicode_are_preserved(): void
    {
        $password = '  عبارت عبور با فاصله ۱۲۳  ';
        $this->administrator(['password' => $password]);
        $this->login(password: trim($password))->assertUnauthorized();
        $this->login(password: $password)->assertOk();
    }

    public function test_arbitrary_or_missing_origin_is_accepted_with_valid_csrf(): void
    {
        $this->administrator();
        $this->withHeader('Origin', 'https://frontend.example');
        $this->login()->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withoutHeader('Origin');
        $this->login()->assertOk();
    }

    public function test_cors_preflight_accepts_any_domain_and_custom_headers_without_authentication(): void
    {
        $this->options('/api/v1/admin/media', [], [
            'Origin' => 'https://another-frontend.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-csrf-token,idempotency-key',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://another-frontend.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertDatabaseCount('panel_sessions', 0);
    }

    public function test_login_fields_are_allowlisted_and_roles_cannot_be_submitted(): void
    {
        $this->administrator();
        $this->csrf();
        $this->postJson(self::BASE.'/login', ['username' => 'editor.demo', 'password' => self::PASSWORD, 'role_id' => 1])
            ->assertUnprocessable()->assertJsonPath('error.fields.role_id.0', 'This field is not accepted.');
    }

    #[DataProvider('transportCases')]
    public function test_transport_errors_follow_contract(string $body, string $contentType, int $status, string $code): void
    {
        $response = $this->call('POST', self::BASE.'/login', [], [], [], ['CONTENT_TYPE' => $contentType], $body);
        $response->assertStatus($status)->assertJsonPath('error.code', $code);
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
    }

    public static function transportCases(): array
    {
        return [
            ['{bad', 'application/json', 400, 'INVALID_JSON'],
            ['{}', 'text/plain', 415, 'UNSUPPORTED_MEDIA_TYPE'],
            [str_repeat('x', 16385), 'application/json', 413, 'PAYLOAD_TOO_LARGE'],
            ['[]', 'application/json', 422, 'VALIDATION_FAILED'],
        ];
    }

    public function test_guest_and_former_cookie_cannot_access_identity(): void
    {
        $this->get(self::BASE.'/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withUnencryptedCookie('tahlil-panel-session', 'old-signed-cookie');
        $this->withUnencryptedCookie(PanelSessions::COOKIE, 'session-public-id');
        $this->get(self::BASE.'/me')->assertUnauthorized();
    }

    public function test_role_changes_and_role_deletion_do_not_revoke_access(): void
    {
        $role = Role::factory()->create(['name' => 'Writer']);
        $user = $this->administrator(['role_id' => $role->id]);
        $this->login()->assertJsonPath('data.user.role.name', 'Writer');
        $role->update(['name' => 'Developer']);
        $this->get(self::BASE.'/me')->assertOk()->assertJsonPath('data.user.role.name', 'Developer');
        $role->delete();
        $this->get(self::BASE.'/me')->assertOk()->assertJsonPath('data.user.role', null);
        $user->refresh()->name = 'New display';
        $user->save();
        $this->get(self::BASE.'/me')->assertOk()->assertJsonPath('data.user.name', 'New display');
    }

    public function test_idle_and_absolute_expiration_use_the_server_clock(): void
    {
        $this->freezeTime();
        $this->administrator();
        $response = $this->login()->assertOk();
        $this->travel(23)->hours();
        $this->get(self::BASE.'/me')->assertOk();
        $this->travel(24)->hours();
        $this->get(self::BASE.'/me')->assertUnauthorized();
        $response = $this->login()->assertOk();
        $sessionId = $response->json('data.session.id');
        for ($day = 0; $day < 7; $day++) {
            $this->travel(23)->hours();
            $this->get(self::BASE.'/me')->assertOk();
        }
        $this->get(self::BASE.'/me')->assertJsonPath('data.session.expires_at', $response->json('data.session.absolute_expires_at'));
        $this->travel(7)->hours();
        $this->get(self::BASE.'/me')->assertUnauthorized();
        $this->assertSame($response->json('data.session.absolute_expires_at'), PanelSession::find($sessionId)->absolute_expires_at->toIso8601ZuluString());
    }

    public function test_logout_revokes_current_device_and_is_safe_to_repeat_without_csrf(): void
    {
        $user = $this->administrator();
        $other = (new PanelSessions)->create($user)['record'];
        $login = $this->login()->assertOk();
        $this->post(self::BASE.'/logout')->assertNoContent();
        $this->get(self::BASE.'/me')->assertUnauthorized();
        $this->withoutHeader('X-CSRF-Token');
        $this->post(self::BASE.'/logout')->assertNoContent()->assertHeader('X-Request-ID');
        $this->assertDatabaseHas('panel_sessions', ['id' => $login->json('data.session.id'), 'revoke_reason' => 'logout']);
        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_valid_session_cannot_logout_with_invalid_csrf(): void
    {
        $this->administrator();
        $this->login()->assertOk();
        $this->withHeader('X-CSRF-Token', 'wrong');
        $this->post(self::BASE.'/logout')->assertForbidden();
        $this->get(self::BASE.'/me')->assertOk();
    }

    public function test_second_login_rotates_only_the_same_browser(): void
    {
        $user = $this->administrator();
        $other = (new PanelSessions)->create($user)['record'];
        $first = $this->login()->assertOk();
        $second = $this->login()->assertOk();
        $this->assertNotSame($first->json('data.session.id'), $second->json('data.session.id'));
        $this->assertDatabaseHas('panel_sessions', ['id' => $first->json('data.session.id'), 'revoke_reason' => 'rotation']);
        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_logout_all_confirms_password_and_does_not_revoke_another_account(): void
    {
        $user = $this->administrator();
        $other = (new PanelSessions)->create($user)['record'];
        $outsider = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $this->login()->assertOk();
        $this->postJson(self::BASE.'/logout-all', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertNull($other->fresh()->revoked_at);
        $this->postJson(self::BASE.'/logout-all', ['current_password' => self::PASSWORD])->assertNoContent();
        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertNull($outsider->fresh()->revoked_at);
        $this->get(self::BASE.'/me')->assertUnauthorized();
    }

    public function test_password_change_revokes_every_session_and_only_new_password_works(): void
    {
        $user = $this->administrator();
        $other = (new PanelSessions)->create($user)['record'];
        $this->login()->assertOk();
        $new = '  رمز عبور جدید ۱۲۳۴۵۶  ';
        $this->postJson(self::BASE.'/password', ['current_password' => self::PASSWORD, 'new_password' => $new])->assertNoContent();
        $this->assertTrue(Hash::check($new, $user->fresh()->password));
        $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
        $this->assertNotNull($other->fresh()->revoked_at);
        $this->get(self::BASE.'/me')->assertUnauthorized();
        $this->login()->assertUnauthorized();
        $this->login(password: $new)->assertOk();
    }

    #[DataProvider('passwordFailures')]
    public function test_password_policy_failures_leave_hash_and_session_unchanged(string $current, string $new, string $field): void
    {
        $user = $this->administrator();
        $hash = $user->password;
        $this->login()->assertOk();
        $this->postJson(self::BASE.'/password', ['current_password' => $current, 'new_password' => $new])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => [$field]]]);
        $this->assertSame($hash, $user->fresh()->password);
        $this->get(self::BASE.'/me')->assertOk();
    }

    public static function passwordFailures(): array
    {
        return [['wrong', 'Valid replacement password', 'current_password'], [self::PASSWORD, 'short', 'new_password'],
            [self::PASSWORD, str_repeat('x', 129), 'new_password'], [self::PASSWORD, self::PASSWORD, 'new_password']];
    }

    #[DataProvider('securityChanges')]
    public function test_account_security_changes_revoke_access_and_reactivation_does_not_restore_it(string $change): void
    {
        $user = $this->administrator();
        $this->login()->assertOk();
        match ($change) {
            'username' => $user->forceFill(['username' => 'renamed.admin'])->save(),
            'password' => $user->forceFill(['password' => 'Replacement password 456'])->save(),
            'disabled' => $user->forceFill(['is_active' => false])->save(),
            'deleted' => $user->delete(),
        };
        $this->get(self::BASE.'/me')->assertUnauthorized();
        if ($change === 'disabled') {
            $user->forceFill(['is_active' => true])->save();
            $this->get(self::BASE.'/me')->assertUnauthorized();
        }
    }

    public static function securityChanges(): array
    {
        return [['username'], ['password'], ['disabled'], ['deleted']];
    }

    public function test_five_failed_logins_block_sixth_with_retry_after_even_for_unknown_username(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login()->assertUnauthorized();
        }
        $this->login()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertDatabaseHas('panel_security_events', ['event' => 'login_rate_limited']);
    }

    public function test_five_incorrect_password_confirmations_block_security_operations(): void
    {
        $this->administrator();
        $this->login()->assertOk();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(self::BASE.'/logout-all', ['current_password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(self::BASE.'/password', ['current_password' => self::PASSWORD, 'new_password' => 'Valid replacement password'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_aggregate_ip_limit_includes_successes_and_ignores_untrusted_forwarded_ip(): void
    {
        $this->administrator();
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $this->login()->assertOk();
        }
        $this->withHeader('X-Forwarded-For', '198.51.100.77');
        $this->login()->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_success_resets_pair_failures_and_window_expiry_allows_new_attempts(): void
    {
        $this->freezeTime();
        $this->administrator();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->login(password: 'Incorrect password')->assertUnauthorized();
        }
        $this->login()->assertOk();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login(password: 'Incorrect password')->assertUnauthorized();
        }
        $this->login()->assertStatus(429);
        $this->travel(16)->minutes();
        $this->login()->assertOk();
    }

    public function test_tampered_cookie_and_public_session_identifier_are_not_credentials(): void
    {
        $this->administrator();
        $response = $this->login()->assertOk();
        $token = $response->getCookie(PanelSessions::COOKIE, false)->getValue();
        $this->withUnencryptedCookie(PanelSessions::COOKIE, ($token[0] === 'a' ? 'b' : 'a').substr($token, 1));
        $this->get(self::BASE.'/me')->assertUnauthorized();
        $this->withUnencryptedCookie(PanelSessions::COOKIE, $response->json('data.session.id'));
        $this->get(self::BASE.'/me')->assertUnauthorized();
    }

    public function test_new_request_with_fresh_session_service_preserves_identity_and_csrf(): void
    {
        $this->administrator();
        $response = $this->login()->assertOk();
        $token = $response->getCookie(PanelSessions::COOKIE, false)->getValue();
        $sessions = new PanelSessions;
        $this->assertNotNull($sessions->resolve($token));
        $this->assertSame($response->json('data.csrf_token'), $sessions->store($token)->token());
        $this->get(self::BASE.'/me')->assertOk();
    }

    public function test_authenticated_mutations_reject_other_user_ids_and_confirm_password_fields(): void
    {
        $this->administrator();
        $this->login()->assertOk();
        $this->postJson(self::BASE.'/logout-all', ['current_password' => self::PASSWORD, 'user_id' => 2])
            ->assertUnprocessable()->assertJsonPath('error.fields.user_id.0', 'This field is not accepted.');
        $this->postJson(self::BASE.'/password', ['current_password' => self::PASSWORD,
            'new_password' => 'Valid replacement password', 'confirm_password' => 'Valid replacement password'])
            ->assertUnprocessable()->assertJsonPath('error.fields.confirm_password.0', 'This field is not accepted.');
        $this->get(self::BASE.'/me')->assertOk();
    }

    public function test_credentials_in_query_strings_are_rejected_even_with_valid_json_and_csrf(): void
    {
        $this->administrator();
        $this->csrf();
        $this->call('POST', self::BASE.'/login?username=editor.demo&password=placeholder', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnprocessable()
            ->assertJsonPath('error.fields.username.0', 'Query fields are not accepted.');
    }

    #[DataProvider('invalidLoginFields')]
    public function test_login_field_validation_does_not_create_an_authenticated_session(array $payload, string $field): void
    {
        $this->csrf();
        $this->postJson(self::BASE.'/login', $payload)->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        $this->assertDatabaseMissing('panel_sessions', ['user_id' => 1]);
    }

    public static function invalidLoginFields(): array
    {
        return [
            'missing username' => [['password' => self::PASSWORD], 'username'],
            'short username' => [['username' => 'ab', 'password' => self::PASSWORD], 'username'],
            'long username' => [['username' => str_repeat('x', 121), 'password' => self::PASSWORD], 'username'],
            'numeric username' => [['username' => 123, 'password' => self::PASSWORD], 'username'],
            'missing password' => [['username' => 'editor.demo'], 'password'],
            'short password' => [['username' => 'editor.demo', 'password' => '1234567'], 'password'],
            'long password' => [['username' => 'editor.demo', 'password' => str_repeat('x', 257)], 'password'],
            'array password' => [['username' => 'editor.demo', 'password' => ['bad']], 'password'],
        ];
    }

    public function test_session_storage_failure_returns_503_instead_of_anonymous_logout_success(): void
    {
        DB::statement('DROP TABLE panel_sessions');
        $this->get(self::BASE.'/csrf')->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->withUnencryptedCookie(PanelSessions::COOKIE, str_repeat('a', 64));
        $this->post(self::BASE.'/logout')->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    }

    public function test_production_cookies_are_secure_and_legacy_cookie_is_expired(): void
    {
        config(['panel.secure_cookie' => true]);
        $this->administrator();
        $response = $this->login()->assertOk();
        $this->assertTrue($response->getCookie(PanelSessions::COOKIE, false)->isSecure());
        $legacy = $response->getCookie('tahlil-panel-session', false);
        $this->assertSame('/tahlilpanel', $legacy->getPath());
        $this->assertLessThan(time(), $legacy->getExpiresTime());
    }
}
