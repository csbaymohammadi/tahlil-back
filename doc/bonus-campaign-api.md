# Historical bonus campaign API handoff

The current bonus API contract is [Bonus rebuild API](bonus-rebuild-api.md). Use its free-text fields and publication flag for the active integration.

## Routes and transport

Use the application's existing `/api/v1` base. Panel requests use the existing session cookie with `credentials: "include"`. Send the panel `X-CSRF-Token` for mutations and `Content-Type: application/json` for JSON writes. Keep the public bonus rating cookie and rating CSRF flow separate.

| Method and path | Response |
| --- | --- |
| `GET /api/v1/admin/bonuses/items` | Paginated rows with campaign summaries. Use `q`, `category_id`, `page`, and `per_page` (10, 20, or 50). Search covers title, author, categories, provider name, and reward display. |
| `POST /api/v1/admin/bonuses/items` | `201`, full saved record, `version: 1`, and `Location`. |
| `GET /api/v1/admin/bonuses/items/{id}` | `200`, full saved record and current version. |
| `PATCH /api/v1/admin/bonuses/items/{id}` | `200`, full saved record. Include `expected_version`. |
| `DELETE /api/v1/admin/bonuses/items/{id}?expected_version=2` | `204`. |
| `GET /api/v1/bonuses` | Archive cards with `meta`, `filters`, and `facets`. Existing filters are `q`, `category`, `page`, and `per_page=9`. |
| `GET /api/v1/bonuses/{slug}` | Detail with full public campaign and sanitized editorial HTML. |
| `GET /api/v1/bonuses/{slug}/latest` | Up to five cards, excluding the current bonus. |
| `GET /api/v1/page-settings`, `GET /api/v1/search` | Existing bonus cards include the same campaign summary. |

Management uses numeric IDs. Public details and latest use slugs. Responses have the existing `data` and `request_id` envelope. Categories, comments, and guest ratings continue to use their existing routes and types, including the bonus `rating`, `rating_count`, and `rating_revision` fields.

## Create and edit

A minimal draft is `{"title":"Example offer","slug":"example-offer"}`. The defaults are `status: "draft"`, `category_id: []`, `reading_minutes: null`, and empty editorial text. Submit meaningful sanitized HTML, a short description, provider, campaign type, a complete reward, an official source URL, and a redemption URL whenever the result is `published`. Both URLs are required for every publication, including paused or ended campaigns. Published `automatic` campaigns also require a start timestamp.

| Top-level field | Contract |
| --- | --- |
| `title`, `slug` | Required on create, max 200 characters. Slug uses lowercase ASCII letters/digits separated by single hyphens. |
| `description`, `content` | Plain description up to 2,000 characters and rich HTML up to 1 MiB. Empty in drafts is allowed. |
| `cover_image`, `meta_description` | Approved image URL or empty string. Optional plain SEO description up to 500 characters. |
| `category_id`, `category` | Write an ordered list of distinct numeric IDs in `category_id`. Reads return both ID and category arrays in that order. Send `[]` to clear. |
| `reading_minutes` | `null` or a JSON integer 1–1440. |
| `status`, `expected_version` | `draft` or `published`. PATCH requires the current positive numeric version and at least one editable field. |

Send only changed top-level fields in PATCH. If changing `campaign`, submit its complete **writable** snapshot. Omitted top-level `campaign` preserves it. Inside a submitted snapshot, omission resets optional values to the defaults below. Set `provider: null` to clear the provider in a draft. A canonical no-op preserves the version. Exclude resolved identity data and read-only fields such as `amount_display`, `state`, `href`, `exchange_id`, ratings, author, and editorial timestamps.

### Writable campaign fields

