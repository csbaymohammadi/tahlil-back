<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\SaveCommentReply;
use App\Auth\PanelRateLimits;
use App\Comments\CommentParents;
use App\Comments\CommentProjection;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommentReplyRequest;
use App\Http\Resources\Admin\CommentResource;
use App\Models\SiteComment;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CommentController extends Controller
{
    public function index(Request $request, CommentProjection $projection): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'section' => ['sometimes', 'string', Rule::in(['all', ...array_keys(CommentParents::MAP)])],
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $page = (int) ($input['page'] ?? 1);
        $size = (int) ($input['per_page'] ?? 100);
        $section = $input['section'] ?? 'all';
        $query = SiteComment::whereIn('section', array_keys(CommentParents::MAP))->with('reply.author');
        if ($section !== 'all') {
            $query->where('section', $section);
        }
        $total = (clone $query)->count();
        $comments = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $size)->limit($size)->get();
        $projection->prepare($comments);

        return response()->json(['data' => $comments->map(fn (SiteComment $comment): array => (new CommentResource($comment))->resolve($request))->all(),
            'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'total_pages' => $total ? (int) ceil($total / $size) : 0], 'request_id' => $request->attributes->get('request_id')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $comment = SiteComment::with('reply.author')->find(PanelIds::parse($id));
        if (! $comment) {
            throw new PanelAuthException(404, 'COMMENT_NOT_FOUND', 'The comment was not found.');
        }

        return $this->response($request, $comment);
    }

    public function reply(CommentReplyRequest $request, string $id, SaveCommentReply $save): JsonResponse
    {
        $this->limit($request);
        $input = $request->validated();
        $comment = $save->handle($request->attributes->get('panel_session'), PanelIds::parse($id), $input['text'], $input['expected_version'], (string) $request->attributes->get('request_id'));

        return $this->response($request, $comment);
    }

    public function destroy(Request $request, string $id, DeleteComment $delete): Response
    {
        $this->limit($request);
        $input = Validator::make($request->query(), ['expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991']])->validate();
        $delete->handle($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $input['expected_version'], (string) $request->attributes->get('request_id'));

        return response()->noContent();
    }

    private function response(Request $request, SiteComment $comment): JsonResponse
    {
        return response()->json(['data' => (new CommentResource($comment))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    private function limit(Request $request): void
    {
        $key = 'panel:comments:management:'.$request->attributes->get('panel_user')->id;
        $limits = app(PanelRateLimits::class);
        $limits->check($key, (int) config('comments.management_rate_limit'));
        $limits->hit($key);
    }
}
