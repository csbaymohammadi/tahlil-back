<?php

namespace App\Models;

use App\Comments\HasPublicComments;
use Database\Factories\ExchangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exchange extends Model
{
    /** @use HasFactory<ExchangeFactory> */
    use HasFactory, SoftDeletes;

    use HasPublicComments;

    protected $guarded = ['*'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExchangeCategory::class, 'category_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function slugOwnerships(): HasMany
    {
        return $this->hasMany(ExchangeSlugOwnership::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNull('deleted_at');
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer', 'author_id' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer', 'deleted_by' => 'integer',
            'published_at' => 'immutable_datetime', 'version' => 'integer', 'specifications' => 'array', 'deleted_at' => 'immutable_datetime',
        ];
    }
}
