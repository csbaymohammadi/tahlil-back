# Public SEO and ISR handoff

## Scope and result

Backend audit for all nine public domains. Existing endpoint names, response envelopes and category arrays remain unchanged. No frontend implementation or invented author/date/duration data was added. Examples marked **fixture** are captured HTTP responses from isolated SQLite in-memory tests, not production records. Random slugs and request IDs belong to that test run.

Backend corrections:

- Platform and bonus sitemap fingerprints/source stamps no longer depend on private author profiles. Editing an internal author biography cannot advance their public modification date or falsely invalidate discovery.
- Bonus fingerprints use the current public free-text contract instead of obsolete internal campaign/editorial fields. Sitemap titles use the public `provider_name` (plain text, bounded for the existing index column), rather than the generated internal title.
- Added an explicit, conservative demo-withdrawal operation: exact seed identity/content, version 1, private backup before mutation, and existing save actions under the mutation lock. Changed records are skipped. Normal migrations/rebuilds never withdraw content.

## Endpoints and verified results

All routes below accept anonymous server requests without an admin cookie or panel CSRF. `GET <base>/{slug}` is the detail route; `<base>` is the archive. The related suffix is appended to the detail route. No new routes or response fields were required for SEO.

| Domain | Base | Related suffix | 404 code | 503 code | Page size |
|---|---|---|---|---|---:|
| articles | `/api/v1/blog/articles` | `/related` | `ARTICLE_NOT_FOUND` | `BLOG_UNAVAILABLE` | 6 |
| news | `/api/v1/news/articles` | `/latest` | `NEWS_NOT_FOUND` | `NEWS_UNAVAILABLE` | 6 |
| tutorials | `/api/v1/tutorials` | `/latest` | `TUTORIAL_NOT_FOUND` | `TUTORIALS_UNAVAILABLE` | 9 |
| bonuses | `/api/v1/bonuses` | `/latest` | `BONUS_NOT_FOUND` | `BONUSES_UNAVAILABLE` | 9 |
| exchanges | `/api/v1/exchanges` | `/others` | `EXCHANGE_NOT_FOUND` | `EXCHANGES_UNAVAILABLE` | 9 |
| brokers | `/api/v1/brokers` | `/others` | `BROKER_NOT_FOUND` | `BROKERS_UNAVAILABLE` | 9 |
| stock-brokers | `/api/v1/stock-brokers` | `/others` | `STOCK_BROKER_NOT_FOUND` | `STOCK_BROKERS_UNAVAILABLE` | 9 |
| gold-platforms | `/api/v1/gold-platforms` | `/others` | `GOLD_PLATFORM_NOT_FOUND` | `GOLD_PLATFORMS_UNAVAILABLE` | 9 |
| prop-firms | `/api/v1/prop-firms` | `/others` | `PROP_FIRM_NOT_FOUND` | `PROP_FIRMS_UNAVAILABLE` | 9 |

For every domain, regression tests verify anonymous detail 200, empty related/latest/others 200, missing/deleted 404, database failure 503 (never empty 200 or false 404), stable ordering at equal publication timestamps, the final page, and out-of-range pagination. Draft visibility and historical-slug redirects are verified in the eight domains that support that lifecycle. Article/news scheduling is tested separately.

Tutorials intentionally have no independent draft/scheduled lifecycle: a nondeleted record with a valid saved source is public. Do not add a frontend draft assumption. Tutorial source identity is immutable under the current contract; no historical-slug redirect behavior is promised for tutorials.

Published historical slugs in the other eight domains return 301 to the current API detail path. Redirect `Location` is relative and identifies the same record. The frontend should resolve that API path and use the returned `href` for the website canonical/redirect, rather than redirecting a browser page into `/api/v1`. After withdrawal/deletion, historical and current slugs both return the domain 404.

## Metadata available to the frontend

| Content | Existing fields and handling |
|---|---|
| Articles and news | `title`, `description`, `excerpt`, `cover_image`, `author` (`id`, `name`) or null, `author_profile`, `published_at`, detail `updated_at`, `meta_description`, `seo_description`, `content`, `outline`, `href` |
| Tutorials | `title`, `description`, `cover_image`, `published_at`, `created_at`, `updated_at`, `youtube_url`, `youtube_video_id`, `aparat_url`, `aparat_video_id`, `default_provider`, `vid_time`, `duration_seconds`, `duration_source`, `href` |
| Categories | Ordered parallel arrays `category_id` and `category`; empty means `[]` in both. Existing metadata is preserved. |

Missing author/image data may legitimately be null/empty according to the existing media policy. Unknown tutorial `vid_time`, `duration_seconds`, and `duration_source` remain null. Do not construct a fictitious author, publication date, or duration for structured data. `updated_at` is the saved record timestamp, not a replacement for the public fingerprint-based sitemap `last_modified_at`. Tutorial records do not currently have an SEO-specific description field or an author field; use the documented title/description/video fields rather than expecting those fields. Bonus dates/status remain author-entered text under the latest bonus contract, not machine-readable publication dates.

## HTTP and ISR behavior

- 200: use the public payload. Read `href` as the canonical website path.
- 301: follow the canonical API location; canonicalize the website to the final `href`.
- Domain 404: use the website not-found behavior for missing/nonpublic content. An unpublished old slug is not a redirect.
- 503: treat as a temporary upstream failure. Preserve previously generated content where the framework permits and retry revalidation later. Never translate this into `notFound()`, a zero-count listing, or an empty cached result. Keep `request_id` for diagnosis.
- `Retry-After` is returned only when the exception supplies a known retry delay. No guessed delay is added to generic database failures.
- Page metadata is calculated after filtering and before slicing. An out-of-range page stays at the requested number, with empty `data` and the true `total`/`total_pages`. Do not clamp to the last page. Equal timestamps are ordered by descending ID in the tested default/newest archives. Existing sort modes retain their primary criteria and deterministic tie breakers.
- Category joins must not duplicate records; existing multiple-category/filter tests cover unique totals, facets and pagination.

