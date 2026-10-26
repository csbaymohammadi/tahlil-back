# Page Settings API

## Delivery and bases

Implemented for Laravel 13 / PHP 8.3. Development API base resolved by Boost: `http://localhost:8000/api/v1`; admin base adds `/admin`. Staging/production origins have not been supplied or verified. Use deployment configuration rather than inventing them. Companion schema: [13-panel-page-settings.openapi.yaml](13-panel-page-settings.openapi.yaml).

This delivery adds backend endpoints, persistence, diagnostics, public card resolution and signed cache-change delivery. The five-tab React form, website pages and the receiving cache service live outside this repository. No frontend deployment or external cache acknowledgement has been exercised.

## Endpoints and authentication

| Method | Path relative to API base | Behavior |
| --- | --- | --- |
| GET | `/admin/page-settings` | Current editable singleton, metadata and diagnostics |
| PUT | `/admin/page-settings` | Complete replacement with `expected_version` |
| GET | `/admin/page-settings/options/home/{section_key}` | Eligible home catalog |
| GET | `/admin/page-settings/options/menu/{group_key}` | Eligible financial-platform catalog |
| GET | `/page-settings` | Public active projection and opaque revision |

Admin paths use the existing `tahlil-panel-session-v2` cookie and active-account guard. All active administrators, including roleless accounts, have access. PUT requires the existing `X-CSRF-Token` plus an exact allowed `Origin` from `PRESENTATION_ALLOWED_ORIGINS` (comma-separated, defaults to APP_URL). This extra Origin policy is scoped to these new endpoints; legacy account/auth routes keep their established behavior. Responses contain `request_id`, `X-Request-ID` and `Cache-Control: no-store`. Public reads never return panel identity/diagnostics and require no cookie. Allowed public Origin reads receive CORS headers without credential access.

Admin routes allow 300 requests per minute per account and route; public settings reads have an IP-keyed limit. Settings writes accept at most 2 MiB of JSON, require an object body, reject duplicate decoded object keys, and distinguish JSON arrays from objects. GETs have no body. Unknown/repeated/structured query fields are rejected.

## Snapshot, bootstrap and saving

The additive migration creates ID 1, version 1, UTC timestamps, complete generic Persian hero/section labels and fixed archive actions. All content selections, social addresses, ads and logos start empty. There is no fabricated content, automatic publication, or creation during GET. `PageSettingsSeeder` is idempotent and never overwrites an existing snapshot.

GET `data` contains `hero`, `carousels`, `menu`, `links`, `advertisements`, `infinite_slider`, `version`, `created_at`, `updated_at`, and `selection_details`. PUT sends exactly the first six fields plus `expected_version`. Use the full default PUT example at the end; a form initialized from GET must strip read-only metadata/diagnostics and reset all four new-address link inputs to empty strings.

A shared database mutation lock serializes settings, parent publication/deletion and media-reference writes. PUT rechecks the actor/session, expected version and live references inside one transaction. An actual change advances version/timestamp once; canonical no-ops preserve both. Array object-key order is immaterial; fixed section/group order is canonicalized. Selection/ad/logo order remains writable. Empty collections clear them. Every required group must be supplied; omission/null fails rather than resetting another tab. Invalid retained selections also reject a subsequent complete save.

Blank or whitespace-only new social addresses preserve the current address. Nonblank values atomically replace it; no clear operation exists. Saving just a social edit still sends the full snapshot. Failure changes no settings or media associations. Cancel and previews are local operations and never PUT. A lost response requires GET reconciliation before retry; stale `expected_version` produces `409 VERSION_CONFLICT`, never silent merge/replay.

## Fields and catalogs

