<?php

namespace Database\Factories;

use App\Models\Bonus;
use App\Models\User;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Bonus> */
class BonusFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);
        $user = User::factory()->panel();

        return ['title' => $title, 'title_normalized' => PlatformText::panel($title), 'title_public_normalized' => PlatformText::public($title),
            'author_name_normalized' => '', 'slug' => fake()->unique()->slug(3), 'description' => '', 'description_public_normalized' => '',
            'cover_image' => '', 'content' => '<h2>Conditions</h2><p>Campaign terms.</p>', 'reading_minutes' => 5,
            'meta_description' => '', 'category_id' => null, 'status' => 'draft', 'author_id' => $user,
            'created_by' => $user, 'updated_by' => $user, 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Bonus $bonus): void {
            $bonus->forceFill(['title_normalized' => PlatformText::panel($bonus->title), 'title_public_normalized' => PlatformText::public($bonus->title),
                'description_public_normalized' => PlatformText::public($bonus->description)]);
        })->afterCreating(function (Bonus $bonus): void {
            $bonus->forceFill(['author_name_normalized' => PlatformText::panel($bonus->author->name)])->save();
            DB::table('bonus_campaigns')->insert(['bonus_id' => $bonus->id]);
            DB::table('bonus_rating_aggregates')->insert(['bonus_id' => $bonus->id]);
            DB::table('bonus_slug_ownership')->insert(['slug' => $bonus->slug, 'bonus_id' => $bonus->id, 'was_public' => $bonus->status === 'published', 'created_at' => now()]);
        });
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}