## Sitemap and discovery

`GET /api/v1/sitemap/entries` is a cursor feed. It returns only eligible public canonical website paths, not redirect aliases, drafts, deleted records or future scheduled article/news entries. Each identity is `(type,id)`. Published tutorials follow their source-validity rule above.

`last_modified_at` tracks observed public fingerprint changes. A no-op/private-only change does not advance it. A historical rebuild without a trustworthy observed change timestamp returns null instead of synthesizing a date. A changed historical fingerprint also clears an untrusted old timestamp. Do not substitute the deployment/build time for null.

Use the returned `next_cursor` with the same type and limit. A changed feed revision causes **409 `SITEMAP_CURSOR_STALE`**: discard the partial traversal and start again without a cursor. Tokens also bind scope, limit and expiry; do not reuse them across scopes. Publication, public edits, slug changes, withdrawal and deletion synchronously update the public index in the save transaction.

## Signed durable change delivery

The existing outbox and `presentation:deliver` are retained. Editorial publish, public edit, slug change, withdrawal, deletion, scheduled publication and page-settings changes produce durable events. Rollback leaves neither committed content nor a sendable content event. Repeating a scheduled-publish worker does not duplicate the transition.

Headers:

- `X-Revalidation-Timestamp`: Unix seconds for this attempt.
- `X-Revalidation-Signature`: `sha256=` plus HMAC-SHA256 of `<timestamp>.<raw request body>` using the shared secret.
- `Idempotency-Key`: `public-change-<event_id>`.

Validate the signature against the original raw body before JSON parsing/reserialization. Use a timing-safe comparison and the agreed timestamp acceptance window. Deduplicate by event ID/idempotency key; acknowledge with 2xx after accepting work durably. The exact body, event ID and key remain stable on retry; timestamp and signature are regenerated. Redirects are not followed. Timeout, 3xx and 503 stay pending; only 2xx acknowledges delivery. Connection timeout is 3 seconds and total timeout 5 seconds. Retry delay starts at 10 seconds, doubles and caps at one hour.

**Do not discard a content event because its `revision` equals the previous homepage revision.** Different article/news events can share that revision. Process every new event ID and invalidate affected content/search/sitemap as well as configured home areas. Until a real receiver is ready, use periodic revalidation and refresh when returning to a page; webhook delivery is not yet an end-to-end production guarantee.

The controlled receiver test ran the Artisan delivery command through timeout → 302 → 204; signatures were verified on each attempt and all three bodies/keys matched. Existing tests additionally cover 503 → 204 and an unconfigured receiver. A separate test delivered two distinct article/news events sharing one home revision.

## Test evidence

- Baseline existing HTTP/discovery/delivery group: 24 passing tests (338 assertions).
- Expanded targeted group: 58 passing tests, 983 assertions.
- Full isolated suite: 629 tests, 612 passed, 17 skipped, 7,884 assertions. Skipped integration cases are not claimed as verified.
- Pint and diff whitespace checks run on changed PHP.
- Failure injection is restricted to test fixtures; production database/services were not intentionally interrupted.

Tests: `PublicSeoIsrTest`, `PublicSeoIsrEventsTest`, `PublicDiscoveryTest`, `PublicHttpContractTest`, `PublicChangeDeliveryTest`, `DemoContentTest`; the full suite includes public categories, tutorial/news/directory contracts and homepage regression coverage.

## Captured fixture responses

The following are captured responses, not invented examples. They show one public record per domain and the exact error/pagination envelopes. Production observations are documented separately below.

### articles: `/api/v1/blog/articles`

**Fixture 200**

```json
{
  "name": "articles-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "recusandae-ut-explicabo-aliquid-nobis",
      "title": "SEO fixture articles",
      "href": "/blog/recusandae-ut-explicabo-aliquid-nobis",
      "cover_image": "/images/demo-financial-content.png",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [],
      "category": [],
      "author": {
        "id": 1,
        "name": "SEO Fixture Author"
      },
      "reading_minutes": 5,
      "published_at": "2026-11-08T10:00:00.000000Z",
      "content": "<p>Example content.</p>",
      "outline": [],
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "meta_description": "",
      "seo_description": "Public description",
      "author_profile": {
        "id": 1,
        "name": "SEO Fixture Author",
        "bio": "",
        "linkedin_url": "",
        "avatar_url": null
      },
      "tags": [],
      "comment_count": 0
    },
    "request_id": "req_c445d9a8-8fd0-4db6-8053-e3c2bce1c66b"
  }
}
```

**Fixture 404**

```json
{
  "name": "articles-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "ARTICLE_NOT_FOUND",
      "message": "The public article was not found.",
      "fields": []
    },
    "request_id": "req_c4136ec5-b881-462b-9c2e-b92e44be23b7"
  }
}
```

**Fixture 503**

```json
{
  "name": "articles-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "BLOG_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_05e0bedc-33cb-4e46-9692-b4d491cb26b4"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "articles-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 6,
      "total": 13,
      "total_pages": 3
    },
    "facets": {
      "all_count": 13,
      "uncategorized_count": 13,
      "categories": []
    },
    "request_id": "req_1033aabb-f7e5-4e20-9a39-7eb0414e97b2"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "articles-301",
  "status": 301,
  "location": "/api/v1/blog/articles/recusandae-ut-explicabo-aliquid-nobis",
  "retry_after": null,
  "body": null
}
```

### news: `/api/v1/news/articles`

**Fixture 200**

