<?php

namespace App\Models;

use App\Comments\HasPublicComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class PlatformProfile extends Model
{
    use HasFactory;
    use HasPublicComments;
    use SoftDeletes;

    protected $guarded = ['*'];

    /** @return class-string<PlatformCategory> */
    abstract public function categoryModel(): string;

    /** @return class-string<PlatformSlugOwnership> */
    abstract public function slugOwnershipModel(): string;

    public function category(): BelongsTo
    {
        return $this->belongsTo($this->categoryModel(), 'category_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function slugOwnerships(): HasMany
    {
        return $this->hasMany($this->slugOwnershipModel(), $this->profileForeignKey());
    }

    public function profileForeignKey(): string
    {
        return $this->getForeignKey();
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNull($this->getTable().'.deleted_at');
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'author_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'deleted_by' => 'integer',
            'published_at' => 'immutable_datetime',
            'version' => 'integer',
            'specifications' => 'array',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
