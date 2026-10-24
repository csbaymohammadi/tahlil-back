# Panel Exchanges API

This document describes the backend contract for the Exchanges list, editor, publication switch, category dialog, and the internal read services used by the website adapter. It is the handoff for the frontend team.

The exchange module owns its records, categories, slug history, media references, audit events, and public-change outbox. IDs and slugs may equal values in blog, news, or other modules without linking those records. The signed-in administrator is the author on create; all active administrators have the same management access.

## Environments and session

Use the shared panel session and CSRF contract from [1-panel-login-api.md](./1-panel-login-api.md). The API base URLs are:

| Environment | Base URL |
| --- | --- |
| Local development | `http://localhost:8000/api/v1/admin` |
| Staging | `https://<staging-host>/api/v1/admin` |
| Production | `https://service.tahlilex.com/api/v1/admin` |

Authenticated requests send the HttpOnly `tahlil-panel-session-v2` cookie. Send `X-CSRF-Token` on every mutation. The backend accepts cross-origin requests from any origin, while cookie credentials, CSRF token validation, and the shared management guard remain required. Do not treat a trusted Origin as a replacement for CSRF. The CORS response exposes `X-Request-ID`, `Retry-After`, `Location`, and range headers.

All JSON uses `snake_case`. Timestamps are UTC ISO 8601. Every response includes `request_id` and `X-Request-ID`; administrative responses use `Cache-Control: no-store`. JSON requests must be `application/json`, the root must be a JSON object, and unknown mutation fields are rejected. Exchange JSON request bodies are limited to 2 MiB; category operations are limited to 16 KiB. DELETE requests have no body.

```js
const apiBase = 'https://service.tahlilex.com/api/v1/admin';

async function panelRequest(path, { method = 'GET', body, csrfToken } = {}) {
  const response = await fetch(`${apiBase}${path}`, {
    method,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(method === 'GET' ? {} : { 'X-CSRF-Token': csrfToken }),
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });

  if (response.status === 204) return null;
  const payload = await response.json();
  if (!response.ok) {
    const error = new Error(payload.error?.message ?? 'Request failed');
    error.status = response.status;
    error.code = payload.error?.code;
    error.fields = payload.error?.fields ?? {};
    error.requestId = payload.request_id;
    throw error;
  }
  return payload;
}
```

For server-rendered frontend routes, forward the panel cookie to this backend and keep the CSRF token in the frontend session/state. Do not expose the session token to JavaScript or log it. A reverse proxy must forward `Cookie`, `Origin`, `X-CSRF-Token`, `Content-Type`, `X-Request-ID` response, and the `Location` response. Treat network timeouts as an unknown outcome: GET the resource before retrying a mutation; use `expected_version` to avoid overwriting a change. A retry with the old version returns `409 VERSION_CONFLICT` if the first attempt committed.

## Routes

All paths below are relative to the base URL above.

| Method | Path | Purpose | Success |
| --- | --- | --- | --- |
| GET | `/exchanges/items?page=1&per_page=10&q=` | Search and page all live admin records, draft and published | `200` |
| GET | `/exchanges/items/{id}` | Load an editor-ready detail | `200` |
| POST | `/exchanges/items` | Create a draft or publish immediately | `201` |
| PATCH | `/exchanges/items/{id}` | Update fields using optimistic versioning | `200` |
| DELETE | `/exchanges/items/{id}?expected_version={version}` | Soft-delete an exchange | `204` |
| GET | `/exchanges/categories?q=` | Search all live categories, no pagination | `200` |
| GET | `/exchanges/categories/{id}` | Load a category for confirmation | `200` |
| POST | `/exchanges/categories` | Create a category | `201` |
| DELETE | `/exchanges/categories/{id}?expected_version={version}` | Delete and uncategorize live exchanges | `200` |
| POST | `/exchanges/categories/{id}/merge` | Move live exchanges and delete the source category | `200` |

IDs are positive integers within JavaScript's safe integer range. A path ID with invalid syntax returns `422`; a missing or soft-deleted record returns the appropriate `404` after authentication.

## Exchange list and detail

`GET /exchanges/items` accepts `page` (positive integer, default `1`), `per_page` (`10`, `20`, or `50`, default `10`), and `q` (up to 200 characters). Search matches only the normalized title and English name. It trims, normalizes Arabic Yeh/Kaf to Persian Yeh/Kaf, replaces zero-width non-joiners with spaces, collapses whitespace, and case-folds. Results sort by `created_at DESC, id DESC`. Empty pages are valid and return accurate totals.

