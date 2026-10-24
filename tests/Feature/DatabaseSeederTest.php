<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.seed_administrator.email' => null, 'panel.seed_administrator.password' => null]);
    }

    public function test_repeated_seeding_creates_the_default_user_only_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::sole();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($user->getAttributes(), $user->fresh()->getAttributes());
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('Test User', $user->name);
        $this->assertFalse($user->is_active);
    }

    public function test_seeding_preserves_the_existing_users_details_and_password(): void
    {
        $user = User::factory()->panel()->create([
            'email' => 'test@example.com',
            'name' => 'Existing Administrator',
            'password' => 'Existing private password',
        ]);
        $attributes = $user->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($attributes, $user->fresh()->getAttributes());
    }

    public function test_seeding_preserves_soft_deleted_users_without_recreating_them(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);
        $user->delete();
        $attributes = $user->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSoftDeleted($user);
        $this->assertSame($attributes, $user->fresh()->getAttributes());
    }
}
