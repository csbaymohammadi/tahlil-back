# Prop Firms Admin API

This handoff implements the private panel contract from `9-panel-prop-firms.md` using the existing platform-profile infrastructure. The frontend remains a separate application. Machine-readable schemas are in [OpenAPI 3.1](9-panel-prop-firms.openapi.yaml); authentication details are in [Login and Sessions](1-panel-login-api.md), and uploads in [Shared Media](16-shared-media-api.md).

## Connection and authentication

| Environment | API base URL |
| --- | --- |
| Development | `http://localhost:8000/api/v1/admin` (resolved through Laravel Boost) |
| Staging | `https://<staging-host>/api/v1/admin` (not configured or verified) |
| Production | `https://<production-host>/api/v1/admin` (not configured or verified) |

Every endpoint requires the existing active panel session cookie, `tahlil-panel-session-v2`. Mutations require `X-CSRF-Token`; obtain the token through the shared authentication flow. Send `Accept: application/json`, `Content-Type: application/json` for JSON mutation bodies, and browser credentials (`credentials: 'include'`). GET and DELETE do not accept a body. DELETE versions go in the query string. Existing shared authentication intentionally has no Origin allowlist and no role permission catalog; this module preserves that boundary and does not introduce a new access matrix. Cookie same-site rules still apply; see the authentication handoff for CORS and BFF deployment requirements.

Admin responses include `X-Request-ID`, `Cache-Control: no-store` and `Vary: Cookie`. JSON success is `{ "data": ..., "request_id": "req_<uuid>" }`; lists additionally include `meta`. A successful item DELETE is `204` with no body and its request ID in the header. Timestamps are ISO 8601 UTC. Numeric IDs and versions are positive integers no greater than `9007199254740991`; JSON numeric strings are rejected. Frontend adapters can convert snake_case to camelCase without changing server field-error paths.

Profile create/update bodies are bounded to 2 MiB; category bodies to 16 KiB. Mutation rate limits default to 300 per 900 seconds per panel user and domain. Unknown body/query keys are rejected. Do not send `kind`, authors, timestamps, ratings, `published` booleans, or fields from other platform domains.

## Endpoints and representations

Paths below are relative to the API base above.

| Method | Path | Success |
| --- | --- | --- |
| GET | `/prop-firms/items?page=1&per_page=10&q=` | `200`, list and `meta` |
| GET | `/prop-firms/items/{id}` | `200`, full private detail |
| POST | `/prop-firms/items` | `201`, full detail and `Location` |
| PATCH | `/prop-firms/items/{id}` | `200`, full canonical detail |
| DELETE | `/prop-firms/items/{id}?expected_version=2` | `204`, soft deletion |
| GET | `/prop-firms/categories?q=` | `200`, complete category array |
| GET | `/prop-firms/categories/{id}` | `200`, fresh category/version |
| POST | `/prop-firms/categories` | `201`, category and `Location` |
| DELETE | `/prop-firms/categories/{id}?expected_version=1` | `200`, affected count |
| POST | `/prop-firms/categories/{id}/merge` | `200`, moved count |

List queries accept positive `page` (default 1), `per_page` in `10,20,50` (default 10), and `q` up to 200 characters. Ordering is `created_at DESC, id DESC`. Admin search matches only title and English name. It trims, normalizes Arabic/Persian yeh/kaf, replaces ZWNJ with spaces, collapses whitespace, and case-folds. Filtering precedes counting/pagination. Beyond-last pages return an empty array with the requested page and accurate totals. Zero matches use `total_pages: 0`.

Example list response (the author, ID, timestamps and request ID are illustrative):

