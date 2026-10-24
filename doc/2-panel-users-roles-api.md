# Users and roles: backend handoff

## Delivery and environment

This module manages the same `users` records used by panel login. All active, non-deleted authenticated administrators can use every operation, including accounts with no role. Roles are descriptive labels, never privileges. There are no permission payloads, role codes, access matrices, protected system roles, invitations, password delivery, or frontend pages in this delivery.

| Environment | API base |
| --- | --- |
| Development | `http://localhost:8000/api/v1/admin` |
| Staging | `https://<staging-public-host>/api/v1/admin` (deployment input) |
| Production | `https://<production-public-host>/api/v1/admin` (deployment input) |

[OpenAPI](2-panel-users-roles.openapi.yaml) and [Postman collection](2-panel-users-roles.postman_collection.json) accompany this document. See [authentication](1-panel-login-api.md) and [shared media](16-shared-media-api.md).

**Current origin policy, requested by the owner:** no Origin/Referer allowlist, no `PANEL_ALLOWED_ORIGINS` setting. CORS permits requests from every origin, reflects the requesting Origin for credentialed requests, allows all request headers/methods, and exposes request-ID, retry, location and binary headers. The browser still enforces host-only HttpOnly cookies and `SameSite=Lax`; cross-site fetch from an unrelated site may not carry cookies even with `credentials: 'include'`. Use a same-site frontend or a request-scoped proxy/BFF for cookie authentication in that case. No change to cookie SameSite or CSRF is implied by permissive CORS. A wildcard origin policy must be considered when deploying any future change to cookie cross-site behavior.

## Shared request/response rules

Require the current session cookie on every endpoint. Mutations require `X-CSRF-Token`; Origin is optional and unrestricted. Use `Accept: application/json`, `Content-Type: application/json` on POST/PATCH, and `credentials: 'include'`. DELETE has no body. JSON bodies are limited to 16 KiB. Unknown JSON/query fields are errors. PUT is not supported; use PATCH.

Every success is `{ "data": ..., "request_id": "req_<uuid>" }`, with `meta` on the user list. Every error is `{ "error": { "code": "...", "message": "...", "fields": {} }, "request_id": "..." }`. Field errors map names to string arrays. `X-Request-ID` matches the JSON request ID. A `204` has no body. Responses use `Cache-Control: no-store`, `Vary: Cookie` (and Origin when CORS requires it). Timestamps are UTC ISO 8601 `Z`; IDs and versions are positive integers up to `9007199254740991`.

## Types and field behavior

```ts
interface Administrator {
  id: number;
  username: string;
  name: string;
  email: string;
  avatar_media_id: number | null;
  avatar_url: string | null;
  role_id: number | null;
  role: { id: number; name: string } | null;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
  updated_at: string;
  version: number;
}
interface Role {
  id: number;
  name: string;
  users_count: number;
  created_at: string;
  updated_at: string;
  version: number;
}
interface PageMeta { page: number; per_page: 10 | 20 | 50; total: number; total_pages: number }
```

| Field | Create | PATCH |
| --- | --- | --- |
| `username` | Required string; trim; 3–120 ASCII letters/digits/`.`/`_`/`-` | Optional; same rules; case-insensitive unique lookup |
| `name` | Required Unicode string; trim; 1–100 characters | Optional; same rules |
| `email` | Required valid string; trim and lowercase; max 254 | Optional; same rules |
| `password` | Required exact string; 15–128 characters | Omit or exact `""` preserves; null invalid; nonempty replaces target's password |
| `role_id` | Required existing role ID; JSON integer | Optional existing ID; explicit null invalid |
| `is_active` | Required JSON boolean | Optional JSON boolean; `"true"`, `0`, `1` are not booleans |
| `avatar_media_id` | Optional accessible, ready image ID or null | Omit preserves; null invalid; replacement must be a still 512×512 image |
| `expected_version` | Not accepted | Required JSON integer |

Passwords preserve whitespace and Unicode; a whitespace-only string is a supplied password and is subject to length/difference rules. No composition or common/compromised-password screening is performed, as explicitly requested. New hashes use Argon2id. Stored password/hash is never returned. A supplied replacement must differ from the current password. For the requester's own account, any nonempty replacement returns `422 SELF_PASSWORD_CHANGE_REQUIRED`; use `/auth/password` with the current password instead.