- Hero: required `title` 1–200, `description` 1–2000, three button labels 1–120 and two destinations 1–2048 characters. `accent` is required as a field but may be empty, is at most 200 characters, and must occur exactly in the trimmed title when nonempty. UI highlights its first occurrence. `secondary_mobile_label` and `secondary_label` share `secondary_url`.
- Every carousel requires `key`, matching `kind`, `title` 1–200, `description` 0–2000, `action_label` 1–120, `action_url` 1–2048 and ordered `selected_ids`. Exactly six appear: exchanges/exchange, aparat/video, news/news, bonuses/bonus, videos/video, articles/article.
- Exactly five menu groups contain only `key` and `selected_ids`: exchanges, forex, stock-brokers, gold-platforms, prop-firms. Forex resolves Broker records. Each permits 0–9 selections; catalogs are not capped at nine.
- IDs are JSON integers in 1–9007199254740991, unique within each selection list. Identical numeric IDs in different domains remain unrelated. The same tutorial may independently appear in aparat and videos. Home selections have no nine-item limit.
- Links always contain telegram, instagram, youtube, aparat, in that display order. Values are strings up to 2048 characters; nonempty updates require absolute HTTP/HTTPS.
- Advertisements require both `home_header` and `news_and_articles` arrays. Items contain only nonempty `image` and `link`, each at most 2048 characters. Home Header is shared-layout advertising; News and Articles is the common in-page placement, not per-record targeting. Empty arrays disable placements.
- Slider items require opaque `id` (1–128 ASCII letters/digits, dot, underscore, colon or hyphen; first character alphanumeric), `logo_src` (1–2048), `name` and `english_name` (1–120 each). IDs must be unique; names may repeat. Preserve IDs through reorder/edit. No link or platform association is inferred and no nine-item limit applies.

Text is trimmed, plain, Unicode-preserving and returned as text for escaped rendering. Empty optional descriptions are allowed; null is invalid. Destinations accept one-slash root-relative paths, ASCII fragments or absolute HTTP/HTTPS. Credentials, protocol-relative URLs, unsafe schemes, controls, backslashes and recursively encoded traversal are rejected. Absolute scheme/host are lowercased and default ports removed; intentional query/fragment data is retained. No destination is fetched.

Media values are canonical managed URLs from the existing upload service, explicitly configured `presentation.bundled_assets`, or trusted HTTPS raster URLs on `PRESENTATION_TRUSTED_MEDIA_HOSTS`. The default trusted host is service.tahlilex.com; bundled assets default to none. Raw SVG, arbitrary hosts, local files, base64, data/blob URLs and traversal are not accepted. Managed references must be ready/accessibly owned or already shared, and are attached atomically to the singleton. GIF is supported; banner dimensions 1200×130/2400×260 are recommendations, not validation limits. Removing/replacing an image never deletes bytes. Unattached uploads follow the existing 24-hour media grace policy; use [shared media](16-shared-media-api.md) for binary uploads and previews.

### Options

Both options endpoints accept `q` (plain string, at most 200 trimmed characters), `page` (positive safe integer, default 1), and `per_page` (1–100, default 100). Search normalizes yeh/kaf, ZWNJ/whitespace and case, and matches title plus English platform name where available. SQL wildcards in user input are literal. Sort is `created_at DESC, id DESC`; filtering precedes totals/pagination. Unknown keys are 422. Beyond-last pages return `data: []` and accurate totals; `total_pages` is zero for an empty catalog.

Home option fields are `id`, `kind`, `title`, `description` (empty fallback), `status: published`; tutorial options also contain canonical `aparat_url` or empty string. Menu fields are `id`, `title`, `english_name`, `public_path`.

```json
{"data":[{"id":91,"kind":"video","title":"Lesson","description":"","status":"published","aparat_url":"https://www.aparat.com/v/abc1234"}],"meta":{"page":1,"per_page":100,"total":1,"total_pages":1},"request_id":"req_example"}
```

Only Published/non-deleted editorial records qualify. Due Scheduled items remain excluded until the publication worker actually commits Published. Valid non-deleted saved tutorials are immediately eligible; aparat additionally parses its saved URL using the existing provider rules and requires the same saved video ID. Published bonuses with an ended campaign remain eligible and retain ended metadata.

GET diagnostics contain one group per nonempty stored selection and one `{id,title,eligible,reason}` per saved ID in order. Reasons are `unpublished`, `scheduled`, `deleted`, `not_found`, `invalid_aparat_source`, or null. Deleted/missing items receive a neutral label. Use diagnostics to hydrate removable chips even when the current search page excludes those IDs. Source changes never rewrite saved selections or increment the editable settings version.

## Public projection and UI adapter