```json
{
  "name": "news-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "quo-quam-eaque-dolores-aut",
      "title": "SEO fixture news",
      "href": "/news/quo-quam-eaque-dolores-aut",
      "cover_image": "/images/demo-financial-content.png",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [],
      "category": [],
      "author": {
        "id": 1,
        "name": "SEO Fixture Author"
      },
      "reading_minutes": 5,
      "published_at": "2026-11-08T10:00:00.000000Z",
      "content": "<p>Example content.</p>",
      "outline": [],
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "meta_description": "",
      "seo_description": "Public description",
      "author_profile": {
        "id": 1,
        "name": "SEO Fixture Author",
        "bio": "",
        "linkedin_url": "",
        "avatar_url": null
      },
      "tags": [],
      "comment_count": 0
    },
    "request_id": "req_bdc0bfb9-bc87-446a-913a-d267f6eaaa9c"
  }
}
```

**Fixture 404**

```json
{
  "name": "news-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "NEWS_NOT_FOUND",
      "message": "The public news item was not found.",
      "fields": []
    },
    "request_id": "req_991dcbe1-e2bc-43a0-a40f-734a14db5757"
  }
}
```

**Fixture 503**

```json
{
  "name": "news-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "NEWS_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_6d135a89-059a-45e4-8e69-936f9ce2ddca"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "news-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 6,
      "total": 13,
      "total_pages": 3
    },
    "facets": {
      "all_count": 13,
      "uncategorized_count": 13,
      "categories": []
    },
    "request_id": "req_2a1f53ba-d83f-4eeb-b4e5-9ea848b4f886"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "news-301",
  "status": 301,
  "location": "/api/v1/news/articles/quo-quam-eaque-dolores-aut",
  "retry_after": null,
  "body": null
}
```

### tutorials: `/api/v1/tutorials`

**Fixture 200**

```json
{
  "name": "tutorials-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "youtube-5b38ed7cf6c",
      "title": "SEO fixture tutorials",
      "href": "/tutorials/youtube-5b38ed7cf6c",
      "description": "Public description",
      "cover_image": "/images/tutorials/example.webp",
      "category_id": [
        1
      ],
      "category": [
        {
          "id": 1,
          "name": "exercitationem molestiae accusamus",
          "key": "category-1"
        }
      ],
      "youtube_url": "https://www.youtube.com/watch?v=5b38ed7cf6c",
      "youtube_video_id": "5b38ed7cf6c",
      "aparat_url": "",
      "aparat_video_id": null,
      "default_provider": "youtube",
      "vid_time": null,
      "duration_seconds": null,
      "duration_source": null,
      "created_at": "2026-11-08T10:00:00.000000Z",
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "published_at": "2026-11-08T10:00:00.000000Z"
    },
    "request_id": "req_a2922ff4-ccff-4775-bec0-f9a142ec1121"
  }
}
```

**Fixture 404**

```json
{
  "name": "tutorials-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "TUTORIAL_NOT_FOUND",
      "message": "The public tutorial was not found.",
      "fields": []
    },
    "request_id": "req_e731e075-e62c-445c-bbb7-2a80180573f1"
  }
}
```

**Fixture 503**

```json
{
  "name": "tutorials-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "TUTORIALS_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_b369d857-2ca1-47b5-80fd-3510c678b90f"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "tutorials-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null
    },
    "facets": {
      "all_count": 19,
      "uncategorized_count": 0,
      "categories": [
        {
          "id": 16,
          "name": "aut iste aliquid",
          "key": "category-16",
          "count": 1
        },
        {
          "id": 4,
          "name": "aut rerum reiciendis",
          "key": "category-4",
          "count": 1
        },
        {
          "id": 13,
          "name": "consequatur aliquam harum",
          "key": "category-13",
          "count": 1
        },
        {
          "id": 12,
          "name": "consequatur dolores eveniet",
          "key": "category-12",
          "count": 1
        },
        {
          "id": 1,
          "name": "enim doloribus laudantium",
          "key": "category-1",
          "count": 1
        },
        {
          "id": 19,
          "name": "explicabo odio blanditiis",
          "key": "category-19",
          "count": 1
        },
        {
          "id": 6,
          "name": "in odit ut",
          "key": "category-6",
          "count": 1
        },
        {
          "id": 3,
          "name": "iusto eaque perferendis",
          "key": "category-3",
          "count": 1
        },
        {
          "id": 18,
          "name": "natus laudantium eum",
          "key": "category-18",
          "count": 1
        },
        {
          "id": 14,
          "name": "qui assumenda sequi",
          "key": "category-14",
          "count": 1
        },
        {
          "id": 7,
          "name": "qui vero voluptatum",
          "key": "category-7",
          "count": 1
        },
        {
          "id": 17,
          "name": "quia molestiae unde",
          "key": "category-17",
          "count": 1
        },
        {
          "id": 9,
          "name": "rem facilis culpa",
          "key": "category-9",
          "count": 1
        },
        {
          "id": 15,
          "name": "sit adipisci voluptatem",
          "key": "category-15",
          "count": 1
        },
        {
          "id": 2,
          "name": "soluta delectus officia",
          "key": "category-2",
          "count": 1
        },
        {
          "id": 5,
          "name": "sunt sapiente ducimus",
          "key": "category-5",
          "count": 1
        },
        {
          "id": 11,
          "name": "unde non aut",
          "key": "category-11",
          "count": 1
        },
        {
          "id": 10,
          "name": "velit pariatur quas",
          "key": "category-10",
          "count": 1
        },
        {
          "id": 8,
          "name": "voluptatem dolore neque",
          "key": "category-8",
          "count": 1
        }
      ]
    },
    "request_id": "req_aa85d07b-fede-4d7a-a396-677fefbe18e6"
  }
}
```

### bonuses: `/api/v1/bonuses`

**Fixture 200**

