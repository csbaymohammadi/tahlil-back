# Public gold platforms, prop firms, and bonuses API

This is the frontend integration contract for the three public domains. The base path is `/api/v1`. IDs are numeric and scoped to their domain. The `href` values below are website paths; lookup endpoints use the API paths. Dates are UTC ISO 8601 strings or `null` when unavailable. Public JSON responses include `request_id` and `X-Request-ID`. Examples use illustrative data.

## Transport and shared behavior

- Public GETs require no login and return `Cache-Control: no-cache, must-revalidate`; errors use `no-store`. The existing personal rating endpoint always uses `no-store` and `Vary: Cookie`.
- CORS follows the current application configuration. The browser must send credentials for personal rating state. Its `Secure`, `HttpOnly`, `SameSite=Lax` cookie can require a same-site frontend/BFF depending on browser and deployment; JavaScript must not attempt to read it.
- Only currently published, non-deleted records are visible. Drafts, removed parents and their old aliases return a module-specific 404. A previously public alias returns `301 Location: /api/v1/{domain}/{current-slug}`; `/others` and `/latest` are retained. The website should redirect its own old route to the website canonical path.
- GET request bodies, unknown and repeated parameters are rejected with `422`, except the category fallback described below. Unrecognized parameters are not silently applied. Public reads use the configurable shared 300 requests/minute/source limit. Rate-limit errors have `Retry-After`.
- Search trims `q`, accepts at most 200 Unicode characters, folds case and Arabic ي/ى/ك into Persian ی/ی/ک, and ignores spaces and half-spaces. `%` and `_` are literal characters. Original output strings are unchanged.
- `page` defaults to 1 and is a positive integer; `per_page` may be omitted or must be 9. An out-of-range page preserves the requested page, returns `data: []`, and retains the actual total. `total_pages` is zero for zero records.

## Gold platforms and prop firms

| Request | Purpose |
| --- | --- |
| `GET /gold-platforms` | Gold-platform directory |
| `GET /gold-platforms/{slug}` | Gold-platform review |
| `GET /gold-platforms/{slug}/others` | Up to nine other gold platforms |
| `GET /prop-firms` | Prop-firm directory |
| `GET /prop-firms/{slug}` | Prop-firm review |
| `GET /prop-firms/{slug}/others` | Up to nine other prop firms |

Lists accept `q`, `sort` (`default` or `alphabetical`), `page`, and `per_page`. Default order is creation time descending, then ID descending. Alphabetical ordering uses Persian-aware normalized title order, then ID ascending. Search covers title, English name, and saved description or its derived excerpt. There is no category filter or facets. The `others` endpoint excludes the parent and uses default ordering. Detail and `others` accept no query parameters.

### List success: `GET /gold-platforms?q=gold&page=1`

```json
{
  "data": [{
    "id": 61,
    "kind": "gold-platforms",
    "slug": "example-gold-platform",
    "title": "Example Platform",
    "english_name": "Example Gold",
    "logo": "",
    "href": "/gold-platforms/example-gold-platform",
    "description": "Platform overview.",
    "excerpt": "Platform overview.",
    "category": {"id": 4, "name": "Gold Services"},
    "founding_year": "1400",
    "platform_label": "Gold Platform",
    "rating_average": null,
    "rating_count": 0,
    "comment_count": 2
  }],
  "meta": {"page": 1, "per_page": 9, "total": 1, "total_pages": 1},
  "filters": {"q": "gold", "sort": "default"},
  "request_id": "req_example"
}
```

For prop firms the same schema uses `kind: "prop-firms"`, `href: "/prop-firms/{slug}"`, and `platform_label: "Prop Firm"`. `id`, `slug`, `title`, `english_name`, `href`, `kind` and `platform_label` identify the platform. `logo` is an approved public URL or `""`; `category` is `{id,name}` or `null` and is descriptive only. `founding_year` is a saved string or `""`. `description` is the saved summary; `excerpt` is plain text from that summary or the body, limited to 300 Unicode characters. `rating_average` and `rating_count` are currently always `null` and `0` because there is no rating source for these domains. `comment_count` is the real number of publicly visible top-level comments.

### Detail success: `GET /gold-platforms/example-gold-platform`

The detail contains every list-card key, followed by the fields below. This example includes **all** gold specification keys:

