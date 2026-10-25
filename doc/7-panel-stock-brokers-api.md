# Stock Brokerages Administration API

This document describes the implemented private API for stock-brokerage profiles. Stock brokerages have their own records, category IDs, slug namespace, media discriminator, audit trail, and public read projection. They are not forex brokers or cryptocurrency exchanges.

## Connection and shared behavior

The API base is `/api/v1/admin`; the local Postman base is `http://localhost:8000/api/v1/admin`. No staging or production hostname is configured in this repository. Every route uses the existing `PanelRequest` session, Origin, and CSRF boundary. Send the panel session cookie on all requests and `X-CSRF-Token` on POST, PATCH, and DELETE, with `Content-Type: application/json` for requests with a body. Successful administrative responses include `request_id`, use `Cache-Control: no-store`, and return UTC ISO-8601 timestamps.

Machine-readable contract: [OpenAPI](7-panel-stock-brokers.openapi.yaml). Importable request examples: [Postman collection](7-panel-stock-brokers.postman_collection.json); set the `baseUrl`, `sessionCookie`, and `csrfToken` collection variables after signing in.

Responses use `{ "data": ..., "request_id": "..." }`. List endpoints also return `meta`. Errors use `{ "error": { "code": "...", "message": "...", "fields": {} }, "request_id": "..." }`. Nested specification errors use dot-separated keys such as `specifications.online_trading`. Panel mutations are limited to 300 requests per 15 minutes per signed-in administrator and module; excess requests return `429 RATE_LIMITED` with `Retry-After`.

Profile JSON bodies are limited to 2 MiB, and category-operation bodies to 16 KiB. Writes use transactions and the shared panel mutation lock. Slugs and normalized category names have database uniqueness constraints as well as application validation. The schema migration can resume after MariaDB partial DDL by restoring missing slug-owner or Outbox delivery indexes and creating the remaining tables.

All identity, summary, category-name, and specification values are plain text. Only `content` accepts HTML, which is validated and canonicalized by the shared sanitizer.

## Endpoints

| Method and path | Behavior |
| --- | --- |
| `GET /stock-brokers/items?page=1&per_page=10&q=` | Draft and Published administrative rows, sorted by `created_at DESC, id DESC`; search title and English name. `per_page` is 10, 20, or 50. |
| `GET /stock-brokers/items/{stock_broker_id}` | Full editor detail; deleted or missing IDs return `404 STOCK_BROKER_NOT_FOUND`. |
| `POST /stock-brokers/items` | Create version 1. Returns `201` and a relative `Location` header. |
| `PATCH /stock-brokers/items/{stock_broker_id}` | Requires integer `expected_version` and at least one editable field. Omitted fields are preserved. |
| `DELETE /stock-brokers/items/{stock_broker_id}?expected_version=1` | Soft-delete with optimistic version checking; returns `204`. |
| `GET /stock-brokers/categories?q=` | All live categories, optionally searched by normalized name; includes non-deleted brokerage counts. |
| `GET /stock-brokers/categories/{category_id}` | Fresh category record and version for confirmation. |
| `POST /stock-brokers/categories` | Create a name-only category; returns `201` and `Location`. |
| `DELETE /stock-brokers/categories/{category_id}?expected_version=1` | Uncategorize all non-deleted brokerages, soft-delete the category, and return the actual affected count. |
| `POST /stock-brokers/categories/{category_id}/merge` | Move non-deleted source brokerages to a distinct destination and soft-delete the source atomically. |

List query parameters are validated. Unsupported parameters, invalid IDs, missing expected versions, and invalid enum values return `422 VALIDATION_FAILED`. Pages beyond the last page return an empty `data` array with accurate totals. Category reads are unpaginated.

## Create and update fields

