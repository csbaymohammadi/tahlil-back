# Panel news API and shared Word import

This document describes the authenticated news administration API, its durable publication workflow, internal public-news resolvers, and the shared blog/news DOC/DOCX importer. The backend does not provide dashboard pages, anonymous news HTTP endpoints, HTTP redirects, or a connected public-site cache consumer.

Companion files: [OpenAPI 3.1](4-panel-news.openapi.yaml) and [Postman collection](4-panel-news.postman_collection.json). The shared importer retains the earlier blog contract documented in [the blog handoff](3-panel-blog-api.md).

## Environments and release inputs

| Environment | API base |
| --- | --- |
| Development | `http://localhost:8000/api/v1/admin` |
| Staging | `https://<staging-host>/api/v1/admin` — deployment input |
| Production | `https://service.tahlilex.com/api/v1/admin` — supplied host; remote service was not contacted |

Configure `NEWS_TRUSTED_MEDIA_HOSTS` as comma-separated exact hostnames for approved HTTPS image/video URLs. Its default is `service.tahlilex.com`. Configure `NEWS_FALLBACK_COVER` for the future public serializer and `NEWS_FEATURED_IDS` as an ordered comma-separated list of News IDs for the internal featured resolver. A configured fallback image or featured list does not grant public visibility. No incoming URL is fetched.

Before release, apply additive migrations once, deploy all app instances against the same persistent MariaDB-compatible database and private shared media disk, install the documented DOC-conversion dependencies if DOC support is required, and run Laravel's scheduler continuously. The scheduler must run `schedule:run` each minute; the application schedules `news:publish-due` every ten seconds and `news:prune` hourly. `news:status` reports due work, retries, and undelivered Outbox events. No migration or seed was run on a development or production database for this implementation.

## Authentication and transport

Use the cookie session and CSRF contract in [the login handoff](1-panel-login-api.md). Fetch `GET /auth/csrf`, keep its token in memory, send cookies with `credentials: 'include'`, and include `X-CSRF-Token` on every mutation. Per the owner's explicit setting, requests from any Origin are accepted and credentialed CORS reflects the requesting origin; CSRF verification remains required. Browser cookie same-site rules still apply, so a same-site frontend or a backend-for-frontend proxy is the reliable deployment shape.

Send `Accept: application/json`; JSON writes require `Content-Type: application/json` and an object body. Unknown fields and query parameters are rejected. News item JSON is limited to 2 MiB; category JSON is limited to 16 KiB; DELETE has no body. All responses are private and `no-store`, include `X-Request-ID`, and use this envelope:

```json
{"data": {}, "request_id": "req_<uuid>"}
```

Errors use `{ "error": { "code": "...", "message": "...", "fields": {} }, "request_id": "..." }`. A `204` response has no body. Use positive integer IDs within JavaScript's safe integer range.

All active administrators have identical access, regardless of optional role label. News is a separate domain: equal numeric IDs, slugs, or category names in Blog and News never establish a relationship.

## News item API

All paths below are relative to `/api/v1/admin`. A full detail response has the following shape; list rows omit `content` and `meta_description`.

