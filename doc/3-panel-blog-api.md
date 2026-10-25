# Panel blog API and Word import

This document describes the implemented blog administration API and DOC/DOCX import flow. The backend does not include dashboard pages, public article endpoints, a public cache consumer, or production website redirects.

Companion artifacts: [OpenAPI 3.1](3-panel-blog.openapi.yaml) and [Postman collection](3-panel-blog.postman_collection.json).

## Environments and deployment inputs

| Environment | API base |
| --- | --- |
| Development | `http://localhost:8000/api/v1/admin` |
| Staging | `https://<staging-host>/api/v1/admin` — deployment placeholder |
| Production | `https://service.tahlilex.com/api/v1/admin` |

The production backend host is supplied for this project. Confirm the real deployment, TLS termination, proxy headers, CORS, database, and shared storage separately; this repository does not verify those remote services. `BLOG_TRUSTED_MEDIA_HOSTS` defaults to `service.tahlilex.com`; list additional exact HTTPS media hostnames as a comma-separated setting. The API never fetches submitted URLs.

## Session, CSRF, and response rules

Use the cookie-based panel session from [the authentication handoff](1-panel-login-api.md). Obtain a current CSRF token from `GET /auth/csrf`; include the session cookie and `X-CSRF-Token` for every mutation. Requests from any Origin are accepted by current owner instruction. CORS allows credentials and reflects requesting origins. The host-only `SameSite=Lax` cookie still follows browser same-site rules, so an unrelated cross-site browser app may need a same-site frontend or a backend-for-frontend proxy.

Use `Accept: application/json`. JSON mutations require `Content-Type: application/json` and a JSON object; multipart import must let the browser set its boundary. Requests are private and `no-store`; successful JSON responses use `{ "data": ..., "request_id": "req_<uuid>" }`. Errors use `{ "error": { "code": "...", "message": "...", "fields": {} }, "request_id": "..." }`. Responses include `X-Request-ID`, `Cache-Control: no-store`, and `Vary: Cookie`. `204` has no body. IDs are positive integers; preserve them as JavaScript numbers.

The global authenticated management guard treats all active administrators equally; role labels do not affect access. Unknown JSON fields and query parameters fail validation. Article JSON is capped at 2 MiB, other JSON at 16 KiB, and DELETE requests have no body.

## Article resource and endpoints

Article detail has these fields. `slug` is the English title used by the frontend; there is no separate English-title property. Optional fields may be empty, and an unset reading time is returned as `null`:

```json
{
  "id": 121,
  "title": "Understanding Market Price References",
  "slug": "understanding-market-price-references",
  "description": "A short introduction.",
  "cover_image": "",
  "reading_minutes": null,
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
  "content": "<h2>Introduction</h2><p>Article text.</p>",
  "meta_description": ""
}
```

`content` and `meta_description` are present on detail, create/update, and Word-import responses; article list rows omit them. `public_path` is `/blog/{slug}` only when status is Published. No public route is registered by this delivery.

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/blog/articles?page=1&per_page=10&q=` | List Draft, Scheduled, and Published records; `per_page` is 10, 20, or 50; sort newest first. Search normalized title and current author name. |
| GET | `/blog/articles/{id}` | Return full live article detail. |
| POST | `/blog/articles` | Only `title` and `slug` are required. Every other content field may be omitted or empty; omitted optional fields are stored empty, except `reading_minutes`, which is `null`. Status defaults to `draft`. Returns 201, version 1, `Location`, and a `warnings` array. |
| PATCH | `/blog/articles/{id}` | Require `expected_version` and at least one editable field. Omitted fields are preserved. Empty or null optional text clears its value; invalid optional values are ignored by storing an empty value or `null` and returned in `warnings`. A stale version returns 409. |
| DELETE | `/blog/articles/{id}?expected_version=2` | Soft-delete after version check. Returns 204; repeated deletion returns 404. |

Only the title and English title are required: title is trimmed plain text of 1–200 Unicode characters; `slug` is 1–200 lowercase ASCII letters/digits with single hyphen separators and unique across current and historical ownership. The title editor should send its English title in `slug`. Description, cover, body, meta description, category, and reading time are optional with no form-level length or required checks. The backend still enforces safe HTML, media access, file integrity, and resource limits. An unusable description, image, category, or reading time does not block saving: the value stays empty or `null`, and the response includes Persian warnings such as `{ "field": "category_id", "code": "CATEGORY_IGNORED", "message": "دسته‌بندی قابل‌استفاده نیست و مقاله بدون دسته‌بندی ذخیره شد." }`. Reading time is `null` when unset; no default is fabricated. Category may be omitted or null to clear it.

Create/update success responses contain `warnings: []` when no optional values were ignored. Warning `field` and `code` keys remain stable for frontend handling; every human-readable warning is Persian. These warnings do not indicate a failed save.

Status is `draft`, `published`, or `scheduled`. Schedule time must be a future ISO 8601 instant when Scheduled and null otherwise. First publication time is retained across later edits and republishing. The author is always taken from the session, never from request data.

### Categories

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/blog/categories?q=` | Complete live category list, optionally normalized search; no pagination. |
| GET | `/blog/categories/{id}` | Return a live category and current version. |
| POST | `/blog/categories` | Accept only `{ "name": "Exchange Guides" }`; returns 201, version 1, and `Location`. |
| DELETE | `/blog/categories/{id}?expected_version=2` | Soft-delete and uncategorize all non-deleted assigned articles in one transaction; returns deleted ID and actual affected count. |
| POST | `/blog/categories/{id}/merge` | Accept `expected_version`, `target_category_id`, and `target_expected_version`; move all live articles, advance their versions, then soft-delete source. |

