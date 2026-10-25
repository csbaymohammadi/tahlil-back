<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class PlatformCategory extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['*'];

    /** @return class-string<PlatformProfile> */
    abstract public function profileModel(): string;

    public function profiles(): HasMany
    {
        return $this->hasMany($this->profileModel(), 'category_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'deleted_at' => 'immutable_datetime'];
    }
}
