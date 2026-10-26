<?php

namespace Database\Factories;

use App\Models\PropFirmCategory;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropFirmCategory>
 */
class PropFirmCategoryFactory extends Factory
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
            'name' => $name, 'name_normalized' => PlatformText::panel($name),
            'public_name_normalized' => PlatformText::public($name), 'version' => 1,
        ];
    }
}
