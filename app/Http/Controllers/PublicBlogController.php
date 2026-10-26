<?php

namespace App\Http\Controllers;

use App\Blog\ResolvePublicArticle;
use App\Blog\ResolvePublicBlogArchive;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\PublicBlogRequest;
use App\Http\Resources\PublicArticleResource;
use App\Models\BlogArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PublicBlogController extends Controller
{
    public function index(PublicBlogRequest $request, ResolvePublicBlogArchive $archive): Response
    {
        return response()->json([...$archive->handle($request->validated()), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function featured(Request $request): Response
    {
        return DB::transaction(fn () => $this->collection($request, BlogArticle::public()->with(['category', 'author.avatar'])->orderByDesc('published_at')->orderByDesc('id')->limit(5)->get()));
    }

    public function show(Request $request, string $slug, ResolvePublicArticle $resolver): Response
    {
        return DB::transaction(function () use ($request, $slug, $resolver): Response {
            $result = $resolver->resolve($slug);
            if ($result['type'] !== 'article') {
                return $this->unresolved($result, false);
            }
            $article = $result['article']->load(['category', 'author.avatar']);

            return response()->json(['data' => (new PublicArticleResource($article))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
        });
    }

    public function related(Request $request, string $slug, ResolvePublicArticle $resolver): Response
    {
        return DB::transaction(function () use ($request, $slug, $resolver): Response {
            $result = $resolver->resolve($slug);
            if ($result['type'] !== 'article') {
                return $this->unresolved($result, true);
            }
            $parent = $result['article']->load('category');
            $categoryId = $parent->category && ! $parent->category->trashed() ? $parent->category_id : null;
            $query = BlogArticle::public()->whereKeyNot($parent->id)->with(['category', 'author.avatar']);
            if ($categoryId !== null) {
                $query->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$categoryId]);
            }

            return $this->collection($request, $query->orderByDesc('published_at')->orderByDesc('id')->limit(6)->get());
        });
    }

    private function collection(Request $request, Collection $articles): Response
    {
        return response()->json(['data' => PublicArticleResource::collection($articles)->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    /** @param array<string, mixed> $result */
    private function unresolved(array $result, bool $related): Response
    {
        if ($result['type'] === 'redirect') {
            $slug = rawurlencode(substr($result['path'], strlen('/blog/')));

            return response('', 301)->header('Location', '/api/v1/blog/articles/'.$slug.($related ? '/related' : ''));
        }
        throw new PanelAuthException(404, 'ARTICLE_NOT_FOUND', 'The public article was not found.');
    }
}
