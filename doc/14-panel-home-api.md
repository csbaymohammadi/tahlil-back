# Panel Home Analytics API

Development base resolved by Boost: `http://localhost:8000/api/v1`. Other deployment domains are not supplied. Companion schema: [14-panel-home.openapi.yaml](14-panel-home.openapi.yaml). No dashboard UI or deployed collection client is included.

## Dashboard read

`GET /admin/dashboard/home` accepts no body/query and uses the existing active panel session, including roleless accounts. It does not accept a period, author filter, expected version or counter changes. Identity remains exclusively `/admin/auth/me`; browser clock/Persian calendar uses Asia/Tehran without polling this endpoint.

Success is `{data:{period,generated_at,data_through,total_visits,devices,top_links},request_id}` with `X-Request-ID` and `Cache-Control: private, no-store`. Counts are JSON integers, never formatted strings/percentages. The six ordered device IDs are iphone, android-phone, mac, windows, ipad, android-tablet. Include their zero rows; append other only if positive. Counts sum exactly to total_visits. Top links are at most five `{title,path,visits}` rows, positive counts, ordered visits DESC then canonical path ASC. There is no synthetic page ID or visitor-level response.

Both cards use one database snapshot. Start is midnight of the Tehran date 29 days before today's Tehran date, inclusive. `period.to` is the exclusive UTC processing cutoff, equal to `data_through`; `generated_at` is at or after it. Collection is synchronous, so there is no separate queue watermark. Source events are grouped only after they commit under the shared mutation boundary. All timestamps are ISO 8601 UTC.

The private aggregate cache lasts at most 300 seconds and is keyed by Tehran calendar date, so midnight switches the window immediately. Authentication and current public page/title resolution run on every read, including cached aggregates. Deleted/private/scheduled pages disappear from rankings immediately; their historical visits remain in device totals. Stable domain+content-ID grouping retains counts after slug/title changes. Ranking counts therefore need not sum to total_visits but cannot exceed it.

A working empty source returns total zero, six zero devices and an empty ranking. `ANALYTICS_ENABLED=false` (default) gives `503 ANALYTICS_UNAVAILABLE`, even if a prior aggregate exists. Storage/cache/resolver failures give the same availability error, never fabricated zero. Unexpected transport/auth failures retain the shared envelope. Dashboard limit: 60 requests/minute per account/route with Retry-After on 429.

```json
{"data":{"period":{"key":"last_30_days","timezone":"Asia/Tehran","from":"2026-09-26T20:30:00.000000Z","to":"2026-10-26T08:30:00.000000Z"},"generated_at":"2026-10-26T08:30:00.000000Z","data_through":"2026-10-26T08:30:00.000000Z","total_visits":0,"devices":[{"id":"iphone","visits":0},{"id":"android-phone","visits":0},{"id":"mac","visits":0},{"id":"windows","visits":0},{"id":"ipad","visits":0},{"id":"android-tablet","visits":0}],"top_links":[]},"request_id":"req_home_empty"}
```

```json
{"error":{"code":"ANALYTICS_UNAVAILABLE","message":"Website traffic statistics are not configured.","fields":{}},"request_id":"req_home_error"}
```

## First-party collection contract

`POST /analytics/page-views`, JSON body containing exactly `event_id` (UUID) and `path` (root-relative string, max 2048 characters). Successful acceptance, matching retry, bot exclusion or prefetch exclusion returns 204 with no body and a request-ID header. No session cookie, visitor identifier, client title, count, device or timestamp is accepted. Send with credentials omitted and an Origin exactly listed in `ANALYTICS_ALLOWED_ORIGINS` (comma-separated; default APP_URL). OPTIONS supports the required JSON CORS preflight without credentials. Body limit is 8 KiB. Duplicate keys, wrong media type, unknown fields and repeated query parameters are rejected.

```json
{"event_id":"7dd2cf9c-b09b-4acd-a9b2-3bef500da6a9","path":"/news/example-story?utm_source=campaign#section"}
```

After a successful public page render, create one UUID and POST that display's final path. The browser owns the one emission point: run once after initial hydration, and once after each successful router navigation. Do not also record in SSR. Preserve the same UUID for retries of that display; create a new one for a reload or genuine later navigation to the same page. Never emit for component rerenders, hash-only changes, prefetches, errors or background API queries. This is an integration contract, not a claim that the external site's router has been connected.

