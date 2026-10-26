<?php

namespace App\Comments;

use App\Models\SiteComment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

trait HasPublicComments
{
    public function visibleComments(): HasMany
    {
        return $this->hasMany(SiteComment::class, 'parent_id')->where('section', app(CommentParents::class)->section($this))->visible();
    }

    protected static function bootHasPublicComments(): void
    {
        static::updated(function (Model $parent): void {
            if ($parent->wasChanged(['title', 'slug', 'status'])) {
                self::refreshCommentParent($parent);
            }
        });
        static::deleted(fn (Model $parent) => self::refreshCommentParent($parent));
    }

    private static function refreshCommentParent(Model $parent): void
    {
        $section = app(CommentParents::class)->section($parent);
        $comments = DB::table('site_comments')->where('section', $section)->where('parent_id', $parent->getKey());
        if (! (clone $comments)->exists()) {
            return;
        }
        $comments->update(['page_title_snapshot' => mb_substr((string) $parent->getAttribute('title'), 0, 200)]);
        app(CommentEvents::class)->publicChange('comment_parent_changed', 'system-comment-parent', null,
            ['section' => $section, 'parent_id' => $parent->getKey()]);
    }
}