```json
{
  "data": {
    "id": 121,
    "title": "Digital asset market update",
    "slug": "digital-asset-market-update",
    "description": "A short summary.",
    "cover_image": "",
    "reading_minutes": 5,
    "status": "draft",
    "scheduled_for": null,
    "author": {"id": 12, "name": "Example Editor"},
    "category_id": null,
    "category": null,
    "created_at": "2026-10-24T09:00:00Z",
    "updated_at": "2026-10-24T09:00:00Z",
    "published_at": null,
    "public_path": null,
    "version": 1,
    "content": "<p>News copy.</p>",
    "meta_description": ""
  },
  "request_id": "req_example"
}
```

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/news/items?page=1&per_page=10&q=` | Lists Draft, Scheduled, and Published items; `per_page` is 10, 20, or 50; ordered by `created_at DESC, id DESC`. Search matches normalized title or current author name. Empty pages return `data: []` and accurate pagination metadata. |
| GET | `/news/items/{id}` | Returns a live item, including Draft/Scheduled content, only to an authenticated administrator. Missing/deleted IDs return `404 NEWS_NOT_FOUND`. |
| POST | `/news/items` | Creates an item, returns `201`, version 1, detail, and `Location: /api/v1/admin/news/items/{id}`. Required: `title`, `slug`, `content`, `category_id` (include `null`), `reading_minutes`, `status`. Optional text fields default to `""`. |
| PATCH | `/news/items/{id}` | Requires integer `expected_version` and at least one editable field. Omitted values stay unchanged; empty strings clear optional text/image values; null is valid only for category and schedule where status rules allow it. Returns canonical detail. |
| DELETE | `/news/items/{id}?expected_version=2` | Soft-deletes after an in-transaction version check; returns `204`. Repeated delete/detail is `404 NEWS_NOT_FOUND`. |

### Fields and validation

| Field | Rule |
| --- | --- |
| `title` | Trimmed plain text, 1–200 Unicode characters; never interpreted as HTML. |
| `slug` | Required, 1–200 characters, `^[a-z0-9]+(?:-[a-z0-9]+)*$`. Unique within News across current, historical, deleted, draft, and published slugs. The same item may reclaim its own history. It is independent of Blog slugs. |
| `description` | Optional trimmed plain text, up to 2,000 characters. Empty string clears it; `null` is invalid. Public excerpt fallback is derived without overwriting this field. |
| `cover_image` | Optional empty string or an existing image URL on an exact trusted HTTPS host, managed `/media/{id}/{name}` URL, or safe root-relative `/images/...` asset. No arbitrary host, traversal, protocol-relative URL, data URL, file path, or server-side fetch. `null` is invalid. Clearing a URL does not delete the file. |
| `content` | Required HTML fragment, at most 1 MiB UTF-8. The server parses and stores canonical sanitized HTML. It must retain visible text or a meaningful supported media/table block. |
| `category_id` | Required on create; nullable. Must identify a live News category. Blog category IDs are rejected even when the numbers match. |
| `reading_minutes` | Strict JSON integer from 1 to 1,440. Numeric strings, floats, booleans, and zero are rejected. |
| `meta_description` | Optional trimmed plain text, up to 500 characters. Empty string clears it; `null` is invalid. Public fallback is derived and not saved into this field. |
| `status` | `draft`, `published`, or `scheduled`; no separate `published` boolean. |
| `scheduled_for` | ISO-8601 instant. Required and strictly future for Scheduled; null for Draft/Published. Returned timestamps are UTC. Persian date/time entry belongs to the frontend, which converts `Asia/Tehran` input to UTC before submission. |

The authenticated creator becomes the stable author. Updates preserve `author_id`, `created_at`, and the first non-null `published_at`. Version starts at 1, increments only when a stored editable value changes, and remains stable on no-op PATCH. When the resulting status is Scheduled, the saved schedule must still be future on every save; changing to Draft or Published clears the schedule atomically. `expected_version` is checked inside the transaction.

### Rich text and media

The server-side HTML5 parser/sanitizer preserves supported paragraphs, headings, emphasis, lists, blockquotes, code, links, images, tables, alignment, videos, and valid YouTube embeds. The allowlist rejects scripts, event handlers, forms, SVG, unsupported attributes/classes/CSS, unsafe protocols, malformed media, and invalid YouTube embed paths. YouTube embeds normalize to `youtube-nocookie.com`. Links allow HTTP(S), mailto, tel, same-document fragments, and safe relative `/images/...` assets. Images/videos allow managed media URLs, safe `/images/...` paths, or HTTPS on `NEWS_TRUSTED_MEDIA_HOSTS`; the API never fetches the URL.

Limits include 20,000 HTML nodes, nesting depth 32, 200 rows, 50 columns, 5,000 table cells, 4,096-pixel display dimensions, and spans up to 50. Managed image/video references are validated against ownership, readiness, type, and exact canonical filename; their consumer is `news-item`. A News item is eligible to make referenced media public only when its server record is Published and not soft-deleted. Draft, Scheduled, and deleted records do not grant public media access. Soft delete retains references and physical files.

### News categories

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/news/categories?q=` | Complete live category list (no pagination), normalized search, sorted by normalized name then ID. |
| GET | `/news/categories/{id}` | Returns category detail/version; absent/deleted is `404 CATEGORY_NOT_FOUND`. |
| POST | `/news/categories` | Accepts only `{ "name": "Platform Updates" }`; returns `201`, version 1, and a `Location` header. |
| DELETE | `/news/categories/{id}?expected_version=2` | Uncategorizes all non-deleted assigned News items in the same transaction, advances their versions, soft-deletes the category, and returns `{deleted_category_id, affected_news_count}`. |
| POST | `/news/categories/{id}/merge` | Body: `{ "expected_version": 2, "target_category_id": 7, "target_expected_version": 1 }`. Moves all live items, advances their versions, soft-deletes the source, and returns `{source_category_id, target_category_id, moved_news_count}`. |

