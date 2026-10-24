# Panel authentication: frontend integration and operations

## 1. Delivery and contract decisions

This Laravel 13 backend implements cookie-based panel authentication. The authoritative account is `App\Models\User`; there is no separate authentication account. Roles are optional descriptive labels with identical access. Neither a role privilege code nor a permissions array is returned.

The frontend is a separate deliverable. This backend does not implement `/tahlilpanel/login`, dashboard HTML, navigation, profile forms, user CRUD, media uploads, public registration, recovery by email/SMS, OTP, MFA, remember-me, refresh tokens, or bearer-token login. The existing `/` welcome route is unchanged.

**Approved deviations:** Origin/Referer restrictions are disabled and CORS accepts all origins by explicit owner request. Common/compromised-password screening is intentionally omitted. Passwords are never sent to a breach-check service; no local password blocklist is installed. Length, exact matching, current-password verification, difference from the old password, and Argon2id hashing remain enforced.

Companion files:

- [OpenAPI 3.1 contract](1-panel-login.openapi.yaml)
- [Postman v2.1 collection](1-panel-login.postman_collection.json)

## 2. Origins and deployment settings

| Environment | Public origin | API base |
| --- | --- | --- |
| Development | `http://localhost:8000` (resolved from the current project) | `http://localhost:8000/api/v1/admin` |
| Staging | `https://<staging-public-host>` — deployment input, not configured yet | Same origin + `/api/v1/admin` |
| Production | `https://<production-public-host>` — deployment input, not configured yet | Same origin + `/api/v1/admin` |

Requests from any origin are accepted and credentialed CORS reflects the requesting origin; no Origin/Referer allowlist needs configuration. The existing host-only `SameSite=Lax` cookies still follow browser same-site rules: unrelated cross-site fetch may not carry them even with credentials included. Use a same-site frontend or request-scoped proxy/BFF for cookie authentication where necessary. Do not change cookie policy implicitly when enabling CORS. OpenAPI's example staging/production hosts are placeholders, not deployed services.

| Setting | Meaning/default |
| --- | --- |
| `APP_URL` | Public backend URL for generated application locations |
| `PANEL_SECURE_COOKIE` | `true` on production HTTPS; `false` only for local HTTP; explicitly override the local example during deployment |
| `PANEL_TRUSTED_PROXIES` | Comma-separated trusted proxy IPs/CIDRs; empty by default; do not trust arbitrary forwarded headers |
| `PANEL_RATE_STORE` | Shared atomic-lock-capable Laravel cache store; default `database` |
| `DB_*`, `DB_CACHE_*` | Shared authoritative account/session database and cache database configuration |
| `panel.absolute_seconds` | `604800` (7 days) |
| `panel.idle_seconds` | `86400` (24 hours) |
| `panel.anonymous_seconds` | `900` (15 minutes, fixed) |
| `panel.rate_window` | `900` seconds |
| `panel.argon` / `hashing.argon` | Argon2id: memory `19456` KiB, time `2`, threads `1`; benchmark before raising both configurations |

Never use per-instance SQLite files, array/file caches, null stores, or an authentication failover cache in a multi-instance deployment. All instances need the same protected `APP_KEY` to decrypt session payloads, as well as the same databases. Development, staging, and production must use separate accounts, keys, and stores. Do not rotate the key as routine setup of an existing deployment.

Apply the additive migration with `php artisan migrate --force` as an intentional deployment operation, and run `php artisan config:cache` after configuring the environment. Existing users retain their data, get `is_active=false`, and have no generated username. They cannot log in until explicitly provisioned/configured; no account is silently promoted. Do not use destructive migration commands.

Configure Laravel's scheduler (`php artisan schedule:run` every minute). `panel:auth-prune` runs daily, removes expired/revoked sessions and anonymous contexts, and deletes security events older than 90 days. Database backups and access controls must cover users, hashes, encrypted session payloads, token verifiers, and security events. No IP/User-Agent metadata is persisted by this module (retention: zero); the trusted request IP is hashed into expiring rate-limit keys.

## 3. Shared transport and types

