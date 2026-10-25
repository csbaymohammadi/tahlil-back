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
| POST | `/news/items` | Requires only `title` and `slug`; creates a Draft by default and returns `201`, version 1, detail, `warnings`, and `Location: /api/v1/admin/news/items/{id}`. All other fields are optional. |
| PATCH | `/news/items/{id}` | Requires integer `expected_version` and at least one editable field. Omitted values stay unchanged; empty/null optional text and image values clear them. Unusable optional values are cleared with Persian warnings. Returns canonical detail and `warnings`. |
| DELETE | `/news/items/{id}?expected_version=2` | Soft-deletes after an in-transaction version check; returns `204`. Repeated delete/detail is `404 NEWS_NOT_FOUND`. |

### Fields and validation

| Field | Rule |
| --- | --- |
| `title` | Trimmed plain text, 1–200 Unicode characters; never interpreted as HTML. |
| `slug` | Required, 1–200 characters, `^[a-z0-9]+(?:-[a-z0-9]+)*$`. Unique within News across current, historical, deleted, draft, and published slugs. The same item may reclaim its own history. It is independent of Blog slugs. |
| `description` | Optional trimmed plain text, up to 2,000 characters. Empty/null clears it; an unsupported type or overlong value becomes empty and adds a warning. Public excerpt fallback is derived without overwriting this field. |
| `cover_image` | Optional empty string or an existing image URL on an exact trusted HTTPS host, managed `/media/{id}/{name}` URL, or safe root-relative `/images/...` or `/tahlilpanel/images/...` asset. Unsafe, inaccessible, or wrongly typed covers are cleared with a warning. No arbitrary host, traversal, protocol-relative URL, data URL, file path, or server-side fetch. Clearing a URL does not delete the file. |
| `content` | Optional HTML fragment, at most 1 MiB UTF-8; empty content is accepted. The server parses and stores canonical sanitized HTML. Unsafe elements, attributes, links, or managed media access are still rejected; an unsupported JSON type is cleared with a warning. |
| `category_id` | Optional; omitted/null means uncategorized. A missing, invalid, deleted, or Blog-only category is ignored, stored as `null`, and reported with a warning. |
| `reading_minutes` | Optional integer from 1 to 1,440. Missing/null or an unusable value is stored as `null`; unusable provided values add a warning. |
| `meta_description` | Optional trimmed plain text, up to 500 characters. Empty/null clears it; an unsupported type or overlong value becomes empty and adds a warning. Public fallback is derived and not saved into this field. |
| `status` | Optional `draft`, `published`, or `scheduled`; defaults to `draft`. No separate `published` boolean. |
| `scheduled_for` | ISO-8601 instant. Required and strictly future for Scheduled; null for Draft/Published. Returned timestamps are UTC. Persian date/time entry belongs to the frontend, which converts `Asia/Tehran` input to UTC before submission. |

Only the display title and English title/slug receive content validation: title is required and 1–200 Unicode characters; slug is required and 1–200 characters matching `^[a-z0-9]+(?:-[a-z0-9]+)*$`, and remains reserved/unique across News history. Status and schedule continue to receive state validation. The authenticated creator becomes the stable author. Updates preserve `author_id`, `created_at`, and the first non-null `published_at`. Version starts at 1, increments only when a stored editable value changes, and remains stable on no-op PATCH. When the resulting status is Scheduled, the saved schedule must still be future on every save; changing to Draft or Published clears the schedule atomically. `expected_version` is checked inside the transaction.

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

`POST /content-imports` accepts `content_type=blog` or `content_type=news` plus exactly one `.doc` or `.docx` file up to 20 MiB and a UUID `Idempotency-Key`. News uses [the Persian v2 template](../public/tahlilpanel/templates/news-import-v2.docx), served at `/tahlilpanel/templates/news-import-v2.docx`. Its sections are **عنوان، عنوان انگلیسی، توضیحات، تصویر، محتوا، توضیحات متا**; **دسته‌بندی** and **زمان مطالعه** may optionally follow. The first section maps to `title`; the second maps to `slug`. Only these two titles are required and content-validated. The other sections may be empty. The importer still accepts the old v1 marker/table template for existing files. Blog has its own Persian v2 template at `/tahlilpanel/templates/blog-import-v2.docx`; the shared parser selects behavior from `content_type` and keeps category lookup module-local. Author and Draft status come from the authenticated session; category ID and schedule are not accepted from a document. The sample file [news-word-import-sample-fa.docx](samples/news-word-import-sample-fa.docx) can be uploaded as an end-to-end check. A Persian frontend handoff is in [4-panel-news-word-import-fa.md](4-panel-news-word-import-fa.md).

The importer shares parser/conversion/image processing, storage quota, one-in-flight-per-account lock, five new attempts per 15 minutes, and a 24-hour idempotency store. Reusing the same key with different bytes or a different module returns `409 IDEMPOTENCY_CONFLICT`; a concurrent active request returns `409 IMPORT_IN_PROGRESS` with `Retry-After`. Matching successful or validation results replay without reprocessing. A replay of a deleted result returns `404 IMPORT_RESULT_NOT_FOUND` and does not recreate it. Success contains the full canonical record and module-specific `Location`.