The server removes queries/fragments and equivalent trailing slashes, resolves existing published slug aliases, and stores canonical public paths with a stable domain/content ID. A repeated event ID for that identity is a no-op; reusing it for another page returns `409 EVENT_CONFLICT`. UUIDs deduplicate displays, not people. No time-bucket or same-IP suppression removes genuine later returns.

Allowed static archive/home routes come from the server-owned `analytics.static_pages` map; verify it against the deployed website before enabling collection. Detail routes use existing domain publication/alias resolvers, including broker-to-stock legacy redirects. Unknown routes, APIs, panel/auth, health/assets and Draft/Scheduled/deleted content return 422 on `path`. The server does not fetch the submitted path or create a page. A browser's successful-display callback establishes that rendering succeeded; the server independently checks catalog availability, not actual screen visibility. Direct forged events remain possible for first-party browser analytics; this is not an anti-fraud metric.

Device classification reads the request User-Agent transiently. iPad precedes phone detection; Android without Mobile is tablet, Android with Mobile is phone; iPhone, desktop Macintosh, desktop Windows follow. Ambiguous Macintosh+Mobile, Linux and unknown families become other. Desktop-mode iPads without identifiable evidence are not invented as iPads. Identifiable bot/crawler/spider, headless/monitoring, lighthouse/pagespeed, curl/wget/python-requests and absent User-Agents are excluded. Purpose/Sec-Purpose/X-Moz prefetch or prerender events are ignored. These documented heuristics cannot identify every automated browser.

Collection is limited to 120 requests/minute per hashed IP key; IP/User-Agent values are not stored in page events. Persisted columns are event UUID, canonical path, server-controlled type/content ID, device bucket and UTC viewed_at. No query strings, raw IPs, raw agents, visitor cookies or browser secrets are persisted. A rejected unavailable write never increments a count. Pending frontend retries use the same UUID.

## Configuration, retention and frontend use

Enable `ANALYTICS_ENABLED=true` only after migrations and the actual website emitter are connected; there is no imported historical dataset or positive seed. Accepted events are persisted synchronously. Dashboard processing delay is database commit plus at most five minutes of aggregate caching. `analytics:prune` runs daily and removes events/deduplication IDs older than 90 days. Do not retry an old display after retention expires. `analytics:status` reports configuration and newest accepted event without visitor data.

Map `generated_at`, `data_through`, `total_visits`, `top_links` to generatedAt, dataThrough, totalVisits, topLinks. Keep fixed device/period keys unchanged. One authenticated query supplies both analytics cards; revisit/focus can refresh a snapshot older than five minutes. Clear private queries on logout/account replacement. During a refresh failure retain the prior UI snapshot with an error indication; initial failure shows retry, not mock counts. Use `visits/totalVisits*100`, zero if total is zero, with at most one displayed decimal. Welcome identity and browser clock remain independent.

```sh
curl '{{admin_base}}/dashboard/home' -b '{{cookie_jar}}'
curl -X POST '{{public_base}}/analytics/page-views' -H 'Origin: {{allowed_website_origin}}' -H 'User-Agent: {{browser_user_agent}}' -H 'Content-Type: application/json' --data '{"event_id":"{{uuid_per_display}}","path":"/"}'
php artisan analytics:status --no-interaction
```

Errors use the shared request-ID envelope: 400 INVALID_JSON, 401 UNAUTHENTICATED, 403 FORBIDDEN/CSRF_INVALID where applicable, 409 EVENT_CONFLICT, 413 PAYLOAD_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE, 422 VALIDATION_FAILED, 429 RATE_LIMITED with Retry-After, 503 ANALYTICS_UNAVAILABLE, and 500 INTERNAL_ERROR. Collection does not use panel CSRF or expose provider secrets.

Tests cover the empty/unavailable distinction, boundaries/cutoff, Tehran midnight, device invariants/classification, repeated events and alias normalization, ordering/limit, renames/removals with cached totals, route exclusion, limits, prefetch and retention. SQLite and simulated browser requests do not prove external hydration/router wiring or production-scale throughput. Development data was not migrated or modified.
