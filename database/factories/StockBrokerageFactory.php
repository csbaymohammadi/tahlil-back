<?php

namespace Database\Factories;

use App\Models\StockBrokerage;
use App\Models\StockBrokerageCategory;
use App\Models\User;
use App\Support\PlatformText;
use App\Support\PublicText;
use App\Support\StockBrokerSpecifications;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<StockBrokerage> */
class StockBrokerageFactory extends Factory
{
    protected $model = StockBrokerage::class;

    public function configure(): static
    {
        return $this->afterMaking(function (StockBrokerage $brokerage): void {
            $brokerage->forceFill([
                'kind' => 'stock-brokers', 'title_normalized' => PlatformText::panel($brokerage->title), 'title_public_normalized' => PlatformText::public($brokerage->title),
                'english_name_normalized' => PlatformText::panel($brokerage->english_name), 'english_public_normalized' => PlatformText::public($brokerage->english_name),
                'description_normalized' => PlatformText::panel($brokerage->description), 'description_public_normalized' => PlatformText::public($brokerage->description !== '' ? $brokerage->description : PublicText::plain($brokerage->content)),
                'title_sort_key' => PlatformText::persianSortKey($brokerage->title),
            ]);
        })->afterCreating(function (StockBrokerage $brokerage): void {
            DB::table('stock_brokerage_slug_ownership')->insert(['slug' => $brokerage->slug, 'stock_brokerage_id' => $brokerage->id, 'was_public' => $brokerage->status === 'published', 'created_at' => $brokerage->created_at]);
        });
    }

    public function definition(): array
    {
        $title = fake()->unique()->company();
        $user = User::factory()->panel();
        $description = 'Stock brokerage profile description';

        return [
            'kind' => 'stock-brokers', 'title' => $title, 'title_normalized' => PlatformText::panel($title), 'title_public_normalized' => PlatformText::public($title),
            'english_name' => $title, 'english_name_normalized' => PlatformText::panel($title), 'english_public_normalized' => PlatformText::public($title),
            'slug' => fake()->unique()->slug(2), 'description' => $description, 'description_normalized' => PlatformText::panel($description), 'description_public_normalized' => PlatformText::public($description),
            'logo' => '', 'website_url' => '', 'founded_year' => '', 'specifications' => StockBrokerSpecifications::empty(),
            'content' => '<p>Stock brokerage review content</p>', 'meta_description' => '', 'category_id' => StockBrokerageCategory::factory(),
            'status' => 'draft', 'author_id' => $user, 'created_by' => $user, 'updated_by' => $user, 'version' => 1,
            'title_sort_key' => PlatformText::persianSortKey($title),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}
