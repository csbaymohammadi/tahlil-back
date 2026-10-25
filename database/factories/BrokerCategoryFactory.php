<?php

namespace Database\Factories;

use App\Models\BrokerCategory;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerCategory> */
class BrokerCategoryFactory extends Factory
{
    protected $model = BrokerCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['name' => $name, 'name_normalized' => PlatformText::panel($name), 'public_name_normalized' => PlatformText::public($name), 'key' => 'category-'.fake()->unique()->randomNumber(7), 'version' => 1];
    }
}
