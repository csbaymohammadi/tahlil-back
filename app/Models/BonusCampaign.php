<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusCampaign extends Model
{
    protected $primaryKey = 'bonus_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['provider' => 'array', 'exchange_id' => 'integer'];
    }
}