Use `Accept: application/json`. Non-empty mutation bodies must be JSON objects with `Content-Type: application/json`. Login, logout-all and password change require a JSON body; logout has no body. `GET` endpoints accept no body or query fields. Body limit: **16 KiB**, measured in bytes. Unexpected fields are rejected. Password strings are not trimmed, normalized, or converted to numbers. Unknown query fields are also rejected; do not submit credentials in URLs.

Every JSON success is `{ "data": ..., "request_id": "req_<uuid>" }`. Every error is `{ "error": { "code": "...", "message": "...", "fields": {} }, "request_id": "req_<uuid>" }`. `fields` is an object whose values are arrays of strings. Messages currently use English; render safe localized UI messages by stable error code if desired.

All responses include `X-Request-ID`, `Cache-Control: no-store` and `Vary: Cookie`. `204` responses have no JSON/body; their request ID exists only in the header. Timestamps are UTC ISO 8601 with `Z`. Requests do not accept a request-ID override or a client-specified expiration/redirect.

```ts
export interface PanelRole { id: number; name: string }
export interface PanelUser {
  id: number;
  username: string;
  name: string;
  email: string;
  avatar_url: string | null;
  is_active: boolean;
  role: PanelRole | null;
}
export interface PanelSession {
  id: string; // public UUID; NOT a credential
  expires_at: string; // min(idle deadline, absolute deadline)
  absolute_expires_at: string;
}
export interface Identity { user: PanelUser; session: PanelSession }
export interface Success<T> { data: T; request_id: string }
export interface Failure {
  error: { code: string; message: string; fields: Record<string, string[]> };
  request_id: string;
}
```

`avatar_url` is the saved managed avatar canonical location, or null; private avatars require the authenticated cookie/preview flow from the media service. `email` is contact information, never a login alias. Numeric IDs are database-generated positive integers; preserve them as numbers and keep generated identifiers within JavaScript's safe integer range. There is no password hash, verifier, security version, cookie token, or other device metadata in identity JSON.

### Cookies

| Name | Purpose | Lifetime |
| --- | --- | --- |
| `tahlil-panel-session-v2` | Raw opaque 32-random-byte token encoded as 64 hex characters | Fixed 7-day browser expiration from login; server idle deadline can expire earlier |
| `tahlil-panel-csrf-context` | Anonymous context only; never authenticates | Fixed 15 minutes |

Both cookies have `Path=/`, no Domain (host-only), HttpOnly, SameSite=Lax, and Secure on production HTTPS. Session tokens are stored only as SHA-256 verifiers on the server; their independent public UUID is returned in JSON. CSRF state is stored in an encrypted Laravel session payload. Do not place cookie tokens in JSON, URLs, browser storage, or application logs. Keep the CSRF token in memory.

Successful login clears the anonymous cookie. Logout/password change clear both current cookies with the original host/path scope. Responses also expire legacy `tahlil-panel-session` at `Path=/tahlilpanel`; old-format tokens cannot authenticate.

## 4. Endpoint reference

Paths below include the full API prefix. Origin/Referer are optional and unrestricted by explicit owner request. All authenticated mutations and login still require a context-bound CSRF token. Browser `Sec-Fetch-Site` does not bypass token checks.

### GET `/api/v1/admin/auth/csrf`

Authentication optional; no CSRF header, body or query. Reuses the current authenticated session or an existing unexpired anonymous context. Repeated retrieval does not rotate the token and break sibling tabs. An invalid authentication cookie is cleared and an anonymous context may be returned. This is not proof of login.

```json
{"data":{"csrf_token":"<csrf-token>","expires_at":"2026-10-24T10:15:00Z"},"request_id":"req_example_csrf"}
```

Success `200`; storage failure `503`. For authenticated sessions, this valid authenticated request extends the idle deadline up to the fixed absolute deadline. Anonymous deadlines never slide.

### POST `/api/v1/admin/auth/login`

Requires `X-CSRF-Token` from the current anonymous/authenticated context. Only these fields are accepted:

| Field | Validation |
| --- | --- |
| `username` | String, trimmed and lowercased for lookup, 3–120 characters |
| `password` | Exact string, 8–256 characters |

```json
{"username":"editor.demo","password":"<password-entered-by-user>"}
```

Success `200`, with a new session cookie:

```json
{
  "data": {
    "user": {
      "id": 1, "username": "editor.demo", "name": "Example Administrator",
      "email": "editor@example.test", "avatar_url": null, "is_active": true,
      "role": {"id": 1, "name": "Administrator"}
    },
    "session": {
      "id": "8cad9a16-d9b4-4dd4-9125-7bdbca397562",
      "expires_at": "2026-10-25T10:00:00Z",
      "absolute_expires_at": "2026-10-31T10:00:00Z"
    },
    "csrf_token": "<new-session-csrf-token>"
  },
  "request_id": "req_example_login"
}
```

`role` may instead be null with identical access. Successful login consumes the anonymous context, rotates the same browser's existing session, and leaves other devices intact. Credentials are verified even when the browser already has a session. Unknown names, wrong passwords, disabled/deleted accounts all yield the same `401 INVALID_CREDENTIALS`. Unknown usernames run a dummy Argon2id verification. No `return_to` or client redirect is accepted.

### GET `/api/v1/admin/auth/me`

Requires session cookie. No CSRF header, body or target user ID. Success `200` has exactly `data.user` and `data.session` as above, without `csrf_token`. Each request checks account status, session version, revocation and expiration against authoritative storage. Current role data is resolved afresh and is not an access decision.

Missing, malformed, tampered, expired or revoked cookies and inactive/deleted accounts return `401 UNAUTHENTICATED`, clearing the invalid cookie. Store failure returns `503`, never authenticated fallback.

### POST `/api/v1/admin/auth/logout`

No body. With a valid session, requires `X-CSRF-Token`; revokes only that session and returns `204`. A valid session with a missing/wrong token returns `403`.

With no session or an expired/revoked session, neither Origin nor a token from the destroyed context is required; returns `204` and clears the cookies. Storage failure during resolution/revocation returns `503`; cookie deletion alone does not count as successful revocation.

### POST `/api/v1/admin/auth/logout-all`

Requires authenticated cookie, CSRF and exact current password. Only `current_password`, a non-empty string of at most 256 characters, is accepted.

```json
{"current_password":"<current-password>"}
```

Success `204` revokes every session for the current account and clears current cookies. Incorrect password: `422` on `current_password`. No user ID is accepted; another account's devices are unaffected.

### POST `/api/v1/admin/auth/password`

Requires authenticated cookie and CSRF.

```json
{"current_password":"<current-password>","new_password":"<new-password>"}
```

`current_password`: exact non-empty string, at most 256 characters. `new_password`: 15–128 characters, including Unicode/spaces, different from the current password; no composition or breached-password rule. The form may ask for confirmation locally, but must not send `confirm_password` or other profile fields.

Success `204` atomically changes the hash and password timestamp, increments the security version and revokes all sessions. No replacement session is issued: clear private frontend state and require fresh login. Incorrect current password/new-password policy errors return `422` on their respective field. Profile text save and password change must remain separate operations/results.

## 5. Errors, throttling and availability

```json
{"error":{"code":"INVALID_CREDENTIALS","message":"The username or password is incorrect.","fields":{}},"request_id":"req_example_error"}
```

```json
{"error":{"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"new_password":["The new password must be at least 15 characters."]}},"request_id":"req_example_validation"}
```

| HTTP | Stable code | Frontend behavior |
| --- | --- | --- |
| 400 | `INVALID_JSON` | Correct malformed JSON |
| 401 | `INVALID_CREDENTIALS` | Keep login visible; show generic rejection; do not run global redirect handling |
| 401 | `UNAUTHENTICATED` | Clear private identity/cache and redirect protected pages to login |
| 403 | `FORBIDDEN` | Reserved shared integrity failure; Origin is unrestricted |
| 403 | `CSRF_INVALID` | Get a fresh CSRF token, then permit a deliberate user retry |
| 413 | `PAYLOAD_TOO_LARGE` | Request exceeds 16 KiB or upstream PHP limit |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Use JSON for request bodies |
| 422 | `VALIDATION_FAILED` | Attach arrays of errors to named fields; unknown fields are errors too |
| 429 | `RATE_LIMITED` | Respect integer `Retry-After` seconds; countdown is informational |
| 503 | `SERVICE_UNAVAILABLE` | Show unavailable state; never grant fallback access |
| 500 | `INTERNAL_ERROR` | Show safe failure and record request ID for support |

