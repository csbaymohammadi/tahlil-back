# Tutorials Admin API and Playback Handoff

This implements the backend contract in `11-panel-tutorials.md`: one tutorial catalog with paired YouTube/Aparat sources, private CRUD/categories and internal public-read services. It does not create frontend pages, full public read HTTP routes, provider integrations or a frontend cache consumer. See [OpenAPI](11-panel-tutorials.openapi.yaml), [panel authentication](1-panel-login-api.md) and [shared media](16-shared-media-api.md).

## Bases and shared transport

| Environment | Admin base |
| --- | --- |
| Development | `http://localhost:8000/api/v1/admin` |
| Staging | `https://<staging-host>/api/v1/admin` |
| Production | `https://<production-host>/api/v1/admin` |

The development origin follows the current Boost-resolved backend URL. Staging/production hosts remain unconfigured placeholders. Use the existing active panel session cookie `tahlil-panel-session-v2`, `Accept: application/json`, credentials included in browser fetches, and the panel `X-CSRF-Token` for mutations. JSON mutation bodies require `Content-Type: application/json`. Existing panel Origin/CORS and management guard behavior is preserved; no permission catalog is added.

Success JSON is `{data, request_id}`, lists also return meta. Errors are `{error:{code,message,fields},request_id}`. Responses use `X-Request-ID`, `Cache-Control: no-store`, and `Vary: Cookie`. HTTP 204 has no JSON/body. Server dates are ISO 8601 UTC; Persian/Tehran formatting is frontend presentation. IDs and expected versions are positive safe integers, at most 9007199254740991. JSON numeric strings, booleans and fractions are invalid integer inputs.

Maximum tutorial POST/PATCH body is 256 KiB; category bodies are 16 KiB. GET/DELETE accept no body. Unknown query/body fields are rejected. Mutations default to 300 requests per panel user/domain per 900 seconds using the existing shared limiter.

## Endpoints and field rules

All paths below are relative to the admin base.

| Method/path | Success |
| --- | --- |
| `GET /tutorials/items?page=1&per_page=10&q=` | 200, complete compact rows plus meta |
| `GET /tutorials/items/{id}` | 200, same canonical dialog representation |
| `POST /tutorials/items` | 201, canonical record and Location |
| `PATCH /tutorials/items/{id}` | 200, canonical record/version |
| `DELETE /tutorials/items/{id}?expected_version=2` | 204, soft deletion |
| `GET /tutorials/categories?q=` | 200, entire live category array |
| `GET /tutorials/categories/{id}` | 200, fresh category/version/count |
| `POST /tutorials/categories` | 201, name-only category and generated key |
| `DELETE /tutorials/categories/{id}?expected_version=1` | 200, affected_tutorial_count |
| `POST /tutorials/categories/{id}/merge` | 200, moved_tutorial_count |

| Editable field | Validation and defaults |
| --- | --- |
| title | Required plain text, 1–200 Unicode characters after trimming. |
| description | Optional literal multiline text, at most 5,000 characters; default/clear empty string. Text resembling tags remains text and must be escaped, never rendered as HTML. |
| youtube_url | Required supported absolute HTTP/HTTPS video URL, at most 2,048 characters; canonical HTTPS watch URL returned. |
| aparat_url | Optional supported URL with same maximum; default/clear empty string. |
| category_id | Required live tutorial category integer on creation and for the complete result of a regular edit; no null/empty/numeric string. |
| cover_image | Required nonempty trusted image reference, at most 2,048 characters; cannot clear with empty/null. |

PATCH requires expected_version and at least one editable field. Omission preserves data. Only description and Aparat clear with empty strings; no regular-save field permits null. Slug, provider IDs, default_provider, duration, timestamps, actor, status, author, tags and HTML body are not writable inputs. There is no publication enum/action: each committed non-deleted tutorial is immediately public.

List defaults are page 1/per_page 10; per_page accepts 10/20/50. q up to 200 characters matches title only after yeh/kaf normalization, ZWNJ-to-space, whitespace collapse and case-folding. Filter before totals/pagination; order created_at DESC/id DESC. Zero matches use total_pages 0; beyond-last pages retain the requested page with empty data and accurate totals. Category names are eager-loaded.

Create example:

```json
{"title":"Example Tutorial","description":"First line\nSecond line","youtube_url":"https://youtu.be/AbCdEf12_-3?si=tracking","aparat_url":"https://aparat.com/v/abc123","category_id":2,"cover_image":"https://i.ytimg.com/vi/AbCdEf12_-3/hqdefault.jpg"}
```