```json
{
  "data": [{
    "id": 71, "kind": "prop-firms", "title": "Example Prop Firm", "english_name": "Example Firm",
    "slug": "example-prop-firm", "description": "Evaluation and payout overview.", "logo": "", "website_url": "",
    "founded_year": "", "status": "published", "author": {"id": 12, "name": "Example Editor"},
    "category_id": null, "category": null,
    "created_at": "2026-10-26T09:00:00.000000Z", "updated_at": "2026-10-26T09:00:00.000000Z",
    "published_at": "2026-10-26T09:00:00.000000Z", "public_path": "/prop-firms/example-prop-firm", "version": 1
  }],
  "meta": {"page": 1, "per_page": 10, "total": 1, "total_pages": 1},
  "request_id": "req_example"
}
```

Detail/create/update responses add `content`, `meta_description`, and the complete 23-key `specifications` object to every list-item field. List rows omit these large fields. `category_id` and `category` are both null when uncategorized. Draft `public_path` is always null, even after an earlier publication. Original authorship remains readable after the author is soft-deleted.


Complete detail response example:

```json
{
  "data": {
    "id": 71,
    "kind": "prop-firms",
    "title": "Example Prop Firm",
    "english_name": "Example Firm",
    "slug": "example-prop-firm",
    "description": "Evaluation and payout overview.",
    "logo": "",
    "website_url": "",
    "founded_year": "",
    "status": "published",
    "author": {
      "id": 12,
      "name": "Example Editor"
    },
    "category_id": null,
    "category": null,
    "created_at": "2026-10-26T09:00:00.000000Z",
    "updated_at": "2026-10-26T09:00:00.000000Z",
    "published_at": "2026-10-26T09:00:00.000000Z",
    "public_path": "/prop-firms/example-prop-firm",
    "version": 1,
    "content": "<h2>Overview</h2><p>Evaluation and payout conditions.</p>",
    "meta_description": "",
    "specifications": {
      "established_at": "",
      "head_office_address": "",
      "physical_branch_count": "",
      "years_active": "",
      "minimum_deposit": "",
      "maximum_withdrawal": "",
      "in_person_visit": "",
      "address_transparency": "",
      "active_social_media": "",
      "around_the_clock_support": "",
      "support_availability": "",
      "trial_account": "yes",
      "identity_documents": "",
      "dedicated_app": "",
      "evaluation_type": "Two stages",
      "daily_loss_limit": "5% daily; resets at 00:00 UTC",
      "total_loss_limit": "10% trailing equity",
      "payout_rules": "Monthly, subject to eligibility",
      "trading_fee": "",
      "registration_fee": "",
      "inamad": "",
      "virtual_business_union": "",
      "computer_trade_organization": ""
    }
  },
  "request_id": "req_example"
}
```

### Fields and saves

| Field | Type, bounds and semantics |
| --- | --- |
| `title`, `english_name` | Required plain strings on create, 1–120 Unicode characters after trimming. Preserve capitalization/spaces; no English-name uniqueness requirement. |
| `slug` | Required string on create, 1–120 characters, `^[a-z0-9]+(?:-[a-z0-9]+)*$`; never generated or rewritten. |
| `category_id` | Required on create; live prop-firm category integer or null. Omission on PATCH preserves; null uncategorizes. Empty/numeric strings are invalid. |
| `content` | Required meaningful rich HTML on create, maximum 1 MiB in decoded UTF-8 bytes; canonical server-sanitized HTML returned. |
| `status` | Required on create; `draft` or `published`. Updates take effect at commit. |
| `description` | Optional plain text, maximum 2,000 characters; default/clear `""`. |
| `logo` | Optional trusted image reference, maximum 2,048 characters; default/clear `""`, allowed for either status. |
| `website_url` | Optional absolute HTTP/HTTPS URL with host and no user-info/control characters, maximum 2,048 characters; default/clear `""`. Paths/query strings are preserved; never fetched. |
| `founded_year` | Optional string `""` or four ASCII digits, preserving editorial calendar notation. |
| `meta_description` | Optional plain text, maximum 500 characters; default/clear `""`. |
| `specifications` | Optional JSON object, expanded to all keys on create; nested partial merge on PATCH. |
| `expected_version` | Required only for PATCH, positive integer; not an editable field. |

