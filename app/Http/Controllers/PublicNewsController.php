<?php

namespace App\Http\Controllers;

use App\Actions\News\ReadPublicNewsArchive;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\PublicNewsRequest;
use App\Http\Resources\PublicArticleResource;
use App\Models\NewsItem;
use App\News\ResolvePublicNewsItem;
use App\Support\PublicReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class PublicNewsController extends Controller
{
    public function __construct(private PublicReadSnapshot $snapshot) {}

    public function index(PublicNewsRequest $request, ReadPublicNewsArchive $archive): Response
    {
        return $this->snapshot->run(function () use ($request, $archive): Response {
            $result = $archive->handle($request->validated());
            $records = $result['records'];
            unset($result['records']);

            return response()->json(['data' => PublicArticleResource::collection($records)->resolve($request), ...$result, 'request_id' => $request->attributes->get('request_id')]);
        });
    }

    public function featured(Request $request): Response
    {
        return $this->snapshot->run(fn (): Response => $this->collection($request, NewsItem::public()->with(['category', 'author.avatar'])->orderByDesc('published_at')->orderByDesc('id')->limit(4)->get()));
    }

    public function show(Request $request, string $slug, ResolvePublicNewsItem $resolver): Response
    {
        return $this->snapshot->run(function () use ($request, $slug, $resolver): Response {
            $result = $resolver->resolve($slug);
            if ($result['type'] !== 'news_item') {
                return $this->unresolved($result);
            }
            $record = $result['news_item']->load(['category', 'author.avatar']);

            return response()->json(['data' => (new PublicArticleResource($record))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
        });
    }

    public function latest(Request $request, string $slug, ResolvePublicNewsItem $resolver): Response
    {
        return $this->snapshot->run(function () use ($request, $slug, $resolver): Response {
            $result = $resolver->resolve($slug);
            if ($result['type'] !== 'news_item') {
                return $this->unresolved($result, '/latest');
            }

            return $this->collection($request, NewsItem::public()->whereKeyNot($result['news_item']->id)->with(['category', 'author.avatar'])->orderByDesc('published_at')->orderByDesc('id')->limit(5)->get());
        });
    }

    private function collection(Request $request, Collection $records): Response
    {
        return response()->json(['data' => PublicArticleResource::collection($records)->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    /** @param array<string, mixed> $result */
    private function unresolved(array $result, string $suffix = ''): Response
    {
        if ($result['type'] === 'redirect') {
            return response('', 301)->header('Location', '/api/v1/news/articles/'.rawurlencode(substr($result['path'], strlen('/news/'))).$suffix);
        }
        throw new PanelAuthException(404, 'NEWS_NOT_FOUND', 'The public news item was not found.');
    }
}
