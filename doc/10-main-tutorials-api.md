# Public video tutorials API

This handoff covers the backend contract for the public tutorial archive and detail frontend. All paths are relative to `/api/v1`; examples are illustrative. No login, visitor account, comment, rating, enrollment, video proxy, or video-hosting API is required.

## Requests and HTTP behavior

| Method and path | Result |
| --- | --- |
| `GET /tutorials` | Searchable archive, pagination, category facets |
| `GET /tutorials/featured` | Array containing the newest eligible tutorial, or empty |
| `GET /tutorials/{slug}` | One tutorial with its immutable, case-sensitive slug |
| `GET /tutorials/{slug}/latest` | Up to five newest eligible tutorials other than the parent |

Only non-deleted lessons with a valid YouTube URL and matching saved YouTube ID are public. Provider availability on the external network is not checked. A deleted lesson or one with an invalid required source disappears from all public reads. `featured` and `latest` sort by first website publication descending, then numeric ID descending, independent of homepage selections and legacy featured/hero configuration.

Public GETs require no request body. Successful responses use `Cache-Control: no-cache, must-revalidate`; errors use `no-store`. Every JSON response has `request_id` and an identical `X-Request-ID` header. Reads share the configurable public read rate limit, normally 300 requests per minute per source. CORS follows the current application configuration.

## Archive query

`GET /tutorials` supports:

| Parameter | Contract |
| --- | --- |
| `q` | Optional trimmed string, at most 200 Unicode characters; default `""` |
| `category` | Optional immutable category key; effective value may be `null` |
| `page` | Positive integer, default `1` |
| `per_page` | Fixed at `9`; omit it or send `9` |

Search checks title, the complete plain description, and the live category name. Matching folds case, maps Arabic ي/ى to Persian ی and ك to ک, and ignores whitespace and zero-width non-joiners. `%` and `_` are literal text. The response preserves trimmed display `q` rather than the normalized search value.

Missing, empty, `all`, unknown, deleted, malformed, structured, or repeated `category` falls back to All and returns `filters.category: null`. Category keys are stable: retained keys may include `getting-started`, `exchanges`, and `strategies`; new panel categories use `category-{id}`. Do not infer identity from a translated label. Categories are returned in normalized-name order, then ID, including zero-count live categories. A deleted category is Uncategorized.

Facets count the search result **before** the selected category. `all_count` equals category counts plus `uncategorized_count`; `meta.total` includes both search and selected category. Totals, facets and items use the same consistent read snapshot, and ineligible records are excluded before counting or pagination.

Unknown or repeated query parameters, structured non-category parameters, `sort`, invalid values, or a GET body return `422 VALIDATION_FAILED`. Detail, featured and latest accept no query parameters or body. Beyond the last page, the requested page and total are retained but `data` is empty; `total_pages` is zero for an empty result.

### Populated archive response

`GET /tutorials?q=platform&category=exchanges&page=1`

```json
{
  "data": [
    {
      "id": 91,
      "slug": "youtube-AbCdEf12_-3",
      "title": "Getting Started with the Platform",
      "href": "/tutorials/youtube-AbCdEf12_-3",
      "description": "A short introduction.\nFollow the steps shown in the video.",
      "cover_image": "/images/tutorials/example.webp",
      "category": {"id": 2, "name": "Working with Exchanges", "key": "exchanges"},
      "youtube_url": "https://www.youtube.com/watch?v=AbCdEf12_-3",
      "youtube_video_id": "AbCdEf12_-3",
      "aparat_url": "https://www.aparat.com/v/abc1234",
      "aparat_video_id": "abc1234",
      "default_provider": "aparat",
      "duration_seconds": 125,
      "duration_source": "youtube",
      "created_at": "2026-10-24T08:00:00.000000Z",
      "updated_at": "2026-10-24T09:00:00.000000Z",
      "published_at": "2026-10-24T08:00:00.000000Z"
    }
  ],
  "meta": {"page": 1, "per_page": 9, "total": 1, "total_pages": 1},
  "filters": {"q": "platform", "category": "exchanges"},
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {"id": 2, "name": "Working with Exchanges", "key": "exchanges", "count": 1},
      {"id": 3, "name": "Strategies", "key": "strategies", "count": 0}
    ]
  },
  "request_id": "req_example"
}
```

### Empty and out-of-range response

```json
{
  "data": [],
  "meta": {"page": 1, "per_page": 9, "total": 0, "total_pages": 0},
  "filters": {"q": "missing", "category": null},
  "facets": {"all_count": 0, "uncategorized_count": 0, "categories": []},
  "request_id": "req_example"
}
```

