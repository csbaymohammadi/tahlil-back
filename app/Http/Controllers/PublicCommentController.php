<?php

namespace App\Http\Controllers;

use App\Actions\Comments\SubmitComment;
use App\Comments\CommentParents;
use App\Http\Requests\PublicCommentRequest;
use App\Http\Resources\PublicCommentResource;
use App\Models\SiteComment;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PublicCommentController extends Controller
{
    public function index(Request $request, string $section, string $parentId, CommentParents $parents): JsonResponse
    {
        $parents->assertSection($section);
        $id = PanelIds::parse($parentId);
        $input = Validator::make($request->query(), [
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $page = (int) ($input['page'] ?? 1);
        $size = (int) ($input['per_page'] ?? 100);
        $data = DB::transaction(function () use ($parents, $section, $id, $page, $size, $request): array {
            $parents->publicParent($section, $id, 'shared');
            $query = SiteComment::visible()->where('section', $section)->where('parent_id', $id)->with('reply');
            $total = (clone $query)->count();
            $comments = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $size)->limit($size)->get();

            return ['data' => $comments->map(fn (SiteComment $comment): array => (new PublicCommentResource($comment))->resolve($request))->all(),
                'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'total_pages' => $total ? (int) ceil($total / $size) : 0]];
        });

        return response()->json([...$data, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(PublicCommentRequest $request, string $section, string $parentId, SubmitComment $submit): JsonResponse
    {
        $comment = $submit->handle($section, PanelIds::parse($parentId), $request->validated(), (string) $request->header('Idempotency-Key'), (string) $request->attributes->get('request_id'));

        return response()->json(['data' => (new PublicCommentResource($comment))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201);
    }
}