Only `category_id` accepts null. Omitted optional strings default to empty on create and preserve stored values on PATCH. PATCH needs at least one editable field alongside `expected_version`. An empty specifications object is a valid no-op. Actual changes increment the version; no-op saves preserve version/timestamps and emit no duplicate audit/publication events. The response is returned after the transaction commits. Validation/conflict failures preserve entered frontend values and make no partial save.

Create a Published record with empty logo and website:

```json
{
  "title": "Example Prop Firm", "english_name": "Example Firm", "slug": "example-prop-firm",
  "description": "Evaluation and payout overview.", "logo": "", "website_url": "", "founded_year": "",
  "category_id": null,
  "specifications": {
    "trial_account": "yes", "evaluation_type": "Two stages", "daily_loss_limit": "5% daily; resets at 00:00 UTC",
    "total_loss_limit": "10% trailing equity", "payout_rules": "Monthly, subject to eligibility"
  },
  "content": "<h2>Overview</h2><p>Evaluation and payout conditions.</p>", "meta_description": "", "status": "published"
}
```

Use `"status": "draft"` with the same fields to create a Draft. Creation returns version 1 and `Location: /api/v1/admin/prop-firms/items/{id}`. A Draft can be published using `{ "expected_version": 1, "status": "published" }`. Saving `draft` removes visibility; no preview token, scheduler, independent revision, approval workflow, or autosave is provided. `published_at` is set on first publication and survives subsequent drafting/republishing.

Edit example (also usable to publish a Draft):

```json
{
  "expected_version": 1, "logo": "", "website_url": "", "category_id": null,
  "specifications": {"trial_account": "no", "daily_loss_limit": "", "payout_rules": "Updated payout conditions"},
  "status": "published"
}
```

The other specifications, original author and creation time are preserved. Refetch current detail after a `409 VERSION_CONFLICT` and reconcile edits; do not silently overwrite with a fresh version.

### Complete grouped specifications

All values are trimmed strings. Text values allow 1,500 Unicode characters, preserve internal line breaks, and must be rendered as escaped text. Status values are exactly `""`, `"yes"`, `"no"`, `"not-applicable"`; unknown, no and not-applicable remain distinct. Omitted keys default to `""` on create. On PATCH omitted keys are unchanged, `""` clears, and `{}` changes nothing. Arrays, objects, null, booleans, numbers and unknown keys are rejected with stable dot paths such as `specifications.trial_account`.

Groups and frontend mappings, in display order:

| Group | Server key → frontend key | Value |
| --- | --- | --- |
| General Information | `established_at` → `establishedAt` | Text |
| General Information | `head_office_address` → `headOfficeAddress` | Text |
| General Information | `physical_branch_count` → `physicalBranchCount` | Text |
| General Information | `years_active` → `yearsActive` | Text |
| General Information | `minimum_deposit` → `minimumDeposit` | Text |
| General Information | `maximum_withdrawal` → `maximumWithdrawal` | Text |
| Features | `in_person_visit` → `inPersonVisit` | Status |
| Features | `address_transparency` → `addressTransparency` | Status |
| Features | `active_social_media` → `activeSocialMedia` | Status |
| Features | `around_the_clock_support` → `aroundTheClockSupport` | Status |
| Features | `support_availability` → `supportAvailability` | Status |
| Features | `trial_account` → `trialAccount` | Status |
| Trading Conditions | `identity_documents` → `identityDocuments` | Text |
| Trading Conditions | `dedicated_app` → `dedicatedApp` | Status |
| Trading Conditions | `evaluation_type` → `evaluationType` | Text |
| Trading Conditions | `daily_loss_limit` → `dailyLossLimit` | Text |
| Trading Conditions | `total_loss_limit` → `totalLossLimit` | Text |
| Trading Conditions | `payout_rules` → `payoutRules` | Text |
| Fees | `trading_fee` → `tradingFee` | Text |
| Fees | `registration_fee` → `registrationFee` | Text |
| Licenses | `inamad` → `inamad` | Status |
| Licenses | `virtual_business_union` → `virtualBusinessUnion` | Status |
| Licenses | `computer_trade_organization` → `computerTradeOrganization` | Status |

