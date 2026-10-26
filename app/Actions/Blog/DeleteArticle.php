<?php

namespace App\Actions\Blog;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\BlogArticle;
use App\Models\PanelSession;
use App\Support\PublicContentIndex;
use Illuminate\Support\Facades\DB;

class DeleteArticle
{
    public function __construct(private RevokeSessions $sessions, private PublicContentIndex $publicIndex) {}

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): void {
            $actor = $this->sessions->currentUser($session);
            $article = BlogArticle::query()->lockForUpdate()->find($id);
            if (! $article) {
                throw new PanelAuthException(404, 'ARTICLE_NOT_FOUND', 'The article was not found.');
            }
            if ($article->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The article has changed. Fetch it before retrying.');
            }
            $wasPublished = $article->status === 'published';
            $previousStatus = $article->status;
            $article->forceFill([
                'deleted_by' => $actor->id,
                'updated_by' => $actor->id,
                'version' => $article->version + 1,
                'schedule_generation' => $article->schedule_generation + 1,
            ])->save();
            $article->delete();
            DB::table('blog_schedule_tasks')->where('article_id', $id)->whereNull('processed_at')->update(['processed_at' => now(), 'updated_at' => now()]);
            DB::table('blog_audit_events')->insert([
                'actor_id' => $actor->id, 'target_type' => 'article', 'target_id' => $id,
                'event' => 'article_deleted', 'request_id' => $requestId,
                'details' => json_encode(['previous_status' => $previousStatus, 'slug' => $article->slug], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            if ($wasPublished) {
                DB::table('blog_outbox')->insert([
                    'article_id' => $id, 'event' => 'blog_article_deleted',
                    'payload' => json_encode(['request_id' => $requestId, 'article_id' => $id, 'slug' => $article->slug, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                    'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
                ]);
            }
            $this->publicIndex->sync('articles', $id);
        });
    }
}
