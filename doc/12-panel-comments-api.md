# Shared Comments, Official Replies and Public Submission API

Backend handoff for `12-panel-comments.md`, covering the private inbox/reply/delete API, public submission/read, typed parent eligibility, real comment counts, encrypted email and durable invalidation work. No visitor accounts, email delivery, ratings, threads, attachments, approval workflow or frontend UI is introduced. See [OpenAPI](12-panel-comments.openapi.yaml), [panel authentication](1-panel-login-api.md), [bonuses](10-panel-bonuses-api.md), and [tutorials](11-panel-tutorials-api.md).

## Bases, guards and transport

| Environment | Administrative base | Public base |
| --- | --- | --- |
| Development | `http://localhost:8000/api/v1/admin` | `http://localhost:8000/api/v1` |
| Staging | `https://<staging-host>/api/v1/admin` | `https://<staging-host>/api/v1` |
| Production | `https://<production-host>/api/v1/admin` | `https://<production-host>/api/v1` |

Development follows the current Boost-resolved URL. Deployment hosts are placeholders, not verified services. Admin routes require existing active panel session cookie tahlil-panel-session-v2 and the shared management guard; mutations require the panel X-CSRF-Token. Existing panel Origin/CORS behavior remains unchanged and no permission matrix is added. Browser admin fetches include credentials.

Public routes require neither administrator credentials nor guest registration. POST requires an Origin exactly matching the backend's scheme/host/port; use the same-origin frontend or BFF. There is no panel/guest CSRF-token bootstrap for comment submission. Supplied GET origins are also checked, while GET without Origin is accepted. OPTIONS is restricted to same origin, GET/POST and Accept/Content-Type/Idempotency-Key headers. Rating CORS and panel CORS remain independent.

Use Accept: application/json and JSON bodies for PUT/POST. Body maximum is 32 KiB; field bounds apply separately. GET/DELETE accept no body. Unknown, structured or repeated query parameters are rejected before accepting their parsed values. Success contains data/request_id and list meta; errors contain error.code/message/fields and request_id. IDs and versions are positive safe integers; JSON numeric strings/fractions/booleans are rejected. Timestamps are server UTC ISO 8601. All admin and public comment responses use no-store and X-Request-ID. HTTP 204 is bodyless.

## Typed domains and parent visibility

| section | Parent | Current public path |
| --- | --- | --- |
| blog | Blog article ID | `/blog/{slug}` |
| news | News item ID | `/news/{slug}` |
| exchanges | Cryptocurrency exchange ID | `/exchanges/{slug}` |
| brokers | Forex broker ID | `/brokers/{slug}` |
| stock-brokers | Stock brokerage ID | `/stock-brokers/{slug}` |
| gold-platforms | Gold platform ID | `/gold-platforms/{slug}` |
| prop-firms | Prop firm ID | `/prop-firms/{slug}` |
| bonus | Bonus ID | `/bonuses/{slug}` |

Always combine section and immutable parent_id. IDs, categories, names or slugs coinciding across domains do not link parents. All is a virtual admin filter; bonus belongs in All with Bonuses label and may be queried explicitly, without adding a dedicated tab. The seven named tabs use blog/news/exchanges/brokers/stock-brokers/gold-platforms/prop-firms in order after All. Tutorials/video/all/unknown types cannot receive submissions.

Public parents must exist, be non-deleted and Published; missing/Draft/scheduled/deleted targets return uniform 404 CONTENT_NOT_FOUND without comments/title/count leaks. Private cards can remain accessible with parent_visibility public/draft/deleted; scheduled maps to draft. Only public parents receive page_url, derived from their current slug. Draft/deleted parents have page_url null, without preview behavior. Current title is derived from retained parent data, with a last-known snapshot fallback; title changes refresh snapshots. Slug/category changes keep relationships; recreating content under a new ID does not inherit comments. Parent publication/deletion gates comments and replies without destroying history; republishing the same ID restores surviving visible comments.

## Public visitor API

Paths below are relative to /api/v1. The route's parentId placeholder is the numeric parent ID, equivalent to parent_id in the source contract; never supply a slug.

### GET `/comments/{section}/{parentId}`