| Path under `campaign` | Type and default |
| --- | --- |
| `provider` | Object or `null`. A linked provider accepts only `kind` and `id`. An independent provider accepts `kind: "independent"`, `id: null` or omitted, `name`, `english_name`, and `logo`. Names are plain text up to 200 characters. Name is required for independent publication. |
| `provider.kind` | `exchanges`, `brokers`, `stock-brokers`, `gold-platforms`, `prop-firms`, or `independent`. |
| `provider.id` | For linked records, a JSON integer from 1 through 9007199254740991 in the selected directory. |
| `provider.logo` | Optional approved raster URL or managed canonical `/media/…` path, max 2048 characters. Defaults to `""`. Use the shared media service with purpose `logo`. The bonus cover uses `cover`. |
| `type` | `signup`, `deposit`, `no_deposit`, `cashback`, `fee_discount`, `trading`, `referral`, `other`. Defaults to `null` until selected. |
| `reward.kind` | `fixed`, `percentage`, `custom`, or `null` in drafts and legacy records. |
| `reward.amount`, `reward.qualifier` | Fixed reward uses a positive decimal string and `exact` or `up_to`. Defaults are `null` and `exact`. |
| `reward.percentage`, `reward.maximum_amount` | Percentage reward uses a positive decimal string up to 100, plus an optional positive monetary cap. Both default to `null`. |
| `reward.currency` | 2–16 uppercase ASCII letters or digits. Required for a fixed amount at publication and whenever a percentage cap is supplied. Otherwise send `""` or omit. |
| `reward.custom_text` | Plain text up to 200 characters. Required for custom reward publication. Defaults to `""`. |
| `availability` | `automatic`, `paused`, `ended`, `unspecified` (default). |
| `starts_at`, `ends_at`, `reviewed_at` | ISO 8601 UTC timestamp with seconds, optionally up to six fractional digits, or `null` (default). UTC suffix `Z` or `+00:00` is accepted. End must be strictly after start if both are set. |
| `eligibility.audience` | `new_users`, `existing_users`, `all_users`, `unspecified` (default). |
| `eligibility.kyc_required` | JSON boolean or `null` (default). |
| `eligibility.iran_eligibility` | `eligible`, `ineligible`, `conditional`, `unspecified` (default). |
| `eligibility.minimum_deposit`, `eligibility.deposit_currency` | Minimum defaults to `null` and accepts a nonnegative decimal string. Currency defaults to `""` and is required when a minimum is given, including `"0"`. Use the same currency code format as the reward. Positive minimums conflict with `no_deposit`. |
| `eligibility.country_restrictions`, `eligibility.account_requirements` | Plain text up to 2,000 characters each, default `""`. |
| `conditions.reward_withdrawable`, `conditions.profit_withdrawable` | JSON boolean or `null` (default). |
| `conditions.turnover_requirements`, `conditions.usage_deadline`, `conditions.withdrawal_requirements`, `conditions.exclusions` | Plain text up to 2,000 characters each, default `""`. |
| `redemption.url`, `source_url` | Absolute HTTP(S) URLs with a host and no embedded credentials, max 2048 characters. Default `""`. The API stores these links without fetching them. |
| `redemption.promo_code` | Plain text up to 200 characters, default `""`. |
| `redemption.steps` | Ordered list of up to 20 nonblank plain-text strings, each at most 500 characters. Default `[]`. |

Campaign text limits count Unicode code points including spaces and zero-width non-joiners. Decimal fields accept strings with at most 18 digits before the decimal point and 8 after it. Use ASCII digits and a dot. Responses trim insignificant zeros, for example `"050.00"` becomes `"50"`. Format strings for Persian display without converting them to a JavaScript number or converting currencies. Preserve distinctions between `null`, `false`, and `"0"`.

### Provider lookup

Use `GET /api/v1/admin/{kind}/items?q=…&page=…` for server-side search and pagination and `GET /api/v1/admin/{kind}/items/{id}` for the selected record. Debounce search by 300 ms and send the ID and kind only for a linked provider. A private live provider can be selected in a draft. A new linked publication requires a public provider. If an existing public provider later becomes private or is deleted, public reads can retain a previously captured public display snapshot while returning `id: null`, `href: null`, and `exchange_id: null`. Without a safe snapshot, the public provider is `null`. An authenticated edit form can retain the linked ID for remediation.

