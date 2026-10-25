<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class PlatformSlugOwnership extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return class-string<PlatformProfile> */
    abstract public function profileModel(): string;

    public function profile(): BelongsTo
    {
        return $this->belongsTo($this->profileModel(), $this->foreignKey());
    }

    abstract public function foreignKey(): string;
}