DOCX uses bounded ZIP/XML parsing (2,000 entries, 128 MiB uncompressed, 50 images, 150 million aggregate pixels), no external entities/relationships, Persian section parsing or compatible legacy markers, image validation, and the same HTML sanitizer. Processing is capped at 90 seconds. A blank/missing category or invalid reading time is ignored with a Persian warning; an unusable description, metadata description, or cover is left empty with a warning. Missing title or invalid/duplicate English slug still fails. A successful import always creates a Draft, including when optional body content is empty. Security and resource checks remain active for all fields and embedded files. DOC requires LibreOffice, Python `oletools`, the checked-in OLE inspection helper, and OS isolation (`sandbox-exec` on macOS or `bwrap` on Linux); conversion is capped at 45 seconds. Missing prerequisites return controlled `503`; the server never falls back to unsandboxed conversion. The implementation does not add antivirus scanning, remove source media metadata, or offer `.docm`, RTF, ODT, or PDF import.

New Blog and News validation failures return Persian JSON only: `error.code`, `error.message`, `error.fields`, ordered `error.issues`, and `request_id`. They do not include a report file, original filename, or display timestamp. The existing owner-bound report endpoint remains available only for reports created by older News imports; old records are not rewritten or moved. All new errors, including replayed validation results, omit report metadata. Shared import state remains in the existing `blog_content_imports` / `blog_import_reports` tables for backward compatibility; the additive migration adds nullable `news_item_id` without moving prior Blog results.

Use a 120-second frontend timeout. Do not automatically retry with a new key after network loss: retain the exact file and key, then retry to recover a committed result or stored validation result. Closing the browser does not cancel an in-flight transaction. Do not auto-save a local pseudo-record. On successful response upsert by server ID, clear search, return to page 1, and remain on the list. Display the Persian JSON issues directly; new validation responses do not include a downloadable report.

### Ubuntu 24.04 prerequisites and service-unavailable troubleshooting

DOCX is parsed by PHP directly. For PHP 8.3, install the extensions for both the CLI and the PHP-FPM runtime used by the app, plus FFmpeg when Word documents may contain embedded images:

```sh
sudo apt update
sudo apt install php8.3-zip php8.3-xml php8.3-mbstring php8.3-gd ffmpeg
php8.3 -m | grep -E '^(dom|fileinfo|gd|mbstring|xml|zip)$'
ffmpeg -version
```

`fileinfo` is normally included by Ubuntu's PHP common package. Verify the same modules under the deployed PHP-FPM version, then reload PHP-FPM and any long-running workers. DOCX-only files without embedded images do **not** need LibreOffice, Python, `oletools`, or Bubblewrap. The worker must be able to create temporary directories under `storage/framework/cache`; the configured media disk must also be writable if the document contains images.

Legacy `.doc` conversion additionally requires `libreoffice-writer`, `python3-venv`, and `bubblewrap`, plus the pinned `oletools==0.60.2` package in an application-managed Python virtual environment. Set `WORD_IMPORT_PYTHON`, `LIBREOFFICE_BINARY`, and `BWRAP_BINARY` in the application environment and verify Bubblewrap under the actual PHP-FPM user. Ubuntu 24.04 AppArmor may prevent unprivileged user namespaces; do not disable AppArmor globally. The runtime returns a controlled error instead of converting a `.doc` without a working sandbox.

`سرویس پردازش سند موقتاً در دسترس نیست` is the safe public fallback for an unexpected exception during import; it does not identify which prerequisite failed. The response includes `request_id`. The current request logger records the request ID and exception class while omitting exception messages and stack traces, so it may not identify the precise failure. For an optional-field News draft, first confirm the additive migration `2026_10_24_171338_make_news_reading_minutes_nullable` has run: without it, storing the now-optional reading time as `null` can fail at the database layer and become this generic 503. Also verify PHP-FPM extensions, write access to `storage/framework/cache` and the configured media disk, and FFmpeg when the document has embedded images. If the file is a legacy DOC, additionally verify LibreOffice, the configured Python environment and `oletools`, and Bubblewrap/AppArmor isolation. Do not set `APP_DEBUG=true` in production or send document contents, cookies, or CSRF values in a support report.

After deploying this release, apply pending additive migrations with `php artisan migrate --force` during the normal release process. This project has not run migrations on the user's development or production database.

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

Feature coverage includes News item/category CRUD, version/no-op semantics, normalization, category transfer/delete, independent News/Blog IDs/slugs/categories, safe content, resolver visibility/alias behavior, featured selection filtering, scheduled publication, author-search synchronization, Persian v2 and legacy v1 DOCX imports, the uploadable sample file, optional-field warnings for both item API and Word import, Persian JSON-only errors, retry/replay, module-scoped idempotency, managed image attachment, quota/media behavior shared with the media suite, and controlled missing-DOC-prerequisite errors. New imports do not create reports; the owner-bound download path remains for older persisted News reports. The full PHPUnit suite passed on 2026-10-25: 180 tests, 169 passed, and 11 skipped. The Blog/News multi-process MariaDB race tests are skipped when PHPUnit uses isolated SQLite; production locking behavior remains unverified. Redocly validates both OpenAPI files; its only remaining warnings concern the intentional local `localhost` server URLs and missing News license metadata. Postman JSON parsing and production deployment checks remain separate.