Parameters: page starts at 1 (default 1), per_page is 1–100 (default 100). Filter on typed parent, public eligibility, visibility=visible and non-deleted records before counting/pagination. Sort created_at DESC/id DESC. Replies are a single child per card, not extra comment records. Zero results use total_pages 0; beyond-last pages return empty data with accurate totals/requested page.

```json
{
  "data": [{
    "id": 401,
    "name": "Sara Visitor",
    "text": "What are the current conditions?",
    "created_at": "2026-10-26T10:00:00.000000Z",
    "reply": {
      "text": "The conditions are listed above.",
      "created_at": "2026-10-26T10:10:00.000000Z",
      "updated_at": "2026-10-26T10:10:00.000000Z"
    }
  }],
  "meta": {"page":1,"per_page":100,"total":1,"total_pages":1},
  "request_id": "req_public_list"
}
```

Frontend labels public replies with the website's official brand. No staff name/username/account ID, email, version or audit data is returned. Use meta.total for counts rather than one page's array length. Fetch later pages until complete if the list UI has no pagination controls; this is a current traversal, not a snapshot.

### POST `/comments/{section}/{parentId}`

Accept exactly:

```json
{"name":"Sara Visitor","email":"visitor@example.com","text":"What are the current conditions?"}
```

Name: trimmed string 2–80 characters. Email: trimmed RFC-valid address, maximum 254 characters; no DNS verification/network call. Text: trimmed string 10–2,000 characters. Null, non-string and whitespace-only inputs fail. Internal spaces/newlines and literal tag/Markdown-like strings remain plain text; render escaped, without HTML execution, Markdown transformation or auto-embeds. Parent, URLs, status, reply, rating, date and administrator fields cannot be submitted.

A successful write is immediately visible and Unanswered; return 201 with the public comment shape and reply=null. Reset the form only after success; preserve inputs on errors. Pending/hidden legacy records stay excluded from public queries/counts and are never implicitly approved by replying. Their moderation is outside this API.

Idempotency-Key must be a client-generated random UUID v4, reused only for an identical retry. The server canonicalizes trimmed fields, keeps a globally unique normalized key, typed target, HMAC payload fingerprint and result comment ID for 24 hours. It does not store another plaintext copy of email/text in idempotency data. Concurrent identical requests commit one comment/event and return its same ID with 201. Different payload/target returns 409 IDEMPOTENCY_CONFLICT. Missing/invalid key is 422 with idempotency_key field error. Keys are not access credentials. Parent eligibility is rechecked before replay; deleted/hidden results cannot be replayed. After expiry/pruning a key is no longer a duplicate guarantee.

Response:

```json
{"data":{"id":401,"name":"Sara Visitor","text":"What are the current conditions?","created_at":"2026-10-26T10:00:00.000000Z","reply":null},"request_id":"req_comment_created"}
```

Submission attempts after the Origin/body gate, including validation failures and retries, use atomic shared limits: default 5 per IP and 300 global per 900 seconds. Thresholds are configurable in config/comments.php. IP cache keys are HMAC-hashed; no fingerprint, voter identity or email-based ownership is created. Use the existing shared panel.rate_store; all instances need the same DB/cache/APP_KEY. Only configured panel.trusted_proxies influence source IP; do not trust arbitrary forwarded headers. Rate failures return 429 with Retry-After; retry the same key/payload after waiting. These limits are independent of login/rating limits.

## Administrative inbox and reply API

Paths below are relative to /api/v1/admin.

| Method/path | Behavior |
| --- | --- |
| `GET /comments?section=all&page=1&per_page=100` | All managed non-deleted comments, including bonus and unavailable parents. |
| `GET /comments/{id}` | Fresh canonical card/version. |
| `PUT /comments/{id}/reply` | Create or edit the one official reply; always 200. |
| `DELETE /comments/{id}?expected_version=2` | Soft-delete comment and reply atomically; 204. |

section accepts all plus all eight stored types. No search, answer/moderation/date filters, tab badge counters, sort input or bulk actions are supported. Reject unknown sections/query keys, repeated values and array-style query parameters with 422. Default page size is 100, maximum 100; order creation DESC/ID DESC. Editing a reply does not reorder creation. Include non-deleted hidden/pending private history without enabling approval actions. Frontend retrieves successive pages, deduplicates IDs and ignores responses from previously selected tabs; refetch after mutations to reconcile totals/concurrent changes.

Private card representation:

```json
{
  "data": {
    "id":401,
    "section":"gold-platforms",
    "section_label":"Gold Platforms",
    "parent_id":18,
    "author":"Sara Visitor",
    "text":"What are the current conditions?",
    "created_at":"2026-10-26T10:00:00.000000Z",
    "updated_at":"2026-10-26T10:10:00.000000Z",
    "page_title":"Example Gold Platform",
    "page_url":"/gold-platforms/example-gold",
    "parent_visibility":"public",
    "reply":{"text":"The conditions are listed above.","author":"Original Staff","created_at":"2026-10-26T10:10:00.000000Z","updated_at":"2026-10-26T10:10:00.000000Z"},
    "version":2
  },
  "request_id":"req_admin_card"
}
```

All uses the list envelope with these same cards. reply=null means Unanswered; answer status is derived, not writable. Public visitor name becomes private author. Map parent_id/page_title/page_url/parent_visibility/created_at/updated_at to camelCase in adapters; preserve stable section keys. Labels are English supplied defaults; the Persian frontend may use bundled labels. Name-derived avatar and date formatting are presentation only.

First reply:

```json
{"text":"The conditions are listed above.","expected_version":1}
```

Edit that reply with a fresh version:

```json
{"text":"Updated official reply.\nSecond line.","expected_version":2}
```

Reply text is trimmed plain text 1–5,000 characters, preserving newlines. Empty/null/object/oversize and unknown fields fail. A unique comment FK enforces one reply. Original staff ID and creation time are preserved on edit; current display attribution follows the original account, including soft-deleted accounts, with a stored name fallback. Actual last editor is retained privately. Public replies never expose either staff identity.

Comment starts at version 1. First/changed reply advances comment version/updated_at; same canonical text preserves them. Version check precedes no-op detection, so stale identical text still conflicts. Replying to an unavailable parent's retained comment is allowed privately but cannot expose it publicly. Replying never changes parent editorial dates/version/count of comments. No delete-reply, edit-visitor, admin-create-comment or restore endpoint is added.

On 409 preserve the draft, fetch the latest card and require a subsequent explicit save under that version. Never automatically overwrite with a fresh version. On response loss fetch current data before retrying; there is no second reply to append. Frontend opens only one reply form; cancel/switching tabs/cards makes no mutation.

Deletion checks expected_version, marks comment and reply deleted, advances version and records actor/removal metadata in one transaction. Stale concurrent edit/delete cannot partially delete. Repeated detail/reply/delete returns 404 COMMENT_NOT_FOUND. Remove the card from all cached tabs and reconcile counts after success. Keep parent, media, ratings, other comments and private retained history intact.

## Privacy, counts and cache propagation

Email is encrypted using Laravel encrypted cast in a TEXT column and hidden from model serialization. No public or administrative Resource includes it. Following the chosen policy it remains in protected storage with soft-deleted history, without automatic email expiry. There is no email display/export/send API. Routine audits/logs contain no email, full visitor/reply text, request bodies, cookies or tokens. Storage/APP_KEY access remains an operational privileged boundary; do not rotate APP_KEY as routine setup.

CommentParents maps only allowed model types. Parent batches are loaded by section and IDs for the inbox; replies/original authors are eager-loaded. Visible top-level comment counts replace prior fixed zero values in exchange/platform/bonus projections, and are exposed on blog/news public reads/featured news via comment_count. SQL withCount uses the fixed typed relationship, avoiding per-card queries in list resolvers. A standalone projection can resolve its single count directly. Counts exclude replies, pending/hidden/deleted comments; unavailable public parents expose no count. Ratings remain independent.

Create increments visible count once, reply create/edit does not, and delete removes one visible comment. Category/slug changes keep IDs/relationships. Title/status/slug/deletion hooks keep title snapshots and enqueue comment_parent_changed for parents with retained comments; scheduled blog/news publication also uses the existing model/transaction path. Public submission checks the typed parent under the shared mutation transaction, serializing against unpublication/deletion.

Audit events: comment_created, comment_reply_created, comment_reply_updated, comment_deleted. Include actor when applicable, comment/typed-parent IDs, UTC/request ID, version transitions and reply_removed; no body/email text. Outbox covers comment_created/reply_changed/deleted and parent changes, identifying section/parent_id for dependent private All/source inbox data, public lists, parent detail/counts, cards, homepage/search/selections. Success means committed persistence/audit/Outbox, not external cache completion.

