<?php

namespace App\Blog;

use App\Models\BlogArticle;

class BlogArticleSaveResult
{
    /**
     * @param  list<array{field: string, code: string, message: string}>  $warnings
     */
    public function __construct(public BlogArticle $article, public array $warnings = []) {}
}
