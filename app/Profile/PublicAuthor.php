<?php

namespace App\Profile;

use App\Media\MediaAccess;
use App\Models\BlogArticle;
use App\Models\Bonus;
use App\Models\NewsItem;
use App\Models\User;

class PublicAuthor
{
    public function __construct(private MediaAccess $media) {}

    public static function eligible(int $id): bool
    {
        return User::whereKey($id)->exists() && (BlogArticle::public()->where('author_id', $id)->exists() || NewsItem::public()->where('author_id', $id)->exists() || Bonus::public()->where('author_id', $id)->exists());
    }

    /** @return array<string, mixed>|null */
    public function record(?User $author): ?array
    {
        if ($author === null) {
            return null;
        }
        $deleted = $author->trashed();
        $avatar = $deleted ? null : $author->avatar;

        return ['id' => $author->id, 'name' => $author->name, 'bio' => $deleted ? '' : ($author->bio ?? ''), 'linkedin_url' => $deleted ? '' : ($author->linkedin_url ?? ''),
            'avatar_url' => $avatar && $this->media->public($avatar) ? $avatar->canonicalUrl() : null];
    }
}