```json
{
  "name": "bonuses-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "sequi-omnis-repellendus",
      "href": "/bonuses/sequi-omnis-repellendus",
      "provider_name": "SEO fixture bonuses",
      "display_status": "",
      "amount": "",
      "start_date": "",
      "end_date": "",
      "cover_image": "",
      "category_id": [],
      "category": [],
      "rating": null,
      "rating_count": 0,
      "rating_revision": 0,
      "short_description": "Public description",
      "identity_verification": "",
      "iranian_users": "",
      "minimum_deposit": "",
      "website_url": "",
      "content": "<h2 id=\"bonus-heading-1\">Conditions</h2><p>Campaign terms.</p>"
    },
    "request_id": "req_cb838588-d2aa-4ff4-824f-cecfce9baad6"
  }
}
```

**Fixture 404**

```json
{
  "name": "bonuses-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "BONUS_NOT_FOUND",
      "message": "The public bonus was not found.",
      "fields": []
    },
    "request_id": "req_fa583aa5-06a4-4c60-bec4-362712148b37"
  }
}
```

**Fixture 503**

```json
{
  "name": "bonuses-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "BONUSES_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_49261a7c-a5de-454c-991c-6e4ad42c9218"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "bonuses-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null,
      "sort": "newest"
    },
    "facets": {
      "all_count": 19,
      "uncategorized_count": 19,
      "categories": []
    },
    "request_id": "req_c027894e-2ce6-4c58-9f75-ce8338b75919"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "bonuses-301",
  "status": 301,
  "location": "/api/v1/bonuses/sequi-omnis-repellendus",
  "retry_after": null,
  "body": null
}
```

### exchanges: `/api/v1/exchanges`

**Fixture 200**

```json
{
  "name": "exchanges-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "officiis-ipsa-deleniti",
      "title": "SEO fixture exchanges",
      "english_name": "Russel LLC",
      "logo": "/images/exchanges/example.png",
      "href": "/exchanges/officiis-ipsa-deleniti",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [
        1
      ],
      "category": [
        {
          "id": 1,
          "name": "voluptates expedita",
          "key": "category-1"
        }
      ],
      "rating_average": null,
      "rating_count": 0,
      "rating_revision": 0,
      "comment_count": 0,
      "founding_year": "2022",
      "platform_label": "Centralized exchange",
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
        "spot_trading": "",
        "futures_trading": "",
        "identity_documents": "",
        "dedicated_app": "",
        "deposit_methods": "",
        "withdrawal_conditions": "",
        "trading_fee": "",
        "registration_fee": "",
        "inamad": "",
        "virtual_business_union": "",
        "computer_trade_organization": ""
      },
      "content": "<p>Exchange review content</p>",
      "outline": [],
      "meta_description": "",
      "seo_description": "Public description",
      "created_at": "2026-11-08T10:00:00.000000Z",
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "published_at": "2026-11-08T10:00:00.000000Z",
      "markets": "Spot and futures",
      "access": "Web and mobile application",
      "support": "Online support"
    },
    "request_id": "req_e43182d5-8526-4b7c-a3f9-d639263de3e1"
  }
}
```

**Fixture 404**

```json
{
  "name": "exchanges-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "EXCHANGE_NOT_FOUND",
      "message": "The public record was not found.",
      "fields": []
    },
    "request_id": "req_8b85d1e2-35c4-4bae-b975-6b3efe22e1aa"
  }
}
```

**Fixture 503**

```json
{
  "name": "exchanges-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "EXCHANGES_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_02706339-cc35-4522-bf84-12f3be2ed382"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "exchanges-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null,
      "sort": "default"
    },
    "facets": {
      "all_count": 19,
      "uncategorized_count": 0,
      "categories": [
        {
          "id": 10,
          "name": "alias ex",
          "key": "category-10",
          "count": 1
        },
        {
          "id": 17,
          "name": "aliquid nihil",
          "key": "category-17",
          "count": 1
        },
        {
          "id": 5,
          "name": "aspernatur maiores",
          "key": "category-5",
          "count": 1
        },
        {
          "id": 11,
          "name": "commodi ullam",
          "key": "category-11",
          "count": 1
        },
        {
          "id": 18,
          "name": "culpa odit",
          "key": "category-18",
          "count": 1
        },
        {
          "id": 6,
          "name": "delectus repudiandae",
          "key": "category-6",
          "count": 1
        },
        {
          "id": 9,
          "name": "dicta impedit",
          "key": "category-9",
          "count": 1
        },
        {
          "id": 2,
          "name": "natus soluta",
          "key": "category-2",
          "count": 1
        },
        {
          "id": 3,
          "name": "officia blanditiis",
          "key": "category-3",
          "count": 1
        },
        {
          "id": 12,
          "name": "officia facere",
          "key": "category-12",
          "count": 1
        },
        {
          "id": 1,
          "name": "omnis corporis",
          "key": "category-1",
          "count": 1
        },
        {
          "id": 16,
          "name": "sed quis",
          "key": "category-16",
          "count": 1
        },
        {
          "id": 8,
          "name": "ullam dolor",
          "key": "category-8",
          "count": 1
        },
        {
          "id": 4,
          "name": "ut molestiae",
          "key": "category-4",
          "count": 1
        },
        {
          "id": 7,
          "name": "ut nam",
          "key": "category-7",
          "count": 1
        },
        {
          "id": 19,
          "name": "vel eos",
          "key": "category-19",
          "count": 1
        },
        {
          "id": 13,
          "name": "velit consequatur",
          "key": "category-13",
          "count": 1
        },
        {
          "id": 14,
          "name": "vitae doloremque",
          "key": "category-14",
          "count": 1
        },
        {
          "id": 15,
          "name": "voluptatibus enim",
          "key": "category-15",
          "count": 1
        }
      ]
    },
    "request_id": "req_a7268379-02ab-4da1-876d-1143f9265061"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "exchanges-301",
  "status": 301,
  "location": "/api/v1/exchanges/officiis-ipsa-deleniti",
  "retry_after": null,
  "body": null
}
```