List rows include `id`, `title`, `english_name`, `slug`, `description`, `logo`, `website_url`, `founded_year`, `platform_type`, `markets`, `access`, `support`, `status`, `author`, `category_id`, `category`, `created_at`, `updated_at`, `published_at`, `public_path`, and `version`. `content`, `meta_description`, and `specifications` are returned only by detail and mutation responses. `public_path` is null for drafts, including a previously published record that is now a draft.

```json
{
  "data": [],
  "meta": { "page": 1, "per_page": 10, "total": 0, "total_pages": 0 },
  "request_id": "req_example"
}
```

## Exchange create and edit form

Create requires `title`, `english_name`, `slug`, `logo`, `website_url`, `category_id`, `content`, and `status`. `category_id` must be present but can be `null` for Uncategorized. Optional text fields default to `""`. `specifications` is optional and defaults to an object containing all 24 supported keys as empty strings. The response is canonical detail HTML after server-side sanitization. The creator and editor are resolved from the authenticated session.

```json
{
  "title": "Example Exchange",
  "english_name": "Example Exchange",
  "slug": "example-exchange",
  "description": "An overview of this platform and its services.",
  "logo": "/images/exchanges/example.png",
  "website_url": "https://exchange.example/",
  "founded_year": "2022",
  "platform_type": "Centralized exchange",
  "markets": "Spot and futures",
  "access": "Web and mobile application",
  "support": "Online support",
  "specifications": {
    "established_at": "2022",
    "spot_trading": "yes",
    "futures_trading": "yes",
    "dedicated_app": "yes"
  },
  "category_id": 1,
  "content": "<h2>Overview</h2><p>A review of this exchange.</p>",
  "meta_description": "An overview of this exchange and its services.",
  "status": "draft"
}
```

| Field | Type and rules | Create default / PATCH behavior |
| --- | --- | --- |
| `title` | String, trimmed, 1–200 Unicode characters | Required on create; omission preserves; empty/null rejected |
| `english_name` | String, trimmed, 1–200 characters | Required on create; omission preserves; empty/null rejected |
| `slug` | String, 1–200; `^[a-z0-9]+(?:-[a-z0-9]+)*$` | Required on create; omission preserves; no automatic rewrite |
| `description` | Plain text, max 2,000 characters | `""`; omission preserves; `""` clears; null rejected |
| `logo` | Required safe approved location, max 2,048 | Required; omission preserves; empty/null rejected |
| `website_url` | Absolute HTTP/HTTPS URL with host, no credentials, max 2,048 | Required; omission preserves; empty/null rejected |
| `founded_year` | String `""` or exactly four ASCII digits | `""`; omission preserves; `""` clears; null rejected |
| `platform_type`, `markets`, `access`, `support` | Plain strings, max 1,500 each | `""`; omission preserves; `""` clears; null rejected |
| `specifications` | JSON object; see fixed keys below | All empty on create; omission preserves; PATCH merges supplied keys; `{}` is a no-op |
| `category_id` | Integer ID or `null`; numeric strings rejected | Required on create; omission preserves; `null` uncategorizes |
| `content` | Rich HTML string, max 1 MiB UTF-8 | Required; omission preserves on PATCH; must remain meaningful after sanitization |
| `meta_description` | Plain string, max 500 | `""`; omission preserves; `""` clears; null rejected |
| `status` | `draft` or `published` | Required on create; omission preserves |

Create response: `201`, canonical detail, `version: 1`, and `Location: /api/v1/admin/exchanges/items/{id}`. A `published` create gets its first `published_at` immediately after commit. PATCH requires `expected_version` and at least one editable field. Actual changes advance `version` and `updated_at` once. A no-op keeps both unchanged and creates no duplicate public-change event. A stale version returns `409 VERSION_CONFLICT` without partial changes.

```json
{
  "expected_version": 4,
  "support": "Updated support summary",
  "category_id": null,
  "specifications": {
    "around_the_clock_support": "no",
    "withdrawal_conditions": "See the official withdrawal conditions."
  },
  "status": "published"
}
```

All 24 specification keys are fixed and returned in detail, even if blank:

| Group | Keys |
| --- | --- |
| General information | `established_at`, `head_office_address`, `physical_branch_count`, `years_active`, `minimum_purchase`, `maximum_purchase`, `minimum_sale`, `maximum_sale` |
| Features | `in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `spot_trading`, `futures_trading` |
| Trading conditions | `identity_documents`, `dedicated_app`, `deposit_methods`, `withdrawal_conditions` |
| Fees | `trading_fee`, `registration_fee` |
| Licenses | `inamad`, `virtual_business_union`, `computer_trade_organization` |

Every value is a trimmed string, max 1,500 Unicode characters. Status keys (`in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `spot_trading`, `futures_trading`, `dedicated_app`, `inamad`, `virtual_business_union`, `computer_trade_organization`) accept only `""`, `"yes"`, `"no"`, or `"not-applicable"`. Free-text dates, counts, amounts, fee units, and multiline addresses remain strings. Reject null, booleans, numbers, arrays, unknown keys, and extra platform-specific keys. `specifications` itself must be a JSON object; `[]` is invalid while `{}` is valid and is a no-op.

## Categories

`GET /exchanges/categories` returns every live category, optionally filtered by normalized `q` (max 200) and sorted by normalized name then ID. There is no pagination. `exchange_count` counts non-deleted exchanges of either status. The frontend should add its own “Uncategorized” selector option with a null ID.

Create accepts only `{ "name": "International Exchanges" }`, with a trimmed 1–80 character display name. Uniqueness normalizes Arabic/Persian Yeh/Kaf, replaces ZWNJ with spaces, collapses whitespace, and case-folds. Deleted names and keys remain reserved. The API creates a key of `category-{id}`; clients cannot set a key. Duplicate normalized names return `409 CATEGORY_NAME_TAKEN`. Creation returns `201`, the resource, and a `Location` header.

The repeatable `ExchangeCategorySeeder` provides `International Exchanges` → `international` and `Iranian Exchanges` → `iranian`. It does not restore deleted categories or force numeric IDs. A conflicting key/name stops with a clear error.

Delete requires `expected_version` in the query. It soft-deletes the category and atomically sets the category to null on every active exchange assigned to it, Draft or Published. Each affected exchange's `version` and `updated_at` advance. Response:

```json
{ "data": { "deleted_category_id": 4, "affected_exchange_count": 3 }, "request_id": "req_example" }
```

Merge uses the source ID in the path and requires both versions in its JSON object. The source and target must be distinct live categories. All active source exchanges move to the target, then the source is soft-deleted. Counts are computed in the transaction; an empty source returns zero.

```json
{
  "expected_version": 2,
  "target_category_id": 7,
  "target_expected_version": 1
}
```

Successful merge returns `source_category_id`, `target_category_id`, and `moved_exchange_count`. Equality is `422` on `target_category_id`; missing/deleted categories are `404 CATEGORY_NOT_FOUND`; stale versions are `409 VERSION_CONFLICT`.

## Delete and recovery behavior

`DELETE /exchanges/items/{id}?expected_version=4` soft-deletes the record, returns `204`, and preserves its ID, author, media references, audit history, and all slug reservations. A repeat delete or subsequent admin detail lookup returns `404 EXCHANGE_NOT_FOUND`. The frontend should refetch the current list page after deletion and move to the nearest non-empty page if it deleted the last row.

For network timeouts, first refetch the item or list. Never automatically replay a create because its outcome is unknown; query by its chosen slug via the admin list search. For a timeout after PATCH, fetch detail and compare version/fields before deciding whether to retry. If the original request committed, retrying the old `expected_version` receives `409`.

## Slugs and publication

Current, historical, and deleted slugs stay reserved in the exchange namespace. The same exchange can reclaim its own old slug; another exchange cannot. Slugs are independent from blog/news slugs. `published_at` records first successful publication and survives later draft/publish cycles. Drafts have no `public_path` and are never returned from public resolvers.

The internal resolver returns the current published record for its current slug. A formerly public slug resolves directly to the current `/exchanges/{slug}` path while its exchange remains published; it yields not-found when unpublished or deleted. The resolver is a PHP service, not an HTTP route and does not itself emit a 301 response. No guest preview is available.

## HTML and media

The shared sanitizer uses an HTML5-capable parser and a maintained sanitizer. Exchanges use strict link rules: links must be HTTP/HTTPS URLs without credentials or a same-document fragment. External `_blank` links are given `rel="noopener noreferrer"`. The sanitizer preserves supported paragraph/heading, emphasis, list, blockquote, code, table, image, video, YouTube embed, and known structural wrapper elements. Alignment is limited to `text-align: left|right|center|justify` on supported text/table cells. `dir`, safe dimensions, table spans, ordered-list starts, and code-language metadata have bounded rules; classes, arbitrary IDs, event handlers, forms, scripts, SVG, arbitrary style, and unsupported active elements are rejected with `422` on `content`.

