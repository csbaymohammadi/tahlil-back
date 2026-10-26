# Panel Profile API

Development base resolved by Boost: `http://localhost:8000/api/v1/admin`. Staging/production are deployment-specific and unverified. Companion schema: [15-panel-profile.openapi.yaml](15-panel-profile.openapi.yaml). Binary upload/crop-preview support uses [shared media](16-shared-media-api.md).

## Read and update

`GET /profile` returns the signed-in administrator's own record. `PATCH /profile` resolves the same account from the session; no target-ID parameter exists. All active/non-deleted administrators have access, including roleless accounts. The account, auth identity, management record and author share the same immutable ID and version.

Both operations require the existing `tahlil-panel-session-v2` cookie; PATCH additionally requires `X-CSRF-Token`. Origin is optional and unrestricted under the shared credentialed CORS policy in config/cors.php; session and CSRF checks remain in effect. Responses carry `Cache-Control: private, no-store`, `X-Request-ID`, and the shared request envelope. Limits are 300 requests/minute per account/route. Admin JSON body bound is 16 KiB, including encoded wire bytes; duplicate decoded object keys are rejected.

```json
{"data":{"id":7,"name":"Example Editor","linkedin_url":"https://www.linkedin.com/in/editor","bio":"Market analysis.","avatar_media_id":42,"avatar_url":"/media/42/avatar.png","updated_at":"2026-10-26T09:00:00.000000Z","version":3},"request_id":"req_profile_read"}
```

Empty optional text returns empty strings; absent avatar returns null for ID/URL. Never return username, email, role/status, password/hash or session tokens through this profile resource.

PATCH accepts exactly `expected_version` and at least one profile field. Omitted fields preserve existing values. Name is required when submitted, 1–100 trimmed Unicode plain-text characters; biography is 0–5000 trimmed plain-text characters with internal spacing/line breaks preserved. Null text is invalid; empty optional strings clear. LinkedIn is 0–2048 characters; nonempty values must be absolute HTTP/HTTPS on linkedin.com or an exact dot-subdomain, without credentials, controls/backslashes or malformed destinations. Scheme/host/default-port canonicalization preserves path/query/fragment; no URL is fetched or converted to an OAuth credential.

```json
{"expected_version":3,"name":"Updated Editor","linkedin_url":"","bio":"First line.\nSecond line.","avatar_media_id":57}
```

`avatar_media_id` is null to remove, or a positive safe-integer ID of a ready accessible avatar-purpose 512×512 still PNG. Omission preserves it; URL strings/data URLs/base64 are invalid. The browser prepares a square 512×512 PNG, uploads binary through `/media`, then sends its durable ID. Server decoding/validation remains in the media service. Crop/edit creates a new asset, not an overwrite of immutable shared bytes. Use authenticated `/media/{id}/preview` or the existing backend-origin proxy for canvas access and cookies. Crop cancellation leaves the old saved reference intact.

Profile fields, media-reference replacement, audit and public-change event commit together under the shared mutation lock. Invalid/inaccessible/pending/deleted/wrong-purpose media fails without a partial name/bio write; null removal detaches only this account's avatar reference and never physically deletes a shared asset. Unreferenced files enter the existing grace/cleanup lifecycle.

Actual changes advance the same User version and updated_at as user management; canonical no-ops preserve both. A stale expected version returns `409 VERSION_CONFLICT` with no changes. Management edits and password/security changes cannot be overwritten with an older profile snapshot. Refresh GET for deliberate reconciliation and retain unsaved values for comparison. Name/biography/LinkedIn/photo updates do not change security_version or revoke sessions.

## Password flow and partial outcomes

Use the existing `POST /auth/password` only; password fields are rejected by PATCH /profile.

```json
{"current_password":"<current-password>","new_password":"<new-password>"}
```

Collect current-password confirmation, require the new password and browser confirmation to agree, and send no confirm_password. New password bounds remain 15–128 characters, with current-password verification and the existing policy. Never trim/normalize/log secrets. Empty password controls mean no password operation; partial input is a browser validation error.