Accounts with no role remain usable; the edit form may omit `role_id` to preserve this state. Explicit role clearing is performed by role deletion, not null PATCH input. Avatar removal/upload is not an account operation. The media service accepts prepared files; this module accepts only an existing image ID, never a URL/path/base64/file. `avatar_url` is its canonical display URL; private media requires the session cookie or authenticated preview through the frontend's backend-origin proxy.

Username/email uniqueness includes deleted accounts. Role names remain reserved after soft deletion. `users_count` counts non-deleted assigned users, including inactive users, and is computed. Role names are 1–80 Unicode characters after trim. Normalized uniqueness converts Arabic yeh/kaf to Persian, maps ZWNJ to a space, collapses whitespace and case-folds. Display names are preserved separately. No ID/name confers authority.

## Endpoints

| Method | Path relative to API base | Input | Success |
| --- | --- | --- | --- |
| GET | `/users?page=1&per_page=10` | Optional positive `page`; `per_page` 10/20/50 | 200: administrator array + `meta` |
| GET | `/users/{id}` | Safe integer path ID | 200: administrator |
| POST | `/users` | Required create fields above | 201: administrator; `Location: /api/v1/admin/users/{id}` |
| PATCH | `/users/{id}` | `expected_version` and at least one editable field | 200: saved administrator |
| DELETE | `/users/{id}?expected_version=2` | Required positive integer query version; no body | 204 |
| GET | `/roles` | No query/body | 200: complete non-deleted role array |
| GET | `/roles/{id}` | Safe integer path ID | 200: role |
| POST | `/roles` | `name` only | 201: role; `Location: /api/v1/admin/roles/{id}` |
| PATCH | `/roles/{id}` | `expected_version`, `name` | 200: role |
| DELETE | `/roles/{id}?expected_version=2` | Required query version; no body | 200: deletion summary |

Users sort by `created_at DESC, id DESC`. An out-of-range page returns an empty array with the requested page and accurate totals. An entirely empty result has `total_pages: 0`. Inactive panel accounts are included, deleted accounts are excluded. Legacy users with no login username are preserved but excluded from this module's list/detail/mutation target selection. They are not automatically activated or assigned invented usernames.

Roles sort by normalized name then ID; there is no pagination or hidden result limit. First creation does not log in the new user, alter the actor session, or email credentials.

### Example responses

```json
{
  "data": {
    "id": 12, "username": "editor.demo", "name": "Example Editor", "email": "editor@example.test",
    "avatar_media_id": null, "avatar_url": null,
    "role_id": 4, "role": {"id": 4, "name": "Writer"}, "is_active": true,
    "last_login_at": null, "created_at": "2026-10-24T10:00:00Z",
    "updated_at": "2026-10-24T10:00:00Z", "version": 1
  },
  "request_id": "req_example_user"
}
```

```json
{"data":[],"meta":{"page":1,"per_page":10,"total":0,"total_pages":0},"request_id":"req_example_empty"}
```

A populated list uses the same administrator records in `data`. A roleless record has both `role_id` and `role` null.

```json
{"data":[{"id":4,"name":"Writer","users_count":3,"created_at":"2026-10-24T10:00:00Z","updated_at":"2026-10-24T10:00:00Z","version":1}],"request_id":"req_example_roles"}
```

```json
{"data":{"deleted_role_id":4,"affected_users_count":3},"request_id":"req_example_role_delete"}
```

## Versioning, integrity and session effects

POST starts at version 1. PATCH/DELETE compare `expected_version` against the authoritative record inside a transaction. Stale input returns `409 VERSION_CONFLICT`; re-fetch before a deliberate retry. Do not silently update the expected version and replay a mutation. A no-op preserves the version; only actual editable changes advance it. `last_login_at`, computed role labels and counts do not advance the account edit version.

`version` and `security_version` are separate: role assignment/deletion, name, email and avatar changes do not revoke sessions. Password replacement, username changes, deactivation and deletion do. Reactivation does not revive old sessions. `/auth/me` resolves current role/avatar data, so renamed/deleted roles appear immediately without re-login.