Category names are unique after Persian/Arabic yeh/kaf normalization, zero-width non-joiner conversion, whitespace collapse, and case folding. Deleted category names stay reserved. Category deletion/merge preserves article status, schedule, author, media, and publication date. Stale category or article versions return `409 VERSION_CONFLICT`.

## Rich text and media rules

Stored HTML is parsed and sanitized with Symfony HTML Sanitizer and a constrained HTML5 parser. Supported content includes paragraphs, headings, basic text formatting, lists, blockquotes, pre/code, links, images, tables, alignment, video blocks, figures, and YouTube embeds. Unsafe tags/attributes, SVG, event handlers, unsupported CSS, unsafe URL schemes, and unsupported YouTube parameters are rejected with 422; submitted HTML is never echoed in an error.

The content cap is 1 MiB, article JSON cap 2 MiB, 20,000 DOM nodes, nesting depth 32, at most 200 table rows, 50 columns, 5,000 cells, dimensions up to 4,096, and spans up to 50. Media URLs may be managed `/media/{id}/{canonical-name}` references, HTTPS on an exact configured trusted media host, or an existing root-relative `/images/...` asset. Managed media must be accessible and ready; no URL is fetched. Public media exposure depends on an attached published consumer. The backend stores canonical sanitized HTML and trusted references; frontend preparation, cropping, metadata removal, and malware scanning remain frontend/deployment responsibilities described by the shared-media handoff.

Fallback summary/meta/cover values are display derivations and are never written into the explicit fields. The public content serializer and actual website cache invalidation are not implemented here.

## Publication, slug history, and outbox

Drafts and Scheduled records are private. The internal `ResolvePublicArticle` service returns only a Published article; a previously public slug resolves to a direct 301 target while the owning article remains Published. It is an internal rule, not an HTTP route; the website must register its own resolver and redirect behavior.

Scheduled status and a durable generation-tagged task commit together. Laravel Scheduler invokes `blog:publish-due` every 10 seconds, with overlap protection; the worker reconciles missing due tasks, locks article/task state, retries failures, preserves the first `published_at`, and ignores canceled, deleted, or stale-generation work. Production must run Laravel's scheduler every minute (for example, `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`); Laravel keeps that invocation alive for the rest of the minute to run sub-minute tasks. The design target is publication within 60 seconds of the due time under a healthy scheduler/database. This repository does not prove the production scheduler is running.

Publicly effective article/category/author changes create durable rows in `blog_outbox`. No public cache/search/sitemap consumer is registered. Consequently these rows remain pending and no cache purge or redirect is reported as successful. Use `php artisan blog:status` to inspect pending event count/age, overdue schedules, and retrying publication tasks. `php artisan blog:prune` removes reports/idempotency records after 24 hours and audit events older than 90 days; schedule runs hourly. Physical storage errors are retried on the next prune run.

## DOC and DOCX import

### Endpoint and idempotency

