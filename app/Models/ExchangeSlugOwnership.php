<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeSlugOwnership extends Model
{
    protected $table = 'exchange_slug_ownership';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    protected function casts(): array
    {
        return ['exchange_id' => 'integer', 'was_public' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
