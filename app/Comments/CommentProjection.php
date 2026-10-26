<?php

namespace App\Comments;

use App\Models\SiteComment;
use Illuminate\Support\Collection;

class CommentProjection
{
    public function __construct(private CommentParents $parents) {}

    /** @param Collection<int, SiteComment> $comments */
    public function prepare(Collection $comments): void
    {
        foreach ($comments->groupBy('section') as $section => $group) {
            $this->parents->assertSection($section);
            $class = CommentParents::MAP[$section][0];
            $parents = $class::withTrashed()->whereIn('id', $group->pluck('parent_id')->unique())->get()->keyBy('id');
            foreach ($group as $comment) {
                $comment->setRelation('sourceParent', $parents->get($comment->parent_id));
            }
        }
    }

    /** @return array<string, mixed> */
    public function admin(SiteComment $comment): array
    {
        $parent = $comment->relationLoaded('sourceParent') ? $comment->getRelation('sourceParent') : $this->parents->find($comment->section, $comment->parent_id);
        $visibility = $this->parents->visibility($parent);
        $reply = $comment->reply;

        return ['id' => $comment->id, 'section' => $comment->section, 'section_label' => CommentParents::MAP[$comment->section][2],
            'parent_id' => $comment->parent_id, 'author' => $comment->name, 'text' => $comment->text,
            'created_at' => $comment->created_at?->utc()->toISOString(), 'updated_at' => $comment->updated_at?->utc()->toISOString(),
            'page_title' => $parent?->title ?? $comment->page_title_snapshot,
            'page_url' => $visibility === 'public' ? CommentParents::MAP[$comment->section][1].$parent->slug : null,
            'parent_visibility' => $visibility,
            'reply' => $reply ? ['text' => $reply->text, 'author' => $reply->author?->name ?? $reply->author_name,
                'created_at' => $reply->created_at?->utc()->toISOString(), 'updated_at' => $reply->updated_at?->utc()->toISOString()] : null,
            'version' => $comment->version];
    }

    /** @return array<string, mixed> */
    public function publicComment(SiteComment $comment): array
    {
        $reply = $comment->reply;

        return ['id' => $comment->id, 'name' => $comment->name, 'text' => $comment->text,
            'created_at' => $comment->created_at?->utc()->toISOString(),
            'reply' => $reply ? ['text' => $reply->text, 'created_at' => $reply->created_at?->utc()->toISOString(), 'updated_at' => $reply->updated_at?->utc()->toISOString()] : null];
    }
}