`POST /content-imports` accepts exactly multipart parts `content_type=blog` or `content_type=news` and one `file`. This handoff describes the Blog target; see [the news handoff](4-panel-news-api.md) for the News-specific v2 template and Persian warning behavior. Supply a UUID `Idempotency-Key`; a matching administrator/key/module/file checksum replays the original result for 24 hours. Same key with different bytes or a different module returns 409 `IDEMPOTENCY_CONFLICT`. A concurrent or live lease returns 409 `IMPORT_IN_PROGRESS` and `Retry-After`. A deleted imported record is never resurrected; replay returns 404 `IMPORT_RESULT_NOT_FOUND`. New processing is limited to 5 attempts per administrator per 15 minutes and one active conversion per administrator.

Files must be `.doc` or `.docx`, case-insensitively, and 1 byte–20 MiB. MIME is advisory. The server checks actual DOCX/legacy OLE signatures and bounds ZIP entries to 2,000 and expanded bytes to 128 MiB. It accepts up to 50 embedded raster images and 150 million total pixels. Images are normalized to PNG (static) or WebP (animated), then use the existing shared-media quota/storage and transaction-safe references. A successful request returns 201 with `{ "data": { "content_type": "blog", "record": <full article detail> }, "warnings": [], "request_id": "..." }`; imported records are always new Drafts.

The current template is [blog-import-v2.docx](../public/tahlilpanel/templates/blog-import-v2.docx), served at `/tahlilpanel/templates/blog-import-v2.docx`. Its Persian sections appear in this order: **عنوان**, **عنوان انگلیسی**, **توضیحات**, **تصویر**, **محتوا**, **توضیحات متا**. The category and reading-time sections may optionally follow. Put the English title in the second section; it becomes the article `slug`. Put the cover image in the image section; the first image there is used as the cover. Images in the content section remain inline. Empty description, cover, content, metadata, category, or reading time does not prevent Draft creation. Invalid optional values are left empty and reported in a Persian `warnings` array. The parser also continues to accept the previous v1 template for files already in circulation. It does not infer categories, choose a random cover, or use request-supplied author/status data.

Blog and News import validation return 422 `IMPORT_VALIDATION_FAILED` with stable machine `code` and `field` keys, ordered Persian `issues` (`label`, `location`, and `message`), and Persian `fields` messages. Only the title and English title are content-validation errors. Damaged, encrypted, unsafe, oversized, or structurally unsupported files can still fail the file/security checks. New Blog and News errors are JSON-only: they contain no report, filename, or display timestamp. The shared private report endpoint remains only for report records persisted by older News imports. Do not show or request a report after a new import error.

Successful article creation and Word import include `warnings`, which may be empty. The imported record is always a Draft. The initial frontend timeout should be 120 seconds. Do not auto-retry writes. On timeout retain the selected file and idempotency key, explain that closing the browser does not cancel server work, and retry the exact file/key to recover the committed result. For a corrected file, issue a new key.

### Ubuntu 24.04 server prerequisites

DOCX is read directly by PHP. Install/enable PHP extensions `zip`, `xml`/DOM, `mbstring`, `fileinfo`, and `gd`; the image normalizer also needs FFmpeg. DOCX import does not require LibreOffice or Python. For example, on Ubuntu 24.04 with the matching PHP 8.3 packages:

```sh
sudo apt update
sudo apt install php8.3-zip php8.3-xml php8.3-mbstring php8.3-gd ffmpeg
```

These PHP package names are from Ubuntu Noble's PHP 8.3 package set ([Ubuntu package listing](https://packages.ubuntu.com/source/noble/php8.3)).

Legacy DOC conversion additionally requires LibreOffice Writer, Python 3 with a virtual environment containing the pinned `oletools` dependency, and Bubblewrap. Install the operating-system packages and create an isolated venv owned by deployment, not writable by the web worker:

```sh
sudo apt install libreoffice-writer python3 python3-venv bubblewrap
sudo python3 -m venv /opt/tahlil-word-import-venv
sudo /opt/tahlil-word-import-venv/bin/pip install -r /path/to/release/requirements-word-import.txt
```

