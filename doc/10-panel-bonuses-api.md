# Bonuses and Guest Ratings API

Backend handoff for `10-panel-bonuses.md`. The delivery includes the private management API, live guest rating API, internal public-read resolvers, persistence, audit and durable Outbox. UI pages, full public archive/detail HTTP APIs, moderation, campaign redemption, payments and the external frontend cache consumer are separate work. See [OpenAPI 3.1](10-panel-bonuses.openapi.yaml), [panel authentication](1-panel-login-api.md), and [shared media](16-shared-media-api.md).

## 1. API bases and transport

| Environment | Admin base | Public rating base |
| --- | --- | --- |
| Development | `http://localhost:8000/api/v1/admin` | `http://localhost:8000/api/v1` |
| Staging | `https://<staging-host>/api/v1/admin` | `https://<staging-host>/api/v1` |
| Production | `https://<production-host>/api/v1/admin` | `https://<production-host>/api/v1` |

The development origin was resolved through Laravel Boost. Staging and production hosts are deployment placeholders, not verified services. Guest cookies are always Secure: use HTTPS for browser integration when the local browser does not retain/send the cookie on a localhost HTTP origin. No production cookie restriction is relaxed for local development.

Use `Accept: application/json`; mutations with bodies require `Content-Type: application/json`. JSON uses snake_case. IDs/versions are positive integers at most `9007199254740991`; strict JSON numeric fields reject strings, booleans and fractions. Dates are ISO 8601 UTC. GET and DELETE accept no body; unsupported query keys are rejected. Body limits: bonus POST/PATCH 2 MiB, category operations 16 KiB, rating PUT 4 KiB. Content independently permits at most 1 MiB of decoded UTF-8 HTML.

Success JSON contains `data` and `request_id`. Lists additionally contain `meta`. Errors contain `error.code`, `error.message`, `error.fields` (an object of field-path to string arrays), and `request_id`. Every administrative/personalized rating response includes `X-Request-ID`, `Cache-Control: no-store`, and `Vary: Cookie`; ratings also vary on Origin. HTTP 204 has no JSON/body. Messages currently use English; localize by stable codes/field paths.

## 2. Administrative API

All paths in this section are relative to the admin base. Require the shared `tahlil-panel-session-v2` cookie and existing active panel-session management boundary. Mutations require the panel `X-CSRF-Token`. No permission catalog, role matrix, or new login flow is introduced. Shared CORS accepts every Origin; panel and guest rating CSRF contexts remain independent. Browser fetches must include credentials. Default management mutation limit is 300 requests per panel user and bonus domain per 900 seconds.

| Method/path | Result |
| --- | --- |
| `GET /bonuses/items?page=1&per_page=10&q=` | Both live editorial statuses, newest creation first. |
| `GET /bonuses/items/{id}` | Full private detail and current ratings. |
| `POST /bonuses/items` | 201, full canonical detail, version 1, Location header. |
| `PATCH /bonuses/items/{id}` | Canonical detail after optimistic-version update. |
| `DELETE /bonuses/items/{id}?expected_version=2` | 204 after soft deletion. |
| `GET /bonuses/categories?q=` | Complete live category array; no pagination. |
| `GET /bonuses/categories/{id}` | Fresh category/version/count. |
| `POST /bonuses/categories` | 201, name-only category and generated key. |
| `DELETE /bonuses/categories/{id}?expected_version=1` | Uncategorize source bonuses, delete category, return affected count. |
| `POST /bonuses/categories/{id}/merge` | Move live source bonuses to destination and remove source. |

### Editable fields and omission rules

| Field | Rules |
| --- | --- |
| `title` | Required on create, plain text, 1–200 Unicode characters after trimming. |
| `slug` | Required on create, 1–200 characters after trimming, `^[a-z0-9]+(?:-[a-z0-9]+)*$`; no generation/rewriting. |
| `description` | Optional plain text up to 2,000 characters; defaults to `""`. |
| `cover_image` | Optional approved image reference, at most 2,048 characters; defaults to `""` for either status. |
| `content` | Required meaningful rich HTML for either status; canonical sanitized representation returned. |
| `category_id` | Required on create; live bonus category integer or null. Empty/numeric strings invalid. |
| `reading_minutes` | Required integer 1–1,440; never automatically calculated. |
| `meta_description` | Optional plain text up to 500 characters; defaults to `""`. |
| `status` | Required on create; `draft` or `published`. |
| `expected_version` | Required on PATCH only; positive safe integer. |

