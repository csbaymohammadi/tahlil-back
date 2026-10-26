# Gold Platforms Admin API

This handoff documents the private management API and the public-read services available to a future site integration. The API base path is `/api/v1/admin`; set the host in your environment because no deployment host is configured in this repository.

## Connection and shared behavior

All routes require the shared panel session cookie `tahlil-panel-session-v2`. Send `Accept: application/json`; mutations also send `Content-Type: application/json`, the allowed `Origin`, and `X-CSRF-Token`. Administrative responses use `Cache-Control: no-store`. Responses include `data` and `request_id`; paginated lists also include `meta`. Errors use `error.code`, `error.message`, `error.fields`, and `request_id`.

The route fixes the `gold-platforms` domain. The server sets `kind`, author, timestamps, publication date, deletion metadata, version, and rating aggregates. Unknown fields, cross-domain fields, and server-managed fields are rejected. IDs must be positive safe integers. Profile JSON is limited to 2 MiB; category-operation JSON is limited to 16 KiB. The module uses the existing 300 mutation requests per 15 minutes per panel user and domain limit.

## Profile endpoints

| Method and path | Behavior |
| --- | --- |
| `GET /gold-platforms/items?page=1&per_page=10&q=` | Lists live Draft and Published records, newest first; search matches title and English name. `per_page` accepts 10, 20, or 50. |
| `GET /gold-platforms/items/{id}` | Returns the full private detail for either status. |
| `POST /gold-platforms/items` | Creates version 1 and returns `201` plus `Location`. |
| `PATCH /gold-platforms/items/{id}` | Requires `expected_version`; omitted fields remain unchanged. |
| `DELETE /gold-platforms/items/{id}?expected_version=1` | Soft-deletes and returns `204`. |

Names and slugs allow at most 120 characters. A slug must match `^[a-z0-9]+(?:-[a-z0-9]+)*$`; supplied slugs are never rewritten. Logo and official website are optional for Draft and Published records and clear with `""`. Website URLs must be absolute HTTP/HTTPS URLs without credentials. Logo values must point to an approved HTTPS media host, managed media path, or safe root asset. Saving never fetches a URL. `description` is plain text up to 2,000 characters, `founded_year` is empty or four ASCII digits, and `meta_description` is plain text up to 500 characters.

`category_id` is required on creation but may be null for Uncategorized; null on PATCH uncategorizes. Category IDs resolve only inside the gold-platform table. `status` is `draft` or `published`; publication takes effect after commit. The first `published_at` is preserved through later edits, unpublishing, and republication. `{}` specifications leave all saved keys unchanged; an empty string clears an individual value. Only `category_id` accepts null.

Create example:

```json
{
  "title": "Example Gold Platform",
  "english_name": "Example Platform",
  "slug": "example-gold-platform",
  "description": "Gold services and delivery overview.",
  "logo": "",
  "website_url": "",
  "founded_year": "",
  "specifications": {
    "melted_gold_trading": "yes",
    "physical_delivery_time": "Up to 48 hours",
    "physical_delivery_method": "Collection from a branch",
    "asset_storage": "See the platform's storage terms."
  },
  "category_id": 4,
  "content": "<h2>Overview</h2><p>A review of this platform.</p>",
  "meta_description": "An overview of the platform.",
  "status": "draft"
}
```

Update example:

```json
{
  "expected_version": 1,
  "logo": "",
  "website_url": "",
  "category_id": null,
  "specifications": {
    "melted_gold_trading": "yes",
    "physical_delivery_method": ""
  },
  "status": "published"
}
```

Every response includes all 24 string-valued specification keys. Missing keys on create become empty strings; PATCH merges only submitted keys. Text values are trimmed, preserve internal line breaks, and allow at most 1,500 Unicode characters. Status values are exactly `""`, `yes`, `no`, and `not-applicable`.