### Example POST

```json
{
  "title": "Sample deposit reward",
  "slug": "sample-deposit-reward",
  "description": "A reward for eligible new accounts.",
  "content": "<h2>Campaign terms</h2><p>Read the eligibility and withdrawal terms before joining.</p>",
  "category_id": [3, 7],
  "reading_minutes": null,
  "status": "published",
  "campaign": {
    "provider": {"kind": "exchanges", "id": 31},
    "type": "deposit",
    "reward": {"kind": "percentage", "percentage": "20", "maximum_amount": "100", "currency": "USDT"},
    "availability": "automatic",
    "starts_at": "2026-11-10T08:00:00Z",
    "ends_at": "2026-12-01T00:00:00Z",
    "eligibility": {"audience": "new_users", "kyc_required": true, "iran_eligibility": "conditional", "minimum_deposit": "50", "deposit_currency": "USDT"},
    "conditions": {"reward_withdrawable": false, "usage_deadline": "30 days after activation"},
    "redemption": {"url": "https://example.com/claim", "promo_code": "SAMPLE20", "steps": ["Read the terms.", "Activate the offer."]},
    "source_url": "https://example.com/terms",
    "reviewed_at": "2026-11-05T08:00:00Z"
  }
}
```

Example PATCH changing only the editorial summary:

```json
{"expected_version": 1, "description": "See the official terms for eligibility."}
```

To edit a campaign, serialize the current writable fields from the form into `campaign`, apply the edit, and send it alongside `expected_version`. The IDs, dates, offer details, and response identifiers in this guide are illustrative.

## Response examples

Example archive response with one item. The campaign object in this item also appears on homepage, latest, and search cards. Panel list uses the same summary shape with panel-visible identity fields.

```json
{
  "data": [{
    "id": 81, "slug": "sample-deposit-reward", "title": "Sample deposit reward",
    "href": "/bonuses/sample-deposit-reward", "description": "A reward for eligible new accounts.",
    "excerpt": "A reward for eligible new accounts.", "cover_image": "", "reading_minutes": null,
    "category_id": [3, 7], "category": [{"id":3,"name":"Deposit","key":"category-3"},{"id":7,"name":"Trading","key":"category-7"}],
    "published_at": "2026-11-05T08:00:00.000000Z",
    "rating": 4.5, "rating_count": 2, "rating_revision": 2,
    "campaign": {
      "provider": {"kind":"exchanges","id":31,"exchange_id":31,"name":"Sample exchange","english_name":"Sample Exchange","logo":"","href":"/exchanges/sample-exchange"},
      "type": "deposit",
      "reward": {"kind":"percentage","amount":null,"percentage":"20","currency":"USDT","maximum_amount":"100","qualifier":"exact","custom_text":""},
      "amount_display": "20٪ تا سقف 100 USDT",
      "availability": "automatic", "state": "active",
      "starts_at": "2026-11-10T08:00:00.000000Z", "ends_at": "2026-12-01T00:00:00.000000Z",
      "eligibility": {"audience":"new_users","kyc_required":true,"iran_eligibility":"conditional","minimum_deposit":"50","deposit_currency":"USDT"}
    }
  }],
  "meta": {"page":1,"per_page":9,"total":1,"total_pages":1},
  "filters": {"q":"","category":null},
  "facets": {"all_count":1,"uncategorized_count":0,"categories":[{"id":3,"name":"Deposit","key":"category-3","count":1},{"id":7,"name":"Trading","key":"category-7","count":1}]},
  "request_id": "req_example"
}
```

In a public or panel detail, `data.campaign` includes all summary keys. It adds the fields below, and replaces the summary `eligibility` object with the complete one.

