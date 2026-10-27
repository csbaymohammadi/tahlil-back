# Public Website API — Complete Frontend Handoff

Implementation baseline: `e749b80` plus the public news/directories extension, Laravel 13 / PHP 8.3, 2026-10-26.

This is the consolidated frontend contract for the implemented homepage/shared layout, blog, news, cryptocurrency exchanges, forex brokers, stock brokerages, public comments, global search, and sitemap content feed. Request/response details below describe the current backend, not proposed features. If an older panel handoff describes a different public projection, use this document for public website integration; the administrative contracts remain separate.

All prose, technical identifiers, and instructions are in English, following this repository's documentation conventions. Persian sample content illustrates the UTF-8 response format.

Every JSON block is a complete example for the state named immediately above it. Records, IDs, social destinations, request IDs, revisions, and encrypted cursor placeholders are illustrative, not production fixtures or working credentials. Do not seed or hardcode them into the frontend.

## Contents

1. [Delivery boundaries and endpoint inventory](#1-delivery-boundaries-and-endpoint-inventory)
2. [Shared HTTP, values, caching, and errors](#2-shared-http-values-caching-and-errors)
3. [Homepage and shared layout](#3-homepage-and-shared-layout)
4. [Public blog](#4-public-blog)
5. [Public comments and official replies](#5-public-comments-and-official-replies)
6. [Global search](#6-global-search)
7. [Sitemap content feed](#7-sitemap-content-feed)
8. [Complete error response catalog](#8-complete-error-response-catalog)
9. [TypeScript wire contracts](#9-typescript-wire-contracts)
10. [Frontend integration and request examples](#10-frontend-integration-and-request-examples)
11. [Publication, cache propagation, and deployment](#11-publication-cache-propagation-and-deployment)
12. [Frontend acceptance checklist and source references](#12-frontend-acceptance-checklist-and-source-references)

13. [Public news](#13-public-news)
14. [Shared directory behavior](#14-shared-directory-behavior)
15. [Cryptocurrency exchanges](#15-cryptocurrency-exchanges)
16. [Forex brokers](#16-forex-brokers)
17. [Stock brokerages and legacy routes](#17-stock-brokerages-and-legacy-routes)
18. [News and directory errors, types, and deployment](#18-news-and-directory-errors-types-and-deployment)

## 1. Delivery boundaries and endpoint inventory

Configure the API service origin per environment. All paths in this document are relative to `/api/v1` unless the full API prefix is shown. Do not infer a production origin from example links, the current browser host, or the backend's development configuration. Website paths such as `/blog/market-guide` belong to the frontend website origin; API paths such as `/api/v1/blog/articles/market-guide` belong to the API service.

| Method | Path under `/api/v1` | Successful response | Purpose |
| --- | --- | --- | --- |
| GET | `/page-settings` | 200 object; conditional 304 | Shared shell and six homepage sections |
| GET | `/blog/articles` | 200 array + meta + facets | Blog archive |
| GET | `/blog/featured` | 200 array | Up to five featured articles |
| GET | `/blog/articles/{slug}` | 200 object; historical alias 301 | Complete article |
| GET | `/blog/articles/{slug}/related` | 200 array; historical alias 301 | Up to six related articles |
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
| GET | `/comments/{section}/{parent_id}` | 200 array + meta | Public conversation |
| POST | `/comments/{section}/{parent_id}` | 201 object, including matched retries | Submit one visitor comment |
| GET | `/search` | 200 array + meta + filters | Shared header/page search |
| GET | `/sitemap/entries` | 200 array + cursor meta | Complete dynamic public URL discovery |

GET routes also support HEAD through Laravel routing; HEAD has no response body. Registered OPTIONS routes return 204 with no JSON body for browser preflight. All implemented API families use the same unrestricted CORS preflight policy in section 2.

This delivery does not add public write endpoints for articles/settings, visitor authentication, public comment edit/delete/reply, a new CMS, static information-page APIs, sharing counters, market prices, or frontend page rendering. Search/feed support for a content type does not mean its public archive/detail API was added in this change. Website route rendering, SSR, XML generation, static sitemap routes, and UI state remain frontend responsibilities.

## 2. Shared HTTP, values, caching, and errors

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
- Ordinary text is rendered escaped. Only article detail `content` is intended for the controlled rich-text HTML renderer.

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

### 2.3 Cache policy and revisions

| Response | Effective policy |
| --- | --- |
| Successful homepage/blog/search/comment GET | `no-cache, must-revalidate` |
| Sitemap JSON feed | `no-store` |
| Comment POST, including idempotent replay | `no-store` |
| Error responses | `no-store` |
| Conditional homepage 304 | No body; keep the previously validated representation |

Symfony can append `private` and reorder Cache-Control directives. Test the directive semantics rather than one header string. Public reads vary by Origin; they do not vary by panel Cookie. Comment writes can also carry `Vary: Cookie` due to the existing envelope, but cookies are not a comment credential and writes remain no-store.

`page-settings.data.revision` identifies that resolved homepage/shell projection. It is not the administrator's settings version, a write token, or the global revision for search/sitemap. `sitemap.meta.revision` identifies the selected feed scope and has separate cursor semantics. Treat both as opaque strings.

Do not serve withdrawn content indefinitely from a frontend query cache, CDN, SSR cache, or generated XML while background revalidation runs. Use fresh API reads/revalidation before releasing shared cached output; if current eligibility cannot be established, render an unavailable state. A webhook alone does not guarantee that a delayed cached result is current.

### 2.4 Query validation and limits

Only each endpoint's listed parameters are accepted. Unknown, repeated, structured, malformed, or unsupported parameters return 422. Examples: `page=1&page=2`, `q[]=x`, `preview=true`, and `include=drafts`. Do not serialize omitted values as `null`, `undefined`, `all` category IDs, or empty cursor strings. Parameter validation is separate from resource existence.

| Operation group | Default allowance | Window and scope |
| --- | --- | --- |
| Homepage, blog reads, comment reads combined | 300 | Per source, shared read bucket, 60 seconds |
| Search | 60 | Per source, search bucket, 60 seconds |
| Sitemap feed | 60 | Per source, sitemap bucket, 60 seconds |
| Comment submissions | 5 | Per source, 900 seconds |
| Comment submissions | 300 | Global across sources, 900 seconds |

These are backend configuration defaults, not separate quotas per component or page. Read limits use the configured shared panel rate store. Submission checks happen before payload validation, so invalid/retried POSTs can consume submission allowance. Respect 429 `Retry-After` in seconds; do not loop automatically. Failed or unavailable responses are distinct from valid empty results.

## 3. Homepage and shared layout

### 3.1 Request

`GET /api/v1/page-settings`

No query parameters, body, authentication, or CSRF. Fetch one snapshot for the server request and reuse it for header, mobile menu, footer, advertisements, hero, and homepage blocks.

### 3.2 Complete populated response — 200

The example includes all six section objects, all five menu groups, each card schema, both ad placements, social links, and one logo item. The same tutorial intentionally appears in both video sections with the same identity.

```json
{
  "data": {
    "hero": {
      "title": "آگاهانه تحلیل کنید، آگاهانه انتخاب کنید",
      "accent": "آگاهانه انتخاب کنید",
      "description": "بررسی پلتفرم‌ها، اخبار بازار و آموزش‌های کاربردی.",
      "primary_label": "بررسی صرافی‌ها",
      "primary_url": "/#exchanges",
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
        "items": [
          {
            "id": 31,
            "slug": "sample-exchange",
            "title": "صرافی نمونه",
            "english_name": "Sample Exchange",
            "logo": "/images/exchanges/sample.png",
            "href": "/exchanges/sample-exchange",
            "founding_year": "2018",
            "platform_label": "پلتفرم معاملاتی",
            "rating_average": null,
            "rating_count": 0,
            "comment_count": 2
          }
        ]
      },
      {
        "key": "aparat",
        "kind": "video",
        "title": "تحلیل در آپارات",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/tutorials",
        "items": [
          {
            "id": 91,
            "slug": "youtube-BOwRO3z-_Bo",
            "title": "شروع آموزش",
            "href": "/tutorials/youtube-BOwRO3z-_Bo",
            "description": "آموزش گام‌به‌گام شروع کار.",
            "cover_image": "/images/tutorials/start.webp",
            "category": {
              "id": 6,
              "name": "شروع کار",
              "key": "category-6"
            },
            "duration_seconds": 862,
            "youtube_url": "https://www.youtube.com/watch?v=BOwRO3z-_Bo",
            "youtube_video_id": "BOwRO3z-_Bo",
            "aparat_url": "https://www.aparat.com/v/tqlk9l0",
            "aparat_video_id": "tqlk9l0",
            "default_provider": "aparat",
            "duration_source": "aparat",
            "created_at": "2026-10-25T08:00:00.000000Z",
            "updated_at": "2026-10-26T08:00:00.000000Z",
            "published_at": "2026-10-26T08:00:00.000000Z"
          }
        ]
      },
      {
        "key": "news",
        "kind": "news",
        "title": "اخبار بازار",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/news",
        "items": [
          {
            "id": 21,
            "slug": "market-update",
            "title": "تازه‌های بازار",
            "href": "/news/market-update",
            "cover_image": "",
            "description": "مقدمه‌ای برای شناخت بازار.",
            "excerpt": "مقدمه‌ای برای شناخت بازار.",
            "category": {
              "id": 2,
              "name": "اخبار بازار",
              "key": "category-2"
            },
            "author": {
              "id": 7,
              "name": "تحریریه تحلیل"
            },
            "reading_minutes": null,
            "published_at": "2026-10-26T08:00:00.000000Z"
          }
        ]
      },
      {
        "key": "bonuses",
        "kind": "bonus",
        "title": "بونوس‌ها",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/bonuses",
        "items": [
          {
            "id": 81,
            "slug": "welcome-campaign",
            "title": "کمپین خوش‌آمدگویی",
            "description": "شرایط دریافت پاداش را مطالعه کنید.",
            "category": {
              "id": 3,
              "name": "کمپین‌ها",
              "key": "category-3"
            },
            "published_at": "2026-10-26T08:00:00.000000Z",
            "campaign": {
              "provider": {
                "exchange_id": 31,
                "name": "صرافی نمونه",
                "english_name": "Sample Exchange",
                "logo": "/images/exchanges/sample.png"
              },
              "amount_display": "تا ۲۰ دلار",
              "state": "ended"
            },
            "rating": 4.5,
            "rating_count": 2,
            "rating_revision": 2,
            "reading_minutes": 5,
            "href": "/bonuses/welcome-campaign",
            "excerpt": "شرایط دریافت پاداش را مطالعه کنید.",
            "cover_image": ""
          }
        ]
      },
      {
        "key": "videos",
        "kind": "video",
        "title": "ویدیوهای آموزشی",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/tutorials",
        "items": [
          {
            "id": 91,
            "slug": "youtube-BOwRO3z-_Bo",
            "title": "شروع آموزش",
            "href": "/tutorials/youtube-BOwRO3z-_Bo",
            "description": "آموزش گام‌به‌گام شروع کار.",
            "cover_image": "/images/tutorials/start.webp",
            "category": {
              "id": 6,
              "name": "شروع کار",
              "key": "category-6"
            },
            "duration_seconds": 862,
            "youtube_url": "https://www.youtube.com/watch?v=BOwRO3z-_Bo",
            "youtube_video_id": "BOwRO3z-_Bo",
            "aparat_url": "https://www.aparat.com/v/tqlk9l0",
            "aparat_video_id": "tqlk9l0",
            "default_provider": "aparat",
            "duration_source": "aparat",
            "created_at": "2026-10-25T08:00:00.000000Z",
            "updated_at": "2026-10-26T08:00:00.000000Z",
            "published_at": "2026-10-26T08:00:00.000000Z"
          }
        ]
      },
      {
        "key": "articles",
        "kind": "article",
        "title": "مقالات تحلیلی",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/blog",
        "items": [
          {
            "id": 121,
            "slug": "market-guide",
            "title": "راهنمای بازار",
            "href": "/blog/market-guide",
            "cover_image": "/images/blog/market-guide.webp",
            "description": "مقدمه‌ای برای شناخت بازار.",
            "excerpt": "مقدمه‌ای برای شناخت بازار.",
            "category": {
              "id": 4,
              "name": "راهنما",
              "key": "category-4"
            },
            "author": {
              "id": 7,
              "name": "تحریریه تحلیل"
            },
            "reading_minutes": 5,
            "published_at": "2026-10-26T08:00:00.000000Z"
          }
        ]
      }
    ],
    "menu": [
      {
        "key": "exchanges",
        "items": [
          {
            "id": 31,
            "slug": "sample-exchange",
            "title": "صرافی نمونه",
            "english_name": "Sample Exchange",
            "logo": "",
            "href": "/exchanges/sample-exchange"
          }
        ],
        "label": "صرافی‌ها",
        "all_href": "/exchanges"
      },
      {
        "key": "forex",
        "items": [
          {
            "id": 11,
            "slug": "sample-broker",
            "title": "بروکر نمونه",
            "english_name": "Sample Broker",
            "logo": "",
            "href": "/brokers/sample-broker"
          }
        ],
        "label": "بروکرهای فارکس",
        "all_href": "/brokers"
      },
      {
        "key": "stock-brokers",
        "items": [
          {
            "id": 12,
            "slug": "sample-stock",
            "title": "کارگزاری نمونه",
            "english_name": "Sample Stock",
            "logo": "",
            "href": "/stock-brokers/sample-stock"
          }
        ],
        "label": "کارگزاری‌های بورس",
        "all_href": "/stock-brokers"
      },
      {
        "key": "gold-platforms",
        "items": [
          {
            "id": 13,
            "slug": "sample-gold",
            "title": "پلتفرم طلای نمونه",
            "english_name": "Sample Gold",
            "logo": "",
            "href": "/gold-platforms/sample-gold"
          }
        ],
        "label": "پلتفرم‌های طلا",
        "all_href": "/gold-platforms"
      },
      {
        "key": "prop-firms",
        "items": [
          {
            "id": 14,
            "slug": "sample-prop",
            "title": "پراپ‌فرم نمونه",
            "english_name": "Sample Prop",
            "logo": "",
            "href": "/prop-firms/sample-prop"
          }
        ],
        "label": "پراپ‌فرم‌ها",
        "all_href": "/prop-firms"
      }
    ],
    "links": {
      "telegram": "https://t.me/example",
      "instagram": "https://www.instagram.com/example/",
      "youtube": "https://www.youtube.com/@example",
      "aparat": "https://www.aparat.com/example"
    },
    "advertisements": {
      "home_header": [
        {
          "image": "/images/ads/header.gif",
          "link": "/advertising",
          "id": "home_header-0",
          "alt": "تبلیغات"
        }
      ],
      "news_and_articles": [
        {
          "image": "/images/ads/article.webp",
          "link": "/advertising",
          "id": "news_and_articles-0",
          "alt": "تبلیغات"
        }
      ]
    },
    "infinite_slider": [
      {
        "id": "logo-item-1",
        "logo_src": "/images/logos/sample.png",
        "name": "برند نمونه",
        "english_name": "Sample Brand"
      }
    ],
    "revision": "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

### 3.3 Fields and rendering

| Field | Type / meaning | Frontend behavior |
| --- | --- | --- |
| `data.revision` | Non-empty opaque string | Compare only for projection revalidation; never submit as `expected_version` |
| `hero` | Object with the eight fields below | Render one complete escaped title, not separate repeated title lines |
| `infinite_slider` | Ordered array of settings logo items | Preserve identity/order; visual loop duplicates stay client-only |
| `carousels` | Exactly six ordered objects | Use the fixed key/kind mapping below |
| `menu` | Exactly five ordered groups | Reuse for desktop, mobile, and selected footer links |
| `links` | Four strings | Hide any control whose destination is `""` |
| `advertisements` | Two placement arrays | Empty arrays remove the corresponding wrapper |

Hero fields: `title`, `accent`, `description`, `primary_label`, `primary_url`, `secondary_label`, `secondary_mobile_label`, `secondary_url` are all strings. There are eight hero keys. `accent` can be empty; otherwise highlight its first exact occurrence in `title`. Compact and normal secondary labels share `secondary_url`. Action destinations may be validated root-relative paths/fragments or HTTP/HTTPS URLs. Decorations, brand artwork, FAQ copy, community artwork, and theme are frontend content, not extra API fields.

Each logo has `id: string`, `logo_src: string`, `name: string`, `english_name: string`; it has no destination. The ID is a stable settings item identifier, not a numeric content ID. If the referenced image becomes unavailable, the item remains with `logo_src: ""`; use a neutral fallback and keep its text/identity. Browser image failures also use a fallback without changing settings.

Each section has `key`, `kind`, `title`, `description`, `action_label`, `action_url`, `items`.

| Section key | Kind | Item type | Important behavior |
| --- | --- | --- | --- |
| `exchanges` | `exchange` | Exchange homepage card | Real exchange identity and comment count |
| `aparat` | `video` | Tutorial summary | Requires valid YouTube and matching valid Aparat metadata |
| `news` | `news` | Article/news summary | API returns all eligible selections; UI uses first as main and next up to three as supporting cards |
| `bonuses` | `bonus` | Bonus homepage card | Publication and campaign state are independent |
| `videos` | `video` | Tutorial summary | YouTube-only tutorials are allowed |
| `articles` | `article` | Article/news summary | Same summary representation used by blog endpoints |

Preserve administrator selection order. There is no new five/nine-item cap on homepage sections. Unpublished/deleted/missing/ineligible selections are omitted without diagnostics or replacement records. Empty items hide the section's visible card/action shell; retain an invisible anchor only if another UI action needs it.

Menu groups always appear as `exchanges`, `forex`, `stock-brokers`, `gold-platforms`, `prop-firms`. Their `all_href` values are `/exchanges`, `/brokers`, `/stock-brokers`, `/gold-platforms`, `/prop-firms`. Each group has `key`, `items`, `label`, `all_href`. A menu item has exactly `id`, `slug`, `title`, `english_name`, `logo`, `href`. Up to nine eligible selections are returned per group, in saved order. Missing logo is `""`; do not replace a record or infer its identity from the image. Empty groups retain their archive navigation.

### 3.4 Card field reference

**Exchange homepage card**

| Key | Type | Meaning |
| --- | --- | --- |
| `id`, `slug`, `title`, `english_name` | number, string, string, string | Owning exchange identity and names |
| `href` | string | Current website detail path |
| `logo` | string | Approved image reference or `""` |
| `founding_year` | string | Display text; `""` means unspecified; not guaranteed to be a numeric year |
| `platform_label` | string | Platform-type display text; can be `""` |
| `rating_average` | number or null | Current exchange implementation returns null |
| `rating_count` | number | Current exchange implementation returns 0; do not invent a rating |
| `comment_count` | number | Visible top-level comments, independently counted |

**Article/news summary:** every field is documented in [4.1](#41-shared-article-and-news-summary). News uses its own namespace and `/news/{slug}`; no body HTML is included.

**Tutorial homepage summary**

| Key | Type | Meaning |
| --- | --- | --- |
| `id`, `slug`, `title`, `href` | number + strings | One internal tutorial identity; preserve provider ID/slug casing |
| `description` | string | Saved plain multiline description; may be empty |
| `cover_image` | string | Approved public saved cover or `""`; the frontend supplies its neutral fallback |
| `category` | Category or null | Current live category |
| `duration_seconds` | positive number or null | Known metadata; format on frontend; null is unknown, not zero |
| `youtube_url`, `youtube_video_id` | strings | Canonical watch URL and required matching YouTube ID |
| `aparat_url` | string | Canonical watch URL or `""` |
| `aparat_video_id` | string or null | Null when no Aparat source |
| `default_provider` | `aparat` or `youtube` | Aparat when present, otherwise YouTube |
| `duration_source` | `aparat`, `youtube`, or null | Provider supplying the known matching duration; may differ from default provider |
| `created_at`, `updated_at` | UTC string or null | Editorial creation and last update |
| `published_at` | UTC string or null | First availability on this website |

Homepage cards always navigate to `href`; provider watch URLs do not replace the internal page. No embed URL, iframe HTML, editorial version, or full tutorial detail is returned here.

**Bonus homepage card**

Keys are `id`, `slug`, `title`, `description`, `category`, `published_at`, `campaign`, `rating`, `rating_count`, `rating_revision`, `reading_minutes`, `href`, `excerpt`, and `cover_image`. Description is the explicit saved string; excerpt is a bounded plain-text display summary. Category can be null and cover can be `""`. The shared summary also includes integer `reading_minutes` and `rating_revision`; no author, comment count, or personal rating context is included.

`rating` is null when unrated, otherwise a numeric mean from 1 to 5; the server does not format or round it for display. `rating_count` counts votes, not comments. `campaign` always has `provider`, `amount_display`, `state`. Provider is null or `{ exchange_id, name, english_name, logo }`; `exchange_id` can itself be null for an approved historical provider snapshot. Logo can be empty. A withdrawn exchange is not serialized as a live provider; safe historical metadata may remain. `amount_display` is a string, possibly empty. State is exactly `ended` or `unspecified`; do not infer `active`, expiry, reward eligibility, or a claim action.

### 3.5 Advertisements and shared links

`home_header` is the shared upper placement across public routes. `news_and_articles` supplies the common in-page placement. Each resolved ad has `image`, `link`, `id`, `alt`; its ID is based on placement and saved index (for example `home_header-0`) and can change after reordering. It is not a campaign ID. Unavailable ad images are omitted from the public array.

Always render ad anchors with `target="_blank" rel="sponsored noopener noreferrer"`, including internal destinations. These attributes are frontend responsibilities: the current response does **not** include `target` or `rel` fields. Animated valid GIF images are supported.

Use the same `links.telegram`, `instagram`, `youtube`, `aparat` destinations everywhere, in that visual order. Header/community Telegram uses the saved Telegram URL. External social anchors use `target="_blank" rel="noopener noreferrer"`. Image references can be root-relative managed/static paths or approved absolute HTTPS URLs. Resolve a backend-relative media reference against the configured API/media origin, not accidentally against an unrelated frontend deployment host; keep content `href` on the website origin.

### 3.6 Complete valid empty response — 200

This is an intentionally empty selection, not a storage error. All fixed groups remain present.

```json
{
  "data": {
    "hero": {
      "title": "آگاهانه تحلیل کنید، آگاهانه انتخاب کنید",
      "accent": "آگاهانه انتخاب کنید",
      "description": "بررسی پلتفرم‌ها، اخبار بازار و آموزش‌های کاربردی.",
      "primary_label": "بررسی صرافی‌ها",
      "primary_url": "/#exchanges",
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
        "items": []
      },
      {
        "key": "aparat",
        "kind": "video",
        "title": "تحلیل در آپارات",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/tutorials",
        "items": []
      },
      {
        "key": "news",
        "kind": "news",
        "title": "اخبار بازار",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/news",
        "items": []
      },
      {
        "key": "bonuses",
        "kind": "bonus",
        "title": "بونوس‌ها",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/bonuses",
        "items": []
      },
      {
        "key": "videos",
        "kind": "video",
        "title": "ویدیوهای آموزشی",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/tutorials",
        "items": []
      },
      {
        "key": "articles",
        "kind": "article",
        "title": "مقالات تحلیلی",
        "description": "",
        "action_label": "مشاهده همه",
        "action_url": "/blog",
        "items": []
      }
    ],
    "menu": [
      {
        "key": "exchanges",
        "items": [],
        "label": "صرافی‌ها",
        "all_href": "/exchanges"
      },
      {
        "key": "forex",
        "items": [],
        "label": "بروکرهای فارکس",
        "all_href": "/brokers"
      },
      {
        "key": "stock-brokers",
        "items": [],
        "label": "کارگزاری‌های بورس",
        "all_href": "/stock-brokers"
      },
      {
        "key": "gold-platforms",
        "items": [],
        "label": "پلتفرم‌های طلا",
        "all_href": "/gold-platforms"
      },
      {
        "key": "prop-firms",
        "items": [],
        "label": "پراپ‌فرم‌ها",
        "all_href": "/prop-firms"
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
    "infinite_slider": [],
    "revision": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

### 3.7 Conditional response — 304

A 200 response includes `ETag`, with a quoted value derived from `data.revision`. Send the exact returned ETag in `If-None-Match`, including its quoting. The backend recomputes the current projection/eligibility before deciding 304.

```http
GET /api/v1/page-settings
Accept: application/json
If-None-Match: "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
```

```http
HTTP/1.1 304 Not Modified
ETag: "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
X-Request-ID: req_92c2734b-d8ef-40a6-aafd-b3e81c43b905
Cache-Control: no-cache, must-revalidate
```

There is no JSON body. Keep the previously validated snapshot. Without a retained snapshot, make a normal unconditional request. If content is withdrawn or another serialized field changes, an old ETag must lead to a new 200 projection rather than reusing the old data. Only this endpoint implements ETag/304 in this delivery; do not assume conditional support for the others.

Possible errors: 422 invalid query/body, 429 rate limit, 503 `PUBLIC_CONFIGURATION_UNAVAILABLE`, unexpected 500. Full bodies are in section 8.

## 4. Public blog

### 4.1 Shared article and news summary

Homepage article/news cards and blog list/featured/related records share this representation:

```json
{
  "id": 121,
  "slug": "market-guide",
  "title": "راهنمای بازار",
  "href": "/blog/market-guide",
  "cover_image": "/images/blog/market-guide.webp",
  "description": "مقدمه‌ای برای شناخت بازار.",
  "excerpt": "مقدمه‌ای برای شناخت بازار.",
  "category": {
    "id": 4,
    "name": "راهنما",
    "key": "category-4"
  },
  "author": {
    "id": 7,
    "name": "تحریریه تحلیل"
  },
  "reading_minutes": 5,
  "published_at": "2026-10-26T08:00:00.000000Z"
}
```

| Key | Type | Exact interpretation |
| --- | --- | --- |
| `id` | positive safe integer | Stable record ID; comments use it, never the slug |
| `slug` | string | Current public slug |
| `title` | string | Saved Unicode title |
| `href` | string | Website canonical path; `/blog/{slug}` for articles, `/news/{slug}` for news |
| `cover_image` | string | Approved saved cover or `""`; use a neutral frontend fallback |
| `description` | string | Explicit saved optional introduction; can be `""` |
| `excerpt` | string | Description when present, otherwise derived body text; whitespace collapsed, entities decoded, no HTML, at most 300 Unicode characters |
| `category` | Category or null | `{id,name,key}` for a current live category; blog key is `category-{id}` |
| `author` | AuthorSummary or null | Only `{id,name}`; no bio/avatar/email/account details |
| `reading_minutes` | integer or null | Saved minutes from 1 to 1440 when known; null means unspecified |
| `published_at` | UTC string or null | First publication; ordinary edits do not promote list position |

Do not compute and persist reading time or excerpts from the browser. Do not render an excerpt as an extra introduction above the full body when explicit description is empty. A missing author is null; a retained soft-deleted author can keep its historical ID/display name. No username or email is a display fallback.

### 4.2 Archive — GET `/blog/articles`

| Query | Allowed values | Default |
| --- | --- | --- |
| `q` | Optional plain string, max 200 trimmed characters | Empty, no text filter |
| `category_id` | Positive safe integer identifying a live blog category | Omitted = All |
| `page` | Positive safe integer | 1 |
| `per_page` | Exactly 6 | 6 |

Search title, explicit full description (or the derived excerpt when description is empty), and current category name. Arabic yeh/alef maksura and kaf map to Persian forms, ZWNJ becomes a spacing boundary, whitespace collapses, and case folds. Unlike global search, blog search preserves word spacing after normalization. Query text is a literal substring, not SQL or regex syntax.

Apply search before facets. `facets.all_count` is the search-only total; `uncategorized_count` plus all category counts equals it. Selected category affects `meta.total` and items, not the other facet counts. All live categories, including zero-count ones, are returned, sorted by normalized name then ID. The virtual All filter is not a stored category. Unknown/deleted categories produce a 422 field error; do not silently send their names or substitute All.

Items order by `published_at DESC, id DESC`, after eligibility and both filters. Featured articles remain in the ordinary archive. Separate requests are fresh views; offset pagination is not an immutable snapshot.

**Populated 200** for `?q=بازار&category_id=4&page=1&per_page=6`:

```json
{
  "data": [
    {
      "id": 121,
      "slug": "market-guide",
      "title": "راهنمای بازار",
      "href": "/blog/market-guide",
      "cover_image": "/images/blog/market-guide.webp",
      "description": "مقدمه‌ای برای شناخت بازار.",
      "excerpt": "مقدمه‌ای برای شناخت بازار.",
      "category": {
        "id": 4,
        "name": "راهنما",
        "key": "category-4"
      },
      "author": {
        "id": 7,
        "name": "تحریریه تحلیل"
      },
      "reading_minutes": 5,
      "published_at": "2026-10-26T08:00:00.000000Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 6,
    "total": 1,
    "total_pages": 1
  },
  "facets": {
    "all_count": 2,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 2,
        "name": "اخبار و تحلیل",
        "key": "category-2",
        "count": 1
      },
      {
        "id": 4,
        "name": "راهنما",
        "key": "category-4",
        "count": 1
      },
      {
        "id": 8,
        "name": "ویدیو",
        "key": "category-8",
        "count": 0
      }
    ]
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

There is one result after category filtering, while two match the text across categories. `meta.total` is not `facets.all_count` in this example.

**Empty search 200**; zero-count category options are still present:

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
        "name": "راهنما",
        "key": "category-4",
        "count": 0
      }
    ]
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Beyond-last page 200** for `?page=9`, in a catalog with one public article:

```json
{
  "data": [],
  "meta": {
    "page": 9,
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
        "name": "راهنما",
        "key": "category-4",
        "count": 1
      }
    ]
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Move to the last valid page and request it explicitly if a current nonzero total makes the requested page obsolete. Do not relabel this empty page as a populated previous page. For total zero, keep logical page 1 with `total_pages: 0` and no numbered pager.

Possible errors: 422 filter/query validation, 429, 503 `BLOG_UNAVAILABLE`, 500.

### 4.3 Featured — GET `/blog/featured`

No query/body. Up to five distinct current public articles, newest first publication then highest ID. This selection is independent of homepage curation and archive filter/page changes.

**Populated 200** with one available record:

```json
{
  "data": [
    {
      "id": 121,
      "slug": "market-guide",
      "title": "راهنمای بازار",
      "href": "/blog/market-guide",
      "cover_image": "/images/blog/market-guide.webp",
      "description": "مقدمه‌ای برای شناخت بازار.",
      "excerpt": "مقدمه‌ای برای شناخت بازار.",
      "category": {
        "id": 4,
        "name": "راهنما",
        "key": "category-4"
      },
      "author": {
        "id": 7,
        "name": "تحریریه تحلیل"
      },
      "reading_minutes": 5,
      "published_at": "2026-10-26T08:00:00.000000Z"
    }
  ],
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Empty 200**; hide the featured mosaic:

```json
{
  "data": [],
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

There is no meta/facets object, no featured mutation endpoint, and no filler duplication to reach five cards. Possible errors: 422 unsupported query/body, 429, 503 `BLOG_UNAVAILABLE`, 500.

### 4.4 Detail — GET `/blog/articles/{slug}`

No query/body. Resolve current slug or a previously public owned alias. A Draft, Scheduled, deleted, or missing record/alias returns the same 404 `ARTICLE_NOT_FOUND`, regardless of a panel cookie.

**Complete 200**; all summary fields remain present:

```json
{
  "data": {
    "id": 121,
    "slug": "market-guide",
    "title": "راهنمای بازار",
    "href": "/blog/market-guide",
    "cover_image": "/images/blog/market-guide.webp",
    "description": "مقدمه‌ای برای شناخت بازار.",
    "excerpt": "مقدمه‌ای برای شناخت بازار.",
    "category": {
      "id": 4,
      "name": "راهنما",
      "key": "category-4"
    },
    "author": {
      "id": 7,
      "name": "تحریریه تحلیل"
    },
    "reading_minutes": 5,
    "published_at": "2026-10-26T08:00:00.000000Z",
    "content": "<h2 id=\"article-heading-1\">شروع تحلیل</h2><p>متن کامل <strong>مقاله</strong>.</p><h3 id=\"article-heading-2\">مثال</h3><table><tbody><tr><td>نمونه</td></tr></tbody></table>",
    "outline": [
      {
        "id": "article-heading-1",
        "title": "شروع تحلیل",
        "level": 2
      },
      {
        "id": "article-heading-2",
        "title": "مثال",
        "level": 3
      }
    ],
    "updated_at": "2026-10-26T09:00:00.000000Z",
    "meta_description": "راهنمای شناخت و تحلیل بازار.",
    "seo_description": "راهنمای شناخت و تحلیل بازار.",
    "author_profile": {
      "id": 7,
      "name": "تحریریه تحلیل",
      "bio": "آموزش و بررسی بازارهای مالی.",
      "linkedin_url": "https://www.linkedin.com/in/example-editor",
      "avatar_url": null
    },
    "tags": [
      "راهنما"
    ],
    "comment_count": 2
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

| Additional field | Type | Behavior |
| --- | --- | --- |
| `content` | string | Complete sanitized rich-text HTML; can be empty |
| `outline` | array | Ordered `{id,title,level}`; level is 2 or 3; empty when no headings |
| `updated_at` | UTC string or null | Article update timestamp, not first publication |
| `meta_description` | string | Explicit saved override or `""` |
| `seo_description` | string | Plain text, override first, then summary/excerpt, bounded to 500 characters |
| `author_profile` | object or null | `{id,name,bio,linkedin_url,avatar_url}` only |
| `tags` | string array | Exactly current category name as a single item, or `[]` |
| `comment_count` | non-negative number | Visible top-level comment total, excluding replies |

Profile name/ID agree with summary author. Bio and LinkedIn can be empty strings; avatar is a URL or null. Retained soft-deleted authors keep historical name/ID but bio/LinkedIn clear and avatar is null. Do not display guessed biographies or staff account details.

Body H1 becomes H2 in the display copy; H2/H3 get deterministic `article-heading-1`, `article-heading-2`, etc. IDs supplied by authors are discarded before safe rendering. Use `outline.title`, not `outline.text`, as escaped navigation text. Keep full tables, formatting, lists, images, supported video, and restricted YouTube embeds in a responsive rich-text container. These transformations do not overwrite editorial HTML. Unsafe saved HTML that cannot be rendered safely produces 503 `BLOG_UNAVAILABLE`; do not fall back to unsanitized HTML.

**Complete 200 with optional values absent:**

```json
{
  "data": {
    "id": 122,
    "slug": "short-note",
    "title": "یادداشت کوتاه",
    "href": "/blog/short-note",
    "cover_image": "",
    "description": "",
    "excerpt": "متن یادداشت.",
    "category": null,
    "author": null,
    "reading_minutes": null,
    "published_at": "2026-10-26T08:00:00.000000Z",
    "content": "<p>متن یادداشت.</p>",
    "outline": [],
    "updated_at": "2026-10-26T09:00:00.000000Z",
    "meta_description": "",
    "seo_description": "متن یادداشت.",
    "author_profile": null,
    "tags": [],
    "comment_count": 0
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

The related/comments APIs are separate bounded reads. Detail does not embed the entire conversation, related array, advertisements, management version, status, schedule, or private author fields.

### 4.5 Historical aliases — 301

For a still-public article, an old public slug redirects directly to the current **API** URL:

```http
GET /api/v1/blog/articles/old-market-guide
```

```http
HTTP/1.1 301 Moved Permanently
Location: /api/v1/blog/articles/market-guide
X-Request-ID: req_92c2734b-d8ef-40a6-aafd-b3e81c43b905
```

No JSON body. For `/old-market-guide/related`, Location is `/api/v1/blog/articles/market-guide/related`. The website redirect is separately `/blog/market-guide`. Browser fetch/Axios normally follow API redirects automatically; compare the final detail's slug/href with the requested website slug and perform the website's permanent canonical redirect in the server route resolver before rendering. Do not send users to an API JSON path. Owned aliases that were never public do not redirect. Hidden/deleted parents make all aliases 404.

Possible detail errors: 404 `ARTICLE_NOT_FOUND`, 422 unsupported query/body, 429, 503 `BLOG_UNAVAILABLE`, 500.

### 4.6 Related — GET `/blog/articles/{slug}/related`

No query/body or pagination. Parent and alias rules are identical to detail. Exclude the parent's stable numeric ID. Other eligible articles in the same non-null live category come first, then other categories, with `published_at DESC, id DESC` inside each group. A null category adds no same-category priority.

**Populated 200** with a related article:

```json
{
  "data": [
    {
      "id": 123,
      "slug": "risk-guide",
      "title": "راهنمای مدیریت ریسک",
      "href": "/blog/risk-guide",
      "cover_image": "/images/blog/market-guide.webp",
      "description": "نکات پایه مدیریت ریسک.",
      "excerpt": "نکات پایه مدیریت ریسک.",
      "category": {
        "id": 4,
        "name": "راهنما",
        "key": "category-4"
      },
      "author": {
        "id": 7,
        "name": "تحریریه تحلیل"
      },
      "reading_minutes": 5,
      "published_at": "2026-10-26T08:00:00.000000Z"
    }
  ],
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Empty 200**, for a public parent with no other eligible records:

```json
{
  "data": [],
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

At most six distinct summary records are returned. Hide an empty block. A hidden/missing parent produces 404, not an empty success or unrelated recommendations. Possible errors: 404 `ARTICLE_NOT_FOUND`, 422, 429, 503 `BLOG_UNAVAILABLE`, 500.

### 4.7 Blog page integration

Use shareable website URL state `/blog?q=...&category_id=...&page=...`, omitting empty/default values. Reset page to 1 on query/category changes, keep filters on pagination, and debounce typing around 300 ms while explicit submit applies immediately. Cancel/ignore obsolete requests.

Use `advertisements.news_and_articles` from the shared settings snapshot. Fetch comments with `section=blog` and `parent_id=data.id`; preserve that identity across slug changes. Use `href`, `title`, `seo_description`, publication/update times, safe cover, and public author for SSR metadata. Do not reconstruct author LinkedIn URLs, create tag archives, fabricate market prices, or add backend writes for sharing.

## 5. Public comments and official replies

### 5.1 Typed targets

| Search/feed type | Comment section | Parent ID | Website path |
| --- | --- | --- | --- |
| `articles` | `blog` | Article ID | `/blog/{slug}` |
| `news` | `news` | News ID | `/news/{slug}` |
| `exchanges` | `exchanges` | Exchange ID | `/exchanges/{slug}` |
| `brokers` | `brokers` | Forex broker ID | `/brokers/{slug}` |
| `stock-brokers` | `stock-brokers` | Stock brokerage ID | `/stock-brokers/{slug}` |
| `gold-platforms` | `gold-platforms` | Gold platform ID | `/gold-platforms/{slug}` |
| `prop-firms` | `prop-firms` | Prop firm ID | `/prop-firms/{slug}` |
| `bonuses` | `bonus` | Bonus ID | `/bonuses/{slug}` |
| `tutorials` | Unsupported | No comment parent | No public tutorial comments |

`all` is also unsupported publicly. The bonus comment section is singular; forex is plural. IDs coinciding across types do not join conversations. Obtain the section and numeric ID from the resolved owning content, not a mutable slug/provider ID/title.

### 5.2 Read — GET `/comments/{section}/{parent_id}`

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

### 5.3 Submit — POST `/comments/{section}/{parent_id}`

Required browser request:

```http
POST /api/v1/comments/blog/121
Accept: application/json
Content-Type: application/json
Origin: <configured-public-website-origin>
Idempotency-Key: 39d20489-3543-48e4-bfa5-4186e125b71c
```

Origin above is illustrative header documentation; frontend JavaScript lets the browser set it. Do not add panel CSRF or bonus-rating voter credentials.

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

### 5.4 Idempotent retry and uncertain outcomes

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

Possible POST errors: 400 `INVALID_JSON`, 404 `CONTENT_NOT_FOUND`/`COMMENT_NOT_FOUND`, 409 `IDEMPOTENCY_CONFLICT`, 413 `PAYLOAD_TOO_LARGE`, 415 `UNSUPPORTED_MEDIA_TYPE`, 422 `VALIDATION_FAILED`, 429 `RATE_LIMITED`, 503 `COMMENTS_UNAVAILABLE`, 500 `INTERNAL_ERROR`. Full error bodies are in section 8.

## 6. Global search

### 6.1 Request — GET `/search`

| Query | Allowed values | Default |
| --- | --- | --- |
| `q` | Trimmed plain text, max 200 Unicode characters | Empty / recent content |
| `surface` | `header` or `page` | `page` |
| `type` | Header: `all`, `articles`, `news`, `tutorials`; page: only `all` | `all` |
| `page` | Positive safe integer | 1 |
| `per_page` | Integer 1–50 | 20 |

`surface=header&type=all` covers only articles/news/tutorials. A selected header type narrows that same set. `surface=page` covers all nine types and rejects a specific type selector. Do not send `type=exchanges` for page search; that type exists in results/feed, not as a page search filter. Mobile uses header/all; do not request suggestions when its empty state is ordinary navigation.

| Search/feed type | Website detail prefix |
| --- | --- |
| `articles` | `/blog/` |
| `bonuses` | `/bonuses/` |
| `brokers` | `/brokers/` |
| `exchanges` | `/exchanges/` |
| `gold-platforms` | `/gold-platforms/` |
| `news` | `/news/` |
| `prop-firms` | `/prop-firms/` |
| `stock-brokers` | `/stock-brokers/` |
| `tutorials` | `/tutorials/` |

A result's identity is `(type,id)`, not title, slug, image, or ID alone. A tutorial with two providers appears once. News results point to their own details, never a synthetic generic news archive entry.

### 6.2 Matching and ordering

The backend maps Arabic yeh/alef maksura to Persian yeh, kaf to Persian kaf, removes whitespace/ZWNJ/zero-width spaces for comparison, and case-folds. `filters.q` retains trimmed display input, not the destructive matching form. A query empty after normalization uses recent-content behavior. This differs deliberately from blog's space-preserving normalization.

Search title, English name when present, saved plain description (or derived bounded public excerpt), and live category name. Bonuses also search the safe provider Persian/English name and amount-display text. A long explicit description can match beyond the 300-character returned display summary. Fields match independently; suffix/prefix text from adjacent fields is not joined into artificial matches. Raw HTML markup, comments/emails, private author identities, credentials, and administrative data are not indexed.

Treat query as one literal substring. Percent, underscore, and regex-like text have no wildcard/executable semantics. Rank exact title/English-name match first, then prefix, then substring, then supporting-field match. Within each group sort by `published_at DESC`, ASCII `type ASC`, numeric `id DESC`. Empty queries use that timestamp/type/ID order without relevance ranking.

### 6.3 Complete populated 200

Example for `?q=بازار&surface=header&type=articles&page=1&per_page=20`:

```json
{
  "data": [
    {
      "type": "articles",
      "id": 121,
      "slug": "market-guide",
      "title": "راهنمای بازار",
      "english_name": "",
      "description": "مقدمه‌ای برای شناخت بازار.",
      "image": "/images/blog/market-guide.webp",
      "href": "/blog/market-guide",
      "published_at": "2026-10-26T08:00:00.000000Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "بازار",
    "surface": "header",
    "type": "articles"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

| Result field | Type | Meaning |
| --- | --- | --- |
| `type` | Nine-type union above | Required part of record identity |
| `id` | positive safe integer | Owning domain ID |
| `slug` | string | Current canonical slug |
| `title` | string | Escaped public display text |
| `english_name` | string | Empty for domains without one |
| `description` | string | Plain display excerpt, max 300 Unicode characters, possibly empty |
| `image` | string | Approved cover/logo or `""`; neutral fallback |
| `href` | string | Current website detail path, not API/provider URL |
| `published_at` | UTC string or null | First website publication/availability, not last edit/vote |

No full bodies, iframe HTML, categories, comment counts, author profiles, voter data, or index internals are returned in this result schema.

### 6.4 Complete empty, suggestions, and beyond-last responses

**Zero matches — 200:**

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 0,
    "total_pages": 0
  },
  "filters": {
    "q": "unmatched query",
    "surface": "page",
    "type": "all"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Empty-query header suggestions — 200:**

```json
{
  "data": [
    {
      "type": "articles",
      "id": 121,
      "slug": "market-guide",
      "title": "راهنمای بازار",
      "english_name": "",
      "description": "مقدمه‌ای برای شناخت بازار.",
      "image": "/images/blog/market-guide.webp",
      "href": "/blog/market-guide",
      "published_at": "2026-10-26T08:00:00.000000Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "surface": "header",
    "type": "all"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Beyond-last page — 200**, for one matching item and requested page 9:

```json
{
  "data": [],
  "meta": {
    "page": 9,
    "per_page": 20,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "بازار",
    "surface": "page",
    "type": "all"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

All three are successful states, not outages. `meta.total` counts unique eligible matches across the selected scope before pagination. `total_pages` is zero for zero matches. API pages are bounded; use incremental loading and a truthful loaded/total display instead of silently treating the first 20 as the entire dataset. Deduplicate appended results by type+ID and preserve server order.

No separate suggestions endpoint exists. Empty header query returns recent eligible suggestions; empty page query returns recent site-wide content. Reset loaded pages when query/surface/type changes. Changes between requests can shift offsets; no immutable search session is promised.

### 6.5 Frontend behavior and unavailable state

Use one shared service for desktop dialog, mobile menu, `/search`, and the 404 search entry. Debounce typing around 300 ms, make explicit submit immediate, and cancel/ignore obsolete results. Do not request overlays while closed or let a delayed response reopen a closed overlay. Header submit can stay within the dialog. Not-found submit navigates a nonempty query to `/search?q=...`; it does not turn the original 404 into HTTP 200.

On the full page, commit trimmed `q` to website URL state and restore it on reload/back/forward; no visible type/category/sort controls are required. SSR the initial results and hydrate the same query cache. Search variants should use the frontend's noindex policy and must not enter the dynamic sitemap.

Search uses the local database read model, not an external search service. Before every response the backend checks authoritative source stamps, eligibility, and total membership for the requested scope. Missing initialization, source inconsistency, or storage failure produces **503 `SEARCH_UNAVAILABLE`**, not stale snippets or fabricated zero totals. Do not retry indefinitely or keep displaying potentially withdrawn cached snippets as valid. Successful reads require the deployment/backfill described in section 11.

Possible errors: 422 invalid query/scope/type/paging, 429, 503 `SEARCH_UNAVAILABLE`, unexpected 500.

## 7. Sitemap content feed

### 7.1 Request — GET `/sitemap/entries`

This is a JSON content-discovery feed for the website server, not the XML sitemap URL itself.

| Query | Allowed values | Default |
| --- | --- | --- |
| `type` | `all` or one of the nine search/feed types | `all` |
| `limit` | Integer 1–1000 | 1000 |
| `cursor` | Opaque server-issued string, max 8192 characters | Omitted, start a traversal |

No login/body/CSRF. Keep exactly the same effective type and limit for every page of a traversal. URL-encode cursors with URLSearchParams; do not decode, edit, construct, or log them unnecessarily. Use `(type,id)` for identity and `path` for canonical URL uniqueness.

### 7.2 Complete one-page response — 200

Example with `type=all`, `limit=1000` and two eligible records:

```json
{
  "data": [
    {
      "type": "articles",
      "id": 121,
      "title": "راهنمای بازار",
      "path": "/blog/market-guide",
      "last_modified_at": "2026-10-26T09:00:00.123456Z"
    },
    {
      "type": "news",
      "id": 21,
      "title": "تازه‌های بازار",
      "path": "/news/market-update",
      "last_modified_at": null
    }
  ],
  "meta": {
    "type": "all",
    "limit": 1000,
    "total": 2,
    "has_more": false,
    "next_cursor": null,
    "revision": "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

| Entry key | Type | Meaning |
| --- | --- | --- |
| `type` | Nine-type union | Owning public content namespace |
| `id` | positive safe integer | Stable domain identity |
| `title` | string | Real Unicode title for optional human-readable link lists |
| `path` | string | Canonical root-relative website detail path; no origin/query/fragment |
| `last_modified_at` | UTC string or null | Reliable meaningful public-content modification time; null when not known |

| Meta key | Type | Meaning |
| --- | --- | --- |
| `type` | `all` or one content type | Effective scope |
| `limit` | integer | Effective page size |
| `total` | integer | Complete eligible scope count, not current page length |
| `has_more` | boolean | Whether another page is required |
| `next_cursor` | string or null | Nonempty only when has_more is true |
| `revision` | opaque nonempty string | Current selected-scope revision for a coherent traversal |

Ordering is ASCII type ascending, numeric ID ascending, not publication order. No archive, homepage selection, or first-page cap limits the complete feed. Ended but publicly published bonuses remain included. One tutorial with two providers is one internal URL.

`last_modified_at` is not request/build/cache refresh time. Initial new publication and meaningful visible changes update it. No-op saves, comment activity, vote activity, and unchanged rebuilds do not advance it. Historical records imported by the rebuild use null when a reliable modification time is unknown. A rebuild detecting changed historical content can clear an unreliable prior date rather than invent a new one. The revision also includes a public content fingerprint, so two meaningful edits with an identical clock timestamp still invalidate a cursor.

### 7.3 Complete empty response — 200

Example `?type=bonuses` with no eligible bonuses:

```json
{
  "data": [],
  "meta": {
    "type": "bonuses",
    "limit": 1000,
    "total": 0,
    "has_more": false,
    "next_cursor": null,
    "revision": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

This is a valid complete empty scope, different from 503.

### 7.4 Complete multi-page traversal — 200 then 200

Example `?type=all&limit=1`; two eligible records. `OPAQUE_CURSOR_RETURNED_BY_SERVER` is a documentation placeholder, not a valid token.

**First page:**

```json
{
  "data": [
    {
      "type": "articles",
      "id": 121,
      "title": "راهنمای بازار",
      "path": "/blog/market-guide",
      "last_modified_at": "2026-10-26T09:00:00.123456Z"
    }
  ],
  "meta": {
    "type": "all",
    "limit": 1,
    "total": 2,
    "has_more": true,
    "next_cursor": "OPAQUE_CURSOR_RETURNED_BY_SERVER",
    "revision": "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Request the next page using the exact returned cursor:

```text
GET /api/v1/sitemap/entries?type=all&limit=1&cursor=OPAQUE_CURSOR_RETURNED_BY_SERVER
```

**Final page**, with the same revision, total, type, and limit:

```json
{
  "data": [
    {
      "type": "news",
      "id": 21,
      "title": "تازه‌های بازار",
      "path": "/news/market-update",
      "last_modified_at": null
    }
  ],
  "meta": {
    "type": "all",
    "limit": 1,
    "total": 2,
    "has_more": false,
    "next_cursor": null,
    "revision": "a13e9b0401a2f940ac283a30a18c44720196e1df21ca52efb3536ca64c95b008"
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Only after completion should the consumer validate uniqueness/count and generate/publish its XML. An empty `data` array is not itself a completion signal; use `has_more` and `next_cursor` together.

### 7.5 Cursor validity and concurrency

Cursors are authenticated encrypted tokens binding effective type, limit, revision, public position, and expiration. They expire one hour after the traversal's first cursor is created; following later pages does not extend that expiration.

- Invalid/tampered/expired cursor or changed type/limit: 422 `VALIDATION_FAILED`, usually `fields.cursor`.
- Valid cursor whose relevant feed changed: 409 `SITEMAP_CURSOR_STALE`; no `data` is returned.
- Uninitialized/inconsistent/unavailable source: 503 `SITEMAP_UNAVAILABLE`; no successful partial page.

On stale revision, discard the incomplete traversal and restart without cursor. On expired cursor, also start over. Use bounded retries; do not publish a partial, mixed-revision, duplicate, or count-mismatched sitemap. Keep the previous generated output only when its current revision/eligibility is still validated; an old generated file cannot keep withdrawn URLs public indefinitely.

### 7.6 Website ownership

The frontend/server consumer supplies the trusted configured website origin and static routes. Dynamic entries exclude aliases, legacy redirect paths, drafts/scheduled/deleted records, filters/search URLs, panels, APIs, assets, comment/rating resources, and provider/external URLs. The feed does not create an HTML sitemap page or a frontend route.

Add only real existing static home/archive/information routes under the site's indexing policy. Join origin+validated path, serialize XML with an XML-aware encoder, use title only for an optional human-readable link list, and omit lastmod when null. Do not emit a custom title XML element or use build time as a fabricated lastmod. The XML response, robots reference, XML splitting/indexing, and atomic publication are website responsibilities outside this API implementation.

Possible errors: 422, 409 `SITEMAP_CURSOR_STALE`, 429, 503 `SITEMAP_UNAVAILABLE`, 500. Feed responses use no-store and have no ETag implementation in this delivery.

## 8. Complete error response catalog

The following are complete body examples. Messages are representative; code/field semantics are the integration contract. A source failure does not imply a missing record, and an empty successful result does not imply an outage. Every error body also has matching `X-Request-ID` and no-store semantics.

### 8.1 Validation — 422

**Blog filter/paging:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "category_id": [
        "The selected category id is invalid."
      ],
      "per_page": [
        "The selected per page is invalid."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Unknown/repeated/structured public read query:**

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
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Header/page search mismatch:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "type": [
        "Page search accepts only all."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Comment form fields:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "name": [
        "The name field must be at least 2 characters."
      ],
      "email": [
        "The email field must be a valid email address."
      ],
      "text": [
        "The text field must be at least 10 characters."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Comment missing/invalid UUID v4:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "idempotency_key": [
        "Send a random UUID v4 Idempotency-Key header."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Malformed public target ID:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "id": [
        "Use a positive safe integer identifier."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Unsupported public comment section:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "section": [
        "Choose a supported comment section."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Invalid/expired/mismatched feed cursor:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "cursor": [
        "Invalid, expired or mismatched cursor."
      ]
    }
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

A tampered cursor may instead say `Invalid cursor.` under the same field/code. Comment query validation can use the same 422 envelope with `fields: {}`. An unknown JSON body field appears as its own error key with `This field is not accepted.`.

**GET body rejected:**

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "This endpoint does not accept a body.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

### 8.2 Malformed or duplicate JSON — 400

**Malformed comment JSON:**

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

**Duplicate JSON object keys:**

```json
{
  "error": {
    "code": "INVALID_JSON",
    "message": "Duplicate JSON object keys are not accepted.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Do not retry the same malformed encoding automatically. A syntactically valid JSON array instead of an object is 422, not 400.

### 8.3 Browser Origin and preflight

These public endpoints no longer reject requests because Origin is missing or outside an allowlist. A browser preflight returns 204 with no response body and the shared CORS headers described in section 1. Panel authentication and panel/rating CSRF requirements remain in effect on their separate endpoints; they are not credentials for these public APIs.

### 8.4 Missing public article — 404

```json
{
  "error": {
    "code": "ARTICLE_NOT_FOUND",
    "message": "The public article was not found.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Used by article detail and related-parent resolution, including private/deleted parents and aliases. Render a genuine website 404 with generic safe metadata, not an unavailable or empty article shell.

### 8.5 Missing public comment parent — 404

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

Used for a valid typed parent that is missing, hidden, or deleted, both for GET/POST and idempotent replay. No parent title, comment count, or replies are returned.

### 8.6 Missing stored idempotent comment — 404

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

Parent remains public but the previously created result is no longer publicly visible. Do not recreate it implicitly or present it as saved again.

### 8.7 Idempotency conflict — 409

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

Reconcile the logical submission. A deliberately edited payload/target needs a new UUID; do not blindly change the key on every network retry.

### 8.8 Feed revision changed — 409

```json
{
  "error": {
    "code": "SITEMAP_CURSOR_STALE",
    "message": "The feed changed. Restart from the first page.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Discard the unfinished traversal, then restart from page one with the same intended scope. This differs from invalid/expired token 422.

### 8.9 Comment body too large — 413

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

A framework-wide request-size rejection can instead use `The request body is too large.` with the same code. Proxy/web-server limits can reject a request before Laravel and may not use this JSON envelope; handle that as a transport/deployment error.

### 8.10 Unsupported comment media type — 415

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

### 8.11 Rate limited — 429

The response includes `Retry-After: <seconds>` and this complete body for a read limit:

```json
{
  "error": {
    "code": "RATE_LIMITED",
    "message": "Too many requests.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

For submission limits the message can be `Please wait before trying again.` under the same code. Stop automatic retries until the specified delay passes; keep unsent input and the existing pending logical-submission key.

### 8.12 Unavailable source — 503

Each public family has a distinct stable code; error fields are empty. Full bodies for all five families follow.

**Homepage/settings:**

```json
{
  "error": {
    "code": "PUBLIC_CONFIGURATION_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Blog list/featured/detail/related:**

```json
{
  "error": {
    "code": "BLOG_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Comments read/write:**

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

**Search, including missing/stale read-model initialization:**

```json
{
  "error": {
    "code": "SEARCH_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

**Sitemap feed, including missing/stale read-model initialization:**

```json
{
  "error": {
    "code": "SITEMAP_UNAVAILABLE",
    "message": "Public information is temporarily unavailable.",
    "fields": {}
  },
  "request_id": "req_92c2734b-d8ef-40a6-aafd-b3e81c43b905"
}
```

Do not turn these into empty successful arrays, zero comments, article-not-found metadata, or permission to serve unvalidated stale content. Show a controlled error/retry state; request_id helps backend diagnosis.

### 8.13 Unexpected failure — 500

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

Do not expose stack traces or invent successful content. Bound retries; a POST network/500 outcome may be uncertain and must retain its UUID until reconciled.

### 8.14 Unknown endpoint / unsupported method — 404 or 405

For paths inside the implemented public API envelope, a missing endpoint uses HTTP 404 and an unsupported method uses HTTP 405 with the current shared code `NOT_FOUND`:

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

An HTTP 405 can also carry the framework's Allow header. Do not assume this route-level code means an article lookup returned `ARTICLE_NOT_FOUND`. Public APIs in this document do not intentionally return 401 or redirect to panel login; such a response indicates a wrong endpoint/proxy/integration path.

## 9. TypeScript wire contracts

The following types describe the actual **snake_case wire format**. Do not use one loose generic card interface that hides the different field sets. Convert keys once if the UI uses camelCase. `number` still requires runtime safe-integer checks for IDs/counts; TypeScript alone does not validate responses.

```ts
type UtcTimestamp = string;
type PublicId = number;
type PublicTimestamp = UtcTimestamp | null;

type ContentType =
  | 'articles' | 'news' | 'tutorials' | 'exchanges' | 'brokers'
  | 'stock-brokers' | 'gold-platforms' | 'prop-firms' | 'bonuses';
type CommentSection =
  | 'blog' | 'news' | 'exchanges' | 'brokers'
  | 'stock-brokers' | 'gold-platforms' | 'prop-firms' | 'bonus';
type SearchSurface = 'header' | 'page';
type HeaderSearchType = 'all' | 'articles' | 'news' | 'tutorials';

interface ApiErrorBody {
  error: { code: string; message: string; fields: Record<string, string[]> };
  request_id: string;
}
interface Success<T> { data: T; request_id: string }
interface PageMeta {
  page: number; per_page: number; total: number; total_pages: number;
}
interface Paged<T> extends Success<T[]> { meta: PageMeta }
interface Category { id: PublicId; name: string; key: string }
interface AuthorSummary { id: PublicId; name: string }
interface AuthorProfile extends AuthorSummary {
  bio: string; linkedin_url: string; avatar_url: string | null;
}
interface ArticleSummary {
  id: PublicId; slug: string; title: string; href: string;
  cover_image: string; description: string; excerpt: string;
  category: Category | null; author: AuthorSummary | null;
  reading_minutes: number | null; published_at: PublicTimestamp;
}
interface ArticleDetail extends ArticleSummary {
  content: string;
  outline: Array<{ id: string; title: string; level: 2 | 3 }>;
  updated_at: PublicTimestamp; meta_description: string; seo_description: string;
  author_profile: AuthorProfile | null; tags: string[]; comment_count: number;
}
interface BlogFacets {
  all_count: number; uncategorized_count: number;
  categories: Array<Category & { count: number }>;
}
interface BlogListResponse extends Paged<ArticleSummary> { facets: BlogFacets }
type FeaturedResponse = Success<ArticleSummary[]>;
type ArticleDetailResponse = Success<ArticleDetail>;
type RelatedResponse = Success<ArticleSummary[]>;

interface ExchangeHomeCard {
  id: PublicId; slug: string; title: string; english_name: string;
  logo: string; href: string; founding_year: string; platform_label: string;
  rating_average: number | null; rating_count: number; comment_count: number;
}
interface TutorialHomeCard {
  id: PublicId; slug: string; title: string; href: string;
  description: string; cover_image: string; category: Category | null;
  duration_seconds: number | null;
  youtube_url: string; youtube_video_id: string;
  aparat_url: string; aparat_video_id: string | null;
  default_provider: 'aparat' | 'youtube';
  duration_source: 'aparat' | 'youtube' | null;
  created_at: PublicTimestamp; updated_at: PublicTimestamp; published_at: PublicTimestamp;
}
interface BonusProvider {
  exchange_id: PublicId | null; name: string; english_name: string; logo: string;
}
interface BonusHomeCard {
  id: PublicId; slug: string; title: string; href: string;
  description: string; excerpt: string; cover_image: string;
  category: Category | null; published_at: PublicTimestamp; reading_minutes: number;
  campaign: {
    provider: BonusProvider | null;
    amount_display: string; state: 'ended' | 'unspecified';
  };
  rating: number | null; rating_count: number; rating_revision: number;
}
interface SectionBase<K extends string, T extends string, I> {
  key: K; kind: T; title: string; description: string;
  action_label: string; action_url: string; items: I[];
}
type HomeSection =
  | SectionBase<'exchanges', 'exchange', ExchangeHomeCard>
  | SectionBase<'aparat', 'video', TutorialHomeCard>
  | SectionBase<'news', 'news', ArticleSummary>
  | SectionBase<'bonuses', 'bonus', BonusHomeCard>
  | SectionBase<'videos', 'video', TutorialHomeCard>
  | SectionBase<'articles', 'article', ArticleSummary>;
interface MenuItem {
  id: PublicId; slug: string; title: string; english_name: string;
  logo: string; href: string;
}
interface MenuGroup {
  key: 'exchanges' | 'forex' | 'stock-brokers' | 'gold-platforms' | 'prop-firms';
  label: string; all_href: string; items: MenuItem[];
}
interface Advertisement { image: string; link: string; id: string; alt: string }
interface PublicSettings {
  revision: string;
  hero: {
    title: string; accent: string; description: string;
    primary_label: string; primary_url: string;
    secondary_label: string; secondary_mobile_label: string; secondary_url: string;
  };
  infinite_slider: Array<{ id: string; logo_src: string; name: string; english_name: string }>;
  carousels: HomeSection[];
  menu: MenuGroup[];
  links: { telegram: string; instagram: string; youtube: string; aparat: string };
  advertisements: { home_header: Advertisement[]; news_and_articles: Advertisement[] };
}
type SettingsResponse = Success<PublicSettings>;

interface OfficialReply { text: string; created_at: PublicTimestamp; updated_at: PublicTimestamp }
interface PublicComment {
  id: PublicId; name: string; text: string; created_at: PublicTimestamp;
  reply: OfficialReply | null;
}
interface CommentInput { name: string; email: string; text: string }
type CommentListResponse = Paged<PublicComment>;
type CommentCreatedResponse = Success<PublicComment>;

interface SearchResult {
  type: ContentType; id: PublicId; slug: string; title: string;
  english_name: string; description: string; image: string; href: string;
  published_at: PublicTimestamp;
}
interface SearchResponse extends Paged<SearchResult> {
  filters: { q: string; surface: SearchSurface; type: HeaderSearchType };
}
interface SitemapEntry {
  type: ContentType; id: PublicId; title: string; path: string;
  last_modified_at: PublicTimestamp;
}
interface SitemapResponse extends Success<SitemapEntry[]> {
  meta: {
    type: 'all' | ContentType; limit: number; total: number;
    has_more: boolean; next_cursor: string | null; revision: string;
  };
}
```

Array types do not encode required order/counts: runtime validation should confirm six known homepage section keys and five menu keys in their documented order. A single combined detail/summary interface must not make all detail fields optional and accidentally allow missing fields on successful detail responses.

## 10. Frontend integration and request examples

### 10.1 Mapping and query identity

| Wire field | Suggested UI field | Important distinction |
| --- | --- | --- |
| `href` | `href` | Website content navigation; not older `public_path` |
| `excerpt` | `excerpt` | Replaces older homepage `display_description`; does not overwrite explicit description |
| `cover_image` | `coverImage` | Current homepage public image; no `display_cover_image` field |
| `founding_year` | `foundingYear` | Current exchange homepage name; not old `founded_year` |
| `platform_label` | `platformLabel` | Current exchange homepage name; not old `platform_type` |
| `english_name` | `englishName` | String, possibly empty |
| `reading_minutes` | `readingMinutes` | Nullable; do not map null to zero |
| `duration_seconds` | `durationSeconds` | Nullable source metadata |
| `published_at`, `updated_at` | `publishedAt`, `updatedAt` | UTC values formatted by UI |
| `author_profile` | `authorProfile` | Detail-only public profile |
| `outline[].title` | `title` | Heading text; not older `text` |
| `comment_count` | `commentCount` | Top-level total, not array length |
| `per_page`, `total_pages` | `perPage`, `totalPages` | Meta, not inferred from loaded count |
| `all_count`, `uncategorized_count` | `allCount`, `uncategorizedCount` | Search-only facets |
| `last_modified_at` | `lastModifiedAt` | Null means omit XML lastmod |
| `has_more`, `next_cursor` | `hasMore`, `nextCursor` | Cursor pagination, not numbered page meta |
| `request_id` | `requestId` | Operational correlation, never entity identity |

There are no legacy public-field aliases in these new responses. Convert once in the API transformer and reuse across shared hooks/server loaders. Keep settings logo IDs as strings and domain IDs as numbers.

Suggested query keys include all effective request controls:

```ts
const publicKeys = {
  settings: () => ['public', 'page-settings'] as const,
  blog: (q: string, categoryId: number | undefined, page: number) =>
    ['public', 'blog', 'articles', { q: q.trim(), categoryId, page, perPage: 6 }] as const,
  featured: () => ['public', 'blog', 'featured'] as const,
  article: (slug: string) => ['public', 'blog', 'article', slug] as const,
  related: (slug: string) => ['public', 'blog', 'related', slug] as const,
  comments: (section: CommentSection, parentId: number, page: number, perPage = 100) =>
    ['public', 'comments', { section, parentId, page, perPage }] as const,
  search: (q: string, surface: SearchSurface, type: HeaderSearchType, page: number, perPage = 20) =>
    ['public', 'search', { q: q.trim(), surface, type, page, perPage }] as const,
};
```

The example preserves trimmed display query in the cache key so a response's `filters.q` cannot accidentally echo another spelling of a normalization-equivalent query. Backend normalization/ranking is authoritative; do not reimplement a competing local catalog. If the frontend additionally normalizes keys, retain display input separately and handle echoed-filter data deliberately.

### 10.2 Plain GET example

Use the frontend's configured same-origin `/api/v1` proxy or replace the base with its configured API service origin. This code assumes the wire interfaces from section 9 and deliberately omits credentials. Types are compile-time annotations: add the project's response validator at the boundary.

```ts
const API_BASE = '/api/v1';

type QueryValue = string | number | undefined;
function apiUrl(path: string, query: Record<string, QueryValue> = {}): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined) params.set(key, String(value));
  }
  const suffix = params.toString();
  return `${API_BASE}${path}${suffix ? `?${suffix}` : ''}`;
}

async function publicGet<T>(
  path: string,
  query: Record<string, QueryValue> = {},
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(apiUrl(path, query), {
    headers: { Accept: 'application/json' },
    credentials: 'omit',
    cache: 'no-store',
    signal,
  });
  if (!response.ok) {
    const body: unknown = await response.json().catch(() => null);
    throw { status: response.status, body, requestId: response.headers.get('X-Request-ID'),
      retryAfter: response.headers.get('Retry-After') };
  }
  return response.json() as Promise<T>;
}

const archive = await publicGet<BlogListResponse>('/blog/articles', {
  q: 'بازار', page: 1, per_page: 6,
});
const header = await publicGet<SearchResponse>('/search', {
  q: '', surface: 'header', type: 'all', page: 1, per_page: 20,
});
```

This basic wrapper is for JSON 200 reads, with automatic redirect following; do not use it as-is for HEAD, OPTIONS, or an explicit conditional settings request. A real UI adapter should turn the thrown transport information into the project's typed error class and accessible states, with bounded retry rules.

### 10.3 Conditional settings example

```ts
interface RetainedSettings { etag: string | null; body: SettingsResponse }

async function readSettings(previous?: RetainedSettings): Promise<RetainedSettings> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (previous?.etag) headers['If-None-Match'] = previous.etag;
  const response = await fetch(apiUrl('/page-settings'), {
    headers, credentials: 'omit', cache: 'no-store',
  });
  if (response.status === 304) {
    if (!previous) throw new Error('304 received without a retained settings representation');
    return previous;
  }
  if (!response.ok) {
    const body: unknown = await response.json().catch(() => null);
    throw { status: response.status, body, requestId: response.headers.get('X-Request-ID') };
  }
  return { etag: response.headers.get('ETag'), body: await response.json() as SettingsResponse };
}
```

With Axios, use a dedicated public instance with `withCredentials: false`, no inherited panel auth/CSRF headers, and explicitly allow/handle 304 through the project's `validateStatus` handling when conditional requests are used. Do not parse a 304 body as a settings object.

### 10.4 Comment POST example

```ts
interface PendingComment {
  section: CommentSection;
  parentId: number;
  idempotencyKey: string;
  payload: CommentInput;
}

function newPendingComment(
  section: CommentSection, parentId: number, input: CommentInput,
): PendingComment {
  return { section, parentId, idempotencyKey: crypto.randomUUID(), payload: {
    name: input.name.trim(), email: input.email.trim(), text: input.text.trim(),
  } };
}

async function sendPendingComment(pending: PendingComment): Promise<CommentCreatedResponse> {
  const response = await fetch(apiUrl(`/comments/${pending.section}/${pending.parentId}`), {
    method: 'POST',
    credentials: 'omit',
    cache: 'no-store',
    headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      'Idempotency-Key': pending.idempotencyKey,
    },
    body: JSON.stringify(pending.payload),
  });
  if (!response.ok) {
    const body: unknown = await response.json().catch(() => null);
    throw { status: response.status, body, requestId: response.headers.get('X-Request-ID'),
      retryAfter: response.headers.get('Retry-After') };
  }
  return response.json() as Promise<CommentCreatedResponse>;
}
```

Create PendingComment once for the logical submit, outside the retry function. Retrying calls `sendPendingComment` with that same object. Keep it in component memory and do not log/store its private email/text/UUID in query strings, analytics, or persistent browser storage. If user edits target/input, reconcile the old uncertain result separately and create a new logical submission only when intended.

### 10.5 Complete sitemap consumer algorithm

Server-side generation should perform this sequence:

1. Request the desired type and fixed limit without a cursor, using a fresh/no-store read.
2. Validate data/meta types, safe numeric IDs, known type keys, root-relative canonical paths, and parseable/null UTC dates.
3. Retain first-page revision, total, effective type, and limit. Enforce them on every subsequent page.
4. Append unique `(type,id)` entries, rejecting duplicate identities or duplicate canonical paths. Require true/nonempty-token or false/null cursor invariants.
5. Follow every cursor until completion; reject a repeated cursor. Cap retries/restarts and keep cursor expiry/rate limits in mind.
6. If 409 stale, discard the whole partial traversal and restart. If 422 cursor expired, start over with no cursor; malformed input requires correction. Other failures cannot become empty success.
7. At completion verify aggregate unique count equals total, then construct the website's XML/title list with trusted origin/static routes and proper escaping.
8. Before serving generated cached output, revalidate its current feed revision/eligibility. A fresh first-page request is sufficient to compare the selected-scope revision; publish/regenerate a changed full traversal instead of mixing pages.

An empty scope is valid only after a successful 200 with total zero, has_more false, and next_cursor null. Never publish a partly assembled XML document after a later-page failure.

## 11. Publication, cache propagation, and deployment

### 11.1 Authoritative publication and local read model

Articles/news/platforms/bonuses are public only while status is published and not deleted. A due Scheduled article/news stays hidden until its worker commits publication. Valid non-deleted tutorials are public immediately after save with a valid matching required YouTube source; Aparat homepage eligibility adds its own valid source pair.

The same persisted stores drive panel and public output. The local read model contains derived public search/feed data, not a second writable content catalog. Supported content mutations, scheduled transitions, category bulk changes, author/profile updates, and safe provider changes synchronize it inside the owning transaction. Failed synchronization rolls back that mutation rather than returning success with a divergent index. There is no external indexing-delay SLA: successful supported writes update the local model synchronously.

Reads validate source stamps/membership for the requested scope before returning rows/totals. Direct database edits that bypass application actions can cause inconsistency; the safe result is 503 and operational reconciliation/rebuild, not serving stale results. No source timestamp, administrative version, index fingerprint, hidden title, or selection diagnostic is returned as public management data.

### 11.2 Receiver for durable revalidation

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
  "event": "blog_article_changed",
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

### 11.3 Backend activation checklist

Migration/backfill must be completed against the intended deployment database before search/feed integration is enabled:

```sh
php artisan optimize:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan public-content:rebuild --no-interaction
php artisan app:diagnose --no-interaction
```

The rebuild is repeatable, sets discovery readiness only after a successful transaction, preserves unchanged known modification dates, and does not invent today's date for unknown historical records. The command reports its synchronized entry count. Do not run it from browser components or on every request.

CORS accepts all frontend origins through `config/cors.php`; no frontend-origin allowlist needs configuration. Configure the shared rate store/trusted proxy settings, media origins, and the scheduler. `app:diagnose` checks the CLI PHP version/extensions, application key validity, database access, pending migrations and discovery readiness without modifying data or printing secrets. Web workers must use the same runtime/configuration. If an existing deployment has lost `APP_KEY`, restore its original key from secure deployment configuration or backup; do not generate a replacement as a routine repair. Keep the website canonical origin separate from the API service origin. The default configured development database was not migrated as part of the implementation; verify the real deployed environment rather than assuming it is ready because source code is pushed.

## 12. Frontend acceptance checklist and source references

### 12.1 Acceptance checklist

- One server settings snapshot powers shared layout/homepage; no admin settings response is used publicly.
- Six ordered sections/five menu groups render correct schemas, all eligible selected news are available, menu caps are respected, and empty/unknown images get neutral treatment.
- Social URLs are shared and ad links always use their required frontend target/rel attributes.
- Blog search/category/page URL state, six-item pages, search-only facet counts, zero categories, Uncategorized, and beyond-last recovery agree with backend metadata.
- Complete safe article HTML, deterministic outline.title/IDs, nullable reading time, real author profile, category-derived tags, and separate related/comments reads render correctly.
- Historical article website URLs permanently redirect to the canonical website path; hidden parents/aliases yield genuine 404 pages.
- Comments use the exact typed target map, literal escaped prose, no public emails/staff identities, authoritative top-level totals, and incremental bounded loading.
- Comment retries reuse UUID v4 for an uncertain unchanged submission, reconcile returned IDs once, preserve form input on failure, and never add an approval workflow.
- Header/mobile scope is three types; page scope is nine; type/id keys avoid cross-domain collisions and dual-provider duplicates.
- Search normalization/ranking stays server-owned; slow/obsolete requests cannot replace newer input or reopen overlays. Totals are not inferred from one loaded page.
- Feed traversal validates cursor/revision/total/uniqueness, follows every page, restarts coherently, and never publishes partial/stale XML.
- UI distinguishes empty, validation, missing, throttled, unavailable, unexpected, and transport failures; 304/301/204 are not parsed as JSON.
- SSR/query/CDN/XML caches follow current eligibility; webhook processing is signed, idempotent, server-only, and does not treat homepage revision as global revision.
- Dates/counts are localized only at presentation, RTL/LTR fields remain usable, keyboard/reduced-motion/focus behavior is preserved, and private form data is absent from logs/analytics/query URLs.

### 12.2 Implementation sources

These are repository-relative references for backend verification, not frontend HTTP endpoints:

| Concern | Source |
| --- | --- |
| Route inventory | `routes/api.php` |
| Shared envelope/error handling | `app/Http/Middleware/PanelEnvelope.php`, `bootstrap/app.php` |
| Public request validation/rate buckets | `app/Http/Middleware/PublicReadRequest.php`, `config/presentation.php` |
| Homepage public schemas | `app/PageSettings/SettingsProjection.php`, `SettingsDefinition.php` |
| Article summary/detail/outline | `app/Blog/PublicArticleProjection.php`, `PublicArticleOutline.php` |
| Blog filtering/facets/aliases | `app/Blog/ResolvePublicBlogArchive.php`, `ResolvePublicArticle.php` |
| Search and feed traversal | `app/Actions/PublicContent/SearchPublicContent.php`, `ReadSitemapEntries.php` |
| Read-model synchronization/readiness | `app/Support/PublicContentIndex.php` |
| Comment wire privacy and writes | `app/Comments/CommentProjection.php`, `app/Actions/Comments/SubmitComment.php` |
| Comment transport/limits | `app/Http/Middleware/PublicCommentsRequest.php`, `config/comments.php` |
| Shared CORS | `config/cors.php` |
| Safe diagnostics | `app/Support/RequestFailureReporter.php`, `app/Console/Commands/DiagnoseApplication.php` |
| Durable propagation | `app/PageSettings/DeliverChanges.php`, `routes/console.php` |

Related administrative handoffs: [page settings](13-panel-page-settings-api.md), [blog](3-panel-blog-api.md), [comments](12-panel-comments-api.md), [public profile fields](15-panel-profile-api.md), [shared media](16-shared-media-api.md), [tutorials](11-panel-tutorials-api.md), and [bonuses](10-panel-bonuses-api.md). Administrative public-output notes may predate this delivery; this consolidated contract is authoritative for the implemented public endpoints described here.

## 13. Public news

News is a separate typed domain from blog, including coincident IDs/categories/slugs. Only Published, non-deleted stories are public. A due Scheduled story remains private until the publication worker commits its transition. An administrator cookie cannot enable public preview.

### 13.1 Archive: `GET /news/articles`

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

### 13.2 News summary and detail fields

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

### 13.3 Featured: `GET /news/featured`

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

### 13.4 Detail: `GET /news/articles/{slug}`

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

### 13.5 Latest: `GET /news/articles/{slug}/latest`

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

## 14. Shared directory behavior

The three APIs below reuse authoritative panel records. Cryptocurrency exchange, forex and stock brokerage identity never crosses domains because IDs/slugs coincide. Their public routes do not expose author profiles, admin versions/status, account identity, private media or storage details. Only Published, non-deleted parents are eligible, including for aliases and dependent Other lists.

### 14.1 Inputs, ordering and facets

All three list endpoints accept `q` (trimmed valid UTF-8, max 200), `sort=default|alphabetical`, positive safe-integer `page` (default 1) and exactly `per_page=9` (omitted defaults to 9). No body or private status/ID filter is accepted. Unknown, structured or repeated non-category keys are 422.

Exchange and forex additionally accept `category`. Omitted/empty/`all`, unknown/deleted keys, repeated `category`, array/object-shaped category, malformed UTF-8 category or a value above 100 bytes select All. The response's effective `filters.category` is null in those cases. Repeated keys are detected from the original query string rather than trusting the framework's last decoded value. A category merge does not alias the old key to the destination. In forex only, a single scalar `iranian-brokers` returns a category field error; the website implements the compatibility redirect before requesting forex data. Repeated/structured category follows the fallback rule instead.

Stock brokerage lists reject `category`, `category_id`, and `kind`, and have no category facets. Its five-market navigation is frontend route navigation.

Search uses the same space-removing normalization as news. Fields: exchange = title/English name/description-or-excerpt/live category; forex = those fields plus `markets`; stock = title/English name/description-or-excerpt only. Fields match independently; SQL wildcard characters are escaped literally. An explicit saved description is fully searchable; only a body-derived excerpt is bounded to 300 characters.

Default order is `created_at DESC, id DESC`. Alphabetical order compares stored ICU `fa_IR` primary-strength binary sort keys generated from normalized public titles, then ID ascending. It is independent of ratings/comments and ordinary edits do not change creation order. Page data, counts and facets are read within a consistent snapshot; pages across separate requests are fresh reads, not one historical cursor traversal.

`filters.q` preserves trimmed display input, not its destructive matching-normalized version. `total_pages=ceil(total/9)`, zero when empty. A beyond-last page returns empty data with the requested page and correct totals. Frontend recovery refetches a valid page before showing its range.

Exchange/forex facets are search-only, before category selection, and include every live category with zero counts retained. `all_count` equals ordinary live category counts plus Uncategorized. Forex additionally returns `forex_count=all_count`; that virtual count overlaps all ordinary categories and must not be summed with them.

### 14.2 Shared values and review content

`description` remains the saved value, including `""`. `excerpt` is plain text up to 300 Unicode characters from description or full-body fallback. Do not repeat a derived excerpt as a saved introduction. Logo references are safe public media paths/URLs or `""`; do not infer a provider logo or official URL.

There is currently no rating source for these three domains. All exposed rating aggregates are explicitly `rating_average: null`, `rating_count: 0`. They are not fabricated zero/five-star ratings. There is no new voting endpoint or historical import. `comment_count` is the real visible top-level typed comment count and never a vote count. Forex archive summaries intentionally omit rating/comment aggregates; its details include them.

Details preserve full sanitized HTML with tables, media, lists and links. Body H1 becomes H2; generated heading IDs and outline titles/levels match exactly, with deterministic domain prefixes. Supplied conflicting IDs are replaced. The saved editorial body remains unchanged. Empty headings produce an empty body outline. Fixed overview/specifications/comments blocks and specification-group anchors remain frontend DOM responsibilities, not invented HTML sections in the API response.

Each detail returns all 24 domain-specific specification keys as strings. Missing is `""`; status values distinguish `""`, `yes`, `no`, and `not-applicable`. Preserve entered calendars, units, line breaks, currency and qualifiers. Empty never means free/no/unlimited/unregulated. Do not infer years active, license verification, account tables, fees or FAQs. Detailed tables/FAQ/account comparisons belong to authored HTML.

Public reads use the common 300-per-minute source bucket, request IDs, unrestricted CORS and `no-cache, must-revalidate`; failures use `no-store`. The APIs require no visitor login or panel CSRF. Parent visibility is authoritative regardless of cookies. See section 18 for module error codes and alias behavior.

## 15. Cryptocurrency exchanges

### 15.1 List: `GET /exchanges`

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

### 15.2 Detail: `GET /exchanges/{slug}`

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

### 15.3 Others: `GET /exchanges/{slug}/others`

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

## 16. Forex brokers

### 16.1 List: `GET /brokers`

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

### 16.2 Detail: `GET /brokers/{slug}`

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

### 16.3 Others: `GET /brokers/{slug}/others`

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

## 17. Stock brokerages and legacy routes

### 17.1 List: `GET /stock-brokers`

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

### 17.2 Detail: `GET /stock-brokers/{slug}`

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

### 17.3 Others: `GET /stock-brokers/{slug}/others`

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

### 17.4 Legacy lookup: `GET /stock-brokers/legacy-routes/resolve`

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

## 18. News and directory errors, types, and deployment

### 18.1 Alias and collection transport

All four current detail routes return 301 for their own formerly public alias while the parent remains public. Body is empty, with `Location` pointing directly to the corresponding current API route. `/latest` and `/others` suffixes are preserved. There is no JSON to parse for 301. Private/deleted parents and aliases share their module 404. The legacy lookup remains JSON 200/404 and does not turn into an API 308.

Every featured/latest/others endpoint has the same complete empty collection envelope below (with its own request ID). It does not fabricate items to fill a layout. No pagination/meta is added to these bounded collections.

200 — empty featured, latest, or any Other collection:

```json
{
  "data": [],
  "request_id": "req_6a89d836-bf75-4b2b-b0a0-580f236ef65a"
}
```

For any directory, beyond-last requests retain requested `meta.page`, empty `data`, and correct filtered `meta.total/total_pages`. Exchange/forex retain their effective filters and search-only facets; stock has no facets. The news beyond-last example in section 13 follows the same page recovery rule with page size six.

### 18.2 Complete module error examples

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

### 18.3 Additional TypeScript wire types

These extend the common `Success`, `Paged`, `Category`, `PageMeta`, `ArticleSummary`, `ArticleDetail` and `BlogFacets` types in section 9. They describe wire names only; convert once into the UI's camelCase names. Nullable timestamps remain nullable in runtime validation.

```ts
type NewsSummary = ArticleSummary;
type NewsDetail = ArticleDetail;
interface NewsListResponse extends Paged<NewsSummary> { facets: BlogFacets }
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

Detail envelopes are `Success<ExchangeDetail>`, `Success<BrokerDetail>` and `Success<StockBrokerDetail>`. Other collection envelopes are `Success<ExchangeSummary[]>`, `Success<BrokerSummary[]>`, and `Success<StockBrokerSummary[]>`. No HTML or specifications enter these bounded arrays.

### 18.4 Frontend consumers, SEO and cache dependencies

News state uses `/news?q=...&category_id=...&page=...`. Exchange/forex state additionally retains immutable `category` and `sort`; stock state has only q/sort/page. Omit empty/default query values. Apply search/category/sort changes on page 1, preserve filters during pagination, debounce typing approximately 300 ms, and discard obsolete responses. Effective fallback category removes dead URL selections. Share server-rendered initial responses with hydration and use typed domain+ID keys so coincident IDs do not share caches.

News detail metadata uses the actual public author/title/first publication/update data. Directory metadata describes visible facts and does not invent regulatory verification. Website canonicals use trusted frontend origin plus current href. Unfiltered archives use their archive path and normalized page=n for subsequent unfiltered pages; search/category/non-default-sort variants have normalized self-canonicals and `noindex,follow`. Only current eligible detail paths enter the existing sitemap feed; aliases, legacy source paths and filters do not. Alias rendering and metadata share one detail lookup and emit real 301/307/308 website statuses as specified rather than rendering an old path with 200.

Publish/withdraw/delete, category merge/delete, slug/content/specification/logo/site changes and public author/media changes invalidate relevant lists, facets, detail, bounded collections, homepage/menu, search and sitemap through existing durable outboxes. Blog publication also invalidates the latest-blog block on news pages. Comments refresh typed counts without advancing first publication. Stock slug/visibility updates invalidate legacy lookup/website redirect caches. This delivery does not change the signed webhook wire format; process the broad existing `areas` dependencies and refetch current data rather than treating a homepage revision as a global revision.

### 18.5 Deployment and repeatable search backfill

New HTTP paths must be activated together with updated middleware/envelopes and routes. Install the committed lock file, ensure PHP intl with ICU fa_IR and the HTML sanitizer are available, and rebuild derived directory fields before frontend activation:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan public-directories:rebuild-search --no-interaction
php artisan optimize --no-interaction
php artisan app:diagnose --no-interaction
```

No new schema migration is required for these endpoints. The normal application migrations/read-model initialization from section 11 must already have completed. `public-directories:rebuild-search` uses existing public normalized columns and binary sort keys, locks each record during bounded batch updates, includes withdrawn/deleted records for future restoration, and is repeatable. Because normalization/sorting helpers are shared, it also repairs derived fields for the existing gold/prop profile consumers and their category names. Its reported count covers all those profile records plus exchanges. It creates no new content/ratings/mappings, does not modify saved descriptions/bodies, panel-search description fields, editorial version/timestamps, or sitemap modification dates, and does not send external requests.

Smoke-test all four list/detail families, a public historical alias, the bounded collections and the legacy resolver against real mappings when available. Check cache headers/request IDs/CORS, and validate that an absent mapping returns 404 rather than seeding a sample. Run correctness/concurrency checks against an isolated database, never production. The SQLite suite does not establish MariaDB concurrency behavior. Webhook end-to-end acceptance remains dependent on configured frontend DNS and receiver readiness; keep pending work for retry until the receiver accepts it.

Relevant implementation: `PublicNewsController`, `PublicDirectoryController`, `ReadPublicNewsArchive`, `ReadPublicDirectory`, `ResolveLegacyStockRoute`, `PublicDirectoryProjection`, `PublicReadSnapshot`, and `RebuildDirectorySearch`. Internal panel resolvers and their legacy website-routing behavior remain separate from the public forex JSON boundary.

## Gold platforms, prop firms, and bonus public API handoff

The implementation contract for the three additional public domains, including all fields, specifications, examples, rating integration, errors, and cache behavior, is maintained in [public-gold-prop-bonuses-api.md](public-gold-prop-bonuses-api.md). Homepage bonus cards now share the archive summary and additionally expose `reading_minutes` and `rating_revision`; an absent saved cover is `""`. Personalized rating endpoints remain private and `no-store`.


## Public tutorials handoff

The complete archive, featured, detail, latest, player and error contracts are documented in [10-main-tutorials-api.md](10-main-tutorials-api.md). The homepage uses the same public tutorial summary, including `created_at` and `updated_at`.
