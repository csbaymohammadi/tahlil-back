<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BonusVoterContext extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