PATCH requires at least one editable field. Omission preserves values; `""` clears optional strings/images; only category_id permits null. Title, slug, content, status and reading time cannot be cleared. Unknown/readonly fields fail with 422, including full-detail response objects sent back as mutations. Never submit author/date/actor fields, rating/count/revision, campaign/provider/amount/state metadata, a separate English headline, or a `published` boolean.

Every acknowledged mutation has committed its data and applicable audit/Outbox effects. Actual changes advance editorial version/updated_at; no-op saves preserve them and generate no duplicate publication events. A stale expected version returns 409 without partial state. Refetch and reconcile instead of silently changing the expected version. GET/edit supports Drafts privately; public paths for Drafts are always null. First publication sets `published_at`; later drafting, editing and republishing preserve it. Dates describe the editorial page, not campaign validity.

### Create, edit, detail and list examples

Create a Published page with no cover:

```json
{
  "title": "Campaign Overview",
  "slug": "campaign-overview",
  "description": "Read the campaign conditions.",
  "cover_image": "",
  "content": "<h2>Conditions</h2><p>Read the terms of this campaign.</p>",
  "category_id": null,
  "reading_minutes": 5,
  "meta_description": "",
  "status": "published"
}
```

Use the same payload with `status: "draft"` to create a Draft; optional strings may also be omitted. Creation returns `Location: /api/v1/admin/bonuses/items/{id}`.

Complete detail example (IDs/dates illustrative):

```json
{
  "data": {
    "id": 81,
    "title": "Campaign Overview",
    "slug": "campaign-overview",
    "description": "Read the campaign conditions.",
    "cover_image": "",
    "reading_minutes": 5,
    "status": "published",
    "version": 1,
    "rating": null,
    "rating_count": 0,
    "rating_revision": 0,
    "author": {"id": 12, "name": "Example Editor"},
    "category_id": null,
    "category": null,
    "created_at": "2026-10-26T09:00:00.000000Z",
    "updated_at": "2026-10-26T09:00:00.000000Z",
    "published_at": "2026-10-26T09:00:00.000000Z",
    "public_path": "/bonuses/campaign-overview",
    "content": "<h2>Conditions</h2><p>Read the terms of this campaign.</p>",
    "meta_description": "",
    "campaign": {"provider": null, "amount_display": "", "state": "unspecified"}
  },
  "request_id": "req_bonus_example"
}
```

List rows contain every field above except content, meta_description and campaign. They contain live numeric rating/count/revision, including null rating and zero count/revision without votes. An empty list response is:

```json
{"data":[],"meta":{"page":1,"per_page":10,"total":0,"total_pages":0},"request_id":"req_list_example"}
```

List `per_page` accepts 10/20/50 (default 10); page starts at 1. Dashboard search matches title or the author's current normalized display name, including retained authorship after soft deletion. Normalize yeh/kaf, ZWNJ to spaces, repeated whitespace and case. Renaming an author updates the bonus search index using the existing account-update convention. Body/description/provider are not dashboard search fields. Apply filtering before totals/pagination and sort `created_at DESC, id DESC`. Beyond-last pages return an empty array with the requested page and accurate totals.

Partial edit/publish example:

```json
{"expected_version":1,"description":"Updated summary.","category_id":null,"cover_image":"","status":"published"}
```

Saving Draft uses `status: "draft"`; no scheduling, autosave, unpublished revision or approval flow is added. Delete with the current version. Repeated deletion/detail access returns 404. Public visibility and shared media access follow the parent state; deleting or clearing a reference does not delete the file.

### Categories and optimistic transfer

