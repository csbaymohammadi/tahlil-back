<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tutorial extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TutorialCategory::class)->withTrashed();
    }

    public function durations(): HasMany
    {
        return $this->hasMany(TutorialDuration::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'category_id' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
