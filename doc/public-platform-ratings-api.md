# Public Platform Ratings — Frontend Handoff

This contract covers cryptocurrency exchanges, forex brokers, stock brokerages, gold platforms, and prop firms. Bonus ratings keep their existing API, cookie, response field `rating`, data, and limits. New platform APIs use `rating_average`.

## Endpoints and identity

For each `kind` below, use the **numeric record ID** returned in public cards/details, not its slug:

| Kind | GET / PUT |
|---|---|
| `exchanges` | `/api/v1/exchanges/{id}/rating` |
| `brokers` | `/api/v1/brokers/{id}/rating` |
| `stock-brokers` | `/api/v1/stock-brokers/{id}/rating` |
| `gold-platforms` | `/api/v1/gold-platforms/{id}/rating` |
| `prop-firms` | `/api/v1/prop-firms/{id}/rating` |

`OPTIONS` is supported. IDs are canonical positive integers, at most `9007199254740991`; leading zeros, slugs, zero and negative values are invalid. No query parameters are accepted. GET accepts no body. PUT accepts a JSON object no larger than 4096 bytes, containing only `value`.

Use the page/directory kind for directory cards and the search card `type` for global-search cards. The navigation-menu key `forex` maps to rating kind `brokers`; `forex` is not a rating endpoint kind.

The identity is `(kind, platform_id, visitor context)`. For example, exchange 31, broker 31 and bonus 31 are three independent targets. Panel login is not required.

## Read before submitting

Send GET with `credentials: 'include'`. A successful response has these exact data fields (this first example is illustrative):

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 31,
    "rating_average": 4.5,
    "rating_count": 2,
    "rating_revision": 2,
    "my_rating": 4,
    "csrf_token": "<opaque-token>",
    "csrf_expires_at": "2026-11-05T12:15:00.000000Z"
  },
  "request_id": "req_example"
}
```

`my_rating` is the visitor's previous vote or `null`. A target without votes returns `rating_average: null`, `rating_count: 0`, `rating_revision: 0`. Averages are JSON numbers calculated from the exact integer sum/count; format decimals in the UI without changing the submitted integer vote.

The browser receives the host-only cookie `tahlil-platform-voter-v1`, with `Path=/api/v1`, `Secure`, `HttpOnly`, `SameSite=Lax`, and a lifetime of 365 days. One cookie works for all five kinds and is independent of `tahlil-bonus-voter-v1` and panel sessions. JavaScript must not read or construct this cookie. HTTPS and credentialed requests are required. SameSite=Lax follows the same browser deployment constraints as bonus ratings; cross-site third-party-cookie availability is not guaranteed by CORS alone.

GET with no valid cookie creates a fresh context. GET with an expired/invalid cookie replaces it and returns 200. PUT without a valid context returns 403. Expiration or deletion of a browser cookie loses access to that visitor's previous choice, but does not remove their historical votes. This is browser-cookie identity, not a guarantee of one vote per human across browsers/devices.

Keep `csrf_token` in memory and send it in `X-CSRF-Token`. It is valid for at most 15 minutes and never longer than the visitor context. A token from bonuses, another visitor or an expired context is invalid. A platform token can be used across the five platform kinds by the same visitor.

Both successful and failed personal GET/PUT responses have `Cache-Control: no-store, private` and `Vary: Cookie`. Do not put them into shared/SSR/CDN caches. `X-Request-ID` identifies the response; `Retry-After` is exposed to the browser on 429 responses.

## Submit or replace a vote

```http
PUT /api/v1/exchanges/31/rating
Content-Type: application/json
X-CSRF-Token: <token from GET>

{"value":5}
```

`value` must be an actual JSON integer from 1 through 5. Strings such as `"5"`, decimals including `5.0`, booleans, null, arrays, extra fields and repeated JSON keys are rejected. Do not send `kind`, `platform_id`, `voter_id`, counters or `expected_version` in the body.

Successful PUT returns HTTP 200 with these exact data fields (illustrative):

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 31,
    "rating_average": 5,
    "rating_count": 1,
    "rating_revision": 1,
    "my_rating": 5
  },
  "request_id": "req_example"
}
```