Create requires `title`, `english_name`, `slug`, `category_id`, `content`, and `status`. `category_id` must be a JSON integer identifying a live stock-brokerage category; JSON `null` means Uncategorized. `logo`, `website_url`, `description`, `founded_year`, `meta_description`, and all specification values may be omitted on create and default to `""`. Optional strings clear with `""`; null is invalid. PATCH only changes supplied values, and nested specifications merge supplied keys only. The server sets `kind` to `stock-brokers`; a submitted `kind` is rejected.

| Field | Contract |
| --- | --- |
| `title`, `english_name` | Required plain text, 1–120 Unicode characters. English name is not unique. |
| `slug` | Required, 1–120 characters; lowercase ASCII letters/digits separated by single hyphens. Current, old, and deleted slugs remain reserved in this domain. |
| `description` | Optional plain text, up to 2,000 characters. An empty public summary may use the review excerpt without changing this field. |
| `logo` | Optional, up to 2,048 characters. Empty is valid. Non-empty values must be a canonical managed image, approved root-relative image asset, or HTTPS URL on a configured trusted media host. |
| `website_url` | Optional empty string or absolute HTTP/HTTPS URL up to 2,048 characters; no credentials or control characters. It is never fetched. |
| `founded_year` | Optional empty string or exactly four ASCII digits. It remains editorial text. |
| `category_id` | Required on create; live stock-brokerage category integer or `null`. Omission on PATCH preserves it. |
| `content` | Required sanitized HTML, maximum 1 MiB in UTF-8 bytes. Empty content is invalid for both statuses. |
| `meta_description` | Optional plain text up to 500 characters. Empty uses a derived public fallback. |
| `status` | Required `draft` or `published`; publication is immediate after commit. |

There are no top-level `platforms`, `markets`, `access`, `account_currency`, `support`, or `platform_type` editor fields. The server derives the market label and fixed kind from the stock-brokerage domain.

Example create:

```json
{
  "title": "Example Brokerage",
  "english_name": "Example Brokerage",
  "slug": "example-stock-broker",
  "description": "A review of the brokerage's services.",
  "logo": "",
  "website_url": "",
  "founded_year": "1999",
  "category_id": 4,
  "specifications": { "online_trading": "yes", "trading_platform": "Example trading system" },
  "content": "<h2>Review</h2><p>Editorial review.</p>",
  "meta_description": "Stock brokerage review and account information.",
  "status": "draft"
}
```

An update is `{ "expected_version": 1, "logo": "", "website_url": "", "category_id": null, "specifications": { "online_trading": "no" } }`. Actual changes advance the version once. A no-op leaves the version and `updated_at` unchanged and emits no duplicate public-change event. Stale versions return `409 VERSION_CONFLICT` with no partial write. `published_at` records first publication and survives later edits, draft transitions, and republication.

## Complete specification object

Detail reads return all 24 keys. Every value is a trimmed string with up to 1,500 Unicode characters; line breaks are preserved. A missing value is `""`. Status values accept only `""`, `"yes"`, `"no"`, or `"not-applicable"`.

| Group | Keys |
| --- | --- |
| General information | `established_at`, `head_office_address`, `physical_branch_count`, `years_active`, `minimum_purchase`, `maximum_purchase`, `minimum_sale`, `maximum_sale` |
| Features/status | `in_person_visit`, `address_transparency`, `active_social_media`, `around_the_clock_support`, `support_availability`, `online_trading` |
| Trading conditions | `identity_documents`, `dedicated_app` (status), `trading_platform`, `account_opening_documents` |
| Fees | `trading_fee`, `registration_fee` |
| Licenses | `inamad` (status), `virtual_business_union` (status), `computer_trade_organization` (status), `regulator` |

Identity-verification documents and account-opening documents are separate inputs. Amounts, fees, dates, counts, and currency qualifiers remain text. Empty fees are not zero; empty or not-applicable license status is not verification. Reject null, number, boolean, array, object, unknown keys, and fields belonging only to another platform.

## Categories, slugs, and publication

Category names are 1–80 characters and unique after Persian/Arabic yeh-kaf normalization, zero-width non-joiner replacement, whitespace collapse, and case-folding. Deleted names remain reserved. The default `Stock Brokerages` category is seedable and remains an ordinary deletable/mergeable category. Stock categories have no public key or category filter.