Canonical list/detail/create/edit record (IDs and dates illustrative):

```json
{
  "data": {
    "id": 91,
    "title": "Example Tutorial",
    "description": "First line\nSecond line",
    "slug": "youtube-AbCdEf12_-3",
    "youtube_url": "https://www.youtube.com/watch?v=AbCdEf12_-3",
    "youtube_video_id": "AbCdEf12_-3",
    "aparat_url": "https://www.aparat.com/v/abc123",
    "aparat_video_id": "abc123",
    "default_provider": "aparat",
    "cover_image": "https://i.ytimg.com/vi/AbCdEf12_-3/hqdefault.jpg",
    "category_id": 2,
    "category": {"id":2,"name":"Working with Exchanges","key":"exchanges"},
    "duration_seconds": null,
    "duration_source": null,
    "created_at": "2026-10-26T10:00:00.000000Z",
    "updated_at": "2026-10-26T10:00:00.000000Z",
    "published_at": "2026-10-26T10:00:00.000000Z",
    "public_path": "/tutorials/youtube-AbCdEf12_-3",
    "version": 1
  },
  "request_id": "req_tutorial_example"
}
```

Creation Location is `/api/v1/admin/tutorials/items/{id}`. List wraps these rows in data with meta `{page,per_page,total,total_pages}`. Remove Aparat using:

```json
{"expected_version":1,"aparat_url":""}
```

The result has aparat_url empty, aparat_video_id null and default_provider youtube. Title/source changes preserve slug and creation/first-publication date. Actual changes increment version; equivalent canonical URL/no-op edits preserve version and timestamps. Stale versions fail before no-op handling, without partial changes. On response loss refetch before retrying.

## Provider normalization and identity

YouTube exact hosts: youtube.com, www.youtube.com, m.youtube.com, music.youtube.com, youtube-nocookie.com, www.youtube-nocookie.com, youtu.be, www.youtu.be. Family paths: `/watch?v={id}`, `/shorts/{id}`, `/embed/{id}`, `/live/{id}`. Short hosts use `/{id}`. One optional trailing slash is accepted; arbitrary extra segments/multiple trailing slashes are not. ID is exactly `[A-Za-z0-9_-]{11}`, preserving case. Canonical URL is `https://www.youtube.com/watch?v={id}`.

Aparat exact hosts: aparat.com and www.aparat.com. Paths: `/v/{id}`, `/embed/{id}`, `/video/video/embed/videohash/{id}` with optional `/vt/frame` on the long embed path. One optional trailing slash is accepted. ID is `[A-Za-z0-9_-]{5,32}`, case-sensitive. Canonical URL is `https://www.aparat.com/v/{id}`.

Reject bare IDs, spoofed/subdomain-suffix hosts, credentials, control characters/backslashes, unsupported paths, channel/playlist/search URLs, executable schemes, protocol-relative URLs, non-default ports, duplicate video-identity parameters and contradictory path/query IDs. HTTP/HTTPS input is accepted with only its default port. Drop tracking/referral/start/autoplay query parameters and fragments. No redirects are followed and no URL, thumbnail, channel or provider API is fetched during saving.

Live YouTube claims have database uniqueness with case-sensitive comparisons (ascii_bin on MySQL/MariaDB, default binary comparison on SQLite). Different variants of the same ID conflict with 409 YOUTUBE_VIDEO_TAKEN on youtube_url. The current owner can retain its ID; Aparat IDs may be shared. Soft deletion releases only the live YouTube claim, retaining tutorial/source history and slug ownership. Re-adding creates a new record and does not inherit the deleted record's selections/category or old path.

New slug is `youtube-{case-preserved-id}`; if reserved, append the new numeric tutorial ID, then a numeric suffix if that candidate is also reserved. Slugs are at most 200 ASCII characters and immutable after allocation, including after YouTube replacement. Existing explicitly imported semantic slugs can remain unchanged; no legacy import dataset or sample tutorial catalog is added. Deleted paths return not found, not a redirect to a new lesson using the same video.

## Categories, media and duration

Seed keys/display identities: getting-started = Getting Started; exchanges = Working with Exchanges; strategies = Trading Strategies. Seeder preserves existing/deleted identities. New name-only categories get category-{id}; all is virtual. Name is trimmed plain text, 1–80 characters, normalized for yeh/kaf, ZWNJ/whitespace/case uniqueness; deleted names and keys remain reserved. GET returns the full list ordered by normalized name then ID, optionally filtered by q up to 200 characters.

