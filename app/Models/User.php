<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Support\BlogText;
use App\Support\PanelMutationLock;
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

    protected $attributes = ['security_version' => 1, 'version' => 1, 'is_active' => false];

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
            $this->username = $this->username === null ? null : trim($this->username);
            $this->username_normalized = $this->username === null ? null : Str::lower($this->username);
        }
        if ($this->isDirty('email')) {
            $this->email = mb_strtolower(trim($this->email));
        }
        if ($this->isDirty('name')) {
            $this->blog_name_normalized = BlogText::normalize($this->name);
        }
        $editable = ['username', 'name', 'email', 'password', 'role_id', 'is_active', 'avatar_media_id', 'deleted_at'];
        if ($this->exists && ! $this->isDirty($editable)) {
            return parent::save($options);
        }

        $nameChanged = $this->isDirty('name');

        return DB::transaction(function () use ($options, $nameChanged): bool {
            PanelMutationLock::acquire();
            if ($this->exists) {
                $current = static::withTrashed()->lockForUpdate()->findOrFail($this->id);
                if ($current->security_version !== (int) $this->getOriginal('security_version')) {
                    throw new PanelAuthException(401, 'UNAUTHENTICATED', 'Account security state changed. Sign in again.');
                }
                if ($current->version !== (int) $this->getOriginal('version')) {
                    throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The record has changed. Fetch it before retrying.');
                }
                $this->version = $current->version + 1;
                if ($this->isDirty(['username', 'password', 'is_active', 'deleted_at'])) {
                    $reason = $this->isDirty('password') ? 'password_changed' : ($this->isDirty('username') ? 'username_changed' : ($this->is_active ? 'admin_enabled' : 'admin_disabled'));
                    $this->security_version = $current->security_version + 1;
                    if ($this->isDirty('password')) {
                        $this->password_changed_at = now();
                    }
                    (new PanelSessions)->revoke($this->id, null, $reason, $this->securityRequestId ?? 'account-update-'.Str::uuid());
                }
            }

            $saved = parent::save($options);
            if ($saved && $nameChanged) {
                DB::table('bonuses')->where('author_id', $this->id)->update(['author_name_normalized' => BlogText::normalize($this->name)]);
                DB::table('blog_articles')->where('author_id', $this->id)->update([
                    'author_name_normalized' => BlogText::normalize($this->name),
                ]);
                if (DB::table('blog_articles')->where('author_id', $this->id)->where('status', 'published')->whereNull('deleted_at')->exists()) {
                    DB::table('blog_outbox')->insert([
                        'article_id' => null,
                        'event' => 'blog_author_changed',
                        'payload' => json_encode([
                            'user_id' => $this->id,
                            'request_id' => $this->securityRequestId ?? 'user-profile-update-'.Str::uuid(),
                            'occurred_at' => now()->utc()->toISOString(),
                        ], JSON_THROW_ON_ERROR),
                        'attempts' => 0,
                        'available_at' => now(),
                        'created_at' => now(),
                    ]);
                }
                DB::table('news_items')->where('author_id', $this->id)->update([
                    'author_name_normalized' => BlogText::normalize($this->name),
                ]);
                if (DB::table('news_items')->where('author_id', $this->id)->where('status', 'published')->whereNull('deleted_at')->exists()) {
                    DB::table('news_outbox')->insert([
                        'news_item_id' => null,
                        'event' => 'news_author_changed',
                        'payload' => json_encode([
                            'user_id' => $this->id,
                            'request_id' => $this->securityRequestId ?? 'user-profile-update-'.Str::uuid(),
                            'occurred_at' => now()->utc()->toISOString(),
                        ], JSON_THROW_ON_ERROR),
                        'attempts' => 0,
                        'available_at' => now(),
                        'created_at' => now(),
                    ]);
                }
            }

            return $saved;
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            PanelMutationLock::acquire();
            if ($this->exists) {
                $current = static::withTrashed()->whereKey($this->id)->lockForUpdate()->firstOrFail();
                $this->security_version = $current->security_version + 1;
                $this->version = $current->version + 1;
                $this->is_active = false;
                static::withTrashed()->whereKey($this->id)->update([
                    'security_version' => $this->security_version, 'version' => $this->version, 'is_active' => false,
                ]);
                $this->syncOriginalAttributes(['security_version', 'version', 'is_active']);
                (new PanelSessions)->revoke($this->id, null, 'admin_deleted', $this->securityRequestId ?? 'account-delete-'.Str::uuid());
            }

            return parent::delete();
        });
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    public function deleteWithSecurityContext(string $requestId): ?bool
    {
        $this->securityRequestId = $requestId;
        try {
            return $this->delete();
        } finally {
            $this->securityRequestId = null;
        }
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
            'version' => 'integer',
            'password_changed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
