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

Public responses never contain `my_rating`, visitor context IDs or CSRF. Voting does not change the editorial record's version, update date or publication date. A real vote change updates its public discovery fingerprint, homepage ETag/revision and the existing cache-revalidation outbox. A failed webhook is retained for retry; the vote itself is not submitted again. Frontend cache invalidation after a successful PUT should update the relevant target, directory, home/menu and search cards using the returned authoritative aggregate.

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

### Live verification

Production verification results will be recorded here after deployment. Only existing demo-seeder records are used for real votes. Cookie values and CSRF tokens are redacted. Infrastructure failures are verified in isolated tests; the production service is not deliberately interrupted to generate 503 samples.
