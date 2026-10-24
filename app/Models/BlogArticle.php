<?php

namespace App\Models;

use Database\Factories\BlogArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogArticle extends Model
{
    /** @use HasFactory<BlogArticleFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class)->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function slugs(): HasMany
    {
        return $this->hasMany(BlogSlugOwnership::class, 'article_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNull('deleted_at');
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'author_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_by' => 'integer',
            'reading_minutes' => 'integer',
            'schedule_generation' => 'integer',
            'published_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