Limits are shared across instances using the configured cache and serialized atomic locks:

- Five failed attempts per normalized username/trusted IP pair in 15 minutes; sixth attempt is blocked. Successful login clears that pair's failures.
- Fifty login attempts per trusted IP in 15 minutes, including successful ones; success does not reset the IP bucket.
- Five incorrect current-password confirmations per account in 15 minutes, shared between password change/logout-all. Successful confirmations do not reset this bucket.

The windows begin with the first counted attempt, and reset at expiry. Requests blocked before credential verification do not add new failed credentials. Validation/CSRF failures are not credential attempts. Rate limits do not permanently disable accounts. A cache-lock timeout or database outage fails closed. Deployments must test their actual shared cache and database under load; process-local fallbacks are unsupported.

A timeout/network failure is an **unknown outcome**: do not automatically replay login, logout-all or password changes. Check `/auth/me` and guide the user. A `401` after password change is consistent with success but does not by itself prove which password was committed; require fresh credential entry. Do not silently assume the old password still works. Do not automatically replay a sensitive mutation after refreshing CSRF either.

## 6. Frontend wiring

1. Open `/tahlilpanel/login`, retrieve `/auth/csrf` with cookies and retain `csrf_token` in memory.
2. Submit JSON login with credentials included and `X-CSRF-Token`. Keep fields initially empty; username uses `autocomplete="username"`, password `autocomplete="current-password"`. A local visibility toggle never changes the value. Disable duplicate submissions.
3. On success, replace in-memory CSRF with the returned session token, invalidate old private caches, and navigate to the fixed `/tahlilpanel/dashboard/home`. Reload `/auth/me` for the authoritative account.
4. On authenticated startup/SSR, resolve `/auth/me`. Treat `401` as guest, `503`/network failure as unavailable, not guest/authenticated fallback.
5. Both header and sidebar logout buttons call the same `/auth/logout`. After `204`, clear all private data and CSRF state. Notify other tabs using BroadcastChannel or a non-secret logout notification; never transmit credentials through browser storage.
6. After successful password change/logout-all, clear private state across tabs and require login. If another tab rotated authentication, retrieve a new CSRF token on its next operation rather than retrying blindly.

```js
const base = '/api/v1/admin/auth';
let csrfToken;

async function api(path, { method = 'GET', body } = {}) {
  const response = await fetch(`${base}${path}`, {
    method,
    credentials: 'include',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(method === 'GET' || !csrfToken ? {} : { 'X-CSRF-Token': csrfToken }),
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  if (response.status === 204) return;
  const result = await response.json();
  if (!response.ok) {
    throw Object.assign(new Error(result.error.message), {
      status: response.status,
      ...result.error,
      requestId: result.request_id,
      retryAfter: Number(response.headers.get('Retry-After')) || undefined,
    });
  }
  return result.data;
}

async function prepareCsrf() {
  csrfToken = (await api('/csrf')).csrf_token;
}
async function signIn(username, password) {
  await prepareCsrf();
  const result = await api('/login', { method: 'POST', body: { username, password } });
  csrfToken = result.csrf_token;
  // Clear private query/store caches before publishing the new identity.
  window.location.assign('/tahlilpanel/dashboard/home');
}
async function currentIdentity() { return api('/me'); }
async function signOut() { await api('/logout', { method: 'POST' }); }
async function signOutEverywhere(current_password) {
  await api('/logout-all', { method: 'POST', body: { current_password } });
}
async function changePassword(current_password, new_password) {
  await api('/password', { method: 'POST', body: { current_password, new_password } });
}
```

The sample intentionally leaves rendering/cache-store APIs to the frontend. Call `prepareCsrf()` on authenticated app initialization before mutations; `/me` does not return a token. Handle errors at the relevant form; implement no automatic mutation retry policy. A link back to `/` stays outside credential submission. Never retain password values after navigating away or log the body.

### Frontend route protection

| Route | Required frontend behavior |
| --- | --- |
| `/tahlilpanel` | `/me` success -> dashboard home; `401` -> login; unavailable -> error state |
| `/tahlilpanel/login` | Guests see form; authenticated accounts -> dashboard home |
| `/tahlilpanel/dashboard` | Authenticate, then redirect to dashboard home |
| `/tahlilpanel/dashboard/*` | Check identity server-side before rendering private HTML; APIs enforce authentication independently |