Limits: HTML 1 MiB; 20,000 element nodes; nesting depth 32; up to 200 rows per table, 50 columns per row, and 5,000 cells total; image/video dimensions 1–4,096; row/column spans at most 50. YouTube embeds must use an exact approved YouTube embed host and `/embed/{11-character-id}`; only `start`, `end`, `controls`, and `rel` parameters are accepted, and the source is normalized to `youtube-nocookie.com`.

Logo locations can be a managed canonical image path (`/media/{id}/{canonical-name}`), HTTPS on `EXCHANGE_TRUSTED_MEDIA_HOSTS` (default `service.tahlilex.com`), or safe root-relative assets under `/images/` and `/tahlilpanel/images/`. Content images/videos use the same configured HTTPS host policy, canonical managed paths, or approved static prefixes. No submitted URL is fetched. Managed media ownership, readiness, kind, canonical name, and visibility are checked server-side and references attach atomically under consumer type `exchange`. Upload/crop operations remain on the shared media API.

An exchange becomes a public media consumer only while Published and not soft-deleted. Static/root and trusted HTTPS references do not create backend media references. The frontend should render a safe local fallback if a physical logo asset is unavailable; the backend does not substitute another exchange's logo.

## Internal public-read services for the frontend adapter

No public HTTP endpoint is added in this delivery. The following services are available inside Laravel for a later website adapter:

- `ResolveExchangeArchive::resolve(page: 1, categoryKey: null, search: '', sort: 'default')` returns `items`, `meta`, and `category_options`. It returns nine published exchanges per page. Search covers title, English name, description, and category label; it normalizes Persian/Arabic characters, removes whitespace and zero-width characters, and ignores case. Category counts are computed for matching published results before the selected category is applied. An invalid, deleted, repeated, or empty category key selects All. Sort is `default` (`created_at DESC, id DESC`) or `alphabetical` (ICU `fa_IR` key then ID).
- `ResolveExchangePublicItem::resolve(slug)` returns `exchange`, `redirect` with the direct current path, or `not_found`. The future HTTP adapter should map `redirect` to a permanent `301` and not expose drafts/deleted records.
- `ResolveFeaturedExchanges::resolve(limit, excludeId)` honors distinct configured IDs in their configured order, filters to published records, and uses newest published records when no IDs are configured. `related(current, limit)` excludes the current ID and uses the deterministic newest order. Short/empty results are returned as-is with no duplicates or cross-domain fallback.
- `ExchangePublicProjection` computes card/detail fields. Empty description falls back to an excerpt, empty meta description falls back to safe text, and `specifications.established_at` can fall back to `founded_year` in the separate `display` projection. These values do not overwrite saved fields. With no ratings/comments module, `rating_average` is null, and `rating_count`/`comment_count` are zero.

Sorting requires PHP `intl` and ICU locale `fa_IR`. `EXCHANGE_FEATURED_IDS` is a comma-separated ordered list of IDs. `EXCHANGE_TRUSTED_MEDIA_HOSTS` is a comma-separated host allowlist. Deployment must install/enable `intl` and configure those values when required.

## Audit, Outbox, commands, and cache status

Audit rows record actor, target, event, request ID, changed field names/status/slug transitions, and actual category affected counts. They do not store passwords, cookies, full request bodies, or unsanitized HTML. `exchanges:prune` removes audit rows older than 90 days and delivered outbox events older than 90 days. The scheduler runs this command hourly.

Publicly relevant writes add durable rows to `exchange_outbox`. The production cache consumer and public cache integration are not configured; events therefore remain pending. A successful database write does not claim that the public site cache was cleared. `php artisan exchanges:status` reports pending count/age, cache-consumer state, and whether `intl`/`fa_IR` is available. `php artisan schedule:work` or the deployment's Laravel scheduler is required for hourly pruning. There is no exchange publish-due worker because publication is immediate.

Cache/revalidation triggers represented by the outbox include publication, published edits, unpublication, deletion, slug change, and category create/delete/merge. The future consumer must invalidate the review path, archive pages and category counts, homepage/menu/footer selections, search, related items, and dependent cards. Until a consumer records delivery, no cache success should be surfaced to users.

## Errors

Errors use `{ "error": { "code", "message", "fields" }, "request_id" }`. Codes and field paths are stable; messages may be localized. Nested paths are returned as dotted keys, for example `error.fields["specifications.spot_trading"]`.

