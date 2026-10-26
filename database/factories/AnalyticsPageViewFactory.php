<?php

namespace Database\Factories;

use App\Models\AnalyticsPageView;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AnalyticsPageView> */
class AnalyticsPageViewFactory extends Factory
{
    public function definition(): array
    {
        return ['event_id' => (string) Str::uuid(), 'path' => '/', 'page_type' => 'static', 'content_id' => null, 'device' => 'other', 'viewed_at' => now()->subMinute()];
    }
}
