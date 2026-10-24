<?php

namespace App\Models;

use Database\Factories\ExchangeCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExchangeCategory extends Model
{
    /** @use HasFactory<ExchangeCategoryFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    public function exchanges(): HasMany
    {
        return $this->hasMany(Exchange::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'deleted_at' => 'immutable_datetime'];
    }
}
