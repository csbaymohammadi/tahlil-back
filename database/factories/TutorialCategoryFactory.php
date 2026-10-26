<?php

namespace Database\Factories;

use App\Models\TutorialCategory;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TutorialCategory> */
class TutorialCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return ['name' => $name, 'name_normalized' => PlatformText::panel($name), 'public_name_normalized' => PlatformText::public($name), 'key' => null, 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (TutorialCategory $category): void {
            if ($category->key === null) {
                $category->forceFill(['key' => 'category-'.$category->id])->save();
            }
        });
    }
}
