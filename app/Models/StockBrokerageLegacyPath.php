<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBrokerageLegacyPath extends Model
{
    protected $table = 'broker_legacy_stock_paths';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function stockBrokerage(): BelongsTo
    {
        return $this->belongsTo(StockBrokerage::class);
    }
}