Creation accepts only `{ "name": "Exchange Campaigns" }`. Name is plain text, 1–80 characters after trimming. Normalize uniqueness with yeh/kaf, ZWNJ-to-space, whitespace collapse and case-folding; deleted names and keys stay reserved. Initial category identities have keys `lbank`, `bitunix`, `ourbit`, with display names LBank, Bitunix, Ourbit. Repeat seeding does not recreate deleted identities. New categories get `category-{id}`. `all` is a virtual filter; names/keys never identify an exchange/provider.

Category response:

```json
{"data":{"id":4,"name":"Exchange Campaigns","key":"category-4","bonus_count":3,"version":1,"created_at":"2026-10-26T09:00:00.000000Z","updated_at":"2026-10-26T09:00:00.000000Z"},"request_id":"req_category_example"}
```

GET categories returns all live categories ordered by normalized name then ID; q up to 200 characters filters normalized names. `bonus_count` counts non-deleted Draft and Published records and does not change the category's editable version. Frontend adds local Uncategorized (`null`) and excludes source from transfer destinations.

Merge JSON:

```json
{"expected_version":1,"target_category_id":7,"target_expected_version":1}
```

Delete response:

```json
{"data":{"deleted_category_id":4,"affected_bonus_count":3},"request_id":"req_delete_category"}
```

Merge response:

```json
{"data":{"source_category_id":4,"target_category_id":7,"moved_bonus_count":3},"request_id":"req_merge_category"}
```

Source/target must be distinct live bonus categories. Missing resources return 404, invalid/equal destinations 422, stale source/target versions 409. Transfers include all non-deleted Draft/Published source records, return the actual committed count (possibly zero), and advance their editorial versions/timestamps while preserving author, creation/publication times, status, body, campaign metadata and votes. Normal deletion uncategorizes. Deleted records retain their historical assignment. A repeat operation on the removed source returns 404 and cannot move unrelated records. Assignment and category operations serialize under the shared database mutation lock and transactional row locks.

## 3. Campaign metadata and safe public projections

Each immutable bonus ID owns a separate campaign presentation row. New defaults are `{ "provider": null, "amount_display": "", "state": "unspecified" }`. There is no old dataset in this delivery and no fabricated provider, amount, state or historical votes. Preserve any explicitly populated values on editorial saves/category operations. Campaign-metadata authoring/import is a separate boundary.

The row contains nullable explicit `exchange_id`, nullable safe provider snapshot JSON, amount_display (at most 120 characters) and state. Only `ended` and `unspecified` are publicly represented. A Published page does not imply active or ended, and publication dates do not establish eligibility. No currency conversion, amount extraction, signup, redemption, reward credit or payment action is performed.

A Published/non-deleted explicitly related exchange supplies its current safe identity: exchange_id, name, english_name and logo. Hidden/deleted exchange data is not serialized. A separately retained validated provider snapshot may remain as historical campaign identity with exchange_id null; otherwise provider is null. It has no unavailable review link. Neither category label/key, matching name, mutable slug nor CSS styling can create this relationship. Unsafe/malformed stored metadata uses neutral public values without overwriting storage.

Internal services:

- `ResolvePublicBonus::handle($slug)` returns type `bonus` plus the eligible model, `redirect` with status 301/path, or `not_found`. Formerly public aliases redirect directly to the current path only while Published/non-deleted. Draft-only aliases never preview. Slugs remain reserved across deletion and may be reclaimed only by their owner.
- `ResolveBonusArchive::handle(page: 1, search: '', category: null)` returns nine cards, meta and dynamic category_options. Category accepts an integer ID or key; invalid/deleted keys fall back to All. Default order is `published_at DESC, id DESC`; `BONUS_ARCHIVE_IDS` supplies optional explicit precedence. Search normalizes yeh/kaf, removes whitespace/ZWNJ and ignores case across title, description/excerpt, safe current/historical provider names and amount text. Counts reflect matching public records before the selected category. All includes Uncategorized. No public sort parameter is required.
- `ResolveBonusSelections::latest($excludeId)` returns at most five eligible records, newest publication first, excluding the current ID. `featured()` preserves unique ordered `BONUS_FEATURED_IDS` and omits unavailable IDs. `exchangeStrip()` independently resolves ordered public `BONUS_EXCHANGE_STRIP_IDS`; duplicates for animation are a frontend concern.
- `BonusPublicProjection::card/detail($bonus)` provides stable content_type `bonus`, ID/current path, title, saved description/cover_image, display_description/display_cover_image, reading_minutes, category, campaign and live ratings. Detail adds canonical HTML, matching heading outline, meta_description fallback, keywords and updated_at. It does not require/expose an author's private account profile in public headers. comment_count remains 0 until moderation/comment modules are connected.

