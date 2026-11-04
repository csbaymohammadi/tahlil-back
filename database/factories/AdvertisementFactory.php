<?php

namespace Database\Factories;

use App\Models\Advertisement;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Advertisement> */
class AdvertisementFactory extends Factory
{
    public function definition(): array
    {
        return ['image_media_id' => Media::factory()->state(['purpose' => 'banner', 'canonical_name' => 'banner.png', 'unreferenced_at' => null]),
            'title' => fake()->sentence(3), 'description' => fake()->sentence(), 'link' => '', 'show' => false, 'position' => 1, 'version' => 1,
            'created_by' => User::factory()->panel(), 'updated_by' => fn (array $attributes): int => $attributes['created_by']];
    }
}