Evaluation type, daily/total drawdown, payout wording, minimum deposit, maximum withdrawal, fees and trial-account status are independent editorial declarations. Preserve percentages, currencies, stage/account conditions, static/trailing wording, equity/balance references, time zones and payout eligibility. Do not calculate risk, thresholds, years active or payout dates. Empty does not mean unlimited, zero or free. A license status does not verify funding or guarantee payouts. No challenge ID, account size, profit split, terminal credentials or structured plan API is required. This directory does not provision accounts, evaluate traders, execute trades, take payments or issue payouts. The rich body holds optional challenge explanations, comparison tables, registration guides and FAQs.

## HTML and media

The shared DOM-based sanitizer uses Symfony HTML Sanitizer. Supported elements are `p`, `br`, `h1`–`h3`, `strong`, `b`, `em`, `i`, `u`, `s`, `strike`, `ul`, `ol`, `li`, `blockquote`, `pre`, `code`, `hr`, `a`, `img`, `table`, `thead`, `tbody`, `tfoot`, `tr`, `th`, `td`, `colgroup`, `col`, `video`, restricted YouTube `iframe`, and known editor `div`/`figure` wrappers. Only allowlisted attributes survive: safe links/titles, image alt/dimensions, direction/alignment, table structure and column-width metadata, code-language metadata, and supported media/wrapper attributes.

Bounds: nesting 32; 20,000 DOM elements; 5,000 table cells; 200 rows per table; 50 columns per row; row/column spans 1–50; dimensions 1–4,096 pixels; ordered-list start 1–100,000; code-language metadata 32 characters. Alignment accepts only left/right/center/justify. Arbitrary CSS/classes are not accepted. YouTube IDs have 11 characters; allowed query keys are `start`, `end`, `controls`, `rel`, and canonical embeds normalize to `youtube-nocookie.com`. Allowed iframe hosts are exactly `youtube.com`, `www.youtube.com`, `youtube-nocookie.com`, `www.youtube-nocookie.com`; the path is `/embed/{11-character-id}`. Time query values are 1–7 ASCII digits; `controls`/`rel` accept 0 or 1. `loading` accepts lazy/eager. Column `width` accepts 1–4 ASCII digits starting at 1, optionally followed by `%` (the shared implementation does not restrict percentage widths to 100).

Attribute allowlists: links `href,title,target,rel`; images `src,alt,width,height,title`; videos `src,title,controls,preload,playsinline,width,height`; iframes `src,title,width,height,allowfullscreen,loading`; paragraphs/headings `style,dir`; cells `style,colspan,rowspan,dir`; columns `span,width`; ordered lists `start`; code `data-language`. Other supported elements/wrappers have no attributes. Direction is `ltr`, `rtl`, or `auto`; code language matches `[a-z0-9_+-]{1,32}`. Arbitrary `id`/`class`, including on wrappers, are rejected; the public projection generates heading IDs.

Links permit validated HTTP/HTTPS or same-document fragments; credentials and executable schemes are invalid. New-tab links receive `rel="noopener noreferrer"`. Media/logo references allow configured trusted HTTPS media hosts (`PLATFORM_TRUSTED_MEDIA_HOSTS`, default `service.tahlilex.com`), managed `/media/{id}/{canonical-name}` locations, and approved `/images/` or `/tahlilpanel/images/` assets. Managed references must resolve to accessible ready media of the correct kind. There is no remote fetching/import during save. Upload/crop/storage/physical deletion use the separate shared-media contract.

Scripts, forms, SVG/active elements, event handlers, arbitrary iframes, `srcdoc`, data/base64/blob/local paths and unsafe URLs fail with `422`; dangerous HTML is not silently removed and acknowledged. Harmless unsupported formatting may be canonicalized. Empty paragraphs, NBSP-only text, empty tables and missing valid media sources are insufficient. Both statuses require meaningful content. Render the returned HTML without flattening tables or media; keep all ordinary identity/specification strings escaped. Clearing/replacing references or deleting a firm never deletes a shared media file.