The archive uses database totals/pagination when q is empty. Exact normalized search over safe related provider metadata uses an eager-loaded, 200-record chunk scan with bounded retained page data; it does not issue per-row provider queries or persist guessed search/provider mappings.

Saved empty description/cover fields remain empty. Display excerpts come from safe text; image fallback is `BONUS_FALLBACK_COVER` (default empty, so show a neutral image-free state until configured). No fabricated external image is used. Meta descriptions fall back safely without modifying the explicit override; escape metadata output. Keywords are deterministic deduplicated safe provider/category labels plus configured `bonuses.keywords` (default []). These chips are not a tags editor or eligibility rules.

Only Published/non-deleted parents belong in future menus, sitemap, search, selections, cards, comments and shared caches. Authentication does not enable a public Draft preview. Typed references use `bonus` + immutable ID. Same IDs/slugs in blog/news/platform/exchange domains remain unrelated. Soft deletion preserves original author, history, campaign, votes and independently managed content.

## 4. HTML and media contract

The existing DOM/Symfony sanitizer accepts paragraphs, br, h1–h3, strong/b/em/i/u/s/strike, ul/ol/li, blockquote, pre/code, hr, links, images, tables (table/thead/tbody/tfoot/tr/th/td/colgroup/col), video, restricted YouTube iframe and bare div/figure wrappers. Safe attributes:

- a: href/title/target/rel; img: src/alt/width/height/title.
- video: src/title/controls/preload/playsinline/width/height; iframe: src/title/width/height/allowfullscreen/loading.
- p/headings: style/dir; td/th: style/colspan/rowspan/dir; col: span/width; ol: start; code: data-language. Other accepted elements/wrappers carry no attributes.

Only `text-align: left|right|center|justify` is accepted as style. Direction is ltr/rtl/auto. Arbitrary classes/IDs, event handlers, scripts/forms/SVG/objects, arbitrary CSS, iframe srcdoc and unsupported attributes fail with 422 rather than silently saving stripped active content. Harmless unsupported formatting can be canonicalized. Public heading IDs/outline are generated after storage using the shared decorator, avoiding reserved overview/FAQ/comments/specification anchors and duplicate heading IDs. Render the full returned HTML, preserving media/tables; escape ordinary identity/specification strings.

Bounds: 1 MiB HTML in decoded UTF-8 bytes; nesting 32; 20,000 elements; 5,000 cells; 200 rows/table; 50 columns/row; row/column spans 1–50; media dimensions 1–4,096; ordered-list start 1–100,000; code-language `[a-z0-9_+-]{1,32}`. The shared column-width implementation accepts 1–4 ASCII digits starting at 1 with optional percent suffix; it does not cap percentage widths at 100.

YouTube requires HTTPS on exactly youtube.com/www.youtube.com/youtube-nocookie.com/www.youtube-nocookie.com with `/embed/{11-character-id}`. Stored URLs normalize to www.youtube-nocookie.com. Query keys start/end accept 1–7 digits; controls/rel accept 0/1; no other query keys. Iframe loading is lazy/eager. Links allow safe HTTP/HTTPS or same-document fragments, no user-info or executable schemes; `_blank` links gain `rel="noopener noreferrer"`.

