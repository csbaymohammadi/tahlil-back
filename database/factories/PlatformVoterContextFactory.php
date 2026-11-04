<?php

namespace Database\Factories;

use App\Models\PlatformVoterContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlatformVoterContext> */
class PlatformVoterContextFactory extends Factory
{
    public function definition(): array
    {
        return ['token_hash' => hash('sha256', bin2hex(random_bytes(32))), 'expires_at' => now()->addYear(), 'created_at' => now()];
    }
}
