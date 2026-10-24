<?php

namespace Database\Factories;

use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\User;
use App\Support\ExchangeSpecifications;
use App\Support\ExchangeText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Exchange>
 */
class ExchangeFactory extends Factory
{
    protected $model = Exchange::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Exchange $exchange): void {
            $exchange->forceFill([
                'title_normalized' => ExchangeText::panel($exchange->title),
                'title_public_normalized' => ExchangeText::public($exchange->title),
                'title_sort_key' => ExchangeText::persianSortKey($exchange->title),
                'english_name_normalized' => ExchangeText::panel($exchange->english_name),
                'english_public_normalized' => ExchangeText::public($exchange->english_name),
                'description_normalized' => ExchangeText::panel($exchange->description),
                'description_public_normalized' => ExchangeText::public($exchange->description),
            ]);
        })->afterCreating(function (Exchange $exchange): void {
            DB::table('exchange_slug_ownership')->insert([
                'slug' => $exchange->slug,
                'exchange_id' => $exchange->id,
                'was_public' => $exchange->status === 'published',
                'created_at' => $exchange->created_at,
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->company();
        $englishName = fake()->unique()->company();
        $user = User::factory()->panel();
        $description = 'Exchange profile description';

        return [
            'title' => $title, 'title_normalized' => ExchangeText::panel($title), 'title_public_normalized' => ExchangeText::public($title),
            'english_name' => $englishName, 'english_name_normalized' => ExchangeText::panel($englishName), 'english_public_normalized' => ExchangeText::public($englishName),
            'slug' => fake()->unique()->slug(2), 'description' => $description, 'description_normalized' => ExchangeText::panel($description), 'description_public_normalized' => ExchangeText::public($description),
            'logo' => '/images/exchanges/example.png', 'website_url' => 'https://example.org/', 'founded_year' => '2022',
            'platform_type' => 'Centralized exchange', 'markets' => 'Spot and futures', 'access' => 'Web and mobile application', 'support' => 'Online support',
            'specifications' => ExchangeSpecifications::empty(), 'content' => '<p>Exchange review content</p>', 'meta_description' => '',
            'category_id' => ExchangeCategory::factory(), 'status' => 'draft', 'author_id' => $user, 'created_by' => $user, 'updated_by' => $user,
            'version' => 1, 'title_sort_key' => ExchangeText::persianSortKey($title),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}