Do not use `/dashboard` or `/sign-in` as auth destinations. Do not filter menus by role; null roles have identical functions. Frontend route guards alone are never the backend access boundary.

### Reverse proxy and SSR

Proxy the fixed API path to a trusted configured backend; never accept a client-selected upstream URL. Preserve Cookie and every separate Set-Cookie header without merging them, and keep the public host/path cookie scope. Forward public scheme/host only through trusted proxies configured by IP/CIDR. TLS terminates at the trusted proxy or application.

SSR identity requests must construct a fresh request-specific Cookie header from that browser's current auth context. Use a fixed trusted identity URL and `cache: 'no-store'`; never use a global mutable HTTP client cookie jar or share cached identity across requests. Forward cleanup Set-Cookie headers to the browser when applicable. Identity/token GETs need no Origin header; mutations from a trusted BFF must preserve the request-specific cookie and CSRF context. Origin/Referer are not required or validated by the API.

Private dashboard HTML must use `Cache-Control: no-store` and `Vary: Cookie`, and CDN/shared caching must bypass private pages and this API. This backend sets API headers; the frontend host must set HTML headers.

## 7. Provisioning, recovery and future account operations

From a protected server shell with the deployment environment already configured:

```sh
php artisan panel:admin-create
# Interactive prompts: <username>, <contact-email>, <display-name>, <password>, <confirmation>

php artisan panel:admin-reset-password '<existing-username>'
# Interactive hidden prompts: <new-password>, <confirmation>

php artisan panel:auth-prune
```

Passwords have no CLI argument or environment-variable interface and are not printed. Input hiding has no visible-input fallback. Noninteractive provisioning/reset is deliberately rejected. Do not put real passwords in shell history, seeds, example files, this document or frontend public configuration. Creating the same normalized username again fails without changing the existing password; reset requires a real active, non-deleted target and the same new-password policy. Reset revokes all sessions atomically and records `admin_password_reset`.

New usernames must match `^[A-Za-z0-9._-]+$`, length 3–120; display names max 100; contact email required. No default account or default role is created. A deleted username remains reserved by the unique normalized index. If a legacy row needs to become a panel account, implement an explicit reviewed account-management operation; this delivery does not guess usernames or activate legacy accounts.

Security mutations through `User::save()` increment `security_version` and revoke sessions in the same transaction; `delete()` revokes sessions too. Actions recheck authoritative activity/version and live session before security mutations. Password changes use `saveWithSecurityContext(request_id)` so revocation events share the operation request ID. Updates to name/email/role do not revoke; deleted roles become null.

Future user management must load a fresh model and use its security-aware save/delete path, use `saveWithSecurityContext` when an HTTP request ID exists, and use the same user-row lock order as the auth actions. **Do not perform query-builder/bulk/raw SQL updates to password, username, active status or deletion state:** these bypass model lifecycle invariants. Wrap related mutations in a transaction and recheck activity/session version before protected writes. No user-management endpoints are added here.

Events include `login_succeeded`, `login_failed`, `login_rate_limited`, `logout`, `logout_all`, `password_changed`, `admin_password_reset`, and `session_revoked` with reason. Every event has UTC creation time, request/operation ID, outcome and known user/session IDs. Application exception logs expose only request ID and exception class for these API requests; no request body, raw cookie, CSRF token, verifier/hash or query details are logged by this module. Infrastructure-level APM/proxy logging must also redact authentication headers/bodies. Give security event access only to authorized operators.

## 8. Curl and Postman workflow

The Postman collection uses `base_url`, `username`, `password`, `current_password`, `new_password`, and `csrf_token` variables. All credentials are placeholders. Use its cookie jar. CSRF retrieval and login scripts update the CSRF variable; clear it after a successful logout/password change. Do not export a populated environment with real credentials/cookies. No automatic mutation retry is configured.

Equivalent requests (use placeholder JSON files; avoid putting real passwords into shell history):

