<?php

namespace Database\Factories;

use App\Models\Exchange;
use App\Models\PlatformRatingAggregate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlatformRatingAggregate> */
class PlatformRatingAggregateFactory extends Factory
{
    public function definition(): array
    {
        return ['kind' => 'exchanges', 'platform_id' => Exchange::factory()->published(), 'vote_sum' => 0, 'rating_count' => 0, 'rating_revision' => 0];
    }
}