Images/videos allow configured `BONUS_TRUSTED_MEDIA_HOSTS` (default service.tahlilex.com) over HTTPS, managed `/media/{id}/{canonical-name}`, or approved `/images/` and `/tahlilpanel/images/` root assets. No supplied URL is fetched/imported. Managed references require accessible ready media of the correct kind. Cover images reject protocol-relative/data/blob/local paths, root traversal/encoded traversal and untrusted hosts; they cannot be SVG assets. Upload/crop/storage/physical deletion belong to shared media. Replacing/clearing a cover or deleting a bonus never deletes a physical shared file. Blank paragraphs/NBSP-only content, empty tables and invalid-source media do not satisfy meaningful content for either status.

## 5. Guest rating API and privacy

Paths below are relative to `/api/v1`, not the admin base. No panel cookie, registration or panel login is required. The only identity is a server-issued opaque voter context. It establishes one vote per context, not proof of one vote per human; clearing/replacing cookies creates a new context. IP is used only as a hashed temporary rate-limit key, not a voter identifier/fingerprint.

`GET /bonuses/{id}/rating` accepts no query/body, checks public parent eligibility and creates a context when missing/expired. Cookie `tahlil-bonus-voter-v1` contains 32 random bytes encoded as 64 hex characters; only its SHA-256 hash/reference is stored. Cookie flags: Secure, HttpOnly, SameSite=Lax, host-only, Path=/api/v1/bonuses, fixed maximum age 365 days. Existing valid reads do not slide expiry or rotate the cookie. Raw identifiers never appear in JSON, shared HTML or logs.

Response:

```json
{"data":{"bonus_id":81,"rating":null,"rating_count":0,"rating_revision":0,"my_rating":null,"csrf_token":"<opaque-public-csrf-token>","csrf_expires_at":"2026-10-26T09:15:00.000000Z"},"request_id":"req_rating_read"}
```

The token is random, authenticated/encrypted with Laravel Crypt and bound to purpose, current context hash and expiry. It lasts at most 15 minutes and never beyond context expiry. Separate valid tokens issued to desktop/mobile controls remain valid together. It is distinct from panel CSRF and never embedded in shared cached public HTML.

`PUT /bonuses/{id}/rating` requires this cookie, a current token in X-CSRF-Token, and a JSON body. Origin and Referer are optional and unrestricted. Only `{ "value": 5 }` is accepted, with value an actual integer 1–5. Choosing stars alone is not a write. Reject caller identity/bonus IDs, versions, counts, averages and all other keys.

```json
{"data":{"bonus_id":81,"my_rating":5,"rating":5,"rating_count":1,"rating_revision":1},"request_id":"req_rating_write"}
```

There is a database unique constraint on `(bonus_id, voter_context_id)`. Insert increments sum/count/revision. A changed value replaces the same vote, adjusts exact sum, preserves count and advances revision. Repeating the same value preserves count/revision and produces a no-op audit without duplicate Outbox work. Average is calculated from exact sum/count without storage rounding; zero votes return rating null. Keep the largest rating_revision in the UI to ignore responses arriving out of order.

Eligibility and writes share the parent mutation lock/transaction with editorial unpublication/deletion. A vote cannot commit after a parent becomes unavailable. Published ended campaigns remain rateable. Voting never changes editorial version, updated_at, author or published_at, and an editorial save cannot overwrite ratings from a stale form. Reads/writes return authoritative aggregates after commit. Both responsive widgets use the same choice; do not add a second vote for another widget. Initialize one shared personalized GET per page and share its cookie/token/choice state between responsive widgets; if initial overlapping requests used different new contexts, recover with one fresh GET using the final cookie before submission.

### Origin/CORS, limits and recovery

Shared CORS in config/cors.php accepts all origins, requested methods and headers. Browser preflight returns 204 before authentication or voter-context initialization. Credentialed responses reflect the supplied Origin. Origin and Referer are optional and unrestricted; BONUS_RATING_ALLOWED_ORIGINS is no longer used. Actual PUT still requires the voter cookie and its current X-CSRF-Token; routing and validation still constrain real requests.

Use `credentials: 'include'`. SameSite=Lax and host-only cookies still constrain truly cross-site deployments; use a same-site frontend or same-origin BFF/proxy where needed. Do not relax cookie settings implicitly. CSRF token/context errors return 403 CSRF_INVALID/RATING_CONTEXT_INVALID without a panel redirect; perform a fresh personalized GET then retry the user's explicit choice. Expired contexts with old votes retain those votes, while the new context has my_rating null.