```sh
# Run in a protected local working directory; cookie jar files contain live credentials.
# Origin headers below are optional examples; no origin allowlist is configured.
curl -i -c '<protected-cookie-jar>' -b '<protected-cookie-jar>' \
  'http://localhost:8000/api/v1/admin/auth/csrf'

# login.json: {"username":"<username>","password":"<password>"}
curl -i -c '<protected-cookie-jar>' -b '<protected-cookie-jar>' \
  -H 'Origin: http://localhost:8000' -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <anonymous-csrf-token>' --data-binary '@<protected-login.json>' \
  'http://localhost:8000/api/v1/admin/auth/login'

curl -i -b '<protected-cookie-jar>' \
  'http://localhost:8000/api/v1/admin/auth/me'

curl -i -X POST -c '<protected-cookie-jar>' -b '<protected-cookie-jar>' \
  -H 'Origin: http://localhost:8000' -H 'X-CSRF-Token: <session-csrf-token>' \
  'http://localhost:8000/api/v1/admin/auth/logout'

# Obtain a new login/session before the next independent examples.
# logout-all.json: {"current_password":"<current-password>"}
curl -i -c '<protected-cookie-jar>' -b '<protected-cookie-jar>' \
  -H 'Origin: http://localhost:8000' -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <session-csrf-token>' --data-binary '@<protected-logout-all.json>' \
  'http://localhost:8000/api/v1/admin/auth/logout-all'

# password.json: {"current_password":"<current-password>","new_password":"<new-password>"}
curl -i -c '<protected-cookie-jar>' -b '<protected-cookie-jar>' \
  -H 'Origin: http://localhost:8000' -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <session-csrf-token>' --data-binary '@<protected-password.json>' \
  'http://localhost:8000/api/v1/admin/auth/password'
```

## 9. Contract mapping and verification

| Reference field/behavior | Delivered mapping / difference |
| --- | --- |
| Account `id`, `username`, `username_normalized`, `email`, `name`, `is_active` | Same authoritative `users` fields; normalized username unique and internal |
| `password_hash` | Internal Laravel column `users.password`; Argon2id verifier, never serialized |
| `role_id`, `role: {id,name} \| null` | Nullable FK to `roles`, null on role deletion; no permissions |
| `avatar_media_id`, `avatar_url` | Nullable media FK and canonical avatar display URL; see `16-shared-media-api.md` |
| Creation/update/password/login timestamps, deletion | `created_at`, `updated_at`, `password_changed_at`, `last_login_at`, `deleted_at` |
| Session public `id`, verifier, user, deadlines, revocation | `panel_sessions` with separate UUID and token hash; `security_version` additionally binds account revision |
| CSRF bound state | Encrypted framework session payload; anonymous rows share the session table with `user_id=null` |
| New-password screening | Explicitly omitted by user request |
| Endpoint names, payload fields, envelopes, cookies, deadlines, limits | Preserved as specified; no extra permissions/refresh-token surface |
| Pages, menus, profile display, account switching UI | Frontend responsibility; integration requirements above, no UI implementation claimed |
| Exact staging/production hosts | Not supplied; placeholders and required deployment settings provided |
| Optional IP/User-Agent session metadata | Not collected/persisted; no metadata retention obligation beyond expiring limiter keys |

Verification results are recorded after running the delivered test suite. SQLite tests prove observable request behavior, transactional rollback and deterministic account-version interleavings; dedicated forked SQLite tests in the management/media modules now also exercise actual writer-lock races; tests do not prove deployed TLS/proxy behavior or actual multi-host restart durability. Production-engine concurrency/load testing and frontend/SSR/CDN acceptance must be completed against a disposable deployment with the intended engine and public origins. No production/staging service or separate disposable production-engine database was supplied.

### Executed verification (2026-10-24)

| Check | Result |
| --- | --- |
| Authentication coverage in the final full suite | 51 passed |
| `php artisan test --compact tests/Feature/PanelOperationsTest.php` | 10 passed |
| `composer test` (final run) | **120 passed, 792 assertions**, including management/media/seed coverage and five real multiprocess races; isolated SQLite `:memory:` plus dedicated temporary SQLite files |
| `vendor/bin/pint --dirty --format agent` | Passed after formatting |
| `composer validate --no-check-publish` | Passed |
| `git diff --check` | Passed |
| `npm exec --yes --package=@redocly/cli -- redocly lint doc/1-panel-login.openapi.yaml doc/2-panel-users-roles.openapi.yaml doc/16-shared-media.openapi.yaml --extends minimal --format summary` | All three valid, zero errors; three expected warnings for documented localhost development servers |
| Postman JSON parsing, six method/path pairs, placeholder credentials, relative documentation links | Passed |
| `php artisan route:list --path=api --except-vendor` | Twenty admin routes registered, including six auth routes |

