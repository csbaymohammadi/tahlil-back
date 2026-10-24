<?php

namespace App\Models;

use Database\Factories\PanelSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['token_hash', 'payload'])]
class PanelSession extends Model
{
    /** @use HasFactory<PanelSessionFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'absolute_expires_at' => 'immutable_datetime',
            'idle_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'security_version' => 'integer',
        ];
    }

    public function expiresAt(): string
    {
        return $this->idle_expires_at->min($this->absolute_expires_at)->toIso8601ZuluString();
    }
}
