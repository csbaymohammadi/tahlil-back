<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['email'];

    public function reply(): HasOne
    {
        return $this->hasOne(CommentReply::class, 'comment_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visibility', 'visible');
    }

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'parent_id' => 'integer', 'version' => 'integer'];
    }
}
