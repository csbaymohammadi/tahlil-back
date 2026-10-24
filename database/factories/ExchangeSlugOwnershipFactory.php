<?php

namespace Database\Factories;

use App\Models\Exchange;
use App\Models\ExchangeSlugOwnership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeSlugOwnership>
 */
class ExchangeSlugOwnershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'exchange_id' => Exchange::factory(),
            'was_public' => false,
            'created_at' => now(),
        ];
    }

    public function forExchange(Exchange $exchange, string $slug): static
    {
        return $this->state(fn (): array => [
            'slug' => $slug,
            'exchange_id' => $exchange->id,
            'was_public' => $exchange->status === 'published',
            'created_at' => now(),
        ]);
    }
}
