<?php

namespace App\Models;

use Database\Factories\AdvertisementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Advertisement extends Model
{
    /** @use HasFactory<AdvertisementFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['*'];

    protected $attributes = ['link' => '', 'link_bt' => ''];

    protected function casts(): array
    {
        return ['show' => 'boolean', 'position' => 'integer', 'version' => 'integer', 'image_media_id' => 'integer'];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('show', true)->whereHas('image', fn (Builder $media): Builder => $media->where('status', 'ready')->whereNull('deleted_at'));
    }
}
