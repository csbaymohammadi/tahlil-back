<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->panel(), 'purpose' => 'avatar', 'kind' => 'image', 'status' => 'ready', 'disk' => 'media',
            'storage_key' => 'objects/'.Str::uuid(), 'canonical_name' => 'avatar.png', 'original_name' => 'avatar.png',
            'mime_type' => 'image/png', 'size_bytes' => 100, 'width' => 512, 'height' => 512, 'is_animated' => false,
            'unreferenced_at' => now()];
    }
}