Names are unique after Persian/Arabic yeh and kaf folding, ZWNJ-to-space, whitespace compaction, and case folding. Deleted names remain reserved. Merge source and target are distinct live News categories; both versions are checked under deterministic row locks. Category operations serialize with News saves, and actual committed affected counts are returned. Category counts use `news_count`.

## Publication, URL ownership, and Outbox

News schedules use durable `news_schedule_tasks` rows, a per-item schedule generation, idempotent reconciliation, a global mutation lock, retries with backoff, and a transactional transition to Published. A stale task cannot publish an item that was edited, cancelled, deleted, or scheduled again. The scheduler aims for publication within 60 seconds of due time under normal operation. First-publication time is retained. Inspect status with `php artisan news:status`; due work is processed with `php artisan news:publish-due`.

`ResolvePublicNewsItem` is an internal resolver only: it returns a published, non-deleted current slug; an alias redirects directly to `/news/{current-slug}` only when that slug was previously public; all other states return not-found. `ResolveFeaturedNewsItems` filters configured IDs to distinct published/live records, preserves selected order, fills remaining positions newest-first, and caps results at four; latest excludes the current record and sorts by `published_at DESC, id DESC`. No public HTTP route is registered in this delivery.

Publicly effective News changes write to the separate `news_outbox`. No consumer is registered here, so events remain pending. `news:status` reports pending count and age; successful cache invalidation, website redirect, sitemap update, or search-index revalidation cannot be claimed until the site registers and acknowledges a consumer. Audit is stored separately in `news_audit_events`, recording actor, target, changed fields/status/slug transitions, request ID, UTC times, and actual category counts without full bodies or secrets. `news:prune` removes delivered outbox, processed scheduling, and audit records older than 90 days. Existing `blog:prune` continues to clean shared Word import records and reports without relocating old blog data.

## Shared Word import

`POST /content-imports` accepts `content_type=blog` or `content_type=news` plus exactly one `.doc` or `.docx` file up to 20 MiB and a UUID `Idempotency-Key`. The matching module-specific template is [public/tahlilpanel/templates/news-import-v1.docx](../public/tahlilpanel/templates/news-import-v1.docx), served at `/tahlilpanel/templates/news-import-v1.docx`; its required marker is `content_type: news`. The existing Blog template remains `content_type: blog`. Templates share the metadata keys `title`, `slug`, `description`, `category_name`, `reading_minutes`, and `meta_description`; cover and body images are embedded in their marked regions. Author, status, category ID, and schedule are not accepted from the document. Successful imports always create a complete Draft with the current administrator as author and a module-local category lookup.

The importer shares parser/conversion/image processing, storage quota, one-in-flight-per-account lock, five new attempts per 15 minutes, and a 24-hour idempotency store. Reusing the same key with different bytes or a different module returns `409 IDEMPOTENCY_CONFLICT`; a concurrent active request returns `409 IMPORT_IN_PROGRESS` with `Retry-After`. Matching successful or validation results replay without reprocessing. A replay of a deleted result returns `404 IMPORT_RESULT_NOT_FOUND` and does not recreate it. Success contains the full canonical record and module-specific `Location`.

DOCX uses bounded ZIP/XML parsing (2,000 entries, 128 MiB uncompressed, 50 images, 150 million aggregate pixels), no external entities/relationships, strict markers/metadata, image validation, and the same HTML sanitizer. Processing is capped at 90 seconds. DOC requires LibreOffice, Python `oletools`, the checked-in OLE inspection helper, and OS isolation (`sandbox-exec` on macOS or `bwrap` on Linux); conversion is capped at 45 seconds. Missing prerequisites return controlled `503`; the server never falls back to unsandboxed conversion. The implementation does not add antivirus scanning, remove source media metadata, or offer `.docm`, RTF, ODT, or PDF import.

Validation failures return JSON `error.issues` in Persian and, when report generation succeeds, a private 24-hour report from `GET /content-imports/reports/{report_id}`. Report ownership is tied to the creating administrator. News reports download as `news-import-errors.docx`; Blog reports retain `blog-import-errors.docx`. Report generation failure keeps JSON issues and sets `report: null`; report download never retries the import. Shared import state stays in the existing `blog_content_imports` / `blog_import_reports` tables for backward compatibility; the additive migration adds nullable `news_item_id` without moving prior Blog results.

Use a 120-second frontend timeout. Do not automatically retry with a new key after network loss: retain the exact file and key, then retry to recover a committed result or stored validation result. Closing the browser does not cancel an in-flight transaction. Do not auto-save a local pseudo-record. On successful response upsert by server ID, clear search, return to page 1, and remain on the list. Keep JSON issues visible if report download fails.