Deleting/deactivating self returns `409 SELF_ACCOUNT_PROTECTED`. Sending unchanged `is_active: true` for self is valid. At least one active panel account is preserved transactionally, independent of roles. User deletion soft-deletes, deactivates, advances version and revokes sessions atomically; media references remain retained. No restore API is supplied.

All roles may be deleted, including the actor's own or any Administrator-named label. Deletion soft-deletes the role, advances its version, clears assignments of non-deleted users and advances their versions in one transaction; it preserves activity and sessions. The returned count is from that transaction, not a previously displayed count. An empty role catalog is valid; create a new label before using the required-role account-create form.

Concurrency uses the durable `panel_mutation_lock` singleton row, updated at transaction entry before locking actors/targets/media. The same coordination is used by existing auth security operations and model-backed account changes. SQLite obtains a real writer lock through this update, rather than relying on unsupported SQLite `FOR UPDATE`. New consumer actions must acquire this lock first, recheck their actor/session and commit references with the owning record. Never bypass security/version invariants with arbitrary raw/bulk account updates.

## Errors

| HTTP | Code | Meaning / field |
| --- | --- | --- |
| 400 | `INVALID_JSON` | Malformed JSON |
| 401 | `UNAUTHENTICATED` | Missing, expired, revoked, disabled/deleted actor |
| 403 | `CSRF_INVALID` | Missing/mismatched CSRF; obtain a fresh token before deliberate retry |
| 403 | `FORBIDDEN` | Reserved shared integrity error; Origin is not restricted |
| 404 | `USER_NOT_FOUND`, `ROLE_NOT_FOUND` | Missing/deleted/non-panel target |
| 409 | `USERNAME_TAKEN`, `EMAIL_TAKEN`, `ROLE_NAME_TAKEN` | Reserved/duplicate value; error on `username`, `email` or `name` |
| 409 | `VERSION_CONFLICT` | Stale record version; no partial save |
| 409 | `SELF_ACCOUNT_PROTECTED`, `LAST_ACTIVE_ADMIN` | Account-integrity rule |
| 413 | `PAYLOAD_TOO_LARGE` | Body over 16 KiB or upstream PHP limit |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Use JSON for POST/PATCH |
| 422 | `VALIDATION_FAILED` | Unknown fields, invalid values, role/media/version or missing editable fields |
| 422 | `SELF_PASSWORD_CHANGE_REQUIRED` | Use `/auth/password` for own password |
| 429 | `RATE_LIMITED` | Shared limiter outcome; honor `Retry-After` when present |
| 503 | `SERVICE_UNAVAILABLE` | Required database/session/cache unavailable |
| 500 | `INTERNAL_ERROR` | Unexpected safe error; retain request ID for support |

No additional users/roles endpoint rate cap is imposed by this delivery. Authentication/security confirmation limits remain in force for their own endpoints. Unsupported HTTP methods return 405 with a safe shared error; routing errors do not turn into 500.

```json
{"error":{"code":"EMAIL_TAKEN","message":"This email is already registered.","fields":{"email":["This email is already registered."]}},"request_id":"req_example_conflict"}
```

Messages currently use English and may be rendered in Persian by code/field mapping. No internal query/stack/hash values are returned. Account and reference writes roll back together if validation or audit persistence fails.

## Frontend sequence and examples

- Load `/users` and complete `/roles`; use no-role placeholders for null assignments.
- Before editing/deleting, GET the target's current record/version. Keep password empty in the edit form.
- Upload/crop an avatar through the media workflow first; send its returned integer ID. Failed form saves leave new uploads unattached for later retry/expiry.
- POST successful creation: invalidate lists and move to page 1. DELETE successful user removal: re-fetch totals and choose the nearest remaining valid page.
- On 409 version conflicts, re-fetch and let the user resolve changes. On network timeout, determine the outcome before another write; do not blindly replay credentials/security changes.
- After any session-invalidating self username change, clear private UI state and sign in again. Role changes require refreshing identity/list data only.