### brokers: `/api/v1/brokers`

**Fixture 200**

```json
{
  "name": "brokers-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "saepe-aut-eum",
      "title": "SEO fixture brokers",
      "english_name": "Harris-Carter",
      "logo": "/images/brokers/example.png",
      "href": "/brokers/saepe-aut-eum",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [
        1
      ],
      "category": [
        {
          "id": 1,
          "name": "nihil ea",
          "key": "category-7609472"
        }
      ],
      "rating_average": null,
      "rating_count": 0,
      "rating_revision": 0,
      "comment_count": 0,
      "filter_keys": [
        "forex",
        "category-7609472"
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
        "copy_trading": "",
        "identity_documents": "",
        "dedicated_app": "",
        "trading_platform": "",
        "account_types": "",
        "account_currency": "",
        "trading_fee": "",
        "registration_fee": "",
        "spread": "",
        "inamad": "",
        "virtual_business_union": "",
        "computer_trade_organization": "",
        "regulator": ""
      },
      "content": "<p>Broker review content</p>",
      "outline": [],
      "meta_description": "",
      "seo_description": "Public description",
      "created_at": "2026-11-08T10:00:00.000000Z",
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "published_at": "2026-11-08T10:00:00.000000Z",
      "founded_year": "2022",
      "platforms": "WebTrader",
      "access": "Web and mobile",
      "account_currency": "USD"
    },
    "request_id": "req_303ca96f-bcce-4e29-bed3-a5029862142d"
  }
}
```

**Fixture 404**

```json
{
  "name": "brokers-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "BROKER_NOT_FOUND",
      "message": "The public record was not found.",
      "fields": []
    },
    "request_id": "req_f68f6d50-da73-45d0-8ecf-c3ff2bde8ef5"
  }
}
```

**Fixture 503**

```json
{
  "name": "brokers-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "BROKERS_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_0fae41e2-465d-4d88-a4a4-4f96e102713a"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "brokers-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null,
      "sort": "default"
    },
    "facets": {
      "all_count": 19,
      "forex_count": 19,
      "uncategorized_count": 0,
      "categories": [
        {
          "id": 14,
          "name": "amet fugit",
          "key": "category-5383225",
          "count": 1
        },
        {
          "id": 11,
          "name": "consequatur iure",
          "key": "category-2435500",
          "count": 1
        },
        {
          "id": 18,
          "name": "consequatur magnam",
          "key": "category-5003423",
          "count": 1
        },
        {
          "id": 13,
          "name": "dolor aliquid",
          "key": "category-5947316",
          "count": 1
        },
        {
          "id": 9,
          "name": "dolores et",
          "key": "category-4122048",
          "count": 1
        },
        {
          "id": 1,
          "name": "ea exercitationem",
          "key": "category-8814059",
          "count": 1
        },
        {
          "id": 2,
          "name": "et voluptatem",
          "key": "category-5651293",
          "count": 1
        },
        {
          "id": 5,
          "name": "fugiat facere",
          "key": "category-9824671",
          "count": 1
        },
        {
          "id": 15,
          "name": "ipsam placeat",
          "key": "category-2402327",
          "count": 1
        },
        {
          "id": 7,
          "name": "iste magni",
          "key": "category-3180156",
          "count": 1
        },
        {
          "id": 4,
          "name": "molestiae eligendi",
          "key": "category-8180483",
          "count": 1
        },
        {
          "id": 10,
          "name": "nam consequatur",
          "key": "category-907340",
          "count": 1
        },
        {
          "id": 6,
          "name": "non minima",
          "key": "category-5322950",
          "count": 1
        },
        {
          "id": 12,
          "name": "officia at",
          "key": "category-1204717",
          "count": 1
        },
        {
          "id": 17,
          "name": "pariatur praesentium",
          "key": "category-7879959",
          "count": 1
        },
        {
          "id": 3,
          "name": "possimus eum",
          "key": "category-1603467",
          "count": 1
        },
        {
          "id": 19,
          "name": "quo eum",
          "key": "category-5762764",
          "count": 1
        },
        {
          "id": 16,
          "name": "sequi et",
          "key": "category-9134594",
          "count": 1
        },
        {
          "id": 8,
          "name": "velit consequatur",
          "key": "category-7249082",
          "count": 1
        }
      ]
    },
    "request_id": "req_7af0e10b-ed24-42b0-925d-2b3c82e3230a"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "brokers-301",
  "status": 301,
  "location": "/api/v1/brokers/saepe-aut-eum",
  "retry_after": null,
  "body": null
}
```

### stock-brokers: `/api/v1/stock-brokers`

**Fixture 200**

```json
{
  "name": "stock-brokers-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "blanditiis-eos",
      "title": "SEO fixture stock-brokers",
      "english_name": "Kreiger-Gusikowski",
      "logo": "",
      "href": "/stock-brokers/blanditiis-eos",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [
        1
      ],
      "category": [
        {
          "id": 1,
          "name": "quibusdam temporibus",
          "key": "category-1"
        }
      ],
      "rating_average": null,
      "rating_count": 0,
      "rating_revision": 0,
      "comment_count": 0,
      "kind": "stock-brokers",
      "founding_year": "",
      "platform_label": "Stock Brokerage",
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
        "online_trading": "",
        "identity_documents": "",
        "dedicated_app": "",
        "trading_platform": "",
        "account_opening_documents": "",
        "trading_fee": "",
        "registration_fee": "",
        "inamad": "",
        "virtual_business_union": "",
        "computer_trade_organization": "",
        "regulator": ""
      },
      "content": "<p>Stock brokerage review content</p>",
      "outline": [],
      "meta_description": "",
      "seo_description": "Public description",
      "created_at": "2026-11-08T10:00:00.000000Z",
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "published_at": "2026-11-08T10:00:00.000000Z"
    },
    "request_id": "req_f6bfcb97-18d3-4699-b619-4c921a8d7075"
  }
}
```

