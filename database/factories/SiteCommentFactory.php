<?php

namespace Database\Factories;

use App\Models\BlogArticle;
use App\Models\SiteComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SiteComment> */
class SiteCommentFactory extends Factory
{
    public function definition(): array
    {
        return ['section' => 'blog', 'parent_id' => BlogArticle::factory()->state(['status' => 'published', 'published_at' => now()]), 'page_title_snapshot' => 'Comment source',
            'name' => 'Example Visitor', 'email' => 'visitor@example.com', 'text' => 'A visitor comment with meaningful text.', 'visibility' => 'visible', 'version' => 1];
    }
}
