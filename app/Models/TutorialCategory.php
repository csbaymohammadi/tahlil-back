<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TutorialCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    public function tutorials(): HasMany
    {
        return $this->hasMany(Tutorial::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
