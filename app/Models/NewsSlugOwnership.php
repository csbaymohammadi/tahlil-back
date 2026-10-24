<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsSlugOwnership extends Model
{
    public $timestamps = false;

    protected $table = 'news_slug_ownership';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['news_item_id' => 'integer', 'was_public' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
