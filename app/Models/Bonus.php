<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bonus extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BonusCategory::class)->withTrashed();
    }

    public function campaign(): HasOne
    {
        return $this->hasOne(BonusCampaign::class);
    }

    public function ratingAggregate(): HasOne
    {
        return $this->hasOne(BonusRatingAggregate::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNull('bonuses.deleted_at');
    }

    protected function casts(): array
    {
        return ['category_id' => 'integer', 'author_id' => 'integer', 'reading_minutes' => 'integer',
            'version' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
