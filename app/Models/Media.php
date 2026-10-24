<?php

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['storage_key', 'disk', 'user_id'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'width' => 'integer', 'height' => 'integer', 'is_animated' => 'boolean', 'duration_seconds' => 'float', 'size_bytes' => 'integer', 'deleted_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime', 'unreferenced_at' => 'immutable_datetime'];
    }

    public function canonicalUrl(): ?string
    {
        return $this->status === 'ready' && $this->deleted_at === null ? route('media.show', ['id' => $this->id, 'name' => $this->canonical_name], false) : null;
    }
}
