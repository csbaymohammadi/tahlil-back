<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, PanelAdministratorSeeder::class, ExchangeCategorySeeder::class, BrokerCategorySeeder::class, StockBrokerageCategorySeeder::class, GoldPlatformCategorySeeder::class]);
        User::withTrashed()->firstOrCreate(
            ['email' => 'test@example.com'],
            fn (): array => User::factory()->raw([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]),
        );
    }
}