| Status / code | Meaning and frontend action |
| --- | --- |
| `401 UNAUTHENTICATED` | Refresh/restart login flow; do not retry the mutation blindly |
| `403 CSRF_INVALID` | Refresh CSRF token, then let the user retry |
| `403 FORBIDDEN` | Management guard denied access |
| `400 INVALID_JSON` | Correct request serialization |
| `404 EXCHANGE_NOT_FOUND` / `CATEGORY_NOT_FOUND` | Refresh lists and close stale editor/dialog |
| `409 SLUG_TAKEN` | Show field error on `slug`; choose another slug |
| `409 CATEGORY_NAME_TAKEN` | Show field error on `name` |
| `409 VERSION_CONFLICT` | Fetch latest detail/category and ask user to reconcile edits |
| `413 PAYLOAD_TOO_LARGE` | Reduce request size |
| `415 UNSUPPORTED_MEDIA_TYPE` | Send a JSON object with `application/json` |
| `422 VALIDATION_FAILED` | Map each `error.fields` entry to its field; content errors map to editor |
| `429 RATE_LIMITED` | Respect `Retry-After` |
| `503 SERVICE_UNAVAILABLE` | Retry read or mutation only after resolving its commit outcome |
| `500 INTERNAL_ERROR` | Show `request_id` for support; do not display raw server details |

Example:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "specifications.spot_trading": ["Select a supported status value."],
      "website_url": ["Enter an absolute HTTP or HTTPS URL without credentials."]
    }
  },
  "request_id": "req_example"
}
```

## Frontend field mapping and workflow

Keep a single domain transformer between frontend camelCase form state and the snake_case API. `specifications` keys are the literal snake_case names in the contract. Initialize edit forms from `GET /exchanges/items/{id}` because list rows intentionally omit large fields. Save with PATCH and its latest `version`; replace local state with the response, including canonicalized HTML and incremented version. The category selector uses `GET /exchanges/categories`, includes a local null/Uncategorized option, and stores category IDs as JSON numbers.

For category deletion, fetch detail before showing the versioned confirmation; after success use `affected_exchange_count` for the message and refresh category counts. For transfer, exclude the source in the UI but retain server validation; submit both versions and use `moved_exchange_count` in the result. `Location` is relative to the API origin. The “view” action should use `/exchanges/{slug}` only for published records; public website routing remains outside this API.

```sh
curl --include \
  -H 'Accept: application/json' \
  -H 'Cookie: tahlil-panel-session-v2=<session-token>' \
  'https://service.tahlilex.com/api/v1/admin/exchanges/items?page=1&per_page=10&q=example'

curl --include -X POST \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Cookie: tahlil-panel-session-v2=<session-token>' \
  -H 'X-CSRF-Token: <csrf-token>' \
  --data '{"title":"Example Exchange","english_name":"Example Exchange","slug":"example-exchange","logo":"/images/exchanges/example.png","website_url":"https://exchange.example/","category_id":null,"content":"<p>Review text.</p>","status":"draft"}' \
  'https://service.tahlilex.com/api/v1/admin/exchanges/items'
```

## Delivery and verification

Automated coverage is in `tests/Feature/ExchangesManagementTest.php`. Real multi-process write races are in `tests/Feature/ExchangesConcurrencyTest.php`; they require `pcntl` and an explicitly disposable MariaDB schema passed as `EXCHANGES_CONCURRENCY_DATABASE`. The normal test configuration remains SQLite `:memory:`. The database migration and category seed are intentionally not run against the development database or production server.

Verification completed for this delivery: the exchange feature file passed (8 tests, 118 assertions); the full PHPUnit suite passed (168 tests: 157 passed and 11 skipped); the three dedicated multi-process tests passed (35 assertions) on a temporary MariaDB 11.7.2 schema, which was dropped afterward. They cover slug/category uniqueness, stale edits, and assignment races with category deletion/merge. `composer validate --no-check-publish`, `composer audit`, Pint, OpenAPI lint, and JSON/YAML parsing passed. Redocly reports one informational warning because the documented local development URL intentionally uses `localhost`.

Acceptance results are reported from the actual verification run. This delivery does not provide the public HTTP API/pages, real redirect response, public-cache consumer, comment/rating subsystem, or browser/frontend acceptance tests. Cache delivery is therefore pending; no such completion is reported. Root static assets and trusted HTTPS media references are accepted without remote fetch; physical file existence is not verified for those external/static paths. Managed media is validated and attached through the existing private media service.