Public comment endpoints currently use no-store and query current eligible data. No external cache consumer/revalidation worker is connected. Pending events retain delivered_at=null; attempts/available_at/last_error are future consumer fields, not evidence of actual retries. A consumer must acknowledge only after successful propagation and retain/retry failures idempotently without undoing an acknowledged write or inviting another visitor submission. Existing parent-module events remain relevant to their own public caches.

`php artisan comments:status` reports pending work/missing consumer. `comments:prune` runs hourly, removes expired 24-hour idempotency records and audit/delivered events older than 90 days, preserving all pending work, comments, replies and encrypted email history. Configure Laravel's scheduler. Migration is additive and not applied to the development DB; deploy intentionally with php artisan migrate --force. Rollback drops module data; prefer forward fixes after real submissions.

## Errors, examples and verification

Codes: 401 UNAUTHENTICATED (admin), 403 FORBIDDEN/CSRF_INVALID, 404 COMMENT_NOT_FOUND/CONTENT_NOT_FOUND, 409 VERSION_CONFLICT/IDEMPOTENCY_CONFLICT, 422 VALIDATION_FAILED, 400 INVALID_JSON, 413 PAYLOAD_TOO_LARGE, 415 UNSUPPORTED_MEDIA_TYPE, 429 RATE_LIMITED with Retry-After, controlled 500 INTERNAL_ERROR/503 SERVICE_UNAVAILABLE.

```json
{"error":{"code":"VALIDATION_FAILED","message":"Check the submitted information.","fields":{"text":["Enter visible reply text."]}},"request_id":"req_comment_error"}
```

Keep form values after failures. A failed mutation cannot partially save/delete text or change counts. Unsupported sections and malformed IDs are 422; public unavailable parents uniformly return 404 without private data. Do not log raw inputs when reporting a request ID.

Use placeholders and separate Postman environments; never commit actual session/CSRF/email credentials. The OpenAPI imports into Postman. reply.json/edited-reply.json contain the examples above. Reuse the same opaque UUID and same body only for retries of that one public submission.

```sh
curl '{{admin_base}}/comments?section=all&page=1&per_page=100' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl '{{admin_base}}/comments/{{comment_id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl -X PUT '{{admin_base}}/comments/{{comment_id}}/reply' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @reply.json
curl -X PUT '{{admin_base}}/comments/{{comment_id}}/reply' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @edited-reply.json
curl -X DELETE '{{admin_base}}/comments/{{comment_id}}?expected_version=3' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}'
curl '{{public_base}}/comments/bonus/{{bonus_id}}?page=1&per_page=100'
curl -X POST '{{public_base}}/comments/bonus/{{bonus_id}}' -H 'Origin: {{same_origin}}' -H 'Idempotency-Key: {{random_uuid_v4}}' -H 'Content-Type: application/json' --data '{"name":"Sara Visitor","email":"visitor@example.com","text":"What are the current conditions?"}'
```

Verified on 2026-10-26:

- PublicCommentsTest and CommentsManagementTest: 11 tests passed, covering all eight parent domains, unavailable parents, encrypted/private email, literal text, canonical one-reply edits/no-ops, soft deletion/history, real visible counts, idempotent/conflicting/expired keys, paging, repeated query rejection, authentication/CSRF, Origin, body limits and throttling.
- TutorialCommentsConcurrencyTest: 8 shared race tests passed, including first replies, reply/delete, duplicate public submissions, parent unpublication and atomic submission limits.
- `php artisan test --compact`: 286 total, 275 passed, 11 existing tests skipped because their separate MySQL/MariaDB environments are not configured; 3,169 assertions.
- `vendor/bin/pint --dirty --format agent`: passed.
- OpenAPI YAML/references, all 7 registered comment operations (6 API operations plus OPTIONS), all 7 JSON examples and local links validated; `git diff --check` passed.

There are 27 new tests across the two modules and their shared concurrency suite. Tests use SQLite memory and isolated temporary databases for forked races; they do not certify production MySQL/MariaDB DDL or locking. No frontend inbox behavior or external cache propagation is claimed tested. UI-only interactions and external cache integration remain the agreed boundary; the public comment endpoints and true count wiring are implemented here. The development database was not migrated.