One visitor has one vote per platform. A new value replaces the previous vote without increasing the count. Repeating the same value returns success without increasing count/revision or producing another public-change event. `rating_revision` increases only on an actual insertion or value change. There is no delete-vote endpoint.

## Public cards and details

Every existing public card/detail for these five kinds includes:

```json
{"rating_average":4.5,"rating_count":12,"rating_revision":18}
```

This includes public lists, details, related/other items, existing homepage/carousel cards, navigation-menu cards and global-search cards. Existing selection slots are preserved. `comment_count` counts visible comments independently of `rating_count`; never derive one from the other.

Public responses never contain `my_rating`, visitor context IDs or CSRF. Voting does not change the editorial record's version, update date or publication date. A real vote change updates its public discovery fingerprint, homepage ETag/revision when the target appears in its snapshot, and the existing cache-revalidation outbox. A failed webhook is retained for retry; the vote itself is not submitted again. Frontend cache invalidation after a successful PUT should update the relevant target, directory, home/menu and search cards using the returned authoritative aggregate.

## Errors and limits

All errors use the existing envelope:

```json
{"error":{"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"value":["The value field must be an integer."]}},"request_id":"req_example"}
```

The example above is illustrative; messages can differ. Branch on HTTP status and error code, not English text.

| HTTP | Code | Handling |
|---|---|---|
| 400 | `INVALID_JSON` | Malformed JSON or duplicate keys; correct the request. |
| 403 | `RATING_CONTEXT_INVALID` | GET a fresh context; keep the selected star and require another explicit submit. |
| 403 | `CSRF_INVALID` | GET a fresh token; do not automatically repeat PUT. |
| 404 | `EXCHANGE_NOT_FOUND` | Exchange missing, deleted or not public. |
| 404 | `BROKER_NOT_FOUND` | Broker missing, deleted or not public. |
| 404 | `STOCK_BROKER_NOT_FOUND` | Stock brokerage missing, deleted or not public. |
| 404 | `GOLD_PLATFORM_NOT_FOUND` | Gold platform missing, deleted or not public. |
| 404 | `PROP_FIRM_NOT_FOUND` | Prop firm missing, deleted or not public. |
| 413 | `PAYLOAD_TOO_LARGE` | Body exceeds 4 KiB. |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Send `application/json`. |
| 422 | `VALIDATION_FAILED` | Display `fields.value`, `fields.id` or other supplied field errors. |
| 429 | `RATE_LIMITED` | Respect `Retry-After` seconds; preserve the selection and do not auto-submit. |
| 503 | `RATINGS_UNAVAILABLE` | Preserve the form; offer an explicit later retry and request ID. |

Requests share a 900-second rate window across all five platform kinds and IDs:

| Operation | Per visitor context | Per IP |
|---|---:|---:|
| GET | 120 | 300 |
| PUT | 30 | 120 |

Repeated no-op PUTs consume the write quota. Limits are independent of bonus ratings. Invalid transport is rejected before rating processing; IP checks precede context/CSRF checks. The server does not expose raw IP addresses or visitor tokens in errors or public change events.

## Minimal browser integration

```ts
type RatingKind = 'exchanges' | 'brokers' | 'stock-brokers' | 'gold-platforms' | 'prop-firms';
type Rating = {
  kind: RatingKind;
  platform_id: number;
  rating_average: number | null;
  rating_count: number;
  rating_revision: number;
  my_rating: number | null;
};
type RatingContext = Rating & { csrf_token: string; csrf_expires_at: string };

async function ratingRequest<T>(url: string, options: RequestInit = {}): Promise<T> {
  // This helper sends exactly one request. Do not wrap PUT with automatic retries.
  const response = await fetch(url, { ...options, credentials: 'include', cache: 'no-store' });
  const body = await response.json();
  if (!response.ok) {
    throw Object.assign(new Error(body.error?.message ?? 'Rating request failed.'), {
      status: response.status,
      code: body.error?.code,
      fields: body.error?.fields,
      requestId: body.request_id,
      retryAfter: response.headers.get('Retry-After'),
    });
  }
  return body.data as T;
}

function readRating(apiOrigin: string, kind: RatingKind, id: number) {
  return ratingRequest<RatingContext>(`${apiOrigin}/api/v1/${kind}/${id}/rating`);
}

function submitRating(apiOrigin: string, kind: RatingKind, id: number, value: number, csrfToken: string) {
  return ratingRequest<Rating>(`${apiOrigin}/api/v1/${kind}/${id}/rating`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ value }),
  });
}
```

