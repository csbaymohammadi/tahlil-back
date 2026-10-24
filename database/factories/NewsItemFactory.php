<?php

namespace Database\Factories;

use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsItem>
 */
class NewsItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $author = User::factory();

        return [
            'title' => fake()->sentence(4),
            'title_normalized' => 'example article',
            'slug' => fake()->unique()->slug(),
            'description' => '',
            'cover_image' => '',
            'content' => '<p>Example content.</p>',
            'category_id' => null,
            'reading_minutes' => 5,
            'meta_description' => '',
            'status' => 'draft',
            'scheduled_for' => null,
            'schedule_generation' => 0,
            'author_id' => $author,
            'created_by' => fn (array $attributes): int => $attributes['author_id'],
            'updated_by' => fn (array $attributes): int => $attributes['author_id'],
            'author_name_normalized' => 'example editor',
            'published_at' => null,
            'version' => 1,
        ];
    }
}