```json
{
  "eligibility": {
    "audience":"new_users","kyc_required":true,"iran_eligibility":"conditional",
    "minimum_deposit":"50","deposit_currency":"USDT","country_restrictions":"","account_requirements":""
  },
  "conditions": {
    "reward_withdrawable":false,"profit_withdrawable":null,"turnover_requirements":"",
    "usage_deadline":"30 days after activation","withdrawal_requirements":"","exclusions":""
  },
  "redemption": {"url":"https://example.com/claim","promo_code":"SAMPLE20","steps":["Read the terms.","Activate the offer."]},
  "source_url": "https://example.com/terms",
  "reviewed_at": "2026-11-05T08:00:00.000000Z"
}
```

Panel detail additionally has the current editorial `version`, `status`, and canonical `content`. Public detail keeps its existing editorial fields including sanitized `content`, `updated_at`, and `comment_count`. `provider.exchange_id` remains a read-only compatibility alias for a publicly visible linked exchange. Use `kind` and `id` in new consumers.

### Errors and retry behavior

```json
{
  "error": {"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"campaign.reward.amount":["Enter a decimal string with up to 18 integer and 8 fractional digits."]}},
  "request_id":"req_example"
}
```

```json
{
  "error": {"code":"VERSION_CONFLICT","message":"Fetch the current bonus before retrying.","fields":{}},
  "request_id":"req_example"
}
```

Map `422 VALIDATION_FAILED` dot paths to form fields. Handle `409 VERSION_CONFLICT` by keeping the user's input and offering a fresh read and explicit reconciliation. For timeouts or ambiguous write results, inspect the record or list before allowing an explicit resubmission. Media upload failure is separate from the bonus write. Reuse confirmed media uploads and the same UUID when retrying the same prepared file. Send uploads only for images still retained on Save. Existing auth, CSRF, throttling, and service errors use the same envelope.

## Display and rollout

- Cards use the provider identity, the first live category, server `state`, `amount_display`, and internal bonus detail link. Choose the supporting condition in this order: known minimum deposit (including zero), known audience, then known KYC. Use a neutral fallback for a missing provider and “مبلغ اعلام نشده” for incomplete legacy rewards. Keep full accessible titles and show the existing rating. Show the end date only when supplied.
- On detail, render all categories, eligibility, withdrawal terms, and ordered steps. Show “اعلام نشده” for unknown important facts. The claim button requires `state === "active"`, a valid `redemption.url`, and `iran_eligibility !== "ineligible"`. Show conditional or unknown eligibility explicitly. Source links can remain available in other states. Open external links in a new tab with `rel="noopener noreferrer"`. Promo-code copy should not navigate.
- Authoritative state precedence is explicit `ended`, reached end timestamp, `paused`, `unspecified`, then `upcoming` or `active` for automatic scheduling. Start is inclusive and end is exclusive. Display and enter dates using Tehran time and the Persian calendar, then serialize UTC. Keep `reviewed_at` separate from the editorial `updated_at`.
- Refetch relevant views at a displayed start or end boundary and on visibility/focus. Use the server's returned state. At a displayed end boundary, disable the CTA while refreshing. Only a fresh eligible active response can enable it again. Page-settings ETag changes with card state. The scheduler queues invalidations at due transitions through the existing outbox. Client refetch remains necessary when the webhook receiver is disconnected.
- Release tolerant readers before enabling the new backend contract. Update admin, public-bonus, homepage, and search adapters for nullable reading minutes, nullable reward kind for legacy records, and all five state values. Keep snake_case at the API boundary and map to camelCase once.
- Deployment order after frontend compatibility is verified: run migrations, deploy backend, rebuild the public index with `php artisan public-content:rebuild --no-interaction`, and verify the existing scheduler runs `bonuses:advance-campaigns` every minute. Then enable campaign editing. Existing incomplete published bonuses remain readable. They require the new fields on the next save as published. Keep the additive data migration in place during code rollback.