The OpenAPI validator ran from npm's temporary tooling cache; application dependencies and lockfiles were not changed. Postman was checked structurally; no real credentials or deployed service were used.

### Acceptance coverage

| Acceptance area | Evidence / status |
| --- | --- |
| Secure initial administrator, duplicate protection, operational reset | Automated command tests; no real account created |
| Correct credentials, normalization, exact Unicode/space password matching | Automated API tests |
| Unknown/wrong/inactive/deleted account equivalence; email not login alias | Automated API tests |
| Guest API protection, authoritative identity, roleless/renamed/deleted roles | Automated API tests |
| Token verifier/public-ID separation, tampered/legacy/revoked cookie rejection | Automated API tests |
| Persistence independent of request/session-service objects | Automated fresh-service test against shared test database; actual restarts/multiple instances not exercised |
| Idle/absolute expiration, idle cap, anonymous expiry and stable multi-tab token | Automated controlled-clock tests |
| Browser rotation, consumed-context replay, other-device preservation, repeated logout | Automated API/action tests, including deterministic overlapping login-context test |
| Logout-all and password changes require own current password; no target-ID injection | Automated API tests with a second account/session |
| Deactivation, deletion, username/password changes, restore/reactivation invalidate old access | Automated model/API tests |
| Stale password verification after an account reset cannot issue a session | Automated deterministic interleaving test; actual production-engine process concurrency not exercised |
| Security update/reset and revocation roll back together on event-store failure | Automated database-trigger failure tests |
| Context-bound CSRF with arbitrary/missing Origin; credentialed CORS/preflight | Automated tests through the actual token-check path, without Laravel's usual test bypass |
| HttpOnly/host-only/path and production Secure settings; legacy cookie deletion | Automated response-cookie tests; browser/TLS/proxy deployment not exercised |
| Shared attempt limits, success reset rules, window expiry, Retry-After, forwarded-IP spoofing | Automated tests using database cache; cross-instance load test remains deployment work |
| New-password lengths/difference, Argon2id, old/new credential behavior | Automated API tests; compromised-password screening intentionally omitted |
| JSON envelopes, request IDs, cache headers, unknown fields/query credentials, transport limits | Automated API tests |
| Store outage denies access/revocation with controlled 503 | Automated storage-failure tests |
| Security-event pruning and expired/revoked context cleanup | Automated scheduler-command test; operator must install scheduler |
| Login/dashboard routes, both logout controls, account display, private cache clearing, loading and recovery UI | Documented integration contract; frontend implementation/acceptance deferred |
| Protected HTML and CDN/SSR cache isolation | Frontend/proxy deployment requirement; not exercised by backend-only delivery |

No development migration, production migration, production/staging configuration change, deployment, real provisioning, browser E2E run or asset build was performed. No frontend assets were changed.

### Optional seed administrator

`DatabaseSeeder` also calls `PanelAdministratorSeeder`. Set protected local/deployment values before running `php artisan db:seed --no-interaction`:

```dotenv
PANEL_SEED_ADMIN_USERNAME=admin
PANEL_SEED_ADMIN_NAME="Panel Administrator"
PANEL_SEED_ADMIN_EMAIL=<real-contact-email>
PANEL_SEED_ADMIN_PASSWORD=<private-password-of-15-to-128-characters>
```

The email/password have no repository defaults. With either absent, the administrator seed is skipped. When configured, it creates an active login account, assigns the ordinary Administrator label if available, and records creation without printing the password. Repeated seeding preserves existing credentials/status/profile and does not restore a deleted account. Use the operational reset command for an intentional password reset. A username/email collision is never treated as permission to overwrite another account. The original inactive test fixture remains separate and is excluded from the panel-account list.