**Fixture 404**

```json
{
  "name": "stock-brokers-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "STOCK_BROKER_NOT_FOUND",
      "message": "The public record was not found.",
      "fields": []
    },
    "request_id": "req_8c4ce56c-2cd3-4968-b6b5-719e9847a4df"
  }
}
```

**Fixture 503**

```json
{
  "name": "stock-brokers-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "STOCK_BROKERS_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_bd864742-b92f-4edd-951f-6ad58dbbd315"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "stock-brokers-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null,
      "sort": "default"
    },
    "facets": {
      "all_count": 19,
      "uncategorized_count": 0,
      "categories": [
        {
          "id": 16,
          "name": "accusamus minima",
          "key": "category-16",
          "count": 1
        },
        {
          "id": 19,
          "name": "accusantium vitae",
          "key": "category-19",
          "count": 1
        },
        {
          "id": 11,
          "name": "architecto aut",
          "key": "category-11",
          "count": 1
        },
        {
          "id": 13,
          "name": "commodi eos",
          "key": "category-13",
          "count": 1
        },
        {
          "id": 2,
          "name": "deleniti non",
          "key": "category-2",
          "count": 1
        },
        {
          "id": 10,
          "name": "eos iure",
          "key": "category-10",
          "count": 1
        },
        {
          "id": 15,
          "name": "harum aperiam",
          "key": "category-15",
          "count": 1
        },
        {
          "id": 7,
          "name": "illo doloribus",
          "key": "category-7",
          "count": 1
        },
        {
          "id": 6,
          "name": "iste eum",
          "key": "category-6",
          "count": 1
        },
        {
          "id": 9,
          "name": "iste soluta",
          "key": "category-9",
          "count": 1
        },
        {
          "id": 5,
          "name": "laudantium aspernatur",
          "key": "category-5",
          "count": 1
        },
        {
          "id": 1,
          "name": "minus quod",
          "key": "category-1",
          "count": 1
        },
        {
          "id": 18,
          "name": "minus ut",
          "key": "category-18",
          "count": 1
        },
        {
          "id": 3,
          "name": "nam veritatis",
          "key": "category-3",
          "count": 1
        },
        {
          "id": 12,
          "name": "quibusdam id",
          "key": "category-12",
          "count": 1
        },
        {
          "id": 17,
          "name": "qui fugit",
          "key": "category-17",
          "count": 1
        },
        {
          "id": 8,
          "name": "qui quia",
          "key": "category-8",
          "count": 1
        },
        {
          "id": 4,
          "name": "velit rerum",
          "key": "category-4",
          "count": 1
        },
        {
          "id": 14,
          "name": "voluptas laudantium",
          "key": "category-14",
          "count": 1
        }
      ]
    },
    "request_id": "req_3128655b-d0c6-4b07-9617-2196f7ee1fd2"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "stock-brokers-301",
  "status": 301,
  "location": "/api/v1/stock-brokers/blanditiis-eos",
  "retry_after": null,
  "body": null
}
```

### gold-platforms: `/api/v1/gold-platforms`

**Fixture 200**

```json
{
  "name": "gold-platforms-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "et-assumenda-ut",
      "title": "SEO fixture gold-platforms",
      "english_name": "Jacobs-Miller",
      "logo": "",
      "href": "/gold-platforms/et-assumenda-ut",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [
        1
      ],
      "category": [
        {
          "id": 1,
          "name": "perferendis aut",
          "key": "category-1"
        }
      ],
      "rating_average": null,
      "rating_count": 0,
      "rating_revision": 0,
      "comment_count": 0,
      "kind": "gold-platforms",
      "founding_year": "",
      "platform_label": "Gold Platform",
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
        "melted_gold_trading": "",
        "identity_documents": "",
        "dedicated_app": "",
        "physical_delivery_time": "",
        "physical_delivery_method": "",
        "asset_storage": "",
        "trading_fee": "",
        "registration_fee": "",
        "inamad": "",
        "virtual_business_union": "",
        "computer_trade_organization": ""
      },
      "content": "<p>Gold platform review content</p>",
      "outline": [],
      "meta_description": "",
      "seo_description": "Public description",
      "created_at": "2026-11-08T10:00:00.000000Z",
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "published_at": "2026-11-08T10:00:00.000000Z"
    },
    "request_id": "req_ca93dd23-7e6a-4ee0-bef2-de82d50cf564"
  }
}
```

**Fixture 404**

```json
{
  "name": "gold-platforms-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "GOLD_PLATFORM_NOT_FOUND",
      "message": "The public record was not found.",
      "fields": []
    },
    "request_id": "req_e7568bed-38a8-4862-a815-ed19ccd12af8"
  }
}
```

**Fixture 503**