```json
{
  "data": {
    "id": 61, "kind": "gold-platforms", "slug": "example-gold-platform",
    "title": "Example Platform", "english_name": "Example Gold", "logo": "",
    "href": "/gold-platforms/example-gold-platform", "description": "Platform overview.",
    "excerpt": "Platform overview.", "category": {"id": 4, "name": "Gold Services"},
    "founding_year": "1400", "platform_label": "Gold Platform",
    "rating_average": null, "rating_count": 0, "comment_count": 2,
    "website_url": "https://example.com",
    "specifications": {
      "established_at": "", "head_office_address": "", "physical_branch_count": "", "years_active": "",
      "minimum_purchase": "", "maximum_purchase": "", "minimum_sale": "", "maximum_sale": "",
      "in_person_visit": "", "address_transparency": "", "active_social_media": "", "around_the_clock_support": "",
      "support_availability": "", "melted_gold_trading": "", "identity_documents": "", "dedicated_app": "",
      "physical_delivery_time": "", "physical_delivery_method": "", "asset_storage": "", "trading_fee": "",
      "registration_fee": "", "inamad": "", "virtual_business_union": "", "computer_trade_organization": ""
    },
    "content": "<h2 id=\"gold-platform-heading-1\">Review</h2><p>Complete review.</p>",
    "outline": [{"id": "gold-platform-heading-1", "title": "Review", "level": 2}],
    "meta_description": "", "seo_description": "Platform overview.",
    "created_at": "2026-10-20T08:00:00.000000Z", "updated_at": "2026-10-22T08:00:00.000000Z",
    "published_at": "2026-10-21T08:00:00.000000Z"
  },
  "request_id": "req_example"
}
```

The prop-firm detail has the same fields, using `prop-firm-heading-{ordinal}` IDs and the following complete **23-key** specification object:

```json
{
  "established_at": "", "head_office_address": "", "physical_branch_count": "", "years_active": "",
  "minimum_deposit": "", "maximum_withdrawal": "", "in_person_visit": "", "address_transparency": "",
  "active_social_media": "", "around_the_clock_support": "", "support_availability": "", "trial_account": "",
  "identity_documents": "", "dedicated_app": "", "evaluation_type": "", "daily_loss_limit": "",
  "total_loss_limit": "", "payout_rules": "", "trading_fee": "", "registration_fee": "",
  "inamad": "", "virtual_business_union": "", "computer_trade_organization": ""
}
```

Every specification value is a string; `""` means unspecified. `website_url` may be empty. `content` is the complete sanitized display HTML. Body H1 headings are presented as H2 without changing the saved body. `outline` contains one `{id,title,level}` entry per H2/H3 in order, with unique deterministic IDs even for repeated titles. `meta_description` is the saved override, possibly empty; `seo_description` uses the override or the excerpt. Dates are editorial events, not verification of the platform's claims.

### Other-platform success

`GET /prop-firms/{slug}/others` returns the same complete card schema as a directory entry:

```json
{"data": [], "request_id": "req_example"}
```

The array is empty if no eligible alternatives exist and contains at most nine entries. Detail errors are `404 GOLD_PLATFORM_NOT_FOUND` or `404 PROP_FIRM_NOT_FOUND`; source failures are `503 GOLD_PLATFORMS_UNAVAILABLE` or `503 PROP_FIRMS_UNAVAILABLE`.

## Bonuses

| Request | Purpose |
| --- | --- |
| `GET /bonuses` | Bonus archive and facets |
| `GET /bonuses/{slug}` | Public bonus details |
| `GET /bonuses/{slug}/latest` | Up to five other latest bonuses |
| `GET /bonuses/{bonus_id}/rating` | Personal rating and CSRF context |
| `PUT /bonuses/{bonus_id}/rating` | Create or change personal rating |

The first three lookups use a **slug**, including a slug consisting only of digits. The rating endpoint uses the numeric **ID**, not the slug. Archive parameters are `q`, `category`, `page`, `per_page`; there is no sort or campaign-state filter. Ordering is `published_at DESC, id DESC`, independent of homepage selections. Search checks title, saved description or derived excerpt, safe public provider names, and amount. Category is an immutable public key. Missing, empty, `all`, unknown, deleted, structured, or repeated category becomes All and `filters.category` is `null`. Facets are calculated after search and before category selection, include all live categories even at zero, and count records without a live category as uncategorized.

### Archive success: `GET /bonuses`

```json
{
  "data": [{
    "id": 81, "slug": "campaign-overview", "title": "Campaign Overview",
    "href": "/bonuses/campaign-overview", "description": "Campaign terms.", "excerpt": "Campaign terms.",
    "cover_image": "", "category": {"id": 4, "name": "Campaigns", "key": "category-4"},
    "reading_minutes": 5, "published_at": "2026-10-24T08:00:00.000000Z",
    "campaign": {"provider": {"exchange_id": 31, "name": "Provider", "english_name": "Provider", "logo": ""}, "amount_display": "100 USDT", "state": "ended"},
    "rating": 4.5, "rating_count": 2, "rating_revision": 2
  }],
  "meta": {"page": 1, "per_page": 9, "total": 1, "total_pages": 1},
  "filters": {"q": "", "category": null},
  "facets": {"all_count": 1, "uncategorized_count": 0, "categories": [{"id": 4, "name": "Campaigns", "key": "category-4", "count": 1}]},
  "request_id": "req_example"
}
```

`description` is the stored optional summary; `excerpt` is plain text of at most 300 Unicode characters, derived from description or body. `cover_image` is a public image URL or `""`; display the frontend's neutral fallback for empty values. `reading_minutes` is a positive integer. `category` may be `null`. `campaign.provider` may be `null`; an independently retained historical provider has `exchange_id: null`. A private or deleted exchange is never exposed as a public provider. Provider `name`, `english_name`, and `logo` are strings, with empty logo permitted. `amount_display` is a saved string or `""`; do not infer an amount. `state` is `ended` or `unspecified` and is independent of editorial publication. Both states can be rated and commented on while the parent is public.

