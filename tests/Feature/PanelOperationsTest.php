<?php

namespace Tests\Feature;

use App\Actions\Auth\Login;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PanelOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_first_administrator_without_default_credentials(): void
    {
        $this->artisan('panel:admin-create')
            ->expectsQuestion('Username', 'Owner.Admin')
            ->expectsQuestion('Contact email', 'owner@example.test')
            ->expectsQuestion('Display name', 'Site Owner')
            ->expectsQuestion('Password (15-128 characters)', 'Protected initial password')
            ->expectsQuestion('Confirm password', 'Protected initial password')
            ->expectsOutput('Panel administrator created.')->assertSuccessful();
        $user = User::sole();
        $this->assertSame('owner.admin', $user->username_normalized);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->role_id);
        $this->assertTrue(Hash::check('Protected initial password', $user->password));
    }

    public function test_repeated_provisioning_never_resets_existing_password(): void
    {
        $user = User::factory()->panel()->create(['username' => 'owner.admin']);
        $hash = $user->password;
        $this->artisan('panel:admin-create')
            ->expectsQuestion('Username', 'OWNER.ADMIN')
            ->expectsQuestion('Contact email', 'different@example.test')
            ->expectsQuestion('Display name', 'Site Owner')
            ->expectsQuestion('Password (15-128 characters)', 'Protected replacement password')
            ->expectsQuestion('Confirm password', 'Protected replacement password')
            ->expectsOutput('That username already exists. No account was changed.')->assertFailed();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_operational_reset_revokes_sessions_and_records_the_event(): void
    {
        $user = User::factory()->panel()->create(['username' => 'owner.admin']);
        $session = (new PanelSessions)->create($user)['record'];
        $this->artisan('panel:admin-reset-password', ['username' => ' OWNER.ADMIN '])
            ->expectsQuestion('New password (15-128 characters)', 'Protected replacement password')
            ->expectsQuestion('Confirm password', 'Protected replacement password')
            ->expectsOutput('Password reset; all sessions revoked.')->assertSuccessful();
        $this->assertTrue(Hash::check('Protected replacement password', $user->fresh()->password));
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('panel_security_events', ['event' => 'admin_password_reset', 'user_id' => $user->id]);
    }

    public function test_operator_reset_failure_is_sanitized_and_keeps_old_password_and_sessions(): void
    {
        $user = User::factory()->panel()->create(['username' => 'owner.admin']);
        $hash = $user->password;
        $session = (new PanelSessions)->create($user)['record'];
        DB::statement("CREATE TRIGGER fail_reset_audit BEFORE INSERT ON panel_security_events BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        $this->artisan('panel:admin-reset-password', ['username' => 'owner.admin'])
            ->expectsQuestion('New password (15-128 characters)', 'Protected replacement password')
            ->expectsQuestion('Confirm password', 'Protected replacement password')
            ->expectsOutput('Account storage is unavailable or conflicted; the password reset was rolled back.')
            ->assertFailed();

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertNull($session->fresh()->revoked_at);
    }

    public function test_operator_commands_reject_noninteractive_input(): void
    {
        $this->artisan('panel:admin-create', ['--no-interaction' => true])->assertFailed();
        $this->artisan('panel:admin-reset-password', ['username' => 'missing', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_pruning_keeps_valid_sessions_and_only_ninety_days_of_audit_events(): void
    {
        $this->freezeTime();
        $user = User::factory()->panel()->create();
        $live = (new PanelSessions)->create($user)['record'];
        $expired = PanelSession::factory()->create(['idle_expires_at' => now()->subSecond()]);
        $revoked = PanelSession::factory()->create(['revoked_at' => now()]);
        foreach ([now()->subDays(91), now()->subDays(90), now()] as $time) {
            DB::table('panel_security_events')->insert(['event' => 'login_failed', 'request_id' => 'req_retention', 'outcome' => 'failure', 'created_at' => $time]);
        }
        $this->artisan('panel:auth-prune')->assertSuccessful();
        $this->assertDatabaseHas('panel_sessions', ['id' => $live->id]);
        $this->assertDatabaseMissing('panel_sessions', ['id' => $expired->id]);
        $this->assertDatabaseMissing('panel_sessions', ['id' => $revoked->id]);
        $this->assertDatabaseCount('panel_security_events', 2);
    }

    public function test_password_reset_committed_during_verification_prevents_stale_login(): void
    {
        $user = User::factory()->panel()->create(['username' => 'owner.admin', 'password' => 'Original password 123']);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($user, &$changed): void {
            if (! $changed && str_contains($query->sql, 'select') && str_contains($query->sql, 'username_normalized')) {
                $changed = true;
                $user->password = 'Replacement password 456';
                $user->save();
            }
        });
        try {
            app(Login::class)->handle('owner.admin', 'Original password 123', null, null, 'req_concurrent_reset');
            $this->fail('Stale verification must not issue a session.');
        } catch (PanelAuthException $exception) {
            $this->assertSame('INVALID_CREDENTIALS', $exception->errorCode);
        }
        $this->assertTrue($changed);
        $this->assertDatabaseCount('panel_sessions', 0);
    }

    public function test_restoring_a_deleted_account_does_not_restore_its_revoked_session(): void
    {
        $user = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($user);
        $user->delete();
        $user->restore();

        $this->assertFalse($user->fresh()->trashed());
        $this->assertNull((new PanelSessions)->resolve($session['token']));
        $this->assertNotNull($session['record']->fresh()->revoked_at);
    }

    public function test_overlapping_login_from_a_rotated_browser_context_cannot_create_a_second_session(): void
    {
        $user = User::factory()->panel()->create(['username' => 'owner.admin', 'password' => 'Original password 123']);
        $previous = (new PanelSessions)->create($user)['record'];
        $first = app(Login::class)->handle('owner.admin', 'Original password 123', $previous->id, null, 'req_first_rotation');
        try {
            app(Login::class)->handle('owner.admin', 'Original password 123', $previous->id, null, 'req_stale_rotation');
            $this->fail('A consumed browser context must not create another session.');
        } catch (PanelAuthException $exception) {
            $this->assertSame('CSRF_INVALID', $exception->errorCode);
        }

        $this->assertDatabaseCount('panel_sessions', 2);
        $this->assertNull($first['record']->fresh()->revoked_at);
        $this->assertNotNull($previous->fresh()->revoked_at);
    }

    public function test_model_security_update_rolls_back_if_revocation_audit_write_fails(): void
    {
        $user = User::factory()->panel()->create();
        $hash = $user->password;
        $session = (new PanelSessions)->create($user)['record'];
        DB::statement("CREATE TRIGGER fail_security_audit BEFORE INSERT ON panel_security_events BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        try {
            $user->password = 'Replacement password 456';
            $user->save();
            $this->fail('The security change must roll back.');
        } catch (QueryException) {
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertNull($session->fresh()->revoked_at);
        }
    }
}