```json
{"data":{"id":2,"name":"Working with Exchanges","key":"exchanges","tutorial_count":3,"version":1,"created_at":"2026-10-26T10:00:00.000000Z","updated_at":"2026-10-26T10:00:00.000000Z"},"request_id":"req_category"}
```

Delete uncategorizes all live source tutorials and advances their versions/updated_at, preserving sources, slug, media and creation/publication dates. They remain publicly playable, but their next regular edit requires a new live category:

```json
{"expected_version":2,"category_id":7}
```

Merge requires distinct live source/target and both versions:

```json
{"expected_version":1,"target_category_id":7,"target_expected_version":1}
```

Results:

```json
{"data":{"deleted_category_id":2,"affected_tutorial_count":3},"request_id":"req_delete_category"}
```

```json
{"data":{"source_category_id":2,"target_category_id":7,"moved_tutorial_count":3},"request_id":"req_merge_category"}
```

Counts are actual committed updates, including zero; retrying removed source returns 404. Category count includes non-deleted tutorials and does not advance category version. All mutations share the existing transaction/mutation lock to prevent dangling categories and silent lost edits.

Cover hosts come from TUTORIAL_TRUSTED_MEDIA_HOSTS (default service.tahlilex.com), plus i.ytimg.com. Approved `/images/`, `/tahlilpanel/images/`, and managed `/media/{id}/{canonical-name}` are supported. Reject unsafe schemes/hosts, traversal/encoded traversal, protocol-relative/data/blob/local paths and SVG covers. Managed references require accessible ready images and use media consumer type video. No upload/crop/import is performed. Replacing/deleting keeps shared files and external videos. A thumbnail never determines playback identity. TUTORIAL_FALLBACK_COVER defaults empty; frontend image-load failure should use a configured safe fallback without rewriting storage or substituting another lesson's cover.

Duration is supporting metadata tied to tutorial/provider/video ID. `RecordTutorialDuration::handle(id, provider, videoId, seconds, requestId)` is an internal ingestion boundary, not an HTTP dialog field or provider fetcher. It accepts current saved sources and positive safe integer seconds. Prefer known default-source duration, otherwise the other source; unknown returns null/null. Replacement/clearing removes old-source duration metadata before returning the save. Duration changes enqueue a public-change event but do not advance editorial version, updated_at or publication/creation times. No synchronous discovery or credentials are required. Display known seconds as rounded-up localized minutes in the frontend, without storing that label.

## Public services, playback and cache boundary

`ResolveTutorialCatalog` exposes:

- archive(page, search, categoryKey): nine records/page, title/description/category normalized search, combined filtering, counts before category selection, All including Uncategorized. Unknown/deleted key falls back to All. Default published_at DESC/id DESC.
- detail(slug): type tutorial with data, or not_found; deleted identities are unavailable.
- latest(excludeId): up to five non-deleted tutorials excluding the current ID, newest publication first.
- featured(): configured unique TUTORIAL_FEATURED_IDS order, skipping unavailable records; without configuration defaults to newest five.
- featured(true): up to five eligible saved Aparat tutorials; TUTORIAL_APARAT_IDS optionally configures order. Eligibility is applied before filling positions.
- hero(): configured TUTORIAL_HERO_ID or newest tutorial when unconfigured. An unavailable explicitly configured hero is omitted.

Public projections use content_kind video and the immutable ID/path, plus fixed youtube_embed_url/aparat_embed_url. Invalid legacy IDs produce no arbitrary iframe/watch URL. Fixed URLs:

```text
https://www.youtube-nocookie.com/embed/{id}?autoplay=0&controls=1&playsinline=1&fs=1
https://www.aparat.com/video/video/embed/videohash/{id}/vt/frame
```

Aparat is first/default when present; YouTube is secondary/default otherwise. Both tabs belong to one lesson. Frontend must stop/unmount the inactive iframe, disable missing sources, use title-based accessible labels, responsive 16:9 frames and safe new-tab watch links (`noopener noreferrer`). Provider failures affect the player only, preserving lesson/cover/description and alternative source; syntax does not guarantee embedding availability. No automatic health-probe switch occurs.

Plain multiline description hides when empty. Display published_at using Persian calendar/Tehran timezone, current category when present, and unknown-duration placeholder when absent. No author, comments, ratings, progress or tags are required. Latest blog content is a separate module; page-settings video IDs never mean provider IDs or media object IDs.