`rating` is the actual average from 1 to 5 or `null`, `rating_count` is the actual vote count, and `rating_revision` increases only on a real vote change. Prefer the highest revision when merging asynchronous aggregate responses. Public cards do not contain `my_rating`, a voter ID, CSRF tokens, author identity, or administrative fields. The homepage uses the same summary shape.

### Detail success: `GET /bonuses/campaign-overview`

```json
{
  "data": {
    "id": 81, "slug": "campaign-overview", "title": "Campaign Overview", "href": "/bonuses/campaign-overview",
    "description": "Campaign terms.", "excerpt": "Campaign terms.", "cover_image": "",
    "category": {"id": 4, "name": "Campaigns", "key": "category-4"}, "reading_minutes": 5,
    "published_at": "2026-10-24T08:00:00.000000Z",
    "campaign": {"provider": {"exchange_id": 31, "name": "Provider", "english_name": "Provider", "logo": ""}, "amount_display": "100 USDT", "state": "ended"},
    "rating": 4.5, "rating_count": 2, "rating_revision": 2,
    "content": "<h2 id=\"article-heading-1\">Terms</h2><p>Complete conditions.</p>",
    "updated_at": "2026-10-25T08:00:00.000000Z", "meta_description": "", "seo_description": "Campaign terms.",
    "tags": ["Provider", "Campaigns"], "comment_count": 3
  },
  "request_id": "req_example"
}
```

`content` is sanitized authored HTML with body H1 normalized to H2; render only with the application's trusted HTML policy. `meta_description` preserves the saved override, while `seo_description` falls back to the excerpt. `tags` are ordered, deduplicated nonempty safe provider name, English name and live category name. `comment_count` counts visible top-level comments. The `latest` response is `{ "data": [<bonus summary>, ...], "request_id": "..." }`, up to five newest public bonuses excluding the resolved parent; an empty array is valid.

### Personal rating read and write

`GET /bonuses/81/rating` returns `200`, sets a secure HttpOnly voter cookie when needed, and returns:

```json
{
  "data": {"bonus_id": 81, "rating": null, "rating_count": 0, "rating_revision": 0, "my_rating": null,
    "csrf_token": "<opaque-token>", "csrf_expires_at": "2026-10-24T09:15:00.000000Z"},
  "request_id": "req_example"
}
```

Keep the cookie with browser credentials. Send the `csrf_token` from this read as `X-CSRF-Token` on `PUT /bonuses/81/rating`, with `Content-Type: application/json` and the complete body `{"value":5}`. The value must be an integer 1–5. The token expires after at most 15 minutes; read again if it expires or the context is invalid. Successful write:

```json
{"data": {"bonus_id": 81, "my_rating": 5, "rating": 5, "rating_count": 1, "rating_revision": 1}, "request_id": "req_example"}
```

A repeat of the same vote leaves the revision unchanged. A changed vote replaces the visitor's previous vote. The context cookie lasts up to one year and is scoped to `/api/v1/bonuses`; clearing it loses that browser's personal voting context. Personalized responses must never be shared-cache entries. Rating has its existing independent per-IP and per-context 15-minute limits. A missing/private bonus returns `404 BONUS_NOT_FOUND`; invalid CSRF/context returns `403`, and throttling returns `429` plus `Retry-After`.

## Comments and error responses

Use existing `GET/POST /comments/{section}/{parent_id}` with `section` equal to `gold-platforms`, `prop-firms`, or singular `bonus`, and the numeric parent ID. Comments and replies are public projections; email and administrative identities are never returned. POST requires the existing `Idempotency-Key` and strict `{name,email,text}` contract. See [the consolidated public API guide](public-website-api.md) for complete comment response and idempotency examples.

Errors share this envelope, with a matching `X-Request-ID` header:

```json
{"error": {"code": "VALIDATION_FAILED", "message": "The given data was invalid.", "fields": {"page": ["The page field is invalid."]}}, "request_id": "req_example"}
```

`fields` is an object and is empty when no field-specific errors apply. `422 VALIDATION_FAILED` covers invalid queries; detail/list source failures return their domain's `503` code, including `BONUSES_UNAVAILABLE` for bonus editorial reads. `429 RATE_LIMITED` includes `Retry-After`. The existing personalized rating source-error behavior is unchanged. Unknown or withdrawn bonus slugs return `404 BONUS_NOT_FOUND`. Do not expose internal exception text to users or treat a service failure as an empty list.

## Deployment notes

Deploy with the existing dependencies and run `php artisan public-directories:rebuild-search --no-interaction` before activating these directories to synchronize existing derived search and Persian sort keys. No new migration or rating system is introduced. Existing outbox delivery retries independently of successful content/vote writes; delivery to the frontend requires the configured webhook receiver and DNS to be available.