1. Read context when the rating control opens. Initialize the selected star from `my_rating`; null means no previous selection.
2. Changing stars only changes local state. PUT runs only when the user presses the submit button. Disable the button while it is pending.
3. On success, display the returned `my_rating` and aggregate, and update/invalidate related frontend caches.
4. On a timeout, network error, interrupted response or other uncertain outcome, do not automatically repeat PUT. A subsequent GET is safe to reconcile `my_rating` and revision. Keep the local choice distinct from the confirmed server value.
5. A refreshed CSRF/context does not authorize automatic resubmission. The user must explicitly submit again after a failed or uncertain attempt.
6. Preserve the request ID for support. Do not log cookies, CSRF tokens or credentialed response headers containing their values.

## Deployment and verification

Deploy the additive migration before enabling the new code, rebuild public discovery using `php artisan public-content:rebuild --no-interaction`, refresh existing configuration/route caches, and reload PHP workers. Application rollback retains all rating tables and votes; do not drop them to roll back code. The migration can be reapplied after a partial run or application rollback. `platform-ratings:prune` removes only expired contexts without votes and is scheduled hourly.

The normal tests use isolated SQLite memory databases. `PlatformRatingConcurrencyTest` is opt-in and requires a separately created disposable MariaDB database named `tahlil_ratings_test_<12 hex digits>`, using `PLATFORM_RATING_TEST_DATABASE`, `PLATFORM_RATING_TEST_USER`, optional `PLATFORM_RATING_TEST_PASSWORD` and optional `PLATFORM_RATING_TEST_SOCKET`. It migrates that disposable database with `migrate:fresh`; never point it at application data.

### Frontend cache receiver is not connected yet

As confirmed by the operator on 2026-11-05 (Asia/Tehran), the frontend revalidation receiver is not connected yet. Backend rating APIs, public projections, ETags and discovery are active and verified. The configured receiver host `tahlilex.com` currently does not resolve from the backend server (`cURL error 6`); the ordinary resolver can resolve the API host and unrelated public hosts. No DNS settings, receiver URL or secrets were changed for this release.

