<?php

namespace Database\Factories;

use App\Models\GoldPlatform;
use App\Models\GoldPlatformCategory;
use App\Models\User;
use App\Support\GoldPlatformSpecifications;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<GoldPlatform>
 */
class GoldPlatformFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->company();
        $description = 'Gold platform profile description';
        $user = User::factory()->panel();

        return [
            'kind' => 'gold-platforms', 'title' => $title,
            'title_normalized' => PlatformText::panel($title), 'title_public_normalized' => PlatformText::public($title),
            'english_name' => $title, 'english_name_normalized' => PlatformText::panel($title), 'english_public_normalized' => PlatformText::public($title),
            'slug' => fake()->unique()->slug(2), 'description' => $description,
            'description_normalized' => PlatformText::panel($description), 'description_public_normalized' => PlatformText::public($description),
            'markets_public_normalized' => null, 'logo' => '', 'website_url' => '', 'founded_year' => '',
            'specifications' => GoldPlatformSpecifications::empty(), 'content' => '<p>Gold platform review content</p>', 'meta_description' => '',
            'category_id' => GoldPlatformCategory::factory(), 'status' => 'draft', 'author_id' => $user,
            'created_by' => $user, 'updated_by' => $user, 'version' => 1, 'title_sort_key' => PlatformText::persianSortKey($title),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (GoldPlatform $platform): void {
            $platform->forceFill([
                'kind' => 'gold-platforms', 'title_normalized' => PlatformText::panel($platform->title),
                'title_public_normalized' => PlatformText::public($platform->title),
                'english_name_normalized' => PlatformText::panel($platform->english_name),
                'english_public_normalized' => PlatformText::public($platform->english_name),
                'description_normalized' => PlatformText::panel($platform->description),
                'description_public_normalized' => PlatformText::public($platform->description),
                'title_sort_key' => PlatformText::persianSortKey($platform->title),
            ]);
        })->afterCreating(function (GoldPlatform $platform): void {
            DB::table('gold_platform_slug_ownership')->insert([
                'slug' => $platform->slug, 'gold_platform_id' => $platform->id,
                'was_public' => $platform->status === 'published', 'created_at' => $platform->created_at,
            ]);
        });
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}
