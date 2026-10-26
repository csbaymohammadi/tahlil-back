<?php

namespace App\Actions\Comments;

use App\Comments\CommentEvents;
use App\Comments\CommentParents;
use App\Exceptions\PanelAuthException;
use App\Models\SiteComment;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitComment
{
    public function __construct(private CommentParents $parents, private CommentEvents $events) {}

    /** @param array{name: string, email: string, text: string} $input */
    public function handle(string $section, int $parentId, array $input, string $key, string $requestId): SiteComment
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => ['Send a random UUID v4 Idempotency-Key header.']]);
        }
        $this->parents->assertSection($section);
        $key = strtolower($key);
        $values = ['name' => trim($input['name']), 'email' => trim($input['email']), 'text' => trim($input['text'])];
        $fingerprint = hash_hmac('sha256', json_encode([$section, $parentId, $values], JSON_THROW_ON_ERROR), config('app.key'));

        return DB::transaction(function () use ($section, $parentId, $values, $fingerprint, $key, $requestId): SiteComment {
            PanelMutationLock::acquire();
            $parent = $this->parents->publicParent($section, $parentId, 'update');
            $existing = DB::table('comment_idempotency')->where('key', $key)->first();
            if ($existing && $existing->expires_at <= now()->format('Y-m-d H:i:s')) {
                DB::table('comment_idempotency')->where('key', $key)->delete();
                $existing = null;
            }
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint) || $existing->section !== $section || (int) $existing->parent_id !== $parentId) {
                    throw new PanelAuthException(409, 'IDEMPOTENCY_CONFLICT', 'Use the same key only to retry the same submission.');
                }
                $comment = SiteComment::visible()->with('reply')->find($existing->comment_id);
                if (! $comment) {
                    throw new PanelAuthException(404, 'COMMENT_NOT_FOUND', 'The comment is no longer available.');
                }

                return $comment;
            }
            $comment = new SiteComment;
            $comment->forceFill([...$values, 'section' => $section, 'parent_id' => $parentId,
                'page_title_snapshot' => mb_substr($parent->title, 0, 200), 'visibility' => 'visible', 'version' => 1])->save();
            DB::table('comment_idempotency')->insert(['key' => $key, 'section' => $section, 'parent_id' => $parentId,
                'fingerprint' => $fingerprint, 'comment_id' => $comment->id, 'expires_at' => now()->addSeconds(config('comments.idempotency_seconds')), 'created_at' => now()]);
            $this->events->audit('comment_created', $requestId, null, $comment->id, ['section' => $section, 'parent_id' => $parentId, 'to_version' => 1]);
            $this->events->publicChange('comment_created', $requestId, $comment->id, ['section' => $section, 'parent_id' => $parentId]);

            return $comment->fresh('reply');
        });
    }
}