## Categories, identity and transactions

Category creation accepts only `{ "name": "Prop Firm Services" }`. Names are plain text, 1–80 characters, normalized for Persian/Arabic yeh/kaf, ZWNJ, whitespace and case. Normalized names, including deleted names, remain reserved within this domain. GET returns the entire list sorted by normalized name and ID; no pagination or truncation. The selector adds a local Uncategorized option (`null`); transfer destinations exclude the source in the frontend, and the backend also rejects equal IDs.

Category response:

```json
{"data":{"id":4,"name":"Prop Firm Services","prop_firm_count":3,"created_at":"2026-10-26T09:00:00.000000Z","updated_at":"2026-10-26T09:00:00.000000Z","version":1},"request_id":"req_example"}
```

Counts include non-deleted Draft and Published records and do not themselves increment category versions. Category creation does not create a public category page. The optional default `Prop Firms` category is an ordinary deletable/mergeable category, resolved by stored identity rather than assumed ID 1.

Merge body:

```json
{"expected_version":1,"target_category_id":7,"target_expected_version":1}
```

Delete response:

```json
{"data":{"deleted_category_id":4,"affected_prop_firm_count":3},"request_id":"req_example"}
```

Merge response:

```json
{"data":{"source_category_id":4,"target_category_id":7,"moved_prop_firm_count":3},"request_id":"req_example"}
```

Deletion uncategorizes all non-deleted source firms. Merge transfers all non-deleted source firms and soft-deletes only the source category. Both advance affected firm versions, timestamps and editor metadata while preserving other values and publication state. Empty sources return count zero. Stale source/target versions fail atomically. Retrying against the removed source returns 404 without moving other records. Assignment, merge and deletion use the shared database mutation lock and transactional row locks; assignment either commits before the category operation and is included or fails after the category disappears. Counts reflect committed updates.

The fixed `prop-firms` domain has independent tables, category IDs, slug ownership, rate keys, media consumers, audits and Outbox. Same numeric IDs/slugs in exchanges, forex, stock or gold do not identify this firm. The route fixes the kind; requests cannot reclassify records. Soft deletion preserves immutable ID, ownership/history, author and retained audit data, and hides the parent. Typed references use `prop-firms` plus ID, never display names or mutable slugs. Category operations do not delete platform bodies or independently managed content. Shared comments/ratings are not installed or implemented here.

## Public-read services and integration boundary

Internal PHP entrypoints:

- `ResolvePropFirmArchive::handle(page: 1, search: '', sort: 'default')`: nine cards per page, default newest-first with ID tie-breaker; `alphabetical` uses Persian title ordering and ID. Search includes title, English name and description or content excerpt, normalizing yeh/kaf and removing whitespace/ZWNJ while ignoring case. It filters before counting and returns `items` and `meta`; there is no internal category filter UI/API.
- `ResolvePropFirmPublicItem::handle($slug)`: `type: profile` plus the model, `type: redirect` with status 301/path, or `type: not_found`. Only Published non-deleted records resolve. Draft-only historical aliases are not previews. Formerly public aliases redirect directly to the current slug only while published; reclaiming an owned alias preserves identity and avoids chains.
- `PlatformPublicProjection::card/detail($model, PlatformProfileDefinition::propFirms())`: canonical path, identity, optional image/official site, founding-year text, domain label `Prop Firm`, description/excerpt, specifications, HTML, metadata and outline. `display.established_at` falls back to founding year without changing saved fields. Metadata falls back safely without writing a derived value into the override. Current aggregates are `rating_average: null`, `rating_count: 0`, `comment_count: 0` until real typed rating/comment modules are connected.
- `ResolveFeaturedPropFirms::resolve($limit = 4, $excludeId = null)` uses ordered unique `PROP_FIRM_FEATURED_IDS`, skips unavailable IDs and does not fill missing selections with arbitrary firms. `related($current, $limit = 4)` returns distinct eligible same-domain firms excluding the current ID.