```json
{
  "name": "gold-platforms-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "GOLD_PLATFORMS_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_e2ab9751-7492-4307-8261-81c3d2d71e7d"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "gold-platforms-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null,
      "sort": "default"
    },
    "facets": {
      "all_count": 19,
      "uncategorized_count": 0,
      "categories": [
        {
          "id": 10,
          "name": "adipisci quis",
          "key": "category-10",
          "count": 1
        },
        {
          "id": 3,
          "name": "blanditiis molestias",
          "key": "category-3",
          "count": 1
        },
        {
          "id": 5,
          "name": "consequatur aperiam",
          "key": "category-5",
          "count": 1
        },
        {
          "id": 16,
          "name": "delectus expedita",
          "key": "category-16",
          "count": 1
        },
        {
          "id": 8,
          "name": "dolores quaerat",
          "key": "category-8",
          "count": 1
        },
        {
          "id": 19,
          "name": "et alias",
          "key": "category-19",
          "count": 1
        },
        {
          "id": 12,
          "name": "et enim",
          "key": "category-12",
          "count": 1
        },
        {
          "id": 14,
          "name": "et quod",
          "key": "category-14",
          "count": 1
        },
        {
          "id": 4,
          "name": "et ut",
          "key": "category-4",
          "count": 1
        },
        {
          "id": 2,
          "name": "iusto vel",
          "key": "category-2",
          "count": 1
        },
        {
          "id": 7,
          "name": "maxime consectetur",
          "key": "category-7",
          "count": 1
        },
        {
          "id": 18,
          "name": "neque eum",
          "key": "category-18",
          "count": 1
        },
        {
          "id": 6,
          "name": "quam animi",
          "key": "category-6",
          "count": 1
        },
        {
          "id": 13,
          "name": "quisquam quia",
          "key": "category-13",
          "count": 1
        },
        {
          "id": 11,
          "name": "similique mollitia",
          "key": "category-11",
          "count": 1
        },
        {
          "id": 17,
          "name": "ut quia",
          "key": "category-17",
          "count": 1
        },
        {
          "id": 9,
          "name": "velit minus",
          "key": "category-9",
          "count": 1
        },
        {
          "id": 1,
          "name": "veniam eos",
          "key": "category-1",
          "count": 1
        },
        {
          "id": 15,
          "name": "vitae et",
          "key": "category-15",
          "count": 1
        }
      ]
    },
    "request_id": "req_f3dcb100-034e-4505-952c-9da471f4f5bb"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "gold-platforms-301",
  "status": 301,
  "location": "/api/v1/gold-platforms/et-assumenda-ut",
  "retry_after": null,
  "body": null
}
```

### prop-firms: `/api/v1/prop-firms`

**Fixture 200**

```json
{
  "name": "prop-firms-200",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": {
      "id": 1,
      "slug": "neque-magni-corrupti",
      "title": "SEO fixture prop-firms",
      "english_name": "Gleason-Tromp",
      "logo": "",
      "href": "/prop-firms/neque-magni-corrupti",
      "description": "Public description",
      "excerpt": "Public description",
      "category_id": [
        1
      ],
      "category": [
        {
          "id": 1,
          "name": "cum explicabo",
          "key": "category-1"
        }
      ],
      "rating_average": null,
      "rating_count": 0,
      "rating_revision": 0,
      "comment_count": 0,
      "kind": "prop-firms",
      "founding_year": "",
      "platform_label": "Prop Firm",
      "website_url": "",
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
        "trial_account": "",
        "identity_documents": "",
        "dedicated_app": "",
        "evaluation_type": "",
        "daily_loss_limit": "",
        "total_loss_limit": "",
        "payout_rules": "",
        "trading_fee": "",
        "registration_fee": "",
        "inamad": "",
        "virtual_business_union": "",
        "computer_trade_organization": ""
      },
      "content": "<p>Prop firm review content</p>",
      "outline": [],
      "meta_description": "",
      "seo_description": "Public description",
      "created_at": "2026-11-08T10:00:00.000000Z",
      "updated_at": "2026-11-08T10:00:00.000000Z",
      "published_at": "2026-11-08T10:00:00.000000Z"
    },
    "request_id": "req_6efa93c5-a674-41af-a785-8b02c08890f1"
  }
}
```

**Fixture 404**

```json
{
  "name": "prop-firms-404",
  "status": 404,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "PROP_FIRM_NOT_FOUND",
      "message": "The public record was not found.",
      "fields": []
    },
    "request_id": "req_f7d3886d-f792-47e2-8f6d-f87180b96db2"
  }
}
```

**Fixture 503**

```json
{
  "name": "prop-firms-503",
  "status": 503,
  "location": null,
  "retry_after": null,
  "body": {
    "error": {
      "code": "PROP_FIRMS_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_0f44fd2e-cab6-4121-be6e-40dd4c1da047"
  }
}
```

**Fixture out-of-range**

```json
{
  "name": "prop-firms-out-of-range",
  "status": 200,
  "location": null,
  "retry_after": null,
  "body": {
    "data": [],
    "meta": {
      "page": 99999,
      "per_page": 9,
      "total": 19,
      "total_pages": 3
    },
    "filters": {
      "q": "",
      "category": null,
      "sort": "default"
    },
    "facets": {
      "all_count": 19,
      "uncategorized_count": 0,
      "categories": [
        {
          "id": 19,
          "name": "aut corrupti",
          "key": "category-19",
          "count": 1
        },
        {
          "id": 4,
          "name": "corrupti voluptatum",
          "key": "category-4",
          "count": 1
        },
        {
          "id": 18,
          "name": "deserunt et",
          "key": "category-18",
          "count": 1
        },
        {
          "id": 13,
          "name": "dolorem ratione",
          "key": "category-13",
          "count": 1
        },
        {
          "id": 14,
          "name": "et soluta",
          "key": "category-14",
          "count": 1
        },
        {
          "id": 5,
          "name": "facilis et",
          "key": "category-5",
          "count": 1
        },
        {
          "id": 16,
          "name": "labore ratione",
          "key": "category-16",
          "count": 1
        },
        {
          "id": 7,
          "name": "laborum fugit",
          "key": "category-7",
          "count": 1
        },
        {
          "id": 3,
          "name": "nobis quidem",
          "key": "category-3",
          "count": 1
        },
        {
          "id": 2,
          "name": "nostrum quisquam",
          "key": "category-2",
          "count": 1
        },
        {
          "id": 17,
          "name": "quia beatae",
          "key": "category-17",
          "count": 1
        },
        {
          "id": 10,
          "name": "quis praesentium",
          "key": "category-10",
          "count": 1
        },
        {
          "id": 12,
          "name": "quis sunt",
          "key": "category-12",
          "count": 1
        },
        {
          "id": 6,
          "name": "ratione aliquid",
          "key": "category-6",
          "count": 1
        },
        {
          "id": 8,
          "name": "reprehenderit voluptatem",
          "key": "category-8",
          "count": 1
        },
        {
          "id": 15,
          "name": "sint explicabo",
          "key": "category-15",
          "count": 1
        },
        {
          "id": 11,
          "name": "ut id",
          "key": "category-11",
          "count": 1
        },
        {
          "id": 9,
          "name": "vel blanditiis",
          "key": "category-9",
          "count": 1
        },
        {
          "id": 1,
          "name": "veniam consequuntur",
          "key": "category-1",
          "count": 1
        }
      ]
    },
    "request_id": "req_7cc176cc-fb24-450e-bab6-bff3da26de31"
  }
}
```