Every committed create/edit/delete/category/duration change records tutorial Outbox work for archive/detail/metadata, homepage/hero/Aparat, menus/search/latest/selections/count dependencies. Audit events are tutorial_created/updated/deleted, tutorial_category_created/deleted and tutorial_categories_merged, including actor/target/request ID, changed fields/source transitions or committed counts, without full bodies/credentials.

Internal reads are dynamic and reflect committed new slugs without deployment. This repository does not add public tutorial HTTP pages/read API or cache delivery. Frontend routes must resolve arbitrary current slugs at runtime. Outbox is durable but external invalidation/retry is not connected: success means committed database work, not cache completion. Pending events retain delivered_at null; attempts/available_at/last_error support a future consumer. A consumer must acknowledge only after success and retain/retry failures idempotently.

`php artisan tutorials:status` reports pending work/missing consumer; `tutorials:prune` runs hourly and removes audit/delivered events older than 90 days, preserving all pending work, identities and slug reservations. Configure Laravel's scheduler. Migration is additive and was not applied to development; deploy it intentionally with `php artisan migrate --force`. Rollback drops new module data. No dependencies/provider credentials were added.

## Errors, curl/Postman and verification

Stable codes: 401 UNAUTHENTICATED; 403 FORBIDDEN/CSRF_INVALID; 404 TUTORIAL_NOT_FOUND/CATEGORY_NOT_FOUND; 409 YOUTUBE_VIDEO_TAKEN/CATEGORY_NAME_TAKEN/VERSION_CONFLICT; 422 VALIDATION_FAILED; 400 INVALID_JSON; 413 PAYLOAD_TOO_LARGE; 415 UNSUPPORTED_MEDIA_TYPE; 429 RATE_LIMITED with Retry-After; controlled 500 INTERNAL_ERROR/503 SERVICE_UNAVAILABLE. Preserve field values and reconcile fresh versions after conflicts.

```json
{"error":{"code":"YOUTUBE_VIDEO_TAKEN","message":"This YouTube video already belongs to a tutorial.","fields":{"youtube_url":["Choose a different video or edit the existing tutorial."]}},"request_id":"req_duplicate"}
```

Use placeholder environments; never commit real cookies/tokens. create.json/edit.json/merge.json contain the examples above. Import companion OpenAPI or curl commands into Postman.

```sh
curl '{{admin_base}}/tutorials/items?page=1&per_page=10&q=' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl '{{admin_base}}/tutorials/items/{{id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl -X POST '{{admin_base}}/tutorials/items' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @create.json
curl -X PATCH '{{admin_base}}/tutorials/items/{{id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @edit.json
curl -X DELETE '{{admin_base}}/tutorials/items/{{id}}?expected_version=2' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}'
curl '{{admin_base}}/tutorials/categories?q=' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl '{{admin_base}}/tutorials/categories/{{category_id}}' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}'
curl -X POST '{{admin_base}}/tutorials/categories' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data '{"name":"Platform Walkthroughs"}'
curl -X DELETE '{{admin_base}}/tutorials/categories/{{category_id}}?expected_version=1' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}'
curl -X POST '{{admin_base}}/tutorials/categories/{{category_id}}/merge' -H 'Cookie: tahlil-panel-session-v2={{panel_cookie}}' -H 'X-CSRF-Token: {{panel_csrf}}' -H 'Content-Type: application/json' --data-binary @merge.json
```

Verified on 2026-10-26:

- TutorialProvidersTest and TutorialsManagementTest: 8 tests passed; cover provider variants/rejections, CRUD, case-sensitive identity, slug reservation, category maintenance, source-bound duration, canonical no-ops, media retention/rollback, public selections and invalid legacy playback guards.
- TutorialCommentsConcurrencyTest: 8 shared forked race tests passed, including duplicate video creation/replacement, stale edits and category-assignment/deletion races.
- `php artisan test --compact`: 286 total, 275 passed, 11 existing deployment-engine tests skipped, 3,169 assertions.
- `vendor/bin/pint --dirty --format agent`: passed after formatting.
- OpenAPI YAML/references, all 10 registered tutorial operations, all 9 JSON examples and local document links validated; `git diff --check` passed.

Database tests use SQLite memory/isolated temporary databases; these do not certify MySQL/MariaDB DDL or production row-lock semantics. No provider uptime, frontend player rendering or external cache delivery is claimed verified. UI-only behavior and full public HTTP routing remain the agreed separate integration boundary. The development database was not migrated.
