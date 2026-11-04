<?php

namespace App\Ratings;

use App\Models\PlatformRatingAggregate;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasPlatformRatings
{
    public function ratingAggregate(): HasOne
    {
        return $this->hasOne(PlatformRatingAggregate::class, 'platform_id')->where('kind', PlatformRatings::kind($this));
    }
}
