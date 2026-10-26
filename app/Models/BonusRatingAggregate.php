<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BonusRatingAggregate extends Model
{
    protected $primaryKey = 'bonus_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['vote_sum' => 'integer', 'rating_count' => 'integer', 'rating_revision' => 'integer'];
    }

    /** @return array{rating: float|null, rating_count: int, rating_revision: int} */
    public function projection(): array
    {
        return ['rating' => $this->rating_count > 0 ? $this->vote_sum / $this->rating_count : null,
            'rating_count' => $this->rating_count, 'rating_revision' => $this->rating_revision];
    }
}