```js
const api = 'https://service.tahlilex.com/api/v1/admin';
const csrfResponse = await fetch(`${api}/auth/csrf`, { credentials: 'include' });
const csrf = (await csrfResponse.json()).data.csrf_token;
const form = new FormData();
form.append('content_type', 'news');
form.append('file', selectedFile);
const result = await fetch(`${api}/content-imports`, {
  method: 'POST',
  credentials: 'include',
  headers: { 'X-CSRF-Token': csrf, 'Idempotency-Key': attemptUuid, Accept: 'application/json' },
  body: form,
  signal: AbortSignal.timeout(120_000),
});
```

Let the browser set multipart `Content-Type` and its boundary. For SSR/BFF, forward the cookie and CSRF header to the backend, relay `Set-Cookie` with intentional host/path semantics, and never expose the session cookie to JavaScript. Route guards must use `/auth/me` as their authoritative session check, preserve unsaved editor data on authentication/network failures, and never infer authorization from the role label.

A normal save follows the same CSRF/session pattern. Preserve the version returned by detail and send it on every PATCH; after `409 VERSION_CONFLICT`, reload the record and let the editor resolve its local changes instead of overwriting silently.

```js
const saved = await fetch(`${api}/news/items/${news.id}`, {
  method: 'PATCH',
  credentials: 'include',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrf,
  },
  body: JSON.stringify({
    expected_version: news.version,
    title: form.title,
    scheduled_for: form.status === 'scheduled' ? tehranDateTime.toISOString() : null,
    status: form.status,
  }),
  signal: AbortSignal.timeout(15_000),
});
```

For command-line diagnostics, use a short-lived test session and do not put real cookies or CSRF tokens in shell history or logs:

```sh
curl -i 'https://service.tahlilex.com/api/v1/admin/news/items?page=1&per_page=10' \
  -H 'Accept: application/json' \
  --cookie 'tahlil-panel-session-v2=<session-cookie>'

curl -i -X POST 'https://service.tahlilex.com/api/v1/admin/news/items' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <csrf-token>' \
  --cookie 'tahlil-panel-session-v2=<session-cookie>' \
  --data '{"title":"Sample announcement","slug":"sample-announcement","content":"<p>Sample copy.</p>","category_id":null,"reading_minutes":2,"status":"draft"}'
```

For an SSR/BFF request, copy only the named session cookie to the backend `Cookie` header, forward `X-CSRF-Token`, and relay `Set-Cookie` without widening its domain or removing `HttpOnly`, `Secure`, or `SameSite` attributes. A browser route guard should call `/auth/me` with credentials; `401` redirects to login while retaining the local editor state in the frontend's private state store. `403 CSRF_INVALID` refreshes CSRF and asks the user to retry; it must not replay a write automatically. `422` maps `error.fields` to controls, `409` reloads the server version, and `429` waits for `Retry-After`.

## Errors and known delivery limits

News-specific codes include `NEWS_NOT_FOUND`, `CATEGORY_NOT_FOUND`, `SLUG_TAKEN`, `CATEGORY_NAME_TAKEN`, and `VERSION_CONFLICT`. Shared transport/auth codes include `UNAUTHENTICATED`, `FORBIDDEN`, `CSRF_INVALID`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_MEDIA_TYPE`, `VALIDATION_FAILED`, `RATE_LIMITED`, `SERVICE_UNAVAILABLE`, and `INTERNAL_ERROR`. Import adds `INVALID_WORD_FILE`, `IMPORT_VALIDATION_FAILED`, `IDEMPOTENCY_CONFLICT`, `IMPORT_IN_PROGRESS`, `IMPORT_RESULT_NOT_FOUND`, and `IMPORT_REPORT_NOT_FOUND`.

This handoff covers backend API behavior, not frontend form behavior, keyboard/mobile acceptance, public anonymous endpoints, visible redirects, cache completion, search/sitemap consumers, or browser download policy. Those frontend/public-site acceptance checks remain unverified. Verify scheduler supervision, persistent shared disk, database version, TLS/proxy/CORS, cookie transport, and DOC sandbox on the real deployment before release.

## Automated coverage

Feature coverage includes News item/category CRUD, version/no-op semantics, normalization, category transfer/delete, independent News/Blog IDs/slugs/categories, safe content, resolver visibility/alias behavior, featured selection filtering, scheduled publication, author-search synchronization, successful/invalid DOCX imports, private report ownership, retry/replay, module-scoped idempotency, managed image attachment, quota/media behavior shared with the media suite, and controlled missing-DOC-prerequisite errors. The isolated SQLite suite does not prove multi-process locking; the dedicated MariaDB concurrency tests and full verification outcomes are recorded after the run in this file.
