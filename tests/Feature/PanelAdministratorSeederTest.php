<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PanelAdministratorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\PanelTestCase;

class PanelAdministratorSeederTest extends PanelTestCase
{
    use RefreshDatabase;

    private function settings(): void
    {
        config(['panel.seed_administrator' => ['username' => 'admin', 'name' => 'Panel Owner',
            'email' => 'owner@example.test', 'password' => 'Private initial seed password']]);
    }

    public function test_configured_seed_account_can_authenticate_and_use_the_management_api(): void
    {
        $this->settings();
        $this->seed(DatabaseSeeder::class);
        $user = User::where('username_normalized', 'admin')->sole();
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('Private initial seed password', $user->password));
        $session = (new PanelSessions)->create();
        $this->withCredentials()->withUnencryptedCookie(PanelSessions::CONTEXT_COOKIE, $session['token'])
            ->withHeader('X-CSRF-Token', $session['store']->token());
        $response = $this->postJson('/api/v1/admin/auth/login', ['username' => 'admin', 'password' => 'Private initial seed password'])->assertOk();
        $this->withUnencryptedCookie(PanelSessions::COOKIE, $response->getCookie(PanelSessions::COOKIE, false)->getValue());
        $this->get('/api/v1/admin/users')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_repeated_seed_does_not_reset_password_or_reactivate_existing_accounts(): void
    {
        $this->settings();
        $this->seed(DatabaseSeeder::class);
        $user = User::where('username_normalized', 'admin')->sole();
        $user->password = 'Changed private password';
        $user->is_active = false;
        $user->save();
        $before = $user->fresh()->getAttributes();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, User::where('username_normalized', 'admin')->count());
        $user->delete();
        $this->seed(DatabaseSeeder::class);
        $this->assertSoftDeleted($user);
        $this->assertSame(1, User::withTrashed()->where('username_normalized', 'admin')->count());
    }

    public function test_missing_private_seed_credentials_do_not_create_an_admin_with_a_default_password(): void
    {
        config(['panel.seed_administrator.email' => null, 'panel.seed_administrator.password' => null]);
        $this->seed(PanelAdministratorSeeder::class);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_seed_rejects_short_passwords_before_creating_an_account(): void
    {
        $this->settings();
        config(['panel.seed_administrator.password' => 'short']);
        try {
            $this->seed(PanelAdministratorSeeder::class);
            $this->fail('A weak seed password must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_seed_never_overwrites_another_account_that_reserved_its_email(): void
    {
        $this->settings();
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $before = $user->fresh()->getAttributes();
        try {
            $this->seed(PanelAdministratorSeeder::class);
            $this->fail('The reserved email must not be reused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reserved', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }
}
