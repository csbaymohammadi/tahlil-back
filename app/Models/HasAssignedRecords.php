<?php

namespace App\Models;

use App\Support\CategoryAssignments;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasAssignedRecords
{
    public function assignedRecords(): BelongsToMany
    {
        $recordClass = array_search(static::class, CategoryAssignments::MODELS, true);

        if ($recordClass === false) {
            throw new \LogicException('Register the category domain before using assignments.');
        }

        return $this->belongsToMany($recordClass, CategoryAssignments::table(new $recordClass), 'category_id', 'record_id');
    }
}
