<?php

namespace App\Comments;

use Illuminate\Database\Eloquent\Model;

class CommentCounts
{
    public function for(Model $parent): int
    {
        if (app(CommentParents::class)->visibility($parent) !== 'public') {
            return 0;
        }
        if (array_key_exists('comment_count', $parent->getAttributes())) {
            return (int) $parent->getAttribute('comment_count');
        }

        return $parent->visibleComments()->count();
    }
}
