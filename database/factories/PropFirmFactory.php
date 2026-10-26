<?php

namespace Database\Factories;

use App\Models\PropFirm;
use App\Models\PropFirmCategory;
use App\Models\User;
use App\Support\PlatformText;
use App\Support\PropFirmSpecifications;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<PropFirm>
 */
class PropFirmFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->company();
        $description = 'Prop firm profile description';
        $user = User::factory()->panel();

        return [
            'kind' => 'prop-firms', 'title' => $title,
            'title_normalized' => PlatformText::panel($title), 'title_public_normalized' => PlatformText::public($title),
            'english_name' => $title, 'english_name_normalized' => PlatformText::panel($title), 'english_public_normalized' => PlatformText::public($title),
            'slug' => fake()->unique()->slug(2), 'description' => $description,
            'description_normalized' => PlatformText::panel($description), 'description_public_normalized' => PlatformText::public($description),
            'markets_public_normalized' => null, 'logo' => '', 'website_url' => '', 'founded_year' => '',
            'specifications' => PropFirmSpecifications::empty(), 'content' => '<p>Prop firm review content</p>', 'meta_description' => '',
            'category_id' => PropFirmCategory::factory(), 'status' => 'draft', 'author_id' => $user,
            'created_by' => $user, 'updated_by' => $user, 'version' => 1, 'title_sort_key' => PlatformText::persianSortKey($title),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PropFirm $platform): void {
            $platform->forceFill([
                'kind' => 'prop-firms', 'title_normalized' => PlatformText::panel($platform->title),
                'title_public_normalized' => PlatformText::public($platform->title),
                'english_name_normalized' => PlatformText::panel($platform->english_name),
                'english_public_normalized' => PlatformText::public($platform->english_name),
                'description_normalized' => PlatformText::panel($platform->description),
                'description_public_normalized' => PlatformText::public($platform->description),
                'title_sort_key' => PlatformText::persianSortKey($platform->title),
            ]);
        })->afterCreating(function (PropFirm $platform): void {
            DB::table('prop_firm_slug_ownership')->insert([
                'slug' => $platform->slug, 'prop_firm_id' => $platform->id,
                'was_public' => $platform->status === 'published', 'created_at' => $platform->created_at,
            ]);
        });
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}