Set `WORD_IMPORT_PYTHON=/opt/tahlil-word-import-venv/bin/python`, `LIBREOFFICE_BINARY=/usr/bin/soffice`, and `BWRAP_BINARY=/usr/bin/bwrap` in the application environment, then reload PHP-FPM/workers. The runtime uses read-only system/runtime binds, a private temporary work directory, no network, a 45-second DOC conversion ceiling, and a 90-second total processing ceiling. Verify the complete conversion under the actual PHP-FPM service user. Ubuntu 24.04 restricts unprivileged user namespaces through AppArmor, so if `bwrap` cannot create namespaces, review the host policy and apply a narrowly scoped profile for the service; do not disable AppArmor or namespace restrictions globally ([Ubuntu 24.04 release notes](https://documentation.ubuntu.com/release-notes/24.04/)). Missing isolation causes a controlled error, never an unsandboxed conversion.

DOC conversion runs only when all prerequisites resolve: LibreOffice (`soffice`), Python with `oletools==0.60.2`, the checked-in OLE inspection helper, and OS isolation. macOS uses `sandbox-exec`; Linux uses `bwrap`. Install prerequisites in the deployment image and verify the policy there.

DOCX is parsed directly with bounded ZIP/XML operations, `LIBXML_NONET`, no external entities, and external package relationships rejected. The converter rejects active/OLE content, encrypted or malformed files, unsupported image/structure cases, and external image fetches. This implementation does not run antivirus, sanitize original EXIF before parsing, reproduce every Word typography feature, or implement frontend error rendering.

## Errors and frontend integration

Common errors include 401 `UNAUTHENTICATED`, 403 `CSRF_INVALID`, 404 `ARTICLE_NOT_FOUND`/`CATEGORY_NOT_FOUND`, 409 `SLUG_TAKEN`/`CATEGORY_NAME_TAKEN`/`VERSION_CONFLICT`, 422 `VALIDATION_FAILED`, 413 `PAYLOAD_TOO_LARGE`, 415 `UNSUPPORTED_MEDIA_TYPE`, 429 `RATE_LIMITED` with `Retry-After`, and controlled 503 `SERVICE_UNAVAILABLE`. Import adds `IMPORT_VALIDATION_FAILED`, `IDEMPOTENCY_CONFLICT`, `IMPORT_IN_PROGRESS`, `IMPORT_RESULT_NOT_FOUND`, and `IMPORT_REPORT_NOT_FOUND`.

Keep the editor state intact on 409/422 and refresh the relevant record before resolving a version conflict. Do not assign client-side IDs to persisted articles. After import success, prepend/upsert by returned ID, clear search, set page 1, and remain on the list. On session expiry, preserve unsaved editor data locally only according to frontend privacy policy; never log HTML, documents, cookies, CSRF tokens, or report bytes.

```js
const api = 'https://service.tahlilex.com/api/v1/admin';
const csrfResponse = await fetch(`${api}/auth/csrf`, { credentials: 'include' });
const csrf = (await csrfResponse.json()).data.csrf_token;

const form = new FormData();
form.append('content_type', 'blog');
form.append('file', selectedFile);
const result = await fetch(`${api}/content-imports`, {
  method: 'POST',
  credentials: 'include',
  headers: { 'X-CSRF-Token': csrf, 'Idempotency-Key': attemptUuid, Accept: 'application/json' },
  body: form,
  signal: AbortSignal.timeout(120_000),
});
```

The browser must not set multipart `Content-Type` manually. Same-site deployment is recommended for cookie transport. If a frontend server proxies requests, forward the panel cookie and CSRF header to the backend and relay `Set-Cookie` with the backend host/path semantics intentionally; never expose the cookie to JavaScript.

## Operational verification and actual coverage

- Automated feature tests cover article/category CRUD, normalized search/name handling, versions/no-op, optional-field warnings, scheduled publication, internal slug resolution, Persian v2 and legacy v1 DOCX parsing/import for Blog and News, success replay, Persian title/slug validation, JSON-only errors and warnings for both modules, idempotency conflicts, and a controlled 503 when legacy DOC conversion prerequisites are unavailable.
- The full PHPUnit suite passed locally on 2026-10-25: 180 tests, 169 passed, and 11 skipped. PHPUnit uses isolated SQLite `:memory:`; the Blog/News MariaDB multi-process race tests were skipped because this run did not use a MariaDB test connection. The production database version, scheduler timing, shared filesystem behavior, proxy/cookie transport, and browser downloads remain deployment checks.
- The Persian Blog and News v2 templates and the News sample document were rendered and visually inspected; legacy v1 parsing remains covered by feature tests. A separate Microsoft Word client compatibility test was not run.
- Migrations and seeds were not run against the development or production database. No remote service was contacted.
- Symfony HTML Sanitizer is installed at 7.4.20. Redocly validated the Blog and News OpenAPI documents successfully; it reported only three warnings for intentionally local `localhost` development URLs and the News document's omitted license metadata. Postman collections parsed successfully.
