<?php

namespace Database\Factories;

use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Models\User;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Tutorial> */
class TutorialFactory extends Factory
{
    public function definition(): array
    {
        $id = substr(bin2hex(random_bytes(8)), 0, 11);
        $title = fake()->sentence(3);
        $actor = User::factory()->panel();

        return ['title' => $title, 'title_normalized' => PlatformText::panel($title), 'title_public_normalized' => PlatformText::public($title),
            'description' => '', 'description_public_normalized' => '', 'slug' => 'youtube-'.$id,
            'youtube_url' => 'https://www.youtube.com/watch?v='.$id, 'youtube_video_id' => $id,
            'aparat_url' => '', 'aparat_video_id' => null, 'cover_image' => '/images/tutorials/example.webp',
            'category_id' => TutorialCategory::factory(), 'created_by' => $actor, 'updated_by' => $actor, 'published_at' => now(), 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Tutorial $tutorial): void {
            $tutorial->forceFill(['title_normalized' => PlatformText::panel($tutorial->title), 'title_public_normalized' => PlatformText::public($tutorial->title), 'description_public_normalized' => PlatformText::public($tutorial->description)]);
        })->afterCreating(function (Tutorial $tutorial): void {
            DB::table('tutorial_slug_ownership')->insert(['slug' => $tutorial->slug, 'tutorial_id' => $tutorial->id, 'created_at' => now()]);
            DB::table('tutorial_youtube_claims')->insert(['video_id' => $tutorial->youtube_video_id, 'tutorial_id' => $tutorial->id]);
        });
    }
}