| Group | Specification keys |
| --- | --- |
| General Information | `established_at`, `head_office_address`, `physical_branch_count`, `years_active`, `minimum_purchase`, `maximum_purchase`, `minimum_sale`, `maximum_sale` |
| Features | `in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `melted_gold_trading` |
| Trading Conditions | `identity_documents`, `dedicated_app`, `physical_delivery_time`, `physical_delivery_method`, `asset_storage` |
| Fees | `trading_fee`, `registration_fee` |
| Licenses | `inamad`, `virtual_business_union`, `computer_trade_organization` |

The status fields are the six Features fields, `dedicated_app`, and the three Licenses fields. The remaining eleven values are editorial text; empty fee or limit values mean unspecified, not zero. Delivery, storage, and license declarations are not verified guarantees or transaction inputs.

`content` is required HTML, limited to 1 MiB in UTF-8 bytes. Canonical sanitized content must retain visible text or supported media. The sanitizer allows paragraphs, headings, basic emphasis/lists, block quotes, code, links, images, tables, video, and validated YouTube embeds. It rejects scripts, active elements, arbitrary iframe hosts, event handlers, SVG, data/blob URLs, filesystem paths, unsafe links, and unsupported media sources with a `422` `content` error. External links are restricted to HTTP/HTTPS and protected when opening a new tab. Images/videos accept only trusted HTTPS hosts, managed media paths, or approved `/images/` and `/tahlilpanel/images/` assets. No remote URL is fetched.

Sanitizer resource limits: nesting depth 32, 20,000 DOM elements, 5,000 table cells, 200 rows per table, 50 columns per row, cell spans 1–50, media dimensions 1–4,096 pixels, table column spans 1–50, ordered-list start 1–100,000, and 32 characters for code-language metadata. Paragraph/heading/cell alignment accepts only left, right, center, or justify. YouTube embeds require an 11-character video ID on the supported YouTube hosts; only `start`, `end`, `controls`, and `rel` query parameters are accepted, and stored URLs normalize to `youtube-nocookie.com`.

## Category endpoints

| Method and path | Behavior |
| --- | --- |
| `GET /gold-platforms/categories?q=` | Returns all live categories, filtered by normalized name and sorted by normalized name then ID. |
| `GET /gold-platforms/categories/{id}` | Returns current category data and version. |
| `POST /gold-platforms/categories` | Accepts only `{ "name": "Gold Platform Services" }`; returns `201` and `Location`. |
| `DELETE /gold-platforms/categories/{id}?expected_version=1` | Uncategorizes every non-deleted platform, advances its version, soft-deletes the category, and returns `affected_gold_platform_count`. |
| `POST /gold-platforms/categories/{id}/merge` | Requires source and target versions; transfers all non-deleted records and returns `moved_gold_platform_count`. |

Category names are plain text, 1–80 characters, unique after Persian/Arabic yeh/kaf normalization, zero-width non-joiner normalization, whitespace collapse, and case folding. Deleted names remain reserved. Categories are flat and domain-specific. Category counts include all live Draft and Published platforms.

Merge body:

```json
{
  "expected_version": 1,
  "target_category_id": 7,
  "target_expected_version": 1
}
```

Category deletion and merge run in a transaction under the shared database mutation lock. The returned affected count is the database update count. A stale source, target, or profile version returns `409 VERSION_CONFLICT`; invalid/equal destinations return `422`; missing or deleted categories return `404 CATEGORY_NOT_FOUND`.

## Persistence and error behavior

Profile and category writes, audit events, slug history, media references, and Outbox changes commit atomically. Slugs remain reserved across changes and soft deletion. Same IDs and slugs may exist independently in other platform domains. A no-op PATCH preserves the version and writes no duplicate audit or Outbox event. Profile deletion retains slug ownership and shared media.

Common error codes include `UNAUTHENTICATED`, `FORBIDDEN`, `CSRF_INVALID`, `GOLD_PLATFORM_NOT_FOUND`, `CATEGORY_NOT_FOUND`, `SLUG_TAKEN`, `CATEGORY_NAME_TAKEN`, `VERSION_CONFLICT`, `VALIDATION_FAILED`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_MEDIA_TYPE`, and `RATE_LIMITED`. Nested field errors use dot-separated keys such as `specifications.melted_gold_trading`.

Audit events include `gold_platform_created`, `gold_platform_updated`, `gold_platform_published`, `gold_platform_unpublished`, `gold_platform_slug_changed`, `gold_platform_deleted`, `gold_platform_category_created`, `gold_platform_category_deleted`, and `gold_platform_categories_merged`. Public changes enter `gold_platform_outbox`; undelivered events remain pending until a cache consumer is connected. Check status with `php artisan platform-profiles:status gold-platforms`; prune old audit and delivered Outbox events with `php artisan platform-profiles:prune gold-platforms`. The prune command retains every undelivered Outbox event.

## Public-read service contract and connection status

The backend exposes services for a nine-item public archive, public detail resolution, configured selections, related platforms, and historical slug resolution. Public services return only Published, non-deleted records. The archive searches title, English name, and description or canonical-content excerpt with Persian text normalization; it supports newest-first and Persian alphabetical order and has no public category filter. Details return canonical sanitized HTML, a derived outline with unique anchors, `rating_average: null`, `rating_count: 0`, and `comment_count: 0` until those systems are connected. Configured IDs come from `GOLD_PLATFORM_FEATURED_IDS`; missing records are omitted and configured order is retained. Related records stay within the gold-platform domain and exclude the current ID.

The resolver returns `301` destinations for historical slugs only while a platform is Published. HTTP public routes and redirects, site pages, menus, sitemap, cache consumer, ratings, and comments are not connected by this backend delivery. The profile APIs are live only after the migration has been applied; this repository does not provide a production or staging base URL.

## Verification record

Verification on 2026-10-26:

- `php artisan test --compact tests/Feature/GoldPlatformsTest.php`: 12 passed, 161 assertions.
- `php artisan test --compact tests/Feature/BrokerPlatformsTest.php`: 16 passed, 250 assertions.
- `php artisan test --compact`: 208 tests, 197 passed and 11 skipped, 1,860 assertions.
- `vendor/bin/pint --dirty --format agent`: passed.
- Postman JSON and its profile-create example parsed with `jq`; OpenAPI parsed with Ruby Psych and contains all 24 specification keys.

Database-backed tests use SQLite `:memory:`. They cover migration recovery and application transaction behavior, but do not prove MariaDB DDL execution or production row-lock semantics. Run the migration in a MariaDB staging environment before deployment.
