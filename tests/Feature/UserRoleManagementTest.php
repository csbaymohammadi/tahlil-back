<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class UserRoleManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin';

    private function fields(Role $role): array
    {
        return ['username' => 'new.editor', 'name' => 'مدیر نمونه', 'email' => 'editor@example.test',
            'password' => 'A private initial password', 'role_id' => $role->id, 'is_active' => true];
    }

    public function test_guests_cannot_list_or_mutate_users_or_roles(): void
    {
        $this->get(self::BASE.'/users')->assertUnauthorized();
        $this->get(self::BASE.'/roles')->assertUnauthorized();
        $this->postJson(self::BASE.'/roles', ['name' => 'Writer'])->assertUnauthorized();
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_roleless_administrator_can_create_an_account_used_by_authentication(): void
    {
        $actor = User::factory()->panel()->create();
        $session = $this->panel($actor);
        $role = Role::factory()->create();
        $response = $this->postJson(self::BASE.'/users', $this->fields($role))->assertCreated()
            ->assertJsonPath('data.username', 'new.editor')->assertJsonPath('data.version', 1)
            ->assertHeader('Location', self::BASE.'/users/2')->assertJsonMissingPath('data.password');
        $user = User::where('username_normalized', 'new.editor')->sole();
        $this->assertTrue(Hash::check('A private initial password', $user->password));
        $this->assertDatabaseHas('panel_security_events', ['event' => 'admin_created', 'actor_id' => $actor->id, 'target_id' => $user->id]);
        $this->assertNull($session['record']->fresh()->revoked_at);
        $this->assertSame(2, $response->json('data.id'));
    }

    public function test_user_list_has_deterministic_pagination_and_excludes_legacy_and_deleted_rows(): void
    {
        $this->freezeTime();
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        User::factory()->create();
        $deleted = User::factory()->panel()->create();
        $deleted->delete();
        $last = User::factory()->panel()->count(11)->create(['is_active' => false])->last();
        $this->get(self::BASE.'/users')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.0.is_active', false);
        $this->get(self::BASE.'/users?page=9&per_page=20')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.page', 9)->assertJsonPath('meta.total', 12);
        $this->get(self::BASE.'/users?per_page=11')->assertUnprocessable();
        $this->get(self::BASE.'/roles?unexpected=1')->assertUnprocessable();
    }

    public function test_display_edit_and_noop_do_not_revoke_sessions_or_advance_security_version(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $user = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($user)['record'];
        $hash = $user->password;
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 1, 'name' => 'Edited', 'password' => ''])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 2, 'name' => 'Edited'])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertNull($session->fresh()->revoked_at);
        $this->assertSame(1, $user->fresh()->security_version);
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_stale_versions_and_invalid_combined_updates_change_nothing(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $user = User::factory()->panel()->create(['name' => 'Original']);
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 99, 'name' => 'Bad'])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 1, 'name' => 'Bad', 'role_id' => 999])
            ->assertUnprocessable();
        $this->assertSame('Original', $user->fresh()->name);
        $this->delete(self::BASE.'/users/'.$user->id.'?expected_version=99')->assertConflict();
        $this->assertNotSoftDeleted($user);
    }

    public function test_self_deactivation_deletion_and_password_bypass_are_rejected(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $this->patchJson(self::BASE.'/users/'.$actor->id, ['expected_version' => 1, 'is_active' => false])
            ->assertConflict()->assertJsonPath('error.code', 'SELF_ACCOUNT_PROTECTED');
        $this->delete(self::BASE.'/users/'.$actor->id.'?expected_version=1')->assertConflict();
        $this->patchJson(self::BASE.'/users/'.$actor->id, ['expected_version' => 1, 'password' => 'New private replacement password'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SELF_PASSWORD_CHANGE_REQUIRED');
        $this->patchJson(self::BASE.'/users/'.$actor->id, ['expected_version' => 1, 'is_active' => true])
            ->assertOk()->assertJsonPath('data.version', 1);
    }

    public function test_credentials_and_email_remain_reserved_after_soft_deletion(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $role = Role::factory()->create();
        $deleted = User::factory()->panel()->create(['username' => 'new.editor', 'email' => 'editor@example.test']);
        $deleted->delete();
        $this->postJson(self::BASE.'/users', $this->fields($role))->assertConflict()->assertJsonPath('error.code', 'USERNAME_TAKEN');
        $this->postJson(self::BASE.'/users', [...$this->fields($role), 'username' => 'different.user', 'email' => ' EDITOR@EXAMPLE.TEST '])
            ->assertConflict()->assertJsonPath('error.code', 'EMAIL_TAKEN');
    }

    public function test_password_replacement_and_username_change_revoke_only_the_target_sessions(): void
    {
        $actor = User::factory()->panel()->create();
        $actorSession = $this->panel($actor)['record'];
        $user = User::factory()->panel()->create(['password' => 'Original private password']);
        $session = (new PanelSessions)->create($user)['record'];
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 1, 'password' => 'Replacement private password'])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertNull($actorSession->fresh()->revoked_at);
        $this->assertTrue(Hash::check('Replacement private password', $user->fresh()->password));
        $second = (new PanelSessions)->create($user->fresh())['record'];
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 2, 'username' => 'renamed.editor'])->assertOk();
        $this->assertNotNull($second->fresh()->revoked_at);
    }

    public function test_deactivation_reactivation_and_delete_never_revive_old_sessions(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $user = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($user);
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 1, 'is_active' => false])->assertOk();
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 2, 'is_active' => true])->assertOk();
        $this->assertNull((new PanelSessions)->resolve($session['token']));
        $this->delete(self::BASE.'/users/'.$user->id.'?expected_version=3')->assertNoContent();
        $this->assertSoftDeleted($user);
        $this->assertFalse($user->fresh()->is_active);
        $this->get(self::BASE.'/users/'.$user->id)->assertNotFound()->assertJsonPath('error.code', 'USER_NOT_FOUND');
        $this->delete(self::BASE.'/users/'.$user->id.'?expected_version=4')->assertNotFound();
    }

    public function test_roles_are_unpaginated_normalized_and_reserved_when_deleted(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        Role::factory()->count(12)->create();
        $response = $this->postJson(self::BASE.'/roles', ['name' => '  مدير كاربري  '])->assertCreated()->assertJsonPath('data.version', 1);
        $id = $response->json('data.id');
        $this->postJson(self::BASE.'/roles', ['name' => 'مدیر کاربری'])->assertConflict()->assertJsonPath('error.code', 'ROLE_NAME_TAKEN');
        $this->get(self::BASE.'/roles')->assertOk()->assertJsonCount(13, 'data');
        $this->delete(self::BASE.'/roles/'.$id.'?expected_version=1')->assertOk();
        $this->postJson(self::BASE.'/roles', ['name' => 'مدیر کاربری'])->assertConflict();
    }

    public function test_deleting_own_role_clears_assignments_versions_and_preserves_sessions(): void
    {
        $role = Role::factory()->create(['name' => 'Administrator']);
        $actor = User::factory()->panel()->create(['role_id' => $role->id]);
        $session = $this->panel($actor)['record'];
        $inactive = User::factory()->panel()->create(['role_id' => $role->id, 'is_active' => false]);
        $this->get(self::BASE.'/roles/'.$role->id)->assertJsonPath('data.users_count', 2);
        $this->patchJson(self::BASE.'/roles/'.$role->id, ['expected_version' => 1, 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.version', 2);
        $this->get(self::BASE.'/auth/me')->assertJsonPath('data.user.role.name', 'Renamed');
        $this->delete(self::BASE.'/roles/'.$role->id.'?expected_version=2')->assertOk()->assertJsonPath('data.affected_users_count', 2);
        $this->assertNull($actor->fresh()->role_id);
        $this->assertSame(2, $actor->fresh()->version);
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertNull($session->fresh()->revoked_at);
        $this->get(self::BASE.'/auth/me')->assertOk()->assertJsonPath('data.user.role', null);
        $this->assertDatabaseHas('panel_security_events', ['event' => 'role_deleted', 'actor_id' => $actor->id, 'affected_users_count' => 2]);
    }

    public function test_role_seeding_is_idempotent_and_does_not_restore_deleted_labels(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->assertDatabaseCount('roles', 4);
        Role::where('name', 'Writer')->firstOrFail()->delete();
        $this->seed(RoleSeeder::class);
        $this->assertDatabaseCount('roles', 4);
        $this->assertSame(3, Role::count());
    }

    #[DataProvider('invalidFields')]
    public function test_user_fields_are_strictly_validated(array $override): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $role = Role::factory()->create();
        $this->postJson(self::BASE.'/users', [...$this->fields($role), ...$override])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
    }

    public static function invalidFields(): array
    {
        return ['boolean string' => [['is_active' => 'true']], 'role string' => [['role_id' => '1']],
            'null password' => [['password' => null]], 'invalid username' => [['username' => 'not valid']],
            'empty name' => [['name' => '   ']], 'unknown permission' => [['permissions' => ['all']]],
            'null role' => [['role_id' => null]], 'null status' => [['is_active' => null]]];
    }

    public function test_mutations_keep_csrf_protection_for_any_origin(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'bad');
        $this->postJson(self::BASE.'/roles', ['name' => 'Writer'])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->withHeader('Origin', 'https://evil.example');
        $this->postJson(self::BASE.'/roles', ['name' => 'Writer'])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_audit_failure_rolls_back_the_whole_management_mutation(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $user = User::factory()->panel()->create(['name' => 'Original']);
        DB::statement("CREATE TRIGGER deny_management_audit BEFORE INSERT ON panel_security_events BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->patchJson(self::BASE.'/users/'.$user->id, ['expected_version' => 1, 'name' => 'Unsaved'])->assertStatus(503);
        $this->assertSame('Original', $user->fresh()->name);
        $this->assertSame(1, $user->fresh()->version);
    }

    public function test_delete_version_must_be_in_the_query_and_body_fields_are_rejected(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $target = User::factory()->panel()->create();
        $this->deleteJson(self::BASE.'/users/'.$target->id.'?expected_version=1', ['expected_version' => 1])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertNotSoftDeleted($target);
    }
}
