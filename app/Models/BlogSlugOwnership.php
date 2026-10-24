<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogSlugOwnership extends Model
{
    public $timestamps = false;

    protected $table = 'blog_slug_ownership';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['article_id' => 'integer', 'was_public' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(BlogArticle::class);
    }
}