**Fixture historical slug**

```json
{
  "name": "prop-firms-301",
  "status": 301,
  "location": "/api/v1/prop-firms/neque-magni-corrupti",
  "retry_after": null,
  "body": null
}
```

### retry-after-503

```json
{
  "name": "retry-after-503",
  "status": 503,
  "location": null,
  "retry_after": "17",
  "body": {
    "error": {
      "code": "BLOG_UNAVAILABLE",
      "message": "Public information is temporarily unavailable.",
      "fields": []
    },
    "request_id": "req_a27751b7-e11d-4917-a7e2-041ce365b34e"
  }
}
```

### fixture-feed-before

```json
{
  "name": "fixture-feed-before",
  "body": {
    "data": [
      {
        "type": "articles",
        "id": 1,
        "title": "SEO feed fixture",
        "path": "/blog/seo-feed-original",
        "last_modified_at": "2026-11-07T22:21:37.103393Z"
      },
      {
        "type": "articles",
        "id": 2,
        "title": "SEO feed fixture",
        "path": "/blog/seo-feed-second",
        "last_modified_at": "2026-11-07T22:21:37.109508Z"
      }
    ],
    "meta": {
      "type": "articles",
      "limit": 1000,
      "total": 2,
      "has_more": false,
      "next_cursor": null,
      "revision": "fdc209e2c6a9d2c3c5a37e5d839adf3db21183eec32f39c02d6c9c70f2c9dda1"
    },
    "request_id": "req_5eed92a3-f11a-4000-b49f-b0fa3ee5f0ae"
  }
}
```

### fixture-stale-cursor

```json
{
  "name": "fixture-stale-cursor",
  "body": {
    "error": {
      "code": "SITEMAP_CURSOR_STALE",
      "message": "The feed changed. Restart from the first page.",
      "fields": []
    },
    "request_id": "req_54370c7f-c86f-4982-b4b3-0b92465a35d6"
  }
}
```

### fixture-feed-after-withdrawal

```json
{
  "name": "fixture-feed-after-withdrawal",
  "body": {
    "data": [
      {
        "type": "articles",
        "id": 2,
        "title": "SEO feed fixture",
        "path": "/blog/seo-feed-second",
        "last_modified_at": "2026-11-07T22:21:37.109508Z"
      }
    ],
    "meta": {
      "type": "articles",
      "limit": 1000,
      "total": 1,
      "has_more": false,
      "next_cursor": null,
      "revision": "85deb1d9be3315078fa00f8c28fb83e85a0c48356c5a9cf2f7c2b58e36ec8d58"
    },
    "request_id": "req_e8ad23db-1373-4068-be83-43385da1418a"
  }
}
```

### webhook-timeout-redirect-success

```json
{
  "name": "webhook-timeout-redirect-success",
  "body": {
    "attempts": [
      {
        "payload": {
          "event_id": "1",
          "event": "page_settings_updated",
          "request_id": "seo-test",
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
          "occurred_at": "2026-11-07T22:21:37.031489Z",
          "revision": "fc425d23495f0699d9285b8dd42d502cfc4febe762e8f6a8865835fdd3a3c98d"
        },
        "idempotency_key": "public-change-1"
      },
      {
        "payload": {
          "event_id": "1",
          "event": "page_settings_updated",
          "request_id": "seo-test",
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
          "occurred_at": "2026-11-07T22:21:37.031489Z",
          "revision": "fc425d23495f0699d9285b8dd42d502cfc4febe762e8f6a8865835fdd3a3c98d"
        },
        "idempotency_key": "public-change-1"
      },
      {
        "payload": {
          "event_id": "1",
          "event": "page_settings_updated",
          "request_id": "seo-test",
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
          "occurred_at": "2026-11-07T22:21:37.031489Z",
          "revision": "fc425d23495f0699d9285b8dd42d502cfc4febe762e8f6a8865835fdd3a3c98d"
        },
        "idempotency_key": "public-change-1"
      }
    ],
    "receiver_statuses": [
      "timeout",
      302,
      204
    ]
  }
}
```

### unchanged-home-revision

```json
{
  "name": "unchanged-home-revision",
  "body": [
    {
      "event_id": "1",
      "event": "blog_article_changed",
      "occurred_at": "2026-11-07T22:21:37.000000Z",
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
      "revision": "fc425d23495f0699d9285b8dd42d502cfc4febe762e8f6a8865835fdd3a3c98d"
    },
    {
      "event_id": "2",
      "event": "news_item_changed",
      "occurred_at": "2026-11-07T22:21:37.000000Z",
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
      "revision": "fc425d23495f0699d9285b8dd42d502cfc4febe762e8f6a8865835fdd3a3c98d"
    }
  ]
}
```

## Production rollout evidence

Pending rollout verification. This section will be completed from the actual server inspection before final handoff. Real receiver delivery remains pending a destination URL; no signing secrets, cookies or private backup contents are included in this document.
