<?php

namespace Database\Factories;

use App\Models\NewsCategory;
use App\Support\BlogText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsCategory>
 */
class NewsCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'name_normalized' => BlogText::normalize($name),
            'version' => 1,
        ];
    }
}
