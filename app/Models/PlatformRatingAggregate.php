<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformRatingAggregate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $attributes = ['vote_sum' => 0, 'rating_count' => 0, 'rating_revision' => 0];

    protected function casts(): array
    {
        return ['platform_id' => 'integer', 'vote_sum' => 'integer', 'rating_count' => 'integer', 'rating_revision' => 'integer'];
    }

    /** @return array{rating_average: float|null, rating_count: int, rating_revision: int} */
    public function projection(): array
    {
        return ['rating_average' => $this->rating_count > 0 ? $this->vote_sum / $this->rating_count : null,
            'rating_count' => $this->rating_count, 'rating_revision' => $this->rating_revision];
    }
}
