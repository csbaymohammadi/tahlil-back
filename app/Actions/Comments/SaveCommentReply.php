<?php

namespace App\Actions\Comments;

use App\Actions\Auth\RevokeSessions;
use App\Comments\CommentEvents;
use App\Exceptions\PanelAuthException;
use App\Models\CommentReply;
use App\Models\PanelSession;
use App\Models\SiteComment;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class SaveCommentReply
{
    public function __construct(private RevokeSessions $sessions, private CommentEvents $events) {}

    public function handle(PanelSession $session, int $id, string $text, int $expectedVersion, string $requestId): SiteComment
    {
        return DB::transaction(function () use ($session, $id, $text, $expectedVersion, $requestId): SiteComment {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $comment = SiteComment::lockForUpdate()->find($id);
            if (! $comment) {
                throw new PanelAuthException(404, 'COMMENT_NOT_FOUND', 'The comment was not found.');
            }
            if ($comment->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current comment before retrying.');
            }
            $text = trim($text);
            $reply = $comment->reply;
            if ($reply && $reply->text === $text) {
                return $comment->load('reply.author');
            }
            $creating = $reply === null;
            $reply ??= new CommentReply;
            $values = ['comment_id' => $id, 'text' => $text, 'updated_by' => $actor->id];
            if ($creating) {
                $values += ['author_id' => $actor->id, 'author_name' => $actor->name];
            }
            $reply->forceFill($values)->save();
            $comment->forceFill(['version' => $comment->version + 1])->save();
            $this->events->audit($creating ? 'comment_reply_created' : 'comment_reply_updated', $requestId, $actor->id, $id,
                ['section' => $comment->section, 'parent_id' => $comment->parent_id, 'from_version' => $expectedVersion, 'to_version' => $comment->version]);
            $this->events->publicChange('comment_reply_changed', $requestId, $id, ['section' => $comment->section, 'parent_id' => $comment->parent_id]);

            return $comment->fresh('reply.author');
        });
    }
}