Cards use the saved title without a required localized prefix, English name, logo/neutral initials fallback, current public path, founding year and platform label. The frontend hides an empty official-site action and safely renders nonempty external new-tab links. Labels/colors/layout are presentation choices. Navigation links to `/exchanges`, `/brokers`, `/stock-brokers`, `/gold-platforms`, `/prop-firms` are separate directories; internal categories cannot change these destinations or a record's kind.

Detail returns canonical HTML and heading outline; reserve `specifications`, `specifications-general`, `specifications-features`, `specifications-conditions`, `specifications-fees`, `specifications-licenses`, `overview`, `faq`, `comments`. Body heading anchors are unique and avoid reserved IDs. The frontend must render matching section targets and show overview/FAQ/comments links only when corresponding sections exist. Tables/media stay intact. Draft/deleted records must remain absent from future menus, search, sitemap, related cards, selections, metadata and typed parent aggregates; an admin session does not change ordinary public eligibility.

**Delivery boundary agreed for this module:** these are internal resolvers, not guest HTTP endpoints. Public pages, public HTTP redirects/API, menus, sitemap, rating/comment systems and the frontend cache consumer remain separate integration work. Newly published slugs are immediately resolvable by the backend service without deployment; the separate frontend must provide dynamic runtime routes and must not use a fixed build-time slug list. Saving alone does not demonstrate public HTTP route availability.

### Cache, events and operations

Public mutations atomically enqueue durable rows in `prop_firm_outbox`; public item changes and category changes cover dependent detail/archive/selections/count reads. Draft-only private item changes do not need public invalidation. Audit events are `prop_firm_created`, `prop_firm_updated`, `prop_firm_published`, `prop_firm_unpublished`, `prop_firm_slug_changed`, `prop_firm_deleted`, `prop_firm_category_created`, `prop_firm_category_deleted`, `prop_firm_categories_merged`. Audits include actor/target/request ID, server time, changed field names, status/slug transitions or committed affected counts, without credentials or full HTML/request bodies.

A successful response means the database mutation, ownership/media reference updates, audit and applicable Outbox writes committed. It does **not** mean the separate frontend cache was invalidated. No external consumer or automatic delivery/retry worker is configured. Undelivered rows retain `delivered_at=null`; `attempts`, `available_at`, `last_error` are reserved for a future consumer and are not evidence of actual retries. Integrate an idempotent consumer before relying on cached public reads; mark delivered only after acknowledgement, record failures and schedule retries through that integration. Do not acknowledge delivery or prune pending events on failure. Internal resolvers query the authoritative database and do not cache drafts.

```sh
php artisan platform-profiles:status prop-firms
php artisan platform-profiles:prune prop-firms
```

Status reports pending event count/oldest age and the missing consumer. Pruning runs hourly through the existing scheduler, retaining undelivered events and deleting delivered Outbox and audit entries older than 90 days, matching the other modules. Slug ownership and soft-deleted records are retained.

Apply the new additive migration with `php artisan migrate --force` during deployment; no development database migration was required to run the isolated tests. Run the usual scheduler. Rollback drops the new tables and their data, so prefer a forward fix after real data exists. No dependencies, permission catalogs or frontend bundles are added.

## Errors and frontend handling

Errors retain the shared envelope:

```json
{"error":{"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"specifications.trial_account":["Select a supported status value."]}},"request_id":"req_example"}
```

