<?php

namespace App\Models;

use App\Support\CategoryAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasCategories
{
    public function initializeHasCategories(): void
    {
        $this->with[] = 'categories';
    }

    public static function bootHasCategories(): void
    {
        static::created(function (Model $record): void {
            app(CategoryAssignments::class)->sync($record, $record->category_id === null ? [] : [(int) $record->category_id]);
        });
        static::updated(function (Model $record): void {
            if ($record->wasChanged('category_id')) {
                app(CategoryAssignments::class)->sync($record, $record->category_id === null ? [] : [(int) $record->category_id]);
            }
        });
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CategoryAssignments::MODELS[static::class], CategoryAssignments::table($this), 'record_id', 'category_id')->withTrashed()->withPivot('position')->orderByPivot('position')->orderBy('id');
    }
}
