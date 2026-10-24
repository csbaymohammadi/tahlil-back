<?php

namespace App\Models;

use Database\Factories\NewsCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NewsCategory extends Model
{
    /** @use HasFactory<NewsCategoryFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'deleted_at' => 'immutable_datetime'];
    }
}