The ten real demo rating-change events remain in their existing outboxes and delivery queue with retry/backoff. Frontend cache invalidation via webhook cannot complete until the receiver is reachable. When the frontend is ready, connect the existing `PUBLIC_REVALIDATION_URL` and server-only `PUBLIC_REVALIDATION_SECRET` integration. Follow the existing signed webhook contract in [Public Website API](public-website-api.md#11-publication-cache-propagation-and-deployment): verify `X-Revalidation-Timestamp` and `X-Revalidation-Signature`, handle `Idempotency-Key` idempotently, invalidate/refetch the supplied `areas`, and return a successful response. The webhook's homepage revision is not a global search/sitemap revision; delayed events must refetch current data rather than overwrite it with older data.

This deferred frontend connection does not require resubmitting any visitor's vote. The browser integration still sends each PUT once and reconciles uncertain outcomes with GET.

### Live verification

Verified on 2026-11-05 (Asia/Tehran), against the deployed backend commit `5b27e80`. Capture timestamp in UTC: `2026-11-04T21:44:10.251721+00:00`.

These are actual production HTTP results against existing demo-seeder records. Test votes were intentionally left on those demo records. No editorial record was changed. Cookie values and CSRF tokens are redacted; request IDs and aggregate values below are from the captured responses.

| Kind | Demo ID | First PUT revision | Same-value PUT revision | Changed-value revision | Final vote count | Visitor vote |
|---|---:|---:|---:|---:|---:|---:|
| `exchanges` | 2 | 1 | 1 | 2 | 1 | 4 |
| `brokers` | 2 | 1 | 1 | 2 | 1 | 4 |
| `stock-brokers` | 2 | 1 | 1 | 2 | 1 | 4 |
| `gold-platforms` | 1 | 1 | 1 | 2 | 1 | 4 |
| `prop-firms` | 1 | 1 | 1 | 2 | 1 | 4 |

All five demo details, matching global-search cards and scoped sitemap responses were checked after the votes. All 14 existing platform cards in the live homepage/navigation snapshot contained the three rating fields and no personal rating/CSRF fields. Rating fields matched the personal readback. A repeated same-value PUT left the homepage ETag unchanged. Bonus GET still returned the legacy `bonus_id`/`rating` contract with its separate cookie.

#### Actual GET before the first vote

`GET /api/v1/exchanges/2/rating` — HTTP 200

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_caef18a6-c3a3-47af-9dcd-23303e049cc6
set-cookie: tahlil-platform-voter-v1=<redacted>; expires=Thu, 04 Nov 2027 21:43:56 GMT; Max-Age=31536000; path=/api/v1; secure; httponly; samesite=lax
```

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 2,
    "rating_average": null,
    "rating_count": 0,
    "rating_revision": 0,
    "my_rating": null,
    "csrf_token": "<redacted>",
    "csrf_expires_at": "2026-11-04T21:58:56.409042Z"
  },
  "request_id": "req_caef18a6-c3a3-47af-9dcd-23303e049cc6"
}
```

#### Actual PUT with value 5

`PUT /api/v1/exchanges/2/rating` — HTTP 200

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_127630f0-aa05-4312-9e8a-f442a6ef3c54
```

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 2,
    "rating_average": 5,
    "rating_count": 1,
    "rating_revision": 1,
    "my_rating": 5
  },
  "request_id": "req_127630f0-aa05-4312-9e8a-f442a6ef3c54"
}
```

#### Actual repeated PUT with value 5

`PUT /api/v1/exchanges/2/rating` — HTTP 200

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_bf1289b8-87f3-4861-93fc-d9c5f10017d6
```

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 2,
    "rating_average": 5,
    "rating_count": 1,
    "rating_revision": 1,
    "my_rating": 5
  },
  "request_id": "req_bf1289b8-87f3-4861-93fc-d9c5f10017d6"
}
```

#### Actual replacement PUT with value 4

`PUT /api/v1/exchanges/2/rating` — HTTP 200

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_4631ccb5-6dda-4cb7-95b5-244006b2e45b
```

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 2,
    "rating_average": 4,
    "rating_count": 1,
    "rating_revision": 2,
    "my_rating": 4
  },
  "request_id": "req_4631ccb5-6dda-4cb7-95b5-244006b2e45b"
}
```

#### Actual GET after replacement

`GET /api/v1/exchanges/2/rating` — HTTP 200

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_6d856ec6-6422-45ef-ac97-e381e9629b85
```

```json
{
  "data": {
    "kind": "exchanges",
    "platform_id": 2,
    "rating_average": 4,
    "rating_count": 1,
    "rating_revision": 2,
    "my_rating": 4,
    "csrf_token": "<redacted>",
    "csrf_expires_at": "2026-11-04T21:58:57.108306Z"
  },
  "request_id": "req_6d856ec6-6422-45ef-ac97-e381e9629b85"
}
```

#### Actual domain-specific 404 responses

Each GET below used numeric ID `9007199254740991`, which does not identify a public platform.

| Kind | HTTP | Error code | Request ID |
|---|---:|---|---|
| `exchanges` | 404 | `EXCHANGE_NOT_FOUND` | `req_08a60ecf-82ff-4823-a269-ace019fa83ec` |
| `brokers` | 404 | `BROKER_NOT_FOUND` | `req_a2fafcc4-1346-4a78-b183-67a16689a126` |
| `stock-brokers` | 404 | `STOCK_BROKER_NOT_FOUND` | `req_04c18990-4d31-44b3-a59d-05948279e7a8` |
| `gold-platforms` | 404 | `GOLD_PLATFORM_NOT_FOUND` | `req_720c13ac-eace-451d-8d55-4db6b020a3fb` |
| `prop-firms` | 404 | `PROP_FIRM_NOT_FOUND` | `req_e9c298be-c520-4ab2-9dad-b690050eac3a` |

#### Actual 403 without a visitor cookie

`PUT /api/v1/exchanges/2/rating` — HTTP 403

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_fe64c4f9-4660-4f17-a6fb-20c562f5b9bf
```

```json
{
  "error": {
    "code": "RATING_CONTEXT_INVALID",
    "message": "Read a fresh rating context before retrying.",
    "fields": {}
  },
  "request_id": "req_fe64c4f9-4660-4f17-a6fb-20c562f5b9bf"
}
```

#### Actual 403 with an invalid CSRF token

`PUT /api/v1/exchanges/2/rating` — HTTP 403

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_b01102b9-1183-4820-9cb4-c613d824433e
```

```json
{
  "error": {
    "code": "CSRF_INVALID",
    "message": "Read a fresh rating context before retrying.",
    "fields": {}
  },
  "request_id": "req_b01102b9-1183-4820-9cb4-c613d824433e"
}
```

#### Actual 422 for value 0

`PUT /api/v1/exchanges/2/rating` — HTTP 422

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_b2137447-843e-490f-b58b-592192b48110
```

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "value": [
        "The value field must be at least 1."
      ]
    }
  },
  "request_id": "req_b2137447-843e-490f-b58b-592192b48110"
}
```

#### Actual 400 for duplicate value keys

`PUT /api/v1/exchanges/2/rating` — HTTP 400

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_773c7913-2546-49a3-92ce-63427d58e80d
```

