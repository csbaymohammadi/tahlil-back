<?php

namespace Database\Factories;

use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** @extends Factory<PanelSession> */
class PanelSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(), 'user_id' => User::factory()->panel(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))), 'security_version' => 1,
            'payload' => Crypt::encryptString(serialize(['_token' => Str::random(40)])),
            'last_seen_at' => now(), 'absolute_expires_at' => now()->addDays(7), 'idle_expires_at' => now()->addDay(),
        ];
    }
}