Limits are global per context/IP across bonuses, configured in `config/bonuses.php`, using the existing shared `panel.rate_store` with atomic locks:

| Window | GET/context | GET/IP | PUT/context | PUT/IP |
| --- | --- | --- | --- | --- |
| 900 seconds | 120 | 300 | 30 | 120 |

Keys HMAC-hash context references and IPs. Neither identity appears in responses/logs. HTTP 429 returns Retry-After. Failed writes leave the UI selection retryable; do not invent a successful local aggregate. A network failure may have occurred after commit: refresh my_rating/aggregate before an uncertain retry. Repeating the same value remains idempotent.

## 6. Events, public routing, cache and deployment

Editorial writes and category operations atomically record audit and applicable public-change Outbox entries. Events include bonus_created/updated/published/unpublished/slug_changed/deleted, bonus_category_created/deleted and bonus_categories_merged. Rating audits distinguish bonus_rating_insert/change/noop and include no raw context. Changed ratings enqueue bonus_rating_changed with rating_revision; editorial public changes enqueue bonus_changed or bonus_deleted; category changes enqueue category change/merge events. Audits contain actor/target, UTC/request ID, changed field names, transitions or committed counts, never complete HTML/request bodies, cookies or tokens.

Internal reads query authoritative current state. Outbox rows are durable, but **no external cache consumer or automatic delivery/retry worker is configured**. Success means the write, media references, audit and applicable Outbox work committed; it does not mean frontend cache completion. Pending rows have delivered_at null and remain pending indefinitely until connected. attempts/available_at/last_error support a future consumer but do not demonstrate an actual retry. A consumer must invalidate detail/archive/homepage/selections/search/counts/metadata/sitemap on content changes and aggregate-dependent dashboard/cards/detail on rating changes, acknowledge only after completion, and record/retry failures idempotently. Observe exchange events as dependencies for explicitly related provider identities too; hiding/updating an exchange must invalidate affected bonus projections when public caching is connected.

Public archive/detail HTTP routes, redirect responses, HTML pages, sitemap/menu consumers and external caches remain separate work. New published slugs resolve immediately through the internal service without deployment; frontend runtime routes must accept new slugs dynamically rather than using a fixed build-time list. This delivery does not claim that `/bonuses/{slug}` is a working HTTP page.

```sh
php artisan bonuses:status
php artisan bonuses:prune
```

Status reports pending count/oldest age and missing consumer. Hourly pruning removes audit and delivered Outbox older than 90 days and expired unused voter contexts. It preserves all pending events, slug ownership, soft-deleted bonus/campaign rows and votes, including expired contexts that have votes. Configure Laravel's usual scheduler. No dependencies changed.

The additive migration is prepared; it was not run on the development database. Apply `php artisan migrate --force` as a deployment operation, then seed categories through the normal seeder if desired. Rollback drops module tables/data; prefer a forward fix after production data exists. All instances require the same authoritative DB, atomic shared cache and APP_KEY. No secret values or real cookies are included here.

## 7. Errors and integration examples

| HTTP | Stable codes |
| --- | --- |
| 400 | INVALID_JSON |
| 401 | UNAUTHENTICATED (admin only) |
| 403 | FORBIDDEN, CSRF_INVALID; RATING_CONTEXT_INVALID for guest writes |
| 404 | BONUS_NOT_FOUND, CATEGORY_NOT_FOUND |
| 409 | SLUG_TAKEN, CATEGORY_NAME_TAKEN, VERSION_CONFLICT |
| 422 | VALIDATION_FAILED |
| 413 / 415 | PAYLOAD_TOO_LARGE / UNSUPPORTED_MEDIA_TYPE |
| 429 | RATE_LIMITED with Retry-After |
| 500 / 503 | INTERNAL_ERROR / SERVICE_UNAVAILABLE |