Category creation accepts only `{ "name": "Retail Services" }`. Its result includes `id`, `name`, `brokerage_count`, timestamps, and `version`; it has no public key. A merge body is `{ "expected_version": 1, "target_category_id": 2, "target_expected_version": 1 }`; success returns `source_category_id`, `target_category_id`, and the actual `moved_brokerage_count`. Delete supplies `expected_version` as a query parameter and returns `deleted_category_id` plus actual `affected_brokerage_count`. A merge into itself is rejected; source and destination versions are both required.

Only Published and non-deleted brokerages appear in public read services. Current and historical slugs resolve to the profile only while it is Published. A formerly public slug returns a resolver result with status `301` and the direct current path; it is not found while hidden and redirects again if the profile is republished. A stock-brokerage review explicitly mapped from a legacy forex source path is redirected by the forex resolver with status `308` while public. Same-slug records do not create mappings automatically.

The request's review HTML is sanitized with the shared HTML sanitizer, preserving supported tables and media while rejecting active elements, unsafe URLs, untrusted media, and invalid embeds. Managed media references use discriminator `stock-brokers`. Shared media is never physically deleted by replacing a logo or deleting a brokerage.

## Public read services and operations

`ResolveStockBrokerageArchive::handle(page, search, sort)` returns nine records per page and searches title, English name, and description/excerpt. Sort is `default` (`created_at DESC, id DESC`) or `alphabetical` (Persian title key, then ID). Uncategorized public records remain eligible. `ResolveStockBrokeragePublicItem::handle(slug)` returns a profile, `301` redirect result, or not-found result. `ResolveFeaturedStockBrokerages` preserves configured stable IDs and fills missing slots by deterministic order; related items exclude the current ID and never fall back to another domain.

The public projection returns read-only rating placeholders (`rating_average: null`, counts `0`) until a typed ratings/comments subsystem is connected. It derives heading outline IDs from sanitized review HTML and reserves fixed specification, overview, FAQ, and comment anchors. The renderer must use those IDs on the corresponding headings/sections.

Mutations write audit events and public-change rows to `stock_brokerage_outbox` in the same transaction. The cache consumer is not configured, so the rows remain pending and API writes do not claim cache invalidation succeeded. `php artisan platform-profiles:status stock-brokers` reports pending events and runtime prerequisites. `php artisan platform-profiles:prune stock-brokers` removes 90-day audit history and delivered outbox rows only; the hourly scheduler runs it.

## Error codes

Expected codes include `UNAUTHENTICATED`, `FORBIDDEN`, `CSRF_INVALID`, `STOCK_BROKER_NOT_FOUND`, `CATEGORY_NOT_FOUND`, `SLUG_TAKEN`, `CATEGORY_NAME_TAKEN`, `VERSION_CONFLICT`, `VALIDATION_FAILED`, `INVALID_JSON`, `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_MEDIA_TYPE`, `RATE_LIMITED`, `INTERNAL_ERROR`, and `SERVICE_UNAVAILABLE`. Unsafe HTML, URLs, unknown fields, invalid category references, and cross-domain IDs return `422`; stale versions and uniqueness conflicts return `409`.

## Validation record

`php artisan test --compact tests/Feature/BrokerPlatformsTest.php` passed: 16 tests and 250 assertions. The complete suite passed 185 tests, skipped 11, and completed 1,699 assertions. It includes regression coverage for MariaDB-safe index lengths and resuming partial migrations. Laravel Pint passed on changed PHP files. Ruby's YAML parser resolved every local OpenAPI reference and confirmed 24 specification keys per schema; `jq` parsed both Postman collections. `route:list` confirms 10 administrative routes for each module. SQLite `:memory:` tests verify application-level transaction behavior; they do not prove row-lock semantics on the deployment database engine. Public pages, public HTTP redirect routes, ratings/comments, and a cache consumer are not part of this API delivery.