| HTTP | Code / handling |
| --- | --- |
| 400 | `INVALID_JSON`: correct malformed JSON. |
| 401 | `UNAUTHENTICATED`: sign in again. |
| 403 | `CSRF_INVALID` or shared-boundary `FORBIDDEN`: refresh CSRF/authentication as appropriate. No new permission catalog is introduced. |
| 404 | `PROP_FIRM_NOT_FOUND`, `CATEGORY_NOT_FOUND`: missing/deleted resource after session checks. |
| 409 | `SLUG_TAKEN`, `CATEGORY_NAME_TAKEN`, `VERSION_CONFLICT`: choose another reserved identity or refetch/reconcile versions. |
| 422 | `VALIDATION_FAILED`: display stable field-path errors without discarding form values. |
| 413 | `PAYLOAD_TOO_LARGE`: body exceeds endpoint limit. |
| 415 | `UNSUPPORTED_MEDIA_TYPE`: send JSON. |
| 429 | `RATE_LIMITED`: observe `Retry-After`. |
| 500 / 503 | `INTERNAL_ERROR` / `SERVICE_UNAVAILABLE`: controlled failures; retain request ID. Read fresh state before retrying a mutation with uncertain delivery. |

## Placeholder-based curl / Postman examples

Replace placeholders locally; never commit real cookie/token values. `create.json` contains the creation JSON above. `edit.json` and `merge.json` contain the corresponding examples. In Postman set `api_base`, `session_cookie`, `csrf_token`, `firm_id`, `category_id`; use the cookie jar and raw JSON bodies, and import the companion OpenAPI file. No live credentials are embedded.

```sh
curl '{{api_base}}/prop-firms/items?page=1&per_page=10&q=' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'Accept: application/json'
curl '{{api_base}}/prop-firms/items/{{firm_id}}' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}'
curl -X POST '{{api_base}}/prop-firms/items' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'X-CSRF-Token: {{csrf_token}}' -H 'Content-Type: application/json' --data-binary @create.json
curl -X PATCH '{{api_base}}/prop-firms/items/{{firm_id}}' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'X-CSRF-Token: {{csrf_token}}' -H 'Content-Type: application/json' --data-binary @edit.json
curl -X DELETE '{{api_base}}/prop-firms/items/{{firm_id}}?expected_version=2' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'X-CSRF-Token: {{csrf_token}}'
curl '{{api_base}}/prop-firms/categories?q=' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}'
curl '{{api_base}}/prop-firms/categories/{{category_id}}' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}'
curl -X POST '{{api_base}}/prop-firms/categories' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'X-CSRF-Token: {{csrf_token}}' -H 'Content-Type: application/json' --data '{"name":"Prop Firm Services"}'
curl -X DELETE '{{api_base}}/prop-firms/categories/{{category_id}}?expected_version=1' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'X-CSRF-Token: {{csrf_token}}'
curl -X POST '{{api_base}}/prop-firms/categories/{{category_id}}/merge' -H 'Cookie: tahlil-panel-session-v2={{session_cookie}}' -H 'X-CSRF-Token: {{csrf_token}}' -H 'Content-Type: application/json' --data-binary @merge.json
```

## Verification record

Verified on 2026-10-26:

- `php artisan test --compact tests/Feature/PropFirmsTest.php`: 21 passed, 412 assertions.
- `php artisan test --compact tests/Feature/PropFirmsTest.php tests/Feature/PropFirmsConcurrencyTest.php`: 24 passed, 437 assertions.
- `php artisan test --compact`: 232 tests, 221 passed, 11 skipped, 2,297 assertions. Skips are existing MariaDB/MySQL concurrency tests requiring a separately configured disposable database.
- `vendor/bin/pint --dirty --format agent`: completed; import ordering fixed in the new factory/test and route imports.
- `git diff --check`: passed.
- OpenAPI YAML parsed with Ruby Psych; local references resolved, all 10 route operations matched Artisan, all 23 specification keys matched PHP, and JSON examples/document links validated.

HTTP tests use SQLite `:memory:`; concurrency tests create and remove an isolated temporary SQLite database. The new forked concurrency cases passed for duplicate slug/category creation, same-version edits, and category assignment versus deletion/merge. These do not prove MariaDB/MySQL production row-lock or DDL behavior. No staging/production endpoint or frontend cache delivery has been verified. Apply the migration and verify it on the deployment database engine before rollout.