```json
{"error":{"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"rating_count":["This field is not accepted."]}},"request_id":"req_error_example"}
```

Preserve form values on field errors. Missing/deleted resources are checked after the relevant access/context boundary. Invalid IDs return 422. Controlled unexpected/infrastructure failures use the common safe envelope; keep the request ID for diagnosis without logging tokens or unsafe content.

Placeholder curl commands below can be imported into Postman. Set admin_base/public_base/origin and identifiers locally; use cookie jars and secret environment variables without committing real credentials. `create.json`, `edit.json`, and `merge.json` contain the JSON examples above. Postman can also import the companion OpenAPI; send cookies and maintain separate public/panel CSRF variables.

```sh
curl '{{admin_base}}/bonuses/items?page=1&per_page=10&q=' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl '{{admin_base}}/bonuses/items/{{bonus_id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl -X POST '{{admin_base}}/bonuses/items' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @create.json
curl -X PATCH '{{admin_base}}/bonuses/items/{{bonus_id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @edit.json
curl -X DELETE '{{admin_base}}/bonuses/items/{{bonus_id}}?expected_version=2' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}'
curl '{{admin_base}}/bonuses/categories?q=' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl '{{admin_base}}/bonuses/categories/{{category_id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl -X POST '{{admin_base}}/bonuses/categories' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data '{"name":"Exchange Campaigns"}'
curl -X DELETE '{{admin_base}}/bonuses/categories/{{category_id}}?expected_version=1' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}'
curl -X POST '{{admin_base}}/bonuses/categories/{{category_id}}/merge' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @merge.json
curl -c voter-cookies.txt '{{public_base}}/bonuses/{{bonus_id}}/rating' -H 'Origin: {{origin}}'
curl -b voter-cookies.txt -X PUT '{{public_base}}/bonuses/{{bonus_id}}/rating' -H 'Origin: {{origin}}' -H 'X-CSRF-Token: {{csrf_token_from_rating_get}}' -H 'Content-Type: application/json' --data '{"value":5}'
curl -b voter-cookies.txt '{{public_base}}/bonuses/{{bonus_id}}/rating' -H 'Origin: {{origin}}'
```

## 8. Verification and agreed differences

Verified on 2026-10-26:

- `php artisan test --compact tests/Feature/BonusesManagementTest.php tests/Feature/BonusRatingTest.php tests/Feature/BonusesConcurrencyTest.php`: 27 passed, 449 assertions.
- `php artisan test --compact`: 259 tests, 248 passed, 11 skipped, 2,746 assertions. Existing skipped tests require separately configured MariaDB/MySQL concurrency databases.
- `vendor/bin/pint --dirty --format agent`: passed after formatting/import fixes.
- `git diff --check`: passed.
- OpenAPI parsed with Ruby Psych; all references resolved and all 13 route operations (12 API operations plus OPTIONS) matched Artisan. All 11 JSON examples and local document links validated.

The 12 management tests cover field rules, current-author search, canonical editor HTML/media, byte bounds, slug isolation/history, category keys/transfers, real vote/campaign preservation, soft deletion, media rollback and operational retention. The 8 guest tests cover private context/cookie/CSRF, vote replacement/idempotency/refresh, expiry recovery, strict values/transport, hidden parents, origin/CORS, limits and independent editorial data. The 7 forked tests cover atomic limits, same/different-context races, exact aggregates, concurrent editorial saves, unavailable-parent races, category races and slug/version conflicts.

Tests use isolated SQLite memory databases and separate temporary SQLite databases for forked races. They do not prove MariaDB/MySQL DDL or row-lock behavior; verify on a disposable deployment-engine database before rollout. The development database was not migrated and frontend cache delivery was not exercised.

Agreed differences from the full site contract: public read HTTP/UI/cache delivery and comments are not connected; comments count is neutral zero. No old campaign dataset was supplied, so no legacy provider/amount/state imports or synthetic vote totals are added. All APIs share unrestricted credentialed CORS; panel and guest rating CSRF contexts remain independent. No route/page availability, cache acknowledgement or staging/production verification is claimed beyond the backend services and endpoints tested here.
