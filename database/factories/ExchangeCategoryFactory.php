<?php

namespace Database\Factories;

use App\Models\ExchangeCategory;
use App\Support\ExchangeText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeCategory>
 */
class ExchangeCategoryFactory extends Factory
{
    protected $model = ExchangeCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['name' => $name, 'name_normalized' => ExchangeText::panel($name), 'public_name_normalized' => ExchangeText::public($name), 'key' => null, 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ExchangeCategory $category): void {
            $category->forceFill([
                'name_normalized' => ExchangeText::panel($category->name),
                'public_name_normalized' => ExchangeText::public($category->name),
            ]);
        });
    }
}