An out-of-range response has the same shape, but `meta.page` is the requested page and `meta.total`/`total_pages` remain the actual nonzero values. The frontend may select the final valid page and refetch. Never fill gaps with duplicates.

## Tutorial representation

All archive, featured, detail, latest and homepage tutorial cards share exactly the same public fields:

| Field | Type and meaning |
| --- | --- |
| `id` | Positive numeric ID scoped to tutorials. |
| `slug` | Immutable case-sensitive saved slug; semantic legacy slugs remain valid. |
| `title` | Saved title. |
| `href` | Root-relative website detail URL, `/tutorials/{slug}`. |
| `description` | Saved plain text, at most 5,000 characters; empty is valid. Preserve line breaks and escape text when rendering. |
| `cover_image` | Approved publicly accessible image URL or `""`; use a neutral frontend fallback when empty or rendering fails. `i.ytimg.com` is an allowed source but does not establish playback identity. |
| `category` | Live `{id, name, key}` object or `null` for Uncategorized. |
| `youtube_url`, `youtube_video_id` | Canonical YouTube watch URL and its matching validated case-sensitive 11-character ID. |
| `aparat_url`, `aparat_video_id` | Canonical Aparat URL and matching ID, or `""` and `null` when absent/invalid. |
| `default_provider` | `"aparat"` if the saved Aparat URL and ID are both valid and match; otherwise `"youtube"`. |
| `duration_seconds` | Positive safe integer or `null` when unknown. |
| `duration_source` | `"aparat"`, `"youtube"`, or `null`; may differ from default provider. |
| `created_at`, `updated_at`, `published_at` | UTC ISO 8601 timestamp or `null`. `published_at` is first availability on this site, not the provider upload date. |

The duration is chosen from saved metadata for the exact `(provider, video ID)` pair: first the default source, then the other valid saved source. Changing an ID invalidates its old duration. A metadata write does not change the editable version, creation time, first-publication time, or editorial update time. The current backend supports recording metadata through its internal `RecordTutorialDuration` action; it does **not** currently discover or refresh durations automatically and does not make outbound provider calls during reads. Unknown remains `null`, not zero or an inferred value.

### Featured response

`GET /tutorials/featured`:

```json
{
  "data": [{
    "id": 91, "slug": "youtube-AbCdEf12_-3", "title": "Getting Started",
    "href": "/tutorials/youtube-AbCdEf12_-3", "description": "A short introduction.",
    "cover_image": "/images/tutorials/example.webp", "category": {"id": 2, "name": "Exchanges", "key": "exchanges"},
    "youtube_url": "https://www.youtube.com/watch?v=AbCdEf12_-3", "youtube_video_id": "AbCdEf12_-3",
    "aparat_url": "https://www.aparat.com/v/abc1234", "aparat_video_id": "abc1234", "default_provider": "aparat",
    "duration_seconds": 125, "duration_source": "youtube", "created_at": "2026-10-24T08:00:00.000000Z",
    "updated_at": "2026-10-24T09:00:00.000000Z", "published_at": "2026-10-24T08:00:00.000000Z"
  }],
  "request_id": "req_example"
}
```

When no eligible lesson exists, `data` is `[]`. Hide the hero card instead of inventing one.

### Detail: YouTube-only, Uncategorized, and unknown duration

`GET /tutorials/youtube-AbCdEf12_-3`:

```json
{
  "data": {
    "id": 91,
    "slug": "youtube-AbCdEf12_-3",
    "title": "Getting Started",
    "href": "/tutorials/youtube-AbCdEf12_-3",
    "description": "First line.\nSecond line.",
    "cover_image": "",
    "category": null,
    "youtube_url": "https://www.youtube.com/watch?v=AbCdEf12_-3",
    "youtube_video_id": "AbCdEf12_-3",
    "aparat_url": "",
    "aparat_video_id": null,
    "default_provider": "youtube",
    "duration_seconds": null,
    "duration_source": null,
    "created_at": "2026-10-24T08:00:00.000000Z",
    "updated_at": "2026-10-24T09:00:00.000000Z",
    "published_at": "2026-10-24T08:00:00.000000Z"
  },
  "request_id": "req_example"
}
```

No iframe markup, author, tags, article body, transcript, comments, votes, editor IDs, version or provider credentials are returned. Changing either provider or title does not replace the slug or numeric identity. An unrelated record with the same numeric ID or slug in another domain remains independent.

### Latest response

`GET /tutorials/youtube-AbCdEf12_-3/latest` resolves the parent first and returns up to five records with the same representation:

