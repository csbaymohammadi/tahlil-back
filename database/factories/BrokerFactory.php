<?php

namespace Database\Factories;

use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\User;
use App\Support\BrokerSpecifications;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Broker> */
class BrokerFactory extends Factory
{
    protected $model = Broker::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Broker $broker): void {
            $broker->forceFill([
                'title_normalized' => PlatformText::panel($broker->title), 'title_public_normalized' => PlatformText::public($broker->title),
                'english_name_normalized' => PlatformText::panel($broker->english_name), 'english_public_normalized' => PlatformText::public($broker->english_name),
                'description_normalized' => PlatformText::panel($broker->description), 'description_public_normalized' => PlatformText::public($broker->description),
                'markets_public_normalized' => PlatformText::public($broker->markets),
                'title_sort_key' => PlatformText::persianSortKey($broker->title),
            ]);
        })->afterCreating(function (Broker $broker): void {
            DB::table('broker_slug_ownership')->insert(['slug' => $broker->slug, 'broker_id' => $broker->id, 'was_public' => $broker->status === 'published', 'created_at' => $broker->created_at]);
        });
    }

    public function definition(): array
    {
        $title = fake()->unique()->company();
        $user = User::factory()->panel();
        $description = 'Broker profile description';

        return [
            'title' => $title, 'title_normalized' => PlatformText::panel($title), 'title_public_normalized' => PlatformText::public($title),
            'english_name' => $title, 'english_name_normalized' => PlatformText::panel($title), 'english_public_normalized' => PlatformText::public($title),
            'slug' => fake()->unique()->slug(2), 'description' => $description, 'description_normalized' => PlatformText::panel($description), 'description_public_normalized' => PlatformText::public($description),
            'logo' => '/images/brokers/example.png', 'website_url' => 'https://example.org/', 'founded_year' => '2022',
            'platforms' => 'WebTrader', 'markets' => 'Forex and CFDs', 'access' => 'Web and mobile', 'account_currency' => 'USD',
            'specifications' => BrokerSpecifications::empty(), 'content' => '<p>Broker review content</p>', 'meta_description' => '',
            'category_id' => BrokerCategory::factory(), 'status' => 'draft', 'author_id' => $user, 'created_by' => $user, 'updated_by' => $user,
            'version' => 1, 'title_sort_key' => PlatformText::persianSortKey($title),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}