```json
{
  "error": {
    "code": "INVALID_JSON",
    "message": "Duplicate JSON object keys are not accepted.",
    "fields": {}
  },
  "request_id": "req_773c7913-2546-49a3-92ce-63427d58e80d"
}
```

#### Actual 415 for text/plain

`PUT /api/v1/exchanges/2/rating` — HTTP 415

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_6caa34f7-7601-4494-80c5-d0f1fff4f53d
```

```json
{
  "error": {
    "code": "UNSUPPORTED_MEDIA_TYPE",
    "message": "Use application/json.",
    "fields": {}
  },
  "request_id": "req_6caa34f7-7601-4494-80c5-d0f1fff4f53d"
}
```

#### Actual 413 for a 4097-byte body

`PUT /api/v1/exchanges/2/rating` — HTTP 413

```http
cache-control: no-store, private
vary: Cookie
x-request-id: req_96bb1f53-567d-4d59-9c11-41eaa959e7a9
```

```json
{
  "error": {
    "code": "PAYLOAD_TOO_LARGE",
    "message": "The rating body exceeds 4 KiB.",
    "fields": {}
  },
  "request_id": "req_96bb1f53-567d-4d59-9c11-41eaa959e7a9"
}
```

#### Actual 429 after exhausting the isolated test visitor's write quota

`PUT /api/v1/exchanges/2/rating` — HTTP 429

```http
cache-control: no-store, private
retry-after: 887
vary: Cookie
x-request-id: req_974b9367-4740-4574-8429-652a9bf6d510
```

```json
{
  "error": {
    "code": "RATE_LIMITED",
    "message": "Please wait before trying again.",
    "fields": {}
  },
  "request_id": "req_974b9367-4740-4574-8429-652a9bf6d510"
}
```

The 429 sample was obtained using same-value PUTs from the test visitor; limits were not lowered and no extra votes were inserted. No production outage was induced for a 503 example. Isolated failure tests verified `503 / RATINGS_UNAVAILABLE`, atomic rollback on audit/outbox failure, and private cache headers.

Final verification: 581 tests passed in the full PHPUnit run (17 skipped, including opt-in concurrency cases), all six opt-in MariaDB 11.7.2 concurrency tests passed separately, and Pint passed. The disposable MariaDB test database was removed after use.
