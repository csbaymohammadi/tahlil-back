# Public News and Directories API — Standalone Frontend Handoff

**Contract date:** 2026-10-27

**Backend:** Laravel 13 / PHP 8.3

**Base path:** `/api/v1`

**Domains:** News, cryptocurrency exchanges, forex brokers, stock brokerages

This standalone document describes the four implemented public domains and their 14 new GET endpoints. It includes complete populated/empty/error examples, field meanings, all specification mappings, pagination/filter rules, aliases/legacy routing, standalone TypeScript types, and shared frontend dependencies. It describes the current code contract; verify deployment/backfill completion before enabling the frontend against a server.

Examples contain illustrative records, media IDs, request IDs and destinations. They are not production fixtures, real credentials or a request to seed records. All technical prose is in English according to repository conventions; Persian values demonstrate UTF-8 transport. Frontend rendering, SSR, metadata, navigation, query caching and XML generation remain frontend responsibilities.

## Contents

- [1. API scope and endpoint inventory](#1-api-scope-and-endpoint-inventory)
- [2. Shared HTTP and transport](#2-shared-http-and-transport)
- [3. Public news](#3-public-news)
- [4. Shared directory behavior](#4-shared-directory-behavior)
- [5. Cryptocurrency exchanges](#5-cryptocurrency-exchanges)
- [6. Forex brokers](#6-forex-brokers)
- [7. Stock brokerages and legacy routes](#7-stock-brokerages-and-legacy-routes)
- [8. News and directory errors, types, and deployment](#8-news-and-directory-errors-types-and-deployment)
- [9. Existing public comment integration](#9-existing-public-comment-integration)
- [10. Frontend cache revalidation receiver](#10-frontend-cache-revalidation-receiver)
- [11. Frontend dependencies, request examples and acceptance](#11-frontend-dependencies-request-examples-and-acceptance)

## 1. API scope and endpoint inventory

Configure the API service origin per environment. All endpoint paths below are relative to `/api/v1`. `href`/`target_path` values are root-relative **website** routes, while API redirect Location values point to API routes. Never join website hrefs against the backend origin by accident. IDs are stable within their typed domain; a news ID, forex ID and stock ID with the same number are unrelated.

| Method | Path under `/api/v1` | Successful response | Purpose |
| --- | --- | --- | --- |
| GET | `/news/articles` | 200 array + meta + facets | Six-item news archive |
| GET | `/news/featured` | 200 array | Up to four newest news stories |
| GET | `/news/articles/{slug}` | 200 object; alias 301 | Complete news story |
| GET | `/news/articles/{slug}/latest` | 200 array; alias 301 | Up to five latest other news stories |
| GET | `/exchanges` | 200 array + meta + filters + facets | Nine-item exchange directory |
| GET | `/exchanges/{slug}` | 200 object; alias 301 | Complete exchange review |
| GET | `/exchanges/{slug}/others` | 200 array; alias 301 | Up to nine other exchanges |
| GET | `/brokers` | 200 array + meta + filters + facets | Nine-item forex directory |
| GET | `/brokers/{slug}` | 200 object; alias 301 | Complete forex review |
| GET | `/brokers/{slug}/others` | 200 array; alias 301 | Up to nine other forex brokers |
| GET | `/stock-brokers` | 200 array + meta + filters | Nine-item stock directory |
| GET | `/stock-brokers/{slug}` | 200 object; alias 301 | Complete stock brokerage review |
| GET | `/stock-brokers/{slug}/others` | 200 array; alias 301 | Up to nine other stock brokerages |
| GET | `/stock-brokers/legacy-routes/resolve` | 200 source/target object | One explicit legacy website-route lookup |

GET routes also support HEAD with no body. Browser preflight returns 204 with no JSON. No content mutation, rating submission, live trading, ingestion, account opening or share-count endpoint is added here. Shared comment GET/POST operations are existing dependencies documented in section 9. Page settings and latest-blog dependencies are summarized in section 11.

## 2. Shared HTTP and transport

### 2.1 Anonymous requests and headers

- Use `Accept: application/json`. GETs have no body and do not need `Content-Type`.
- Do not attach a panel token, panel CSRF header, visitor ID, or account ID. Use browser credentials omitted / Axios `withCredentials: false` for these APIs.
- Browser GET requests and comment submissions accept any Origin, or no Origin. The browser sets Origin automatically: do not try to set it with frontend JavaScript.
- SSR and server-side comment submissions need no Origin header. Comment validation, body limits, idempotency and rate limits still apply.
- Comment POST additionally requires JSON content type and a random UUID v4 `Idempotency-Key`.
- All implemented JSON success/error envelopes include `request_id`; `X-Request-ID` carries the same correlation value. Never use it as content identity, cache identity, or an idempotency key.
- Strings are UTF-8. IDs and counts are JSON numbers, not localized strings. Positive record IDs use JavaScript's safe integer range `1..9007199254740991`.
- Datetimes are UTC ISO 8601 strings, normally including fractional seconds and `Z`. Do not depend on a fixed number of fractional digits. Format visible dates for `Asia/Tehran` and the Persian calendar in the frontend.
- Timestamp fields currently serialize `null` if no underlying timestamp exists. Normal newly published records have a publication time; legacy/absent values must not crash the client or be replaced with today's date.
- Ordinary text is rendered escaped. Only news/review detail `content` is intended for the controlled rich-text HTML renderer.

A shared CORS policy in `config/cors.php` covers `api/*` and `media/*`. It accepts all origins, requested methods and headers. With a browser Origin, responses reflect that exact Origin and include `Access-Control-Allow-Credentials: true`; endpoint routing still limits which methods actually exist. Exposed headers are `ETag`, `X-Request-ID`, `Retry-After`, `Location`, `Content-Range`, `Accept-Ranges`, `Content-Length`, and `Content-Disposition`. Public APIs need no cookies; continue omitting credentials. Avoid unnecessary custom headers or a global JSON Content-Type on GET requests.

Browser preflight requests return 204 before session, CSRF, comment creation or voter-context initialization. Requested methods and headers are reflected; the preflight cache lifetime is 600 seconds. Actual comment POST requests still require JSON and `Idempotency-Key`. Responses without an Origin do not need CORS headers. The old `PRESENTATION_ALLOWED_ORIGINS`, `ANALYTICS_ALLOWED_ORIGINS`, and `BONUS_RATING_ALLOWED_ORIGINS` settings are no longer used.

### 2.2 Success and error envelopes

Success is `data` plus endpoint-specific `meta`, `facets`, or `filters`, followed by `request_id`. There is no `success`, `status`, `result`, or nested `data.data` wrapper. Most arrays are directly at `data`.

Errors use exactly this envelope; `fields` is an object, including when empty:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "page": [
        "The page field must be at least 1."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Treat `error.code` and `error.fields` keys as machine-readable. Messages can differ by validation path, localization, or framework translation. Do not branch on the English message. Field error values are arrays of messages. Header/target errors can use `id`, `section`, `idempotency_key`, `query`, `cursor`, or another documented input name; unknown submitted field names may also appear as error keys. Display messages as text and never interpolate submitted data into HTML.

`301`, `304`, HEAD, and successful `204 OPTIONS` are bodyless. Never call `response.json()` for those responses. Native browser HTTP clients usually follow a 301 before application code receives the final response.

### 2.3 Cache, limits and non-JSON outcomes

All 14 GET endpoints use the shared configured default limit of 300 reads per minute per source. A 429 includes a positive `Retry-After` header. Successful reads carry `Cache-Control: no-cache, must-revalidate`; errors carry `no-store`. Symfony may append `private` or reorder directives. Public reads do not vary on panel Cookie. Existing comment POST responses are no-store; comment writes have their own limits in section 9.

No ETag/304 contract is added to these 14 endpoints. Owned aliases return 301 with an empty body and a direct API Location; OPTIONS returns 204 and HEAD returns no body. Do not call a JSON parser on those empty-body statuses. A missing/private record uses a domain-specific 404, whereas a nonexistent endpoint or unsupported HTTP method within these implemented API path families uses `NOT_FOUND` with 404/405. A 405 can include `Allow`.

404/405 — complete route-level error, distinct from a domain lookup:

```json
{
  "error": {
    "code": "NOT_FOUND",
    "message": "The endpoint was not found.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

500 — complete unexpected failure response; never substitute successful empty data:

```json
{
  "error": {
    "code": "INTERNAL_ERROR",
    "message": "An unexpected error occurred.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

## 3. Public news

News is a separate typed domain from blog, including coincident IDs/categories/slugs. Only Published, non-deleted stories are public. A due Scheduled story remains private until the publication worker commits its transition. An administrator cookie cannot enable public preview.

### 3.1 Archive: `GET /news/articles`

| Parameter | Validation / default |
| --- | --- |
| `q` | Optional trimmed valid UTF-8 string, max 200 Unicode characters; empty means no search |
| `category_id` | Optional positive safe integer selecting a live news category; no `all`, key, null string or foreign taxonomy |
| `page` | Positive safe integer, default 1 |
| `per_page` | Exactly 6; omission defaults to 6 |

Unknown, repeated, structured or malformed parameters return 422. A deleted/unknown category is a `category_id` error; it never silently selects All. A blog category with the same numeric ID has no effect on news taxonomy membership.

Search independently matches title, explicit description (including text beyond the returned excerpt), or the derived 300-character excerpt when description is empty, and the current live category name. Normalize Arabic `ي/ى/ك` into Persian `ی/ی/ک`, remove whitespace/half-spaces and compare case-insensitively. Joined and spaced forms match. `%`, `_`, `!` and other text have literal meaning. Blog keeps its existing separate whitespace-collapsing rules.

Order is first `published_at DESC, id DESC`; edits do not advance first publication. Facets use search matches before the chosen category; list total additionally applies category. All live categories are returned, including zero counts, ordered by normalized name then ID. The frontend owns virtual All; Uncategorized is a count, not another editable category. `all_count` equals ordinary counts plus `uncategorized_count`.

200 — one matching news story, including complete lightweight summary:

```json
{
  "data": [
    {
      "id": 21,
      "slug": "digital-asset-platform-update",
      "title": "گزارش تازه بازار",
      "href": "/news/digital-asset-platform-update",
      "cover_image": "",
      "description": "خلاصهٔ ذخیره‌شدهٔ خبر.",
      "excerpt": "خلاصهٔ ذخیره‌شدهٔ خبر.",
      "category": {
        "id": 4,
        "name": "گزارش بازار",
        "key": "category-4"
      },
      "author": {
        "id": 7,
        "name": "نویسنده عمومی"
      },
      "reading_minutes": null,
      "published_at": "2026-10-24T08:00:00.000000Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 6,
    "total": 1,
    "total_pages": 1
  },
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 4,
        "name": "گزارش بازار",
        "key": "category-4",
        "count": 1
      },
      {
        "id": 5,
        "name": "بدون نتیجه",
        "key": "category-5",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

200 — no matches; live categories remain in facets with zero counts:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 6,
    "total": 0,
    "total_pages": 0
  },
  "facets": {
    "all_count": 0,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 4,
        "name": "گزارش بازار",
        "key": "category-4",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

200 — beyond the last page, with one matching story in the dataset:

```json
{
  "data": [],
  "meta": {
    "page": 99,
    "per_page": 6,
    "total": 1,
    "total_pages": 1
  },
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 4,
        "name": "گزارش بازار",
        "key": "category-4",
        "count": 1
      },
      {
        "id": 5,
        "name": "بدون نتیجه",
        "key": "category-5",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

The frontend must refetch the last valid page before showing its range. Do not relabel empty page 99 as page 1. A zero-result dataset has `total_pages=0` and logical frontend page 1.

### 3.2 News summary and detail fields

| Field | Wire type / meaning |
| --- | --- |
| `id`, `slug`, `title`, `href` | Numeric stable news identity, current slug, saved title, root-relative canonical news path |
| `cover_image` | Valid public media reference or `""`; use a neutral site fallback |
| `description` | Saved optional summary, preserved as stored; `""` remains empty |
| `excerpt` | Plain text, max 300 Unicode characters; saved summary or derived body fallback |
| `category` | `{id,name,key}` or null; key is `category-{id}` |
| `author` | `{id,name}` or null, following the shared public-account policy |
| `reading_minutes` | Integer 1–1440 or null; never estimate a fixed default |
| `published_at` | First-publication UTC timestamp, nullable for historical unknown dates |

Featured/latest/archive/homepage news use this same summary projection. Full body, biography and comment count are detail-only fields.

### 3.3 Featured: `GET /news/featured`

Accept no parameters or body. Return up to four distinct newest eligible stories under the same first-publication order. This endpoint ignores homepage selection and `NEWS_FEATURED_IDS`; archive filters do not change it. Empty is `data: []`.

200 — complete featured response with fewer than four available stories:

```json
{
  "data": [
    {
      "id": 21,
      "slug": "digital-asset-platform-update",
      "title": "گزارش تازه بازار",
      "href": "/news/digital-asset-platform-update",
      "cover_image": "",
      "description": "خلاصهٔ ذخیره‌شدهٔ خبر.",
      "excerpt": "خلاصهٔ ذخیره‌شدهٔ خبر.",
      "category": {
        "id": 4,
        "name": "گزارش بازار",
        "key": "category-4"
      },
      "author": {
        "id": 7,
        "name": "نویسنده عمومی"
      },
      "reading_minutes": null,
      "published_at": "2026-10-24T08:00:00.000000Z"
    }
  ],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

### 3.4 Detail: `GET /news/articles/{slug}`

Resolve current slug or owned formerly public alias in the news namespace. Missing, Draft, Scheduled or deleted records yield `404 NEWS_NOT_FOUND`. A current public record returns the summary plus the fields shown below. This lookup is shared by website rendering and metadata.

200 — complete news detail response:

```json
{
  "data": {
    "id": 21,
    "slug": "digital-asset-platform-update",
    "title": "گزارش تازه بازار",
    "href": "/news/digital-asset-platform-update",
    "cover_image": "",
    "description": "خلاصهٔ ذخیره‌شدهٔ خبر.",
    "excerpt": "خلاصهٔ ذخیره‌شدهٔ خبر.",
    "category": {
      "id": 4,
      "name": "گزارش بازار",
      "key": "category-4"
    },
    "author": {
      "id": 7,
      "name": "نویسنده عمومی"
    },
    "reading_minutes": null,
    "published_at": "2026-10-24T08:00:00.000000Z",
    "content": "<h2 id=\"news-heading-1\">تغییرات</h2><p>متن کامل گزارش.</p><h3 id=\"news-heading-2\">جزئیات</h3><p>اطلاعات تکمیلی.</p>",
    "outline": [
      {
        "id": "news-heading-1",
        "title": "تغییرات",
        "level": 2
      },
      {
        "id": "news-heading-2",
        "title": "جزئیات",
        "level": 3
      }
    ],
    "updated_at": "2026-10-24T09:00:00.000000Z",
    "meta_description": "",
    "seo_description": "خلاصهٔ ذخیره‌شدهٔ خبر.",
    "author_profile": {
      "id": 7,
      "name": "نویسنده عمومی",
      "avatar_url": null,
      "bio": "دربارهٔ نویسنده.",
      "linkedin_url": ""
    },
    "tags": [
      "گزارش بازار"
    ],
    "comment_count": 0
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

`content` is complete safe display HTML, including supported tables/media/formatting. Body H1 becomes H2 in this display copy. `outline` contains generated `{id,title,level}` entries in document order; levels are 2/3 and IDs are `news-heading-{ordinal}`. Repeated headings receive distinct IDs. Empty body headings give `outline: []`. Rendering never overwrites the saved editor content.

`meta_description` is the saved override or `""`; `seo_description` is plain text using that override first, otherwise the summary excerpt, max 500 characters. `updated_at` is the editorial UTC update timestamp, not an independent fact-verification date. `author_profile` is null or `{id,name,avatar_url,bio,linkedin_url}`; missing avatar is null and optional text is `""`. `author` and `author_profile` refer to the same account. `tags` is the one live category name or `[]`. `comment_count` counts visible top-level news comments, excluding hidden/deleted comments and replies. Private email, login, roles, version, status, schedule and deletion data are excluded.

A formerly public alias returns 301 with an empty body and `Location: /api/v1/news/articles/{current_slug}`. Its latest lookup redirects directly to `/api/v1/news/articles/{current_slug}/latest`. Withdrawn aliases return the same 404 as private current slugs. Frontend website aliases must emit their own 301 to `/news/{current_slug}` even when an HTTP client automatically followed the API redirect.

### 3.5 Latest: `GET /news/articles/{slug}/latest`

Resolve and check the parent before listing anything. Accept no parameters/body. Exclude the current typed news ID, then select up to five newest eligible stories by `published_at DESC, id DESC`, with no category preference. Fewer records and `data: []` are valid. This does not return blog articles.

200 — complete latest-news response for news ID 21:

```json
{
  "data": [
    {
      "id": 22,
      "slug": "another-public-report",
      "title": "خبر عمومی دیگر",
      "href": "/news/another-public-report",
      "cover_image": "",
      "description": "خلاصهٔ ذخیره‌شدهٔ خبر.",
      "excerpt": "خلاصهٔ ذخیره‌شدهٔ خبر.",
      "category": {
        "id": 4,
        "name": "گزارش بازار",
        "key": "category-4"
      },
      "author": {
        "id": 7,
        "name": "نویسنده عمومی"
      },
      "reading_minutes": null,
      "published_at": "2026-10-24T08:00:00.000000Z"
    }
  ],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

After comments, the separate latest-blog carousel uses `GET /blog/articles?page=1&per_page=6` without category/search filters. A blog with the same numeric ID as a news story is independent and is not excluded. Comments use `/comments/news/{id}` with the existing GET/POST contract. In-page banners come from `advertisements.news_and_articles` in page settings. Sharing is a frontend action with the canonical website URL; there is no backend share/messaging mutation.

## 4. Shared directory behavior

The three APIs below reuse authoritative panel records. Cryptocurrency exchange, forex and stock brokerage identity never crosses domains because IDs/slugs coincide. Their public routes do not expose author profiles, admin versions/status, account identity, private media or storage details. Only Published, non-deleted parents are eligible, including for aliases and dependent Other lists.

### 4.1 Inputs, ordering and facets

All three list endpoints accept `q` (trimmed valid UTF-8, max 200), `sort=default|alphabetical`, positive safe-integer `page` (default 1) and exactly `per_page=9` (omitted defaults to 9). No body or private status/ID filter is accepted. Unknown, structured or repeated non-category keys are 422.

Exchange and forex additionally accept `category`. Omitted/empty/`all`, unknown/deleted keys, repeated `category`, array/object-shaped category, malformed UTF-8 category or a value above 100 bytes select All. The response's effective `filters.category` is null in those cases. Repeated keys are detected from the original query string rather than trusting the framework's last decoded value. A category merge does not alias the old key to the destination. In forex only, a single scalar `iranian-brokers` returns a category field error; the website implements the compatibility redirect before requesting forex data. Repeated/structured category follows the fallback rule instead.

Stock brokerage lists reject `category`, `category_id`, and `kind`, and have no category facets. Its five-market navigation is frontend route navigation.

Search uses the same space-removing normalization as news. Fields: exchange = title/English name/description-or-excerpt/live category; forex = those fields plus `markets`; stock = title/English name/description-or-excerpt only. Fields match independently; SQL wildcard characters are escaped literally. An explicit saved description is fully searchable; only a body-derived excerpt is bounded to 300 characters.

Default order is `created_at DESC, id DESC`. Alphabetical order compares stored ICU `fa_IR` primary-strength binary sort keys generated from normalized public titles, then ID ascending. It is independent of ratings/comments and ordinary edits do not change creation order. Page data, counts and facets are read within a consistent snapshot; pages across separate requests are fresh reads, not one historical cursor traversal.

`filters.q` preserves trimmed display input, not its destructive matching-normalized version. `total_pages=ceil(total/9)`, zero when empty. A beyond-last page returns empty data with the requested page and correct totals. Frontend recovery refetches a valid page before showing its range.

Exchange/forex facets are search-only, before category selection, and include every live category with zero counts retained. `all_count` equals ordinary live category counts plus Uncategorized. Forex additionally returns `forex_count=all_count`; that virtual count overlaps all ordinary categories and must not be summed with them.

### 4.2 Shared values and review content

`description` remains the saved value, including `""`. `excerpt` is plain text up to 300 Unicode characters from description or full-body fallback. Do not repeat a derived excerpt as a saved introduction. Logo references are safe public media paths/URLs or `""`; do not infer a provider logo or official URL.

There is currently no rating source for these three domains. All exposed rating aggregates are explicitly `rating_average: null`, `rating_count: 0`. They are not fabricated zero/five-star ratings. There is no new voting endpoint or historical import. `comment_count` is the real visible top-level typed comment count and never a vote count. Forex archive summaries intentionally omit rating/comment aggregates; its details include them.

Details preserve full sanitized HTML with tables, media, lists and links. Body H1 becomes H2; generated heading IDs and outline titles/levels match exactly, with deterministic domain prefixes. Supplied conflicting IDs are replaced. The saved editorial body remains unchanged. Empty headings produce an empty body outline. Fixed overview/specifications/comments blocks and specification-group anchors remain frontend DOM responsibilities, not invented HTML sections in the API response.

Each detail returns all 24 domain-specific specification keys as strings. Missing is `""`; status values distinguish `""`, `yes`, `no`, and `not-applicable`. Preserve entered calendars, units, line breaks, currency and qualifiers. Empty never means free/no/unlimited/unregulated. Do not infer years active, license verification, account tables, fees or FAQs. Detailed tables/FAQ/account comparisons belong to authored HTML.

Public reads use the common 300-per-minute source bucket, request IDs, unrestricted CORS and `no-cache, must-revalidate`; failures use `no-store`. The APIs require no visitor login or panel CSRF. Parent visibility is authoritative regardless of cookies. See section 8 for module error codes and alias behavior.

## 5. Cryptocurrency exchanges

### 5.1 List: `GET /exchanges`

Category identity uses immutable live keys, including seeded `international` and `iranian`; new keys are `category-{id}`. Category display names are not identity. Exchange cards expose the shared homepage identity/year/type/rating/comment values plus description/excerpt/category for the directory. No response CSS, computed activity years or automatic verification is supplied.

200 — complete exchange list response:

```json
{
  "data": [
    {
      "id": 31,
      "slug": "example-exchange",
      "title": "صرافی نمونه",
      "english_name": "Example Exchange",
      "logo": "/media/142/logo.png",
      "href": "/exchanges/example-exchange",
      "description": "",
      "excerpt": "بررسی کامل صرافی.",
      "category": {
        "id": 1,
        "name": "صرافی بین‌المللی",
        "key": "international"
      },
      "founding_year": "2022",
      "platform_label": "Centralized exchange",
      "rating_average": null,
      "rating_count": 0,
      "comment_count": 0
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "category": null,
    "sort": "default"
  },
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 1,
        "name": "صرافی بین‌المللی",
        "key": "international",
        "count": 1
      },
      {
        "id": 2,
        "name": "صرافی ایرانی",
        "key": "iranian",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

200 — no matching exchanges:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 0,
    "total_pages": 0
  },
  "filters": {
    "q": "absent",
    "category": null,
    "sort": "default"
  },
  "facets": {
    "all_count": 0,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 1,
        "name": "صرافی بین‌المللی",
        "key": "international",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

200 — `category=deleted-key` falls back to All and returns the effective filter:

```json
{
  "data": [
    {
      "id": 31,
      "slug": "example-exchange",
      "title": "صرافی نمونه",
      "english_name": "Example Exchange",
      "logo": "/media/142/logo.png",
      "href": "/exchanges/example-exchange",
      "description": "",
      "excerpt": "بررسی کامل صرافی.",
      "category": {
        "id": 1,
        "name": "صرافی بین‌المللی",
        "key": "international"
      },
      "founding_year": "2022",
      "platform_label": "Centralized exchange",
      "rating_average": null,
      "rating_count": 0,
      "comment_count": 0
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "category": null,
    "sort": "default"
  },
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 1,
        "name": "صرافی بین‌المللی",
        "key": "international",
        "count": 1
      },
      {
        "id": 2,
        "name": "صرافی ایرانی",
        "key": "iranian",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

### 5.2 Detail: `GET /exchanges/{slug}`

The following example is the complete detail, not a detail-only fragment. `founding_year` maps stored `founded_year`; `platform_label` maps stored `platform_type`. Quick-fact strings `markets`, `access`, `support`, and the official `website_url` are returned exactly for this exchange. `meta_description` is the saved override; `seo_description` uses it first, otherwise description/excerpt. Timestamps are UTC and historical unknown dates may be null. Last update is editorial, not a regulatory verification date.

200 — complete exchange review, including every specification key:

```json
{
  "data": {
    "id": 31,
    "slug": "example-exchange",
    "title": "صرافی نمونه",
    "english_name": "Example Exchange",
    "logo": "/media/142/logo.png",
    "href": "/exchanges/example-exchange",
    "description": "",
    "excerpt": "بررسی کامل صرافی.",
    "category": {
      "id": 1,
      "name": "صرافی بین‌المللی",
      "key": "international"
    },
    "founding_year": "2022",
    "platform_label": "Centralized exchange",
    "rating_average": null,
    "rating_count": 0,
    "comment_count": 0,
    "website_url": "https://example.org/",
    "specifications": {
      "established_at": "",
      "head_office_address": "",
      "physical_branch_count": "",
      "years_active": "",
      "minimum_purchase": "",
      "maximum_purchase": "",
      "minimum_sale": "",
      "maximum_sale": "",
      "in_person_visit": "",
      "address_transparency": "",
      "active_social_media": "",
      "around_the_clock_support": "",
      "support_availability": "",
      "spot_trading": "yes",
      "futures_trading": "no",
      "identity_documents": "",
      "dedicated_app": "",
      "deposit_methods": "",
      "withdrawal_conditions": "",
      "trading_fee": "",
      "registration_fee": "",
      "inamad": "not-applicable",
      "virtual_business_union": "",
      "computer_trade_organization": ""
    },
    "content": "<h2 id=\"exchange-heading-1\">بررسی</h2><p>متن کامل بررسی.</p><h3 id=\"exchange-heading-2\">جزئیات</h3><table><tbody><tr><td>اطلاعات تحریریه</td></tr></tbody></table>",
    "outline": [
      {
        "id": "exchange-heading-1",
        "title": "بررسی",
        "level": 2
      },
      {
        "id": "exchange-heading-2",
        "title": "جزئیات",
        "level": 3
      }
    ],
    "meta_description": "",
    "seo_description": "بررسی کامل صرافی.",
    "created_at": "2026-10-22T08:00:00.000000Z",
    "updated_at": "2026-10-24T09:00:00.000000Z",
    "published_at": "2026-10-24T08:00:00.000000Z",
    "markets": "Spot and futures",
    "access": "Web and mobile",
    "support": ""
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

Specification mapping, in frontend group order:

| Group | Exact flat keys |
| --- | --- |
| General | `established_at`, `head_office_address`, `physical_branch_count`, `years_active`, `minimum_purchase`, `maximum_purchase`, `minimum_sale`, `maximum_sale` |
| Features | `in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `spot_trading`, `futures_trading` |
| Conditions | `identity_documents`, `dedicated_app`, `deposit_methods`, `withdrawal_conditions` |
| Fees | `trading_fee`, `registration_fee` |
| Licenses | `inamad`, `virtual_business_union`, `computer_trade_organization` |

The feature fields, `dedicated_app` and three license fields use the four-state string vocabulary. Other values are editorial text. `established_at` is calendar text, not an event timestamp. A frontend may show the separate founding-year fallback when empty without persisting it. Use actual group anchors such as `exchange-specifications-fees`; body anchors use `exchange-heading-{ordinal}`.

### 5.3 Others: `GET /exchanges/{slug}/others`

Check the parent first, accept no filters/body, exclude current exchange ID, and return up to nine eligible exchanges under the default creation order. There is no same-category preference or personalized recommendation. Empty is `data: []`; hide the carousel.

200 — complete Other Exchanges response for exchange ID 31:

```json
{
  "data": [
    {
      "id": 32,
      "slug": "other-exchange",
      "title": "صرافی دیگر",
      "english_name": "Example Exchange",
      "logo": "/media/142/logo.png",
      "href": "/exchanges/other-exchange",
      "description": "",
      "excerpt": "بررسی کامل صرافی.",
      "category": {
        "id": 1,
        "name": "صرافی بین‌المللی",
        "key": "international"
      },
      "founding_year": "2022",
      "platform_label": "Centralized exchange",
      "rating_average": null,
      "rating_count": 0,
      "comment_count": 0
    }
  ],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

Use `/comments/exchanges/{id}` for persistent comments and `advertisements.news_and_articles` for in-page banners. Official actions use the saved website URL for every provider; no slug-specific referral link or server-side registration proxy is added.

## 6. Forex brokers

### 6.1 List: `GET /brokers`

This API contains only forex records. Every eligible broker has virtual `filter_keys: ["forex"]`, plus its live primary category key when present. `forex-brokers` is a stored category distinct from virtual `forex`. `international-brokers` membership requires explicit category assignment and is not inferred from names/domains/language. Uncategorized brokers still belong to virtual Forex. `filters.category` is null, `forex`, or a live key.

200 — complete forex list response for `category=forex`:

```json
{
  "data": [
    {
      "id": 41,
      "slug": "example-broker",
      "title": "بروکر نمونه",
      "english_name": "Example Broker",
      "logo": "/media/151/logo.png",
      "href": "/brokers/example-broker",
      "description": "",
      "excerpt": "بررسی کامل بروکر.",
      "category": {
        "id": 2,
        "name": "بروکر بین‌المللی",
        "key": "international-brokers"
      },
      "filter_keys": [
        "forex",
        "international-brokers"
      ],
      "markets": "Forex and CFDs"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "category": "forex",
    "sort": "default"
  },
  "facets": {
    "all_count": 1,
    "forex_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 1,
        "name": "بروکر فارکس",
        "key": "forex-brokers",
        "count": 0
      },
      {
        "id": 2,
        "name": "بروکر بین‌المللی",
        "key": "international-brokers",
        "count": 1
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

200 — no matching forex brokers, retaining zero categories and virtual count:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 0,
    "total_pages": 0
  },
  "filters": {
    "q": "absent",
    "category": null,
    "sort": "default"
  },
  "facets": {
    "all_count": 0,
    "forex_count": 0,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 2,
        "name": "بروکر بین‌المللی",
        "key": "international-brokers",
        "count": 0
      }
    ]
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

### 6.2 Detail: `GET /brokers/{slug}`

Includes the complete summary plus official URL, saved `founded_year`, top-level `platforms/access/account_currency`, all specifications, safe HTML/outline, numeric aggregates, saved metadata override, derived SEO description and three UTC dates. Top-level platforms/currency and nested specifications remain independent. There is no exchange-style top-level `platform_type` or `support` field, and no author profile.

200 — complete forex detail with intentionally different top-level and nested currencies:

```json
{
  "data": {
    "id": 41,
    "slug": "example-broker",
    "title": "بروکر نمونه",
    "english_name": "Example Broker",
    "logo": "/media/151/logo.png",
    "href": "/brokers/example-broker",
    "description": "",
    "excerpt": "بررسی کامل بروکر.",
    "category": {
      "id": 2,
      "name": "بروکر بین‌المللی",
      "key": "international-brokers"
    },
    "filter_keys": [
      "forex",
      "international-brokers"
    ],
    "markets": "Forex and CFDs",
    "website_url": "https://example.org/",
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
      "copy_trading": "yes",
      "identity_documents": "",
      "dedicated_app": "",
      "trading_platform": "WebTrader",
      "account_types": "",
      "account_currency": "EUR",
      "trading_fee": "",
      "registration_fee": "",
      "spread": "",
      "inamad": "",
      "virtual_business_union": "",
      "computer_trade_organization": "",
      "regulator": "Editorial regulator description"
    },
    "content": "<h2 id=\"broker-heading-1\">بررسی</h2><p>متن کامل بررسی.</p><h3 id=\"broker-heading-2\">جزئیات</h3><table><tbody><tr><td>اطلاعات تحریریه</td></tr></tbody></table>",
    "outline": [
      {
        "id": "broker-heading-1",
        "title": "بررسی",
        "level": 2
      },
      {
        "id": "broker-heading-2",
        "title": "جزئیات",
        "level": 3
      }
    ],
    "rating_average": null,
    "rating_count": 0,
    "comment_count": 0,
    "meta_description": "",
    "seo_description": "بررسی کامل بروکر.",
    "created_at": "2026-10-22T08:00:00.000000Z",
    "updated_at": "2026-10-24T09:00:00.000000Z",
    "published_at": "2026-10-24T08:00:00.000000Z",
    "founded_year": "2007",
    "platforms": "Web and desktop",
    "access": "Web and mobile",
    "account_currency": "USD"
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

| Group | Exact flat keys |
| --- | --- |
| General | `established_at`, `head_office_address`, `physical_branch_count`, `years_active`, `minimum_deposit`, `maximum_withdrawal` |
| Features | `in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `copy_trading` |
| Conditions | `identity_documents`, `dedicated_app`, `trading_platform`, `account_types`, `account_currency` |
| Fees | `trading_fee`, `registration_fee`, `spread` |
| Licenses | `inamad`, `virtual_business_union`, `computer_trade_organization`, `regulator` |

The six Features fields, `dedicated_app` and three license-status fields use the four-state vocabulary. `regulator` is multiline plain text, never an automatic verified flag. Keep units/pips/currency-per-lot or side/account restrictions and qualifiers. Account comparisons and FAQ remain authored HTML, not a second structured API. Body IDs use `broker-heading-{ordinal}`; group shortcuts such as `broker-specifications-fees` are frontend-owned and must target actual groups.

### 6.3 Others: `GET /brokers/{slug}/others`

Resolve the forex parent first, exclude its typed ID, return up to nine distinct eligible forex summaries in creation order, with no category preference and no stock records. Empty is `data: []`.

200 — complete Other Brokers response for broker ID 41:

```json
{
  "data": [
    {
      "id": 42,
      "slug": "other-broker",
      "title": "بروکر دیگر",
      "english_name": "Example Broker",
      "logo": "/media/151/logo.png",
      "href": "/brokers/other-broker",
      "description": "",
      "excerpt": "بررسی کامل بروکر.",
      "category": {
        "id": 2,
        "name": "بروکر بین‌المللی",
        "key": "international-brokers"
      },
      "filter_keys": [
        "forex",
        "international-brokers"
      ],
      "markets": "Forex and CFDs"
    }
  ],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

Use plural `/comments/brokers/{id}` for comments; do not reuse singular internal content kind `broker` as the comment discriminator. In-page banners use `advertisements.news_and_articles`. Forex APIs do not emit cross-market HTML redirects. The website resolves `category=iranian-brokers` with a 307 to `/stock-brokers`, and explicit relocated review mappings with a 308 through the stock lookup below. Owned forex aliases remain 301 within forex.

## 7. Stock brokerages and legacy routes

### 7.1 List: `GET /stock-brokers`

Only `q`, `sort`, `page`, and `per_page=9` are supported. Market navigation is the fixed website routes `/exchanges`, `/brokers`, `/stock-brokers`, `/gold-platforms`, `/prop-firms`; it is not a category filter. Live or Uncategorized brokerages are eligible independently of editorial category. There is no `facets` or `filters.category` in the response.

200 — complete stock directory response:

```json
{
  "data": [
    {
      "id": 51,
      "slug": "example-stock-broker",
      "title": "کارگزاری نمونه",
      "english_name": "Example Brokerage",
      "logo": "",
      "href": "/stock-brokers/example-stock-broker",
      "description": "",
      "excerpt": "بررسی کامل کارگزاری.",
      "category": {
        "id": 4,
        "name": "خدمات کارگزاری"
      },
      "kind": "stock-brokers",
      "founding_year": "",
      "platform_label": "Stock Brokerage",
      "rating_average": null,
      "rating_count": 0,
      "comment_count": 0
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "sort": "default"
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

200 — complete empty stock directory response:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 0,
    "total_pages": 0
  },
  "filters": {
    "q": "absent",
    "sort": "alphabetical"
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

`kind` is always `stock-brokers`. `founding_year` maps saved `founded_year` without calendar conversion. `platform_label` is the fixed domain label `Stock Brokerage`, which the frontend may localize; it is not an editable platform type. Editorial `category` is `{id,name}` or null, with no invented public key. Logo and website are optional, including for new published records; `""` hides the official action or uses neutral initials. Do not infer a hostname.

### 7.2 Detail: `GET /stock-brokers/{slug}`

The detail includes the complete summary plus optional `website_url`, all specifications, complete safe HTML/outline, saved metadata override, derived SEO description and UTC creation/update/first-publication dates. No top-level forex account currency/platforms/markets/support or public author profile is introduced.

200 — complete stock brokerage detail with absent logo and website:

```json
{
  "data": {
    "id": 51,
    "slug": "example-stock-broker",
    "title": "کارگزاری نمونه",
    "english_name": "Example Brokerage",
    "logo": "",
    "href": "/stock-brokers/example-stock-broker",
    "description": "",
    "excerpt": "بررسی کامل کارگزاری.",
    "category": {
      "id": 4,
      "name": "خدمات کارگزاری"
    },
    "kind": "stock-brokers",
    "founding_year": "",
    "platform_label": "Stock Brokerage",
    "rating_average": null,
    "rating_count": 0,
    "comment_count": 0,
    "website_url": "",
    "specifications": {
      "established_at": "",
      "head_office_address": "",
      "physical_branch_count": "",
      "years_active": "",
      "minimum_purchase": "",
      "maximum_purchase": "",
      "minimum_sale": "",
      "maximum_sale": "",
      "in_person_visit": "",
      "address_transparency": "",
      "active_social_media": "",
      "around_the_clock_support": "",
      "support_availability": "",
      "online_trading": "yes",
      "identity_documents": "Identity information",
      "dedicated_app": "",
      "trading_platform": "Editorial system name",
      "account_opening_documents": "Account-opening documents",
      "trading_fee": "",
      "registration_fee": "",
      "inamad": "",
      "virtual_business_union": "",
      "computer_trade_organization": "",
      "regulator": ""
    },
    "content": "<h2 id=\"stock-broker-heading-1\">بررسی</h2><p>متن کامل بررسی.</p><h3 id=\"stock-broker-heading-2\">جزئیات</h3><table><tbody><tr><td>اطلاعات تحریریه</td></tr></tbody></table>",
    "outline": [
      {
        "id": "stock-broker-heading-1",
        "title": "بررسی",
        "level": 2
      },
      {
        "id": "stock-broker-heading-2",
        "title": "جزئیات",
        "level": 3
      }
    ],
    "meta_description": "",
    "seo_description": "بررسی کامل کارگزاری.",
    "created_at": "2026-10-22T08:00:00.000000Z",
    "updated_at": "2026-10-24T09:00:00.000000Z",
    "published_at": "2026-10-24T08:00:00.000000Z"
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

| Group | Exact flat keys |
| --- | --- |
| General | `established_at`, `head_office_address`, `physical_branch_count`, `years_active`, `minimum_purchase`, `maximum_purchase`, `minimum_sale`, `maximum_sale` |
| Features | `in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `online_trading` |
| Conditions | `identity_documents`, `dedicated_app`, `trading_platform`, `account_opening_documents` |
| Fees | `trading_fee`, `registration_fee` |
| Licenses | `inamad`, `virtual_business_union`, `computer_trade_organization`, `regulator` |

The six Features values, `dedicated_app` and three license-status fields use the four-state string vocabulary. `regulator` and the other keys are editorial plain text. Identity documents and account-opening documents are independent fields. Trading platform is a saved system description, not an executable connection or terminal ID. No forex spread/currency, gold-delivery or prop-evaluation fields enter this object.

Body IDs use `stock-broker-heading-{ordinal}`. Fixed frontend blocks use distinct `overview`, `specifications`, `specifications-general`, `specifications-features`, `specifications-conditions`, `specifications-fees`, `specifications-licenses`, `comments` targets. Do not duplicate the specifications container and general-group ID. Fixed blocks are composed with real body headings by the frontend; omitted FAQ content creates no fake FAQ anchor.

### 7.3 Others: `GET /stock-brokers/{slug}/others`

Check current public stock parent, exclude current typed numeric ID, return up to nine stock summaries in default creation order, with no cross-market records or category preference. Empty is `data: []`. Comments use `/comments/stock-brokers/{id}`. There is no new stock-review in-page advertising slot beyond the shared upper/header/footer settings.

200 — complete Other Stock Brokerages response for brokerage ID 51:

```json
{
  "data": [
    {
      "id": 52,
      "slug": "other-stock-broker",
      "title": "کارگزاری دیگر",
      "english_name": "Example Brokerage",
      "logo": "",
      "href": "/stock-brokers/other-stock-broker",
      "description": "",
      "excerpt": "بررسی کامل کارگزاری.",
      "category": {
        "id": 4,
        "name": "خدمات کارگزاری"
      },
      "kind": "stock-brokers",
      "founding_year": "",
      "platform_label": "Stock Brokerage",
      "rating_average": null,
      "rating_count": 0,
      "comment_count": 0
    }
  ],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

### 7.4 Legacy lookup: `GET /stock-brokers/legacy-routes/resolve`

Require exactly one `source_path` query string: one root-relative `/brokers/{slug}` path, at most 2048 characters. Slug follows the existing lowercase Latin alphanumeric/kebab-case rule. No origin/query/fragment/encoded separator/backslash/traversal is accepted. Missing, repeated, structured or extra parameters return 422. No body, pagination, login or redirect target is accepted.

Read only an explicit mapping in the existing routing table, using stable stock-brokerage ID and current public target slug. The example below presupposes such a real mapping; the deployment does not seed `mofid` or guess mappings from coincident names/slugs. Mapped source slugs remain reserved against new forex claims. Hidden/deleted targets return the same 404 as unknown mappings, without their identity/path. Changing the stock slug changes the returned target through its stable ID.

200 — complete explicit mapping response:

```json
{
  "data": {
    "source_path": "/brokers/old-stock-name",
    "target_path": "/stock-brokers/example-stock-broker"
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

This endpoint itself returns JSON with HTTP 200; the website resolver issues the actual permanent 308 to `target_path`. For a reserved legacy source, perform this bounded lookup before a forex detail request. No mapping means ordinary forex resolution may continue; 503 must remain a service failure, not be treated as a missing mapping or permission to redirect arbitrarily. Never download the whole stock catalog or search it for coincident slugs. Forex JSON endpoints remain forex-only and return BROKER_NOT_FOUND for an unowned legacy stock slug.

## 8. News and directory errors, types, and deployment

### 8.1 Alias and collection transport

All four current detail routes return 301 for their own formerly public alias while the parent remains public. Body is empty, with `Location` pointing directly to the corresponding current API route. `/latest` and `/others` suffixes are preserved. There is no JSON to parse for 301. Private/deleted parents and aliases share their module 404. The legacy lookup remains JSON 200/404 and does not turn into an API 308.

Every featured/latest/others endpoint has the same complete empty collection envelope below (with its own request ID). It does not fabricate items to fill a layout. No pagination/meta is added to these bounded collections.

200 — empty featured, latest, or any Other collection:

```json
{
  "data": [],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

For any directory, beyond-last requests retain requested `meta.page`, empty `data`, and correct filtered `meta.total/total_pages`. Exchange/forex retain their effective filters and search-only facets; stock has no facets. The news beyond-last example in section 3 follows the same page recovery rule with page size six.

### 8.2 Complete module error examples

The HTTP status and stable code control frontend behavior. English messages are explanatory and can vary between validation/source-safety branches. Fields are an object, never an empty array. Error responses carry `X-Request-ID` matching the JSON `request_id`, and `Cache-Control: no-store` (Symfony may append `private`).

404 — NEWS_NOT_FOUND; same envelope for missing and private identity:

```json
{
  "error": {
    "code": "NEWS_NOT_FOUND",
    "message": "The public news item was not found.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

404 — EXCHANGE_NOT_FOUND; same envelope for missing and private identity:

```json
{
  "error": {
    "code": "EXCHANGE_NOT_FOUND",
    "message": "The public record was not found.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

404 — BROKER_NOT_FOUND; same envelope for missing and private identity:

```json
{
  "error": {
    "code": "BROKER_NOT_FOUND",
    "message": "The public record was not found.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

404 — STOCK_BROKER_NOT_FOUND; same envelope for missing and private identity:

```json
{
  "error": {
    "code": "STOCK_BROKER_NOT_FOUND",
    "message": "The public record was not found.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

404 — LEGACY_ROUTE_NOT_FOUND; same envelope for missing and private identity:

```json
{
  "error": {
    "code": "LEGACY_ROUTE_NOT_FOUND",
    "message": "The public legacy route was not found.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

503 — NEWS_UNAVAILABLE; database/source failure:

```json
{
  "error": {
    "code": "NEWS_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

503 — EXCHANGES_UNAVAILABLE; database/source failure:

```json
{
  "error": {
    "code": "EXCHANGES_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

503 — BROKERS_UNAVAILABLE; database/source failure:

```json
{
  "error": {
    "code": "BROKERS_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

503 — STOCK_BROKERS_UNAVAILABLE; database/source failure:

```json
{
  "error": {
    "code": "STOCK_BROKERS_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

422 — invalid/deleted news category ID:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "category_id": [
        "The selected category id is invalid."
      ]
    }
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

422 — forex-only API rejects a single scalar `category=iranian-brokers`:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "category": [
        "The selected category is invalid."
      ]
    }
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

422 — unknown/repeated/structured unsupported query key, including stock category:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Unknown, repeated or structured query parameters are not accepted.",
    "fields": {
      "query": [
        "Use documented parameters once."
      ]
    }
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

422 — malformed/missing legacy source path, such as an absolute URL:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "source_path": [
        "The source path field format is invalid."
      ]
    }
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

422 — GET endpoint received a body:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "This endpoint does not accept a body.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

429 — shared public read rate exceeded; also carries a positive `Retry-After` header:

```json
{
  "error": {
    "code": "RATE_LIMITED",
    "message": "Too many requests.",
    "fields": {}
  },
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

Oversized q/page/per_page/sort input uses 422 with field-specific arrays under `error.fields`. The legacy missing-required-field branch uses its required-field message rather than the malformed example's format message. Exchange/forex invalid ordinary category uses successful All fallback rather than these 422 bodies. Unsafe stored HTML/specifications or unavailable sort keys also use the appropriate module 503 with a safe explanatory message. No saved raw SQL, HTML, URL credentials, email or management stack trace is included in errors.

### 8.3 Complete TypeScript wire types

The following types are self-contained and describe the snake_case wire format. Convert once into the UI's camelCase names. Nullable timestamps remain nullable in runtime validation; TypeScript alone does not validate network data.

```ts
type PublicId = number;
type PublicTimestamp = string | null;
interface ApiErrorBody {
  error: {code: string; message: string; fields: Record<string, string[]>};
  request_id: string;
}
interface Success<T> { data: T; request_id: string }
interface PageMeta { page: number; per_page: number; total: number; total_pages: number }
interface Paged<T> extends Success<T[]> { meta: PageMeta }
interface Category { id: PublicId; name: string; key: string }
interface AuthorSummary { id: PublicId; name: string }
interface AuthorProfile extends AuthorSummary {
  avatar_url: string | null; bio: string; linkedin_url: string;
}
interface NewsSummary {
  id: PublicId; slug: string; title: string; href: string;
  cover_image: string; description: string; excerpt: string;
  category: Category | null; author: AuthorSummary | null;
  reading_minutes: number | null; published_at: PublicTimestamp;
}
interface NewsDetail extends NewsSummary {
  content: string; outline: {id: string; title: string; level: 2 | 3}[];
  updated_at: PublicTimestamp; meta_description: string; seo_description: string;
  author_profile: AuthorProfile | null; tags: string[]; comment_count: number;
}
interface NewsFacets {
  all_count: number; uncategorized_count: number;
  categories: (Category & {count: number})[];
}
interface NewsListResponse extends Paged<NewsSummary> { facets: NewsFacets }
type NewsFeaturedResponse = Success<NewsSummary[]>;
type NewsDetailResponse = Success<NewsDetail>;
type NewsLatestResponse = Success<NewsSummary[]>;
type DirectorySort = 'default' | 'alphabetical';
interface EditorialCategory { id: PublicId; name: string }
interface RatingCounts {
  rating_average: null; rating_count: 0; comment_count: number;
}
interface DirectoryBase {
  id: PublicId; slug: string; title: string; english_name: string;
  logo: string; href: string; description: string; excerpt: string;
}
interface ExchangeSummary extends DirectoryBase, RatingCounts {
  category: Category | null; founding_year: string; platform_label: string;
}
interface BrokerSummary extends DirectoryBase {
  category: Category | null; filter_keys: string[]; markets: string;
}
interface StockBrokerSummary extends DirectoryBase, RatingCounts {
  category: EditorialCategory | null; kind: 'stock-brokers';
  founding_year: string; platform_label: 'Stock Brokerage';
}
interface DirectoryFacets {
  all_count: number; uncategorized_count: number;
  categories: (Category & {count: number})[];
}
interface ForexFacets extends DirectoryFacets { forex_count: number }
interface DirectoryFilters { q: string; category: string | null; sort: DirectorySort }
interface ExchangeListResponse extends Paged<ExchangeSummary> {
  filters: DirectoryFilters; facets: DirectoryFacets;
}
interface BrokerListResponse extends Paged<BrokerSummary> {
  filters: DirectoryFilters; facets: ForexFacets;
}
interface StockBrokerListResponse extends Paged<StockBrokerSummary> {
  filters: {q: string; sort: DirectorySort};
}
interface PublicReviewFields {
  website_url: string; content: string;
  outline: {id: string; title: string; level: 2 | 3}[];
  meta_description: string; seo_description: string;
  created_at: PublicTimestamp; updated_at: PublicTimestamp;
  published_at: PublicTimestamp;
}
interface ExchangeDetail extends ExchangeSummary, PublicReviewFields {
  specifications: ExchangeSpecifications;
  markets: string; access: string; support: string;
}
interface BrokerDetail extends BrokerSummary, RatingCounts, PublicReviewFields {
  specifications: BrokerSpecifications;
  founded_year: string; platforms: string; access: string; account_currency: string;
}
interface StockBrokerDetail extends StockBrokerSummary, PublicReviewFields {
  specifications: StockBrokerSpecifications;
}
interface LegacyStockRouteResponse extends Success<{
  source_path: string; target_path: string;
}> {}
```

Exact specification keys, each with string values:

```ts
interface ExchangeSpecifications {
  established_at: string;
  head_office_address: string;
  physical_branch_count: string;
  years_active: string;
  minimum_purchase: string;
  maximum_purchase: string;
  minimum_sale: string;
  maximum_sale: string;
  in_person_visit: string;
  address_transparency: string;
  active_social_media: string;
  around_the_clock_support: string;
  support_availability: string;
  spot_trading: string;
  futures_trading: string;
  identity_documents: string;
  dedicated_app: string;
  deposit_methods: string;
  withdrawal_conditions: string;
  trading_fee: string;
  registration_fee: string;
  inamad: string;
  virtual_business_union: string;
  computer_trade_organization: string;
}
```

```ts
interface BrokerSpecifications {
  established_at: string;
  head_office_address: string;
  physical_branch_count: string;
  years_active: string;
  minimum_deposit: string;
  maximum_withdrawal: string;
  in_person_visit: string;
  address_transparency: string;
  active_social_media: string;
  around_the_clock_support: string;
  support_availability: string;
  copy_trading: string;
  identity_documents: string;
  dedicated_app: string;
  trading_platform: string;
  account_types: string;
  account_currency: string;
  trading_fee: string;
  registration_fee: string;
  spread: string;
  inamad: string;
  virtual_business_union: string;
  computer_trade_organization: string;
  regulator: string;
}
```

```ts
interface StockBrokerSpecifications {
  established_at: string;
  head_office_address: string;
  physical_branch_count: string;
  years_active: string;
  minimum_purchase: string;
  maximum_purchase: string;
  minimum_sale: string;
  maximum_sale: string;
  in_person_visit: string;
  address_transparency: string;
  active_social_media: string;
  around_the_clock_support: string;
  support_availability: string;
  online_trading: string;
  identity_documents: string;
  dedicated_app: string;
  trading_platform: string;
  account_opening_documents: string;
  trading_fee: string;
  registration_fee: string;
  inamad: string;
  virtual_business_union: string;
  computer_trade_organization: string;
  regulator: string;
}
```

Detail envelopes are `Success<ExchangeDetail>`, `Success<BrokerDetail>` and `Success<StockBrokerDetail>`. Other collection envelopes are `Success<ExchangeSummary[]>`, `Success<BrokerSummary[]>`, and `Success<StockBrokerSummary[]>`. Full HTML and specifications do not enter these bounded arrays; lightweight excerpts remain plain text.

### 8.4 Frontend consumers, SEO and cache dependencies

News state uses `/news?q=...&category_id=...&page=...`. Exchange/forex state additionally retains immutable `category` and `sort`; stock state has only q/sort/page. Omit empty/default query values. Apply search/category/sort changes on page 1, preserve filters during pagination, debounce typing approximately 300 ms, and discard obsolete responses. Effective fallback category removes dead URL selections. Share server-rendered initial responses with hydration and use typed domain+ID keys so coincident IDs do not share caches.

News detail metadata uses the actual public author/title/first publication/update data. Directory metadata describes visible facts and does not invent regulatory verification. Website canonicals use trusted frontend origin plus current href. Unfiltered archives use their archive path and normalized page=n for subsequent unfiltered pages; search/category/non-default-sort variants have normalized self-canonicals and `noindex,follow`. Only current eligible detail paths enter the existing sitemap feed; aliases, legacy source paths and filters do not. Alias rendering and metadata share one detail lookup and emit real 301/307/308 website statuses as specified rather than rendering an old path with 200.

Publish/withdraw/delete, category merge/delete, slug/content/specification/logo/site changes and public author/media changes invalidate relevant lists, facets, detail, bounded collections, homepage/menu, search and sitemap through existing durable outboxes. Blog publication also invalidates the latest-blog block on news pages. Comments refresh typed counts without advancing first publication. Stock slug/visibility updates invalidate legacy lookup/website redirect caches. This delivery does not change the signed webhook wire format; process the broad existing `areas` dependencies and refetch current data rather than treating a homepage revision as a global revision.

### 8.5 Deployment and repeatable search backfill

New HTTP paths must be activated together with updated middleware/envelopes and routes. Install the committed lock file, ensure PHP intl with ICU fa_IR and the HTML sanitizer are available, and rebuild derived directory fields before frontend activation:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan public-directories:rebuild-search --no-interaction
php artisan optimize --no-interaction
php artisan app:diagnose --no-interaction
```

No new schema migration is required for these endpoints. The normal application migrations and public search/sitemap read-model initialization must already have completed; existing deployments should run `php artisan migrate:status` and `php artisan app:diagnose` to verify readiness. `public-directories:rebuild-search` uses existing public normalized columns and binary sort keys, locks each record during bounded batch updates, includes withdrawn/deleted records for future restoration, and is repeatable. Because normalization/sorting helpers are shared, it also repairs derived fields for the existing gold/prop profile consumers and their category names. Its reported count covers all those profile records plus exchanges. It creates no new content/ratings/mappings, does not modify saved descriptions/bodies, panel-search description fields, editorial version/timestamps, or sitemap modification dates, and does not send external requests.

Smoke-test all four list/detail families, a public historical alias, the bounded collections and the legacy resolver against real mappings when available. Check cache headers/request IDs/CORS, and validate that an absent mapping returns 404 rather than seeding a sample. Run correctness/concurrency checks against an isolated database, never production. The SQLite suite does not establish MariaDB concurrency behavior. Webhook end-to-end acceptance remains dependent on configured frontend DNS and receiver readiness; keep pending work for retry until the receiver accepts it.

Relevant implementation: `PublicNewsController`, `PublicDirectoryController`, `ReadPublicNewsArchive`, `ReadPublicDirectory`, `ResolveLegacyStockRoute`, `PublicDirectoryProjection`, `PublicReadSnapshot`, and `RebuildDirectorySearch`. Internal panel resolvers and their legacy website-routing behavior remain separate from the public forex JSON boundary.


## 9. Existing public comment integration

Use the numeric `data.id` of the resolved owning record; preserve that typed identity across slug changes. This section repeats the existing comment contract so the four page implementations do not need a different handoff for the comment form.

| Domain | `section` in `/comments/{section}/{parent_id}` |
| --- | --- |
| News | `news` |
| Cryptocurrency exchange | `exchanges` |
| Forex broker | `brokers` |
| Stock brokerage | `stock-brokers` |

### 9.1 Read — GET `/comments/{section}/{parent_id}`

| Query | Range | Default |
| --- | --- | --- |
| `page` | Positive safe integer | 1 |
| `per_page` | Integer 1–100 | 100 |

No body, login, CSRF, or idempotency key. Origin is optional and unrestricted. Parent visibility is checked before comments/counts are released. Public lists include only visible non-deleted top-level comments and their optional single official reply. Order is `created_at DESC, id DESC`; reply edits do not reorder comments.

**Complete populated 200**, with one answered and one unanswered comment:

```json
{
  "data": [
    {
      "id": 401,
      "name": "سارا احمدی",
      "text": "حداقل مبلغ خرید چقدر است؟\nلطفاً راهنمایی کنید.",
      "created_at": "2026-10-26T08:30:00.000000Z",
      "reply": {
        "text": "حداقل مبلغ در جدول مشخصات درج شده است.",
        "created_at": "2026-10-26T09:00:00.000000Z",
        "updated_at": "2026-10-26T09:15:00.000000Z"
      }
    },
    {
      "id": 400,
      "name": "علی رضایی",
      "text": "از توضیحات کاربردی شما متشکرم.",
      "created_at": "2026-10-26T08:00:00.000000Z",
      "reply": null
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 100,
    "total": 2,
    "total_pages": 1
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

| Comment/reply key | Type | Interpretation |
| --- | --- | --- |
| `id` | positive safe integer | Stable saved comment ID |
| `name` | string | Visitor display name; escaped text |
| `text` | string | Plain text; preserve newlines; never execute markup |
| `created_at` | UTC string or null | Server creation time |
| `reply` | object or null | Null means unanswered |
| `reply.text` | string | Official reply, 1–5000 characters under panel rules |
| `reply.created_at` | UTC string or null | Original reply creation time |
| `reply.updated_at` | UTC string or null | Last official reply edit |

There is no public email, reply ID, administrator ID/name, answer-status enum, internal version, parent URL, or audit data in these records. Render the official website brand identity locally; use neutral visitor avatars, never email-derived remote avatars. `meta.total` counts top-level visible comments only, not replies or the loaded array length.

**Empty 200** for a public parent with no comments:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 100,
    "total": 0,
    "total_pages": 0
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Beyond-last page 200**, for `?page=4&per_page=1` with two comments:

```json
{
  "data": [],
  "meta": {
    "page": 4,
    "per_page": 1,
    "total": 2,
    "total_pages": 2
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Unsupported section/malformed ID/query returns 422. A valid typed target whose parent is private/missing/deleted returns uniform 404 `CONTENT_NOT_FOUND` without parent titles or comments. Source failure returns 503 `COMMENTS_UNAVAILABLE`, never fake zero counts.

Retrieve further bounded pages through the existing list UI/incremental loading. Deduplicate by comment ID, retain newest-first order, and discard responses for an old section/parent. Independent reads can shift after new comments/deletion; after mutations reconcile/refetch the conversation and total. On a later-page network error retain valid loaded records and unsent inputs, with retry. On parent withdrawal remove its public conversation/count rather than keeping stale snippets.

### 9.2 Submit — POST `/comments/{section}/{parent_id}`

Required browser request:

```http
POST /api/v1/comments/news/21
Accept: application/json
Content-Type: application/json
Idempotency-Key: 39d20489-3543-48e4-bfa5-4186e125b71c
```

Origin is optional and unrestricted; browser JavaScript lets the browser set it. Do not add panel CSRF or bonus-rating voter credentials.

**Complete request body:**

```json
{
  "name": "سارا احمدی",
  "email": "visitor@example.org",
  "text": "از راهنمای کامل شما متشکرم.\nیک پرسش دیگر هم دارم."
}
```

| Field | Validation |
| --- | --- |
| `name` | Required string, trim edges, 2–80 Unicode characters |
| `email` | Required string, trim edges, RFC-valid syntax, max 254 characters |
| `text` | Required string, trim edges, 10–2000 Unicode characters |

Only these three fields are accepted. Null, non-string, whitespace-only, oversized values, unknown fields, and a non-object JSON body are rejected. Preserve internal meaningful spacing/newlines and literal markup-like prose; render it escaped. Do not submit reply, author ID, parent URL, timestamps, status, rating, or visitor ID. JSON body size is bounded to 32768 bytes (32 KiB), including JSON encoding overhead. Malformed JSON and duplicate decoded object keys return 400 `INVALID_JSON`; non-JSON content type returns 415.

**Initial success — 201 Created:**

```json
{
  "data": {
    "id": 402,
    "name": "سارا احمدی",
    "text": "از راهنمای کامل شما متشکرم.\nیک پرسش دیگر هم دارم.",
    "created_at": "2026-10-26T10:00:00.000000Z",
    "reply": null
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

A 201 means committed persistence and immediate public visibility, and the same record appears as Unanswered in the panel. There is no Pending/Approved workflow, CAPTCHA, account registration, email verification, or email notification in this flow. The response is no-store, does not echo email, and does not promise a Location header.

### 9.3 Idempotent retry and uncertain outcomes

Generate one random UUID v4 (`crypto.randomUUID()`) per logical submission. Retain it with the exact trimmed name/email/text and typed target in component memory while the outcome is uncertain. Matching retries use the same UUID and receive **201** again with the same numeric ID, without a second insert/count increment. After a later official reply, replay returns the currently visible record and can include that reply:

```json
{
  "data": {
    "id": 402,
    "name": "سارا احمدی",
    "text": "از راهنمای کامل شما متشکرم.\nیک پرسش دیگر هم دارم.",
    "created_at": "2026-10-26T10:00:00.000000Z",
    "reply": {
      "text": "پاسخ پرسش شما به مطلب اضافه شد.",
      "created_at": "2026-10-26T10:30:00.000000Z",
      "updated_at": "2026-10-26T10:30:00.000000Z"
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

The retry gets a new request correlation ID in practice; content identity remains the same. Idempotency retention is 24 hours. A payload/target change is a new logical submission with a new UUID; reuse with different target/payload gives 409 `IDEMPOTENCY_CONFLICT`. Missing/non-v4 keys produce a 422 `idempotency_key` field error. After retention expires the old key no longer guarantees deduplication.

A key is not an access token. Replay rechecks the parent: now-hidden parent gives 404 `CONTENT_NOT_FOUND`; a hidden/deleted saved comment with a still-public parent gives 404 `COMMENT_NOT_FOUND`. Do not resurrect it. On network loss, do not generate a new UUID for an unchanged automatic/manual retry. Disable duplicate clicks. Reset only after confirmed success and only if the inputs still belong to that completed submission; do not discard new edits made while the previous request was in flight. On 429 keep input/key and respect Retry-After.

Possible POST errors: 400 `INVALID_JSON`, 404 `CONTENT_NOT_FOUND`/`COMMENT_NOT_FOUND`, 409 `IDEMPOTENCY_CONFLICT`, 413 `PAYLOAD_TOO_LARGE`, 415 `UNSUPPORTED_MEDIA_TYPE`, 422 `VALIDATION_FAILED`, 429 `RATE_LIMITED`, 503 `COMMENTS_UNAVAILABLE`, 500 `INTERNAL_ERROR`. The full shared error catalog is available in [the consolidated handoff](public-website-api.md#8-complete-error-response-catalog).


### 9.4 Complete shared comment error examples

The existing comment API uses the same envelope. A malformed ID/unsupported section or field validation failure is 422; its `fields` maps submitted field paths to message arrays. Error messages are explanatory; branch-specific wording can differ. These examples document complete bodies for the remaining comment-specific status/code combinations. Shared 429/500 behavior is described in sections 8 and 2.

400 — INVALID_JSON:

```json
{
  "error": {
    "code": "INVALID_JSON",
    "message": "The JSON body is malformed.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

404 — CONTENT_NOT_FOUND:

```json
{
  "error": {
    "code": "CONTENT_NOT_FOUND",
    "message": "The public content was not found.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

404 — COMMENT_NOT_FOUND:

```json
{
  "error": {
    "code": "COMMENT_NOT_FOUND",
    "message": "The comment is no longer available.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

409 — IDEMPOTENCY_CONFLICT:

```json
{
  "error": {
    "code": "IDEMPOTENCY_CONFLICT",
    "message": "Use the same key only to retry the same submission.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

413 — PAYLOAD_TOO_LARGE:

```json
{
  "error": {
    "code": "PAYLOAD_TOO_LARGE",
    "message": "Comment JSON bodies may not exceed 32 KiB.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

415 — UNSUPPORTED_MEDIA_TYPE:

```json
{
  "error": {
    "code": "UNSUPPORTED_MEDIA_TYPE",
    "message": "Use application/json.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

503 — COMMENTS_UNAVAILABLE:

```json
{
  "error": {
    "code": "COMMENTS_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

## 10. Frontend cache revalidation receiver

Frontend/server caches can integrate the existing operator-configured HTTPS webhook receiver. Backend configuration uses `PUBLIC_REVALIDATION_URL` and `PUBLIC_REVALIDATION_SECRET` (at least 32 bytes). Secrets remain server-only.

The scheduler runs `presentation:deliver` every ten seconds when the scheduler is actually running. A delivery run bridges up to 100 pending rows per source outbox and attempts up to ten deliveries. Missing/invalid receiver configuration retains pending work without claiming delivery. Calls have a 3-second connect timeout, 5-second total timeout, and do not follow redirects. Only a successful 2xx receiver response acknowledges a source event.

The signed POST includes:

```text
Content-Type: application/json
X-Revalidation-Timestamp: <Unix-seconds>
X-Revalidation-Signature: sha256=<HMAC-SHA256(timestamp + "." + rawBody, sharedSecret)>
Idempotency-Key: public-change-<delivery-id>
```

**Complete presentation-source payload example:**

```json
{
  "event_id": "123",
  "event": "page_settings_updated",
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905",
  "areas": [
    "homepage",
    "navigation",
    "footer",
    "advertisements",
    "content",
    "authors",
    "search",
    "sitemap"
  ],
  "occurred_at": "2026-10-26T09:00:00.000000Z",
  "revision": "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
}
```

**Complete bridged-domain payload example:**

```json
{
  "event_id": "124",
  "event": "news_changed",
  "occurred_at": "2026-10-26T09:00:00.000000Z",
  "areas": [
    "homepage",
    "navigation",
    "footer",
    "advertisements",
    "content",
    "authors",
    "search",
    "sitemap"
  ],
  "revision": "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
}
```

Bridged source payloads do not promise a request_id, record ID, slug, or full content object. Verify signature against the exact raw request body, use constant-time comparison, apply a receiver timestamp/replay policy, and deduplicate by event_id/idempotency key. Return success only after the receiver has durably accepted its invalidation work. These are receiver integration responsibilities; no receiver was deployed in this repository.

Important: webhook `revision` is the homepage projection revision stamped for that delivery. It is **not** the sitemap revision or a global search revision. Process events even when their homepage revision is unchanged. A retry retains the same payload/event ID; delayed older events must invalidate/refetch current data, never overwrite newer cached content with payload data. Invalidating `areas` should refresh dependent layout, content, author, search, and sitemap artifacts.

Failed delivery retries independently of committed editorial writes, with delays 10, 20, 40, etc. seconds, capped at one hour. Pending work is retained rather than silently dropped. A healthy receiver without backlog can process on a following scheduled run, but backlog, downtime, rate limits, or scheduler failure mean there is no guaranteed ten-second propagation SLA. Do not repeat an already committed content/settings/comment write to repair a cache-delivery failure.


## 11. Frontend dependencies, request examples and acceptance

### 11.1 Shared shell and latest blog

Fetch `GET /page-settings` once for initial shared layout/homepage data and hydrate the shared frontend cache. The response has `{data,request_id}`. Use its existing header/menu/footer/social configuration and `data.advertisements.news_and_articles` for in-page news/exchange/forex banners. Banner links open a new tab with `rel="sponsored noopener noreferrer"`. Stock reviews do not gain a separate in-page advertisement slot. The full settings schema is in [the consolidated handoff](public-website-api.md#3-homepage-and-shared-layout); it is not changed by this feature.

For latest blog content after news comments, call `GET /blog/articles?page=1&per_page=6` with no search/category filter. Its envelope is `{data,meta,facets,request_id}` and its summary fields match the shared article/news summary field set with `/blog/{slug}` hrefs. Do not exclude a blog article simply because its ID equals the current news ID. Keep a secondary carousel failure recoverable without discarding the loaded news body.

The existing global search and sitemap feed already cover these domains; do not create a parallel frontend catalog. Never emit aliases or legacy source paths into the sitemap. Shared upper advertising/header/footer still come from page settings for every page.

### 11.2 Generic GET helper

The helper below handles JSON errors and source correlation without attaching cookies or panel credentials. It follows API aliases by default; the SSR website resolver must compare returned current href/slug with the requested website route and emit the specified website redirect before rendering. Do not treat an API's automatically followed 200 as permission to render a historical website URL with 200.

```ts
class PublicApiError extends Error {
  readonly status: number;
  readonly body: ApiErrorBody | null;
  readonly requestId: string | null;
  readonly retryAfter: string | null;

  constructor(status: number, body: ApiErrorBody | null,
    requestId: string | null, retryAfter: string | null) {
    super(typeof body?.error?.message === 'string'
      ? body.error.message : `Public API request failed (${status})`);
    this.status = status;
    this.body = body;
    this.requestId = requestId;
    this.retryAfter = retryAfter;
  }
}

async function publicGet<T>(
  apiBase: string,
  path: string,
  query: Record<string, string | number | undefined> = {},
  signal?: AbortSignal,
): Promise<T> {
  const url = new URL(apiBase.replace(/\/$/, '') + '/' + path.replace(/^\//, ''));
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined) url.searchParams.set(key, String(value));
  }
  const response = await fetch(url, {
    headers: { Accept: 'application/json' }, credentials: 'omit',
    cache: 'no-cache', redirect: 'follow', signal,
  });
  if (!response.ok) {
    let body: ApiErrorBody | null = null;
    try { body = await response.json() as ApiErrorBody; } catch { /* Upstream may return non-JSON. */ }
    throw new PublicApiError(response.status, body,
      response.headers.get('X-Request-ID'), response.headers.get('Retry-After'));
  }
  return await response.json() as T;
}
```

`apiBase` is a configured absolute service URL ending in `/api/v1`, not the website canonical origin. Add your frontend's runtime response validator at the boundary: TypeScript casts do not validate IDs, nullable values or error shapes. Network/CORS/abort failures can throw before an HTTP response exists. Keep unsent form values and selected filters, ignore aborted/obsolete results, and bound automatic retries. Do not retry 422/404 forever or turn 503 into an empty collection.

Example calls, using the standalone wire types in section 8:

```ts
async function loadPageBlocks(apiBase: string, newsSlug: string, stockSlug: string) {
  const news = await publicGet<NewsListResponse>(apiBase, 'news/articles', {page: 1, per_page: 6});
  const exchanges = await publicGet<ExchangeListResponse>(apiBase, 'exchanges', {category: 'international', sort: 'default', page: 1});
  const brokers = await publicGet<BrokerListResponse>(apiBase, 'brokers', {category: 'forex', page: 1});
  const stock = await publicGet<StockBrokerListResponse>(apiBase, 'stock-brokers', {sort: 'alphabetical', page: 1});
  const report = await publicGet<NewsDetailResponse>(apiBase, 'news/articles/' + encodeURIComponent(newsSlug));
  const brokerage = await publicGet<Success<StockBrokerDetail>>(apiBase, 'stock-brokers/' + encodeURIComponent(stockSlug));
  return {news, exchanges, brokers, stock, report, brokerage};
}
```

These calls illustrate endpoint/query/type combinations, not a requirement to fetch unrelated market lists on one page. Fetch only blocks actually rendered. Build typed query keys from domain, normalized search, effective category, sort and page; keep coincident cross-domain IDs separate.

### 11.3 Acceptance checklist

- Use fixed six-item news pages and nine-item market pages with server totals, zero-result states and explicit beyond-last recovery.
- News invalid category returns 422; exchange/forex ordinary invalid or repeated category falls back to null; stock rejects category entirely.
- Keep virtual Forex distinct from stored `forex-brokers`; International membership must be explicit and Uncategorized remains included under All/Forex.
- Preserve every domain's 24 string specification fields, empty/status distinctions and independent top-level/nested fields; invent no trading terms or verification claims.
- Render complete safe content with actual deterministic outline IDs, one page H1, responsive tables/media and only real optional sections.
- Use real nullable author/media/reading-time/rating values; no fixture identities or guessed provider URLs.
- Apply owned-alias 301 and website compatibility 307/308 behavior with the bounded explicit legacy lookup; private targets must not disclose redirects.
- Comments use typed numeric parent IDs, private email, current official replies and UUID-preserving uncertain retries; public counts exclude replies/hidden/deleted records.
- SSR body and metadata share visibility/canonical resolution. No Draft/Scheduled/deleted record, alias or stale dependent snippet should remain public after withdrawal.
- Keep network/service failure distinct from a successful empty list; retain selected filters and unsent inputs, and recover secondary blocks independently.
- Before activation, complete runtime dependencies/backfill/route-cache checks. Frontend webhook delivery requires its DNS and authenticated receiver; preserve pending events until accepted.

The complete wider backend handoff remains [public-website-api.md](public-website-api.md). This document contains all 14 new endpoint contracts independently; the wider guide adds unrelated homepage/blog/search/sitemap details.