Public GET uses `items` instead of `selected_ids`, preserves every configured section and uses empty `items` to tell the renderer to omit an empty card/action shell. News exposes the first four eligible ordered cards: first is the main card, next three supporting cards. Other eligible selections retain order. Empty menu groups retain `key`, localized `label`, `all_href`, and empty `items`; navigation archives remain available.

Card schemas are explicit in OpenAPI: Exchange and platform cards reuse the current domain projections, including logo, names, category and neutral ratings; bonus cards also include campaign, rating and comment aggregates; tutorial cards include provider URLs, cover, duration/category and public path; article/news cards include explicit description/cover plus derived display variants, reading time, category, safe author and comment count. No HTML body, administrator email/username/status or private diagnostic is serialized. Parent title/slug/status/source/avatar and displayed metadata changes affect the SHA-256 public `revision`, independently of settings version.

Public advertisements add stable placement/index `id`, neutral Persian `alt`, `target: _blank`, and `rel: sponsored noopener noreferrer`. The website must apply these attributes even to internal destinations. Invalid managed images are omitted without mutating storage. Slider output contains only complete saved items, without animation duplicates. UI duplicates for looping, reduced-motion behavior and per-item unsaved modal previews are presentation concerns.

Map snake_case to camelCase: `selected_ids→selectedIds`, `action_label→actionLabel`, `action_url→actionUrl`, `home_header→homeHeader`, `news_and_articles→newsAndArticles`, `infinite_slider→infiniteSlider`, `logo_src→logoSrc`, `english_name→englishName`. Convert field-error segments likewise; submitted array indices must stay tied to the submitted snapshot. Open the affected tab/accordion. Invalidate identity/profile/user-list/Home queries after profile writes; settings save replaces the entire form with the returned canonical snapshot.

## Durable cache propagation

The migration creates `public_change_deliveries`; settings and profile/media changes enqueue safe events in their owning transactions. `presentation:deliver` also bridges existing blog/news/exchange/broker/stock/gold/prop/bonus/tutorial/comment Outboxes using a unique source+ID key. Ten deliveries are attempted per command run; up to 100 pending rows from each source are bridged per run. Source Outbox rows are acknowledged only after the website webhook succeeds.

Configure `PUBLIC_REVALIDATION_URL` as an operator-controlled HTTPS endpoint and `PUBLIC_REVALIDATION_SECRET` as at least 32 bytes. The worker does not follow redirects and uses a 3-second connect / 5-second request timeout. Missing/invalid configuration retains pending work without claiming delivery. Scheduler invokes the command every ten seconds with overlap protection and a shared atomic cache lock. A healthy scheduler/receiver with no backlog normally processes changes on the next run; backlog/HTTP failures increase latency. Retry delays are 10, 20, 40… seconds, capped at one hour, with pending work retained indefinitely.

Example body:

```json
{"event_id":"123","event":"page_settings_updated","request_id":"req_example","areas":["homepage","navigation","footer","advertisements","content","authors","sitemap"],"occurred_at":"2026-10-26T09:00:00.000000Z","revision":"<opaque-sha256>"}
```

Headers: `Idempotency-Key: public-change-123`, `X-Revalidation-Timestamp: <Unix seconds>`, `X-Revalidation-Signature: sha256=<HMAC-SHA256(secret, timestamp + '.' + exact request bytes)>`. Verify the signature with a timing-safe comparison, enforce a five-minute timestamp tolerance, deduplicate event IDs, invalidate/rebuild all named areas from current backend data, and only then return 2xx. Retries retain event ID/body/revision and use a fresh signed timestamp. Events request invalidation, never replacement with an older snapshot; arrivals may be repeated or reordered.

The website must purge homepage HTML/data, desktop/mobile navigation, footer/social fragments, banners, dependent detail/archive/author/search/sitemap caches. The public API itself uses live database resolution and no-store responses, so parent removals do not depend on webhook success or TTL. No fallback fixture or empty-success response is returned when the singleton cannot be read. No website receiver is configured by this delivery, so real website-cache freshness remains an integration requirement.

Inspect with `php artisan presentation:deliver --status --no-interaction`; run the scheduler in deployment. `analytics:prune` removes presentation audits and delivered history older than 90 days, never pending deliveries. Audit contains actor/request IDs, UTC time, versions, changed areas and added/removed/reordered counts, never full snapshots or URL query strings. Failed auth/reference/version requests use the shared security audit with error codes only.