For combined edits, locally validate the whole form, PATCH changed profile fields first, then POST the password change. Stop after a profile failure. A password failure preserves committed profile changes and displays its own errors rather than complete success. A successful password response is 204, revokes all sessions, clears session cookies and requires login with the new password. Clear secret inputs after success. Do not automatically retry an uncertain password timeout; recover through the existing sign-in flow. A profile-only save never calls the password endpoint, and password-only save never issues an empty PATCH.

## Shared identity, authors and caching

`/auth/me` and management reads resolve the updated name/avatar from the same account. Home gets its welcome identity from `/auth/me`, not the analytics response. After a successful PATCH refresh identity, profile and affected user detail/list queries; retain the canonical returned version/values.

`PublicAuthor` supplies only `{id,name,bio,linkedin_url,avatar_url}`. Public article/news cards in `/api/v1/page-settings` use that projection. The reusable projection is available to external detail renderers; this delivery does not register public article/news HTML routes. Existing content authorship/IDs/statuses are preserved. A soft-deleted author keeps the retained name/ID while bio/LinkedIn/avatar become neutral empty/null values; authored content is not deleted.

User avatar references are public only while that non-deleted account is used by a currently Published/non-deleted blog/news/bonus author projection, or the image has another independently public consumer. Otherwise preview/delivery requires authentication. Eligibility is checked live so unpublication/deletion cannot be bypassed by a stale avatar URL. Replacing an avatar does not rewrite old image bytes.

Committed display-field changes emit `author_profile_changed`; author deletion and media lifecycle changes likewise enqueue public cache revalidation. Signed Outbox delivery, acknowledgement, retry policy and receiver instructions are in [page settings handoff](13-panel-page-settings-api.md#durable-cache-propagation). Do not treat a profile save as proof the external website's caches were refreshed. The receiver must refresh relevant author/content/home/navigation consumers from current source data.

`avatarSrc` is preview state; persist avatarMediaId instead. Transform linkedin_url, avatar_media_id, avatar_url, updated_at into camelCase for React; field errors live under `error.fields` with snake_case paths. Open LinkedIn links with escaped destinations and noopener/noreferrer when using a new tab. Loading failures never clear existing persisted references.

## Errors, audit and verification

Expected errors include 400 INVALID_JSON, 401 UNAUTHENTICATED, scoped 403 FORBIDDEN/CSRF_INVALID, 409 VERSION_CONFLICT, 413 PAYLOAD_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE, 422 VALIDATION_FAILED, 429 RATE_LIMITED with Retry-After, and controlled 500/503 service failures. Password errors use the auth endpoint's fields, especially current_password/new_password.

```json
{"error":{"code":"VERSION_CONFLICT","message":"The account has changed. Fetch it before retrying.","fields":{}},"request_id":"req_profile_conflict"}
```

```json
{"error":{"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"linkedin_url":["Enter an HTTP/HTTPS URL on linkedin.com or its subdomains."]}},"request_id":"req_profile_invalid"}
```

Security audit stores actor/target ID, field names, record_version, UTC time and request ID. Rejections record stable error codes. Biography text, full request bodies, URL tracking queries, credentials and cookies are excluded. The additive migration adds the two public fields plus nullable record_version metadata to the existing security audit table; no duplicate account/version model is introduced.

```sh
curl '{{admin_base}}/profile' -b '{{cookie_jar}}'
curl -X PATCH '{{admin_base}}/profile' -b '{{cookie_jar}}' -H 'Origin: {{allowed_origin}}' -H 'X-CSRF-Token: {{csrf_token}}' -H 'Content-Type: application/json' --data-binary @profile.json
```

Tests cover roleless access, normalization/clearing, privileged/foreign-account input rejection, shared version conflicts, avatar accessibility/removal/publication, failure rollback, password-flow independence, session revocation and safe audit metadata. Tests do not exercise an external React cropper or real website-cache receiver. Additive migrations were prepared without changing development data. No production domains, secrets or default credentials are fabricated.
