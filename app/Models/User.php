<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $attributes = ['security_version' => 1, 'is_active' => false];

    protected ?string $securityRequestId = null;

    public function saveWithSecurityContext(string $requestId): bool
    {
        $this->securityRequestId = $requestId;
        try {
            return $this->save();
        } finally {
            $this->securityRequestId = null;
        }
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->isDirty('username')) {
            $this->username_normalized = $this->username === null ? null : Str::lower(trim($this->username));
            $this->username = $this->username === null ? null : trim($this->username);
        }
        if (! $this->exists || ! $this->isDirty(['username', 'password', 'is_active', 'deleted_at'])) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            $current = static::withTrashed()->lockForUpdate()->findOrFail($this->id);
            if ($current->security_version !== (int) $this->getOriginal('security_version')) {
                throw new PanelAuthException(401, 'UNAUTHENTICATED', 'Account security state changed. Sign in again.');
            }
            $reason = $this->isDirty('password') ? 'password_changed' : ($this->isDirty('username') ? 'username_changed' : ($this->is_active ? 'admin_enabled' : 'admin_disabled'));
            $this->security_version = $current->security_version + 1;
            if ($this->isDirty('password')) {
                $this->password_changed_at = now();
            }
            $saved = parent::save($options);
            (new PanelSessions)->revoke($this->id, null, $reason, $this->securityRequestId ?? 'account-update-'.Str::uuid());

            return $saved;
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            if ($this->exists) {
                $current = static::withTrashed()->whereKey($this->id)->lockForUpdate()->firstOrFail();
                $this->security_version = $current->security_version + 1;
                static::withTrashed()->whereKey($this->id)->update(['security_version' => $this->security_version]);
                $this->syncOriginalAttribute('security_version');
                (new PanelSessions)->revoke($this->id, null, 'admin_deleted', 'account-delete-'.Str::uuid());
            }

            return parent::delete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'security_version' => 'integer',
            'password_changed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
