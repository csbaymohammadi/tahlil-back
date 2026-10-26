<?php

namespace App\Models;

use App\Exceptions\PanelAuthException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageSetting extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }

    public static function current(bool $lock = false): self
    {
        return static::query()->when($lock, fn ($query) => $query->lockForUpdate())->find(1)
            ?? throw new PanelAuthException(503, 'SERVICE_UNAVAILABLE', 'Page settings are not initialized.');
    }
}