## Errors, deployment and verification

Errors use `{error:{code,message,fields:{}},request_id}`. Expected statuses: 400 INVALID_JSON, 401 UNAUTHENTICATED, 403 FORBIDDEN/CSRF_INVALID, 409 VERSION_CONFLICT, 413 PAYLOAD_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE, 422 VALIDATION_FAILED, 429 RATE_LIMITED with Retry-After, 500 INTERNAL_ERROR and 503 SERVICE_UNAVAILABLE. For example `fields.hero.accent`, `fields.carousels.1.selected_ids`, and `fields.advertisements.home_header.0.image` map to those exact submitted controls.

```sh
curl '{{admin_base}}/page-settings' -b '{{cookie_jar}}'
curl -X PUT '{{admin_base}}/page-settings' -b '{{cookie_jar}}' -H 'Origin: {{allowed_origin}}' -H 'X-CSRF-Token: {{csrf_token}}' -H 'Content-Type: application/json' --data-binary @settings.json
curl '{{admin_base}}/page-settings/options/menu/forex?q={{encoded_search}}&page=1&per_page=100' -b '{{cookie_jar}}'
curl '{{public_base}}/page-settings'
```

Apply additive migrations during deployment, configure origins/media/webhook, and keep the scheduler running. The development database was not migrated or seeded. No dependency changes or destructive migrations were used. Focused tests cover round trips, ordering/empty states, full-snapshot rollback, no-ops/conflicts, references, source eligibility, strict JSON/transport, media lifecycle, news limits, audits, signatures and retry/acknowledgement. SQLite tests do not prove production-engine locking or actual website revalidation. Browser form/crop/preview integration is outside this backend delivery.

## Complete default PUT example

```json
{
  "expected_version": 1,
  "hero": {
    "title": "آگاهانه تحلیل کنید، آگاهانه انتخاب کنید",
    "accent": "آگاهانه انتخاب کنید",
    "description": "بررسی پلتفرم‌ها، اخبار بازار و آموزش‌های کاربردی.",
    "primary_label": "بررسی صرافی‌ها",
    "primary_url": "/exchanges",
    "secondary_label": "آموزش‌های کاربردی",
    "secondary_mobile_label": "آموزش‌ها",
    "secondary_url": "/tutorials"
  },
  "carousels": [
    {
      "key": "exchanges",
      "kind": "exchange",
      "title": "معرفی صرافی‌ها",
      "description": "",
      "action_label": "مشاهده همه",
      "action_url": "/exchanges",
      "selected_ids": []
    },
    {
      "key": "aparat",
      "kind": "video",
      "title": "تحلیل در آپارات",
      "description": "",
      "action_label": "مشاهده همه",
      "action_url": "/tutorials",
      "selected_ids": []
    },
    {
      "key": "news",
      "kind": "news",
      "title": "اخبار بازار",
      "description": "",
      "action_label": "مشاهده همه",
      "action_url": "/news",
      "selected_ids": []
    },
    {
      "key": "bonuses",
      "kind": "bonus",
      "title": "بونوس‌ها",
      "description": "",
      "action_label": "مشاهده همه",
      "action_url": "/bonuses",
      "selected_ids": []
    },
    {
      "key": "videos",
      "kind": "video",
      "title": "ویدیوهای آموزشی",
      "description": "",
      "action_label": "مشاهده همه",
      "action_url": "/tutorials",
      "selected_ids": []
    },
    {
      "key": "articles",
      "kind": "article",
      "title": "مقالات تحلیلی",
      "description": "",
      "action_label": "مشاهده همه",
      "action_url": "/blog",
      "selected_ids": []
    }
  ],
  "menu": [
    {
      "key": "exchanges",
      "selected_ids": []
    },
    {
      "key": "forex",
      "selected_ids": []
    },
    {
      "key": "stock-brokers",
      "selected_ids": []
    },
    {
      "key": "gold-platforms",
      "selected_ids": []
    },
    {
      "key": "prop-firms",
      "selected_ids": []
    }
  ],
  "links": {
    "telegram": "",
    "instagram": "",
    "youtube": "",
    "aparat": ""
  },
  "advertisements": {
    "home_header": [],
    "news_and_articles": []
  },
  "infinite_slider": []
}
```