```json
{
  "data": [{
    "id": 92, "slug": "semantic-existing-slug", "title": "Another Lesson", "href": "/tutorials/semantic-existing-slug",
    "description": "", "cover_image": "", "category": null,
    "youtube_url": "https://www.youtube.com/watch?v=XXXXXXXXXXX", "youtube_video_id": "XXXXXXXXXXX",
    "aparat_url": "", "aparat_video_id": null, "default_provider": "youtube",
    "duration_seconds": null, "duration_source": null,
    "created_at": "2026-10-25T08:00:00.000000Z", "updated_at": "2026-10-25T08:00:00.000000Z",
    "published_at": "2026-10-25T08:00:00.000000Z"
  }],
  "request_id": "req_example"
}
```

A valid parent with no others returns `{"data": [], "request_id": "req_example"}`. A missing, deleted or ineligible parent returns the same `404 TUTORIAL_NOT_FOUND` as detail; it does not return an unrelated latest list.

## Playback integration

Aparat is presented first and selected when available; otherwise disable that tab and use YouTube. Construct embed URLs from the validated IDs, preserving case:

```text
Aparat embed: https://www.aparat.com/video/video/embed/videohash/{id}/vt/frame
Aparat watch: https://www.aparat.com/v/{id}
YouTube embed: https://www.youtube-nocookie.com/embed/{id}?autoplay=0&controls=1&playsinline=1&fs=1
YouTube watch: https://www.youtube.com/watch?v={id}
```

YouTube IDs match `^[A-Za-z0-9_-]{11}$`; Aparat IDs match `^[A-Za-z0-9_-]{5,32}$`. Use only fixed hosts, no arbitrary URL or returned raw markup. Unmount the previous iframe when switching sources, disable autoplay, give tabs and frames accessible labels, and keep an external watch link with `noopener noreferrer`. An unreachable provider is a player-level state; do not mark the whole tutorial deleted or silently replace the saved source.

## Shared frontend dependencies

- Both homepage video carousels use the same tutorial summary and preserve their saved selection order. The Aparat carousel requires a valid matching Aparat source; the general video carousel permits YouTube-only lessons.
- Channel actions come from `GET /page-settings` fields `links.aparat` and `links.youtube`; hide empty destinations. The shared detail banner comes from `advertisements.news_and_articles`.
- The separate latest-blog block uses `GET /blog/articles?page=1&per_page=6`. Do not exclude a blog article because its ID coincides with the tutorial ID.
- Build query keys from query/category/page, convert snake_case once in the frontend transformer, ignore stale search responses, and use the saved `href` for internal navigation. Search/category changes reset page to 1.
- Server-render detail and metadata from this lookup. Use trusted configured canonical origin. The website's `published_at` is not the provider's `uploadDate`; do not fabricate provider upload metadata or emit an incomplete `VideoObject`. Display dates in `Asia/Tehran` and use the Persian calendar in the frontend.

## Errors

All errors use the common envelope, with `fields` always an object and the same request ID in the response header:

```json
{
  "error": {"code": "TUTORIAL_NOT_FOUND", "message": "The public tutorial was not found.", "fields": {}},
  "request_id": "req_example"
}
```

| HTTP | Code | Client behavior |
| --- | --- | --- |
| 404 | `TUTORIAL_NOT_FOUND` | Show a real not-found state for a missing, deleted, or ineligible parent. |
| 422 | `VALIDATION_FAILED` | Correct documented parameters; inspect `error.fields` when supplied. |
| 429 | `RATE_LIMITED` | Respect the `Retry-After` response header. |
| 503 | `TUTORIALS_UNAVAILABLE` | Show a retryable service error; do not present it as zero results. |

Validation example:

```json
{"error": {"code": "VALIDATION_FAILED", "message": "The given data was invalid.", "fields": {"page": ["The page field format is invalid."]}}, "request_id": "req_example"}
```

## Deployment and invalidation

No migration or new dependency is required for these endpoints. Before activation on the destination database, run the read-only `php artisan app:diagnose --no-interaction` and verify `tutorial_featured_slug` is OK. An existing non-deleted tutorial with slug `featured` conflicts with the fixed featured route and requires an explicit resolution; do not automatically rename a saved slug.

Tutorial saves, deletes, provider changes, category changes and duration updates create existing outbox events. The existing delivery worker sends signed revalidation events for homepage, content, search and sitemap, and independently retries failures. Actual webhook delivery depends on a configured reachable frontend receiver. Source metadata changes invalidate affected public projections and sitemap revision without rewriting editorial timestamps.
