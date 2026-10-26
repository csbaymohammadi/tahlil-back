<?php

namespace App\Actions\Comments;

use App\Actions\Auth\RevokeSessions;
use App\Comments\CommentEvents;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\SiteComment;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class DeleteComment
{
    public function __construct(private RevokeSessions $sessions, private CommentEvents $events) {}

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): void {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $comment = SiteComment::lockForUpdate()->find($id);
            if (! $comment) {
                throw new PanelAuthException(404, 'COMMENT_NOT_FOUND', 'The comment was not found.');
            }
            if ($comment->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current comment before retrying.');
            }
            $reply = $comment->reply;
            if ($reply) {
                $reply->forceFill(['deleted_by' => $actor->id])->save();
                $reply->delete();
            }
            $comment->forceFill(['deleted_by' => $actor->id, 'version' => $comment->version + 1])->save();
            $comment->delete();
            $this->events->audit('comment_deleted', $requestId, $actor->id, $id, ['section' => $comment->section,
                'parent_id' => $comment->parent_id, 'from_version' => $expectedVersion, 'to_version' => $comment->version, 'reply_removed' => $reply !== null]);
            $this->events->publicChange('comment_deleted', $requestId, $id, ['section' => $comment->section, 'parent_id' => $comment->parent_id]);
        });
    }
}
