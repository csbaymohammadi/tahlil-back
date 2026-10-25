<?php

namespace App\News;

use App\Models\NewsItem;

class NewsItemSaveResult
{
    /**
     * @param  list<array{field: string, code: string, message: string}>  $warnings
     */
    public function __construct(public NewsItem $newsItem, public array $warnings = []) {}
}