```js
const base = '<configured-backend-origin>/api/v1/admin';
const csrf = (await (await fetch(`${base}/auth/csrf`, {credentials: 'include'})).json()).data.csrf_token;
const response = await fetch(`${base}/users/<id>`, {
  method: 'PATCH', credentials: 'include',
  headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
  body: JSON.stringify({expected_version: 1, name: 'Updated Editor'})
});
// Branch on status/code; invalidate cached records after confirmed success.
```

```sh
curl -b '<protected-cookie-jar>' '<backend-origin>/api/v1/admin/users?page=1&per_page=10'
curl -b '<protected-cookie-jar>' '<backend-origin>/api/v1/admin/roles'
# create-user.json contains required fields with placeholder credentials only.
curl -i -b '<protected-cookie-jar>' -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <csrf-token>' --data-binary '@<protected-create-user.json>' \
  '<backend-origin>/api/v1/admin/users'
curl -i -X PATCH -b '<protected-cookie-jar>' -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <csrf-token>' --data-binary '@<protected-user-patch.json>' \
  '<backend-origin>/api/v1/admin/users/<id>'
curl -i -X DELETE -b '<protected-cookie-jar>' -H 'X-CSRF-Token: <csrf-token>' \
  '<backend-origin>/api/v1/admin/roles/<id>?expected_version=<version>'
```

The Postman collection contains all ten operations and placeholder variables for IDs, version, cookie-jar context, CSRF and passwords; obtain CSRF/login through the auth collection first.

## Deployment, audit and contract differences

Apply additive migrations intentionally; this task does not migrate or seed development data. Normalization collision preflight fails with affected record IDs before applying changes; resolve duplicates explicitly, never silently merge/delete identities. Existing rows start with edit version 1. Existing legacy users remain inactive unless explicitly configured. `RoleSeeder` inserts the four ordinary baseline labels idempotently without fixed IDs or restoring tombstones. The earlier idempotent test-user seeder is preserved.

Security events now include actor/target, UTC timestamp, outcome, changed field names, request ID and affected count for role deletion. Events contain field names only, never password/hash values. Existing 90-day pruning applies. No content modules exist yet; account soft deletion preserves IDs and references for future authorship integration and does not perform content cascades.

Approved differences: no breached-password screening; legacy username-less users excluded; any Origin permitted; frontend tabs/dialogs and browser E2E outside this delivery. All avatar references follow the shared prepared-file contract, not server cropping/transcoding.

### Optional seed administrator

`DatabaseSeeder` also calls `PanelAdministratorSeeder`. Set protected local/deployment values before running `php artisan db:seed --no-interaction`:

```dotenv
PANEL_SEED_ADMIN_USERNAME=admin
PANEL_SEED_ADMIN_NAME="Panel Administrator"
PANEL_SEED_ADMIN_EMAIL=<real-contact-email>
PANEL_SEED_ADMIN_PASSWORD=<private-password-of-15-to-128-characters>
```

The email/password have no repository defaults. With either absent, the administrator seed is skipped. When configured, it creates an active login account, assigns the ordinary Administrator label if available, and records creation without printing the password. Repeated seeding preserves existing credentials/status/profile and does not restore a deleted account. Use the operational reset command for an intentional password reset. A username/email collision is never treated as permission to overwrite another account. The original inactive test fixture remains separate and is excluded from the panel-account list.

## Executed verification (2026-10-24)

| Check | Result |
| --- | --- |
| `composer test` | **120 passed, 792 assertions** across the full suite |
| Multiprocess database races | Five passed using independent temporary SQLite databases: competing deactivations, role assignment/deletion, attach/discard, duplicate username creation and repeated upload key |
| `vendor/bin/pint --dirty --format agent` | Passed after formatting |
| `composer validate --no-check-publish` | Passed |
| `git diff --check` | Passed |
| Redocly OpenAPI lint, all three contracts | Valid, zero errors; three expected localhost-server warnings |
| Postman collection JSON and documented paths | All three collections valid structurally |

No development database was migrated or seeded, no real administrator was provisioned, and no production service was changed. Production-engine concurrency and deployment/browser acceptance remain unverified; the multiprocess results above apply to SQLite. Application dependencies and lockfiles were not changed.
