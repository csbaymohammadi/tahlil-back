<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TutorialDuration extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['seconds' => 'integer'];
    }
}
