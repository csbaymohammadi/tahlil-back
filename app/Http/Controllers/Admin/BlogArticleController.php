<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Blog\DeleteArticle;
use App\Actions\Blog\SaveArticle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogArticleRequest;
use App\Http\Resources\Admin\BlogArticleResource;
use App\Models\BlogArticle;
use App\Support\BlogText;
use App\Support\PanelIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BlogArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Validator::make($request->query(), [
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', Rule::in([10, 20, 50])],
            'q' => ['sometimes', 'string', 'max:200'],
        ])->validate();
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 10);
        $search = BlogText::normalize((string) ($query['q'] ?? ''));
        $articles = BlogArticle::query()->with(['author', 'category'])->when($search !== '', function (Builder $builder) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $builder->where(function (Builder $builder) use ($pattern): void {
                $builder->where('title_normalized', 'like', $pattern)->orWhere('author_name_normalized', 'like', $pattern);
            });
        });
        $total = (clone $articles)->count();
        $items = $articles->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $items->map(fn (BlogArticle $article): array => (new BlogArticleResource($article))->resolve($request))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage)],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $article = BlogArticle::query()->with(['author', 'category'])->find(PanelIds::parse($id));
        if (! $article) {
            return response()->json(['error' => ['code' => 'ARTICLE_NOT_FOUND', 'message' => 'مقاله پیدا نشد یا حذف شده است.', 'fields' => (object) []], 'request_id' => $request->attributes->get('request_id')], 404);
        }

        return response()->json(['data' => (new BlogArticleResource($article))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(BlogArticleRequest $request, SaveArticle $save): JsonResponse
    {
        $result = $save->handle($request->attributes->get('panel_session'), null, $request->validated(), $request->attributes->get('request_id'));
        $article = $result->article;

        return response()->json(['data' => (new BlogArticleResource($article))->resolve($request), 'warnings' => $result->warnings, 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.blog.articles.show', ['id' => $article->id], false));
    }

    public function update(BlogArticleRequest $request, string $id, SaveArticle $save): JsonResponse
    {
        $result = $save->handle($request->attributes->get('panel_session'), PanelIds::parse($id), $request->validated(), $request->attributes->get('request_id'));
        $article = $result->article;

        return response()->json(['data' => (new BlogArticleResource($article))->resolve($request), 'warnings' => $result->warnings, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function destroy(Request $request, string $id, DeleteArticle $delete): Response
    {
        $data = Validator::make($request->query(), ['expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991']])->validate();
        $delete->handle($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $data['expected_version'], $request->attributes->get('request_id'));

        return response()->noContent();
    }
}
