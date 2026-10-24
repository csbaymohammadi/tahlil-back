# Shared media: prepared-file API and lifecycle

## Delivery, limits and intentional differences

This backend supplies one persistent private upload/reference/delivery service for `avatar`, `logo`, `cover`, `banner`, `editor-image`, and `editor-video`. No media-library menu, frontend uploader, content module, public-author profile, or Word-import endpoint is added.

**Owner-approved boundary:** the frontend prepares/crops/converts files and removes metadata. The server validates actual bytes but does not crop, transcode, re-encode, strip EXIF/GPS, rasterize SVG, or run malware scanning. SVG/HEIC/HEIF input is rejected: send the prepared PNG/JPEG output. No scanner/processor packages are installed. This differs from the original document 16; never claim server-side sanitization or metadata removal for stored files. An upload's byte-for-byte identity is preserved.

The server checks size, MIME/signature, decoding/probe, dimensions, animation limits and codecs independently; filename/browser MIME are not trusted. Uploaded bytes are delivered with a fixed validated Content-Type, safe generated filename, `nosniff`, and access checks, never active HTML/SVG.

| Limit | Delivered setting/value |
| --- | --- |
| Image original binary | Non-empty, at most `5242880` bytes (5 MiB) |
| Video original binary | Non-empty, at most `31457280` bytes (30 MiB) |
| Image dimensions | Positive, each side ≤16384, canvas/frame ≤40 million pixels |
| Animation | ≤300 frames, ≤150 million full-canvas decoded pixels; frame bounds checked |
| Avatar | Still 512×512 image supplied by frontend; no server stretching/cropping |
| Upload attempts | `media.upload_limit=30` per account/60-second fixed window for admitted attempts |
| Stored live-byte quota | `media.quota_bytes=1073741824` (1 GiB/account), including staging reservations |
| Per-account concurrent upload | One, shared cache lock; other requests get 409 + Retry-After |
| Validation | `media.timeout=60` seconds per subprocess; image child PHP memory limit 256 MiB |
| Temporary/unreferenced grace | `media.temporary_seconds=86400` seconds |
| Idempotency scope | Account + upload endpoint + UUID key, 24 hours |
| Processing lease | 180 seconds; abandoned staging is eligible for cleanup after this lease |
| Cleanup schedule | Hourly; failed object deletions retried on subsequent runs |

Raster input accepts JPEG, static PNG, GIF, WebP and static AVIF. APNG and AVIF image sequences are rejected with INVALID_MEDIA; prepare animation as GIF/WebP instead. Static images decode with the installed GD capabilities in a bounded PHP child. GIF frame structure and full animation decoding are checked; animated WebP frames are individually decoded. Supported transparency and animation survive because the original binary is stored unchanged.

Video accepts MP4/H.264 with optional AAC, or WebM/VP8/VP9 with optional Opus/Vorbis. It must have exactly one video stream and at most one audio stream, with positive dimensions and duration; other stream types are rejected. FFprobe performs a bounded local container/stream probe with restricted protocols/containers. Video is not transcoded or fully re-encoded. Files that exceed the validation time/output limit are rejected, not accepted without inspection. Recommended banner sizes are not enforced as aspect-ratio constraints.

## Environment and storage

| Environment | Public API origin |
| --- | --- |
| Development | `http://localhost:8000` |
| Staging | `https://<staging-public-host>` — deployment input |
| Production | `https://<production-public-host>` — deployment input |

Administrative paths start with `/api/v1/admin`; canonical binary paths start with `/media`. [OpenAPI](16-shared-media.openapi.yaml), [Postman](16-shared-media.postman_collection.json), [users](2-panel-users-roles-api.md) and [authentication](1-panel-login-api.md) describe the complete integration.

| Setting | Configuration |
| --- | --- |
| `MEDIA_DISK` | Default `media`, a private local Laravel disk at `storage/app/media-private`, with direct serving disabled |
| `MEDIA_PHP_BINARY` | Installed CLI PHP executable; defaults to current CLI binary or `PHP_BINDIR/php` outside CLI |
| `FFPROBE_BINARY` | Existing FFprobe executable, default `ffprobe` |
| `FFMPEG_BINARY` | Existing FFmpeg executable, default `ffmpeg`; used to validate GIF animation, not transcode uploads |
| `PANEL_RATE_STORE` | Shared atomic-lock-capable cache, default database |
| `PANEL_SECURE_COOKIE` | True for HTTPS production; local HTTP development setting retained |
| `config/media.php` | Operational limits listed above |

Development inspection found PHP GD 2.3.3 with JPEG/PNG/GIF/WebP/AVIF support and FFmpeg/FFprobe 7.1.1. Deployment must provide equivalent decoding/codec capabilities; no hidden browser-only validator is trusted. An unavailable binary yields controlled 503. Upload/probe commands use argument arrays and generated/local temporary paths, never shell interpolation of filenames.

For multiple instances, configure a shared durable **private** filesystem mount and identical media-disk configuration, plus shared metadata/cache databases. Do not select the public disk, expose the private root with a symlink/web-server alias, or serve an alternate bucket/CDN URL. Keep metadata backups consistent with object retention/recovery. Run the Laravel scheduler every minute; `media:prune` is scheduled hourly. A dedicated queue worker is not required for upload readiness or the current hourly cleanup command.

The reverse proxy/PHP upload limits must accommodate 30 MiB plus multipart overhead; application checks still enforce per-purpose limits. Oversized gateway requests may produce bare 413 before Laravel; normalize upstream errors if possible and let the frontend handle bare 413 too. Preserve all Set-Cookie headers. No Origin allowlist is configured: credentialed CORS reflects any requesting origin. Existing host-only `SameSite=Lax` cookies still restrict unrelated cross-site browser requests; see the auth document for same-site/BFF integration. Do not weaken cookie policy as a workaround without reviewing cross-site credential exposure.

## Asset type and responses

```ts
interface MediaAsset {
  id: number;
  purpose: 'avatar' | 'logo' | 'cover' | 'banner' | 'editor-image' | 'editor-video';
  kind: 'image' | 'video';
  status: 'ready';
  original_name: string;
  mime_type: string;
  size_bytes: number;
  width: number;
  height: number;
  duration_seconds: number | null;
  is_animated: boolean;
  url: string;
  preview_path: string;
  created_at: string;
}
```

```json
{
  "data": {
    "id": 142, "purpose": "logo", "kind": "image", "status": "ready",
    "original_name": "example-logo.png", "mime_type": "image/png", "size_bytes": 48210,
    "width": 512, "height": 512, "duration_seconds": null, "is_animated": false,
    "url": "/media/142/logo.png", "preview_path": "/api/v1/admin/media/142/preview",
    "created_at": "2026-10-24T10:00:00Z"
  },
  "request_id": "req_example_media"
}
```

IDs are positive safe integers. Timestamps are UTC ISO 8601. `size_bytes` describes the validated stored bytes; under this prepared-file contract it equals the original file size. Filename is bounded/sanitized display metadata. Storage keys, physical paths, uploader identity and idempotency checksums are private and excluded. The canonical filename is generated from purpose/validated extension, never the uploaded path/name.

Successful JSON uses `data` + `request_id`; errors use the shared `error.code/message/fields` envelope. `X-Request-ID` matches JSON. Private metadata uses `Cache-Control: no-store` and `Vary: Cookie`/Origin as appropriate. No staging/partial state is returned as a ready asset and there is no polling API.

## Endpoints

### POST `/api/v1/admin/media`

Requires active administrator session, `X-CSRF-Token`, UUID `Idempotency-Key`, and `multipart/form-data` with **exactly** `file` and `purpose`. Origin is optional/unrestricted. Let the browser generate the multipart boundary; do not send JSON/base64 or manually set browser Content-Type.

Returns `201` after validation and private storage succeed, with the ready metadata above and `Location: /api/v1/admin/media/{id}`. Uploading does not save an account/content/profile/settings record or publish anything.

Idempotency is account-scoped for 24 hours. Same key + same original bytes/purpose returns the same asset with 201, even if the filename changes. Different bytes/purpose returns `409 IDEMPOTENCY_CONFLICT`. A live in-progress key or another upload for the same account returns `409 UPLOAD_IN_PROGRESS`, with `Retry-After: 2`. A completed but deleted/expired original returns `404 MEDIA_NOT_FOUND`, not a dangling success. Failed validation can be deliberately retried with the same logical input/key after correcting infrastructure; replacement file/purpose uses a new key. After 24 hours the key may be reused as a new logical upload.

Metadata/staging reservation is committed before storage work, so an interrupted write has a tracked cleanup target. Readiness and the idempotency success link commit together after validation/storage and a fresh actor/session check. Failure tombstones the staged row, attempts immediate byte deletion and leaves durable cleanup state for retries. No account status change during processing can silently authorize finalization from a stale session.

### GET `/api/v1/admin/media/{id}`

Requires an active session; no query/body. Returns 200 ready metadata. Unknown, deleted, expired, staging or inaccessible asset returns `404 MEDIA_NOT_FOUND`. Pending uploads are visible only to their creator; an attached asset is readable through a registered consumer the actor can access. All current administrators can read attached panel avatars, independent of roles.

### GET `/api/v1/admin/media/{id}/preview`

Same authentication/access rules, but returns processed/prepared binary directly. Headers include correct Content-Type/Length, safe `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, and `Cache-Control: private, no-store`. There is no redirect to a storage object. Use `fetch(..., {credentials:'include'})`, obtain a blob and a temporary object URL for canvas/preview; revoke object URLs when replaced/unmounted. Never persist preview/blob URLs as canonical fields.

### DELETE `/api/v1/admin/media/{id}`

Requires session and CSRF; no body/query. This discards an owned unattached upload, returning 204 after marking it unavailable and retaining cleanup work. Repeated discard of the creator's tombstone also returns 204. Unknown/other-user unattached assets return 404. An asset with any retained reference returns `409 MEDIA_IN_USE` and cannot be destroyed through this endpoint. Metadata invalidation is immediate even when physical storage deletion needs a retry.

### GET/HEAD `/media/{id}/{generated-name}`

Canonical binary location. Anonymous access is permitted **only** while a trusted server-side consumer resolver confirms at least one currently public eligible reference. A valid readable administrator session can retrieve private canonical media too; anonymous private access returns 404. Panel avatars are private in this delivery because public-author projections do not exist yet.

Eligibility is re-evaluated per request. Withdrawing one public reference does not hide media still referenced publicly elsewhere. Public delivery uses `Cache-Control: no-store`; private delivery uses `private, no-store`. Both vary by Cookie/Origin when applicable. Storage credentials or raw physical paths never appear. Missing/unreadable bytes return 503, not an invented location.

HEAD returns correct content headers without bytes. Video accepts a single `Range: bytes=start-end`, open-ended `start-`, or suffix `-count`; valid ranges return 206 + `Content-Range` and exact length, invalid/unsatisfiable ranges return 416 + `Content-Range: bytes */<size>`. Multi-range requests are not supported. Responses advertise `Accept-Ranges: bytes`. Clients must support HTTP range errors independently of JSON error parsing.

## Reference transactions and future consumers

Accounts use integer `avatar_media_id`; metadata/identity expose `avatar_url`. The account action validates an existing accessible, ready 512×512 still image, persists the account and updates the reference inside one transaction. A failed account save leaves its upload unattached and preserves the prior attachment. Replacing an avatar does not delete the old file; the final detach starts a new 24-hour grace period. Account soft deletion retains its media references.

The internal integration surface is `MediaReferences`:

- `image(id, actor, avatar)` validates an existing image, with avatar dimensions when requested.
- `resolveCanonical(url, actor, kind)` accepts only an exact managed root-relative canonical location, verifies the generated name, access/readiness and matching image/video kind. It never fetches remote URLs or treats URL paths as filesystem paths.
- `replace(consumerType, consumerId, field, ids, actor, avatar=false, kind='image')` must run inside the **same transaction** as the consumer save. It serializes attachment/discard/cleanup using `PanelMutationLock`, validates access/kind, attaches the new IDs, detaches previous IDs and starts a grace period only when the last reference disappears.
- `MediaConsumers::register(type, canRead, isPublic)` registers trusted application callbacks. Unknown consumer types fail closed. Callbacks re-read actual resource/publication state; requests cannot supply public flags or resolver code.

Before a new consumer writes, acquire the shared mutation lock first, recheck actor/session, validate the full form, save the resource and replace references in that transaction. For editor image/video HTML, resolve managed canonical sources and track image/video reference sets separately with their expected kind. Do not attach anything until all form validation succeeds. A recoverable draft/scheduled/soft-deleted resource keeps references until its module's permanent purge. Normal hyperlinks/provider embeds are not managed objects.

Only `user` avatar consumers are wired now; they never authorize anonymous publication. Future content/settings/public-profile modules must register their real access/publication adapters and invoke the same reference API. No fake publication flag or premature public-author rule is introduced. Word ingestion remains a future separate `/content-imports` workflow; this delivery supplies the attachment primitive but does not create/import articles or DOCX files. External/static asset allowlists belong to those consumer modules; this resolver currently accepts managed locations only and performs no network downloads.

## Browser sequence, retries and examples

1. Frontend checks original selection limits; converts HEIC/SVG as appropriate, crops avatar to 512×512, strips metadata and produces a supported binary. Validate the resulting binary size again.
2. Obtain authenticated CSRF. Generate one UUID for this logical file/purpose, then upload using FormData. An Axios progress callback may report upload progress; progress is not proof of persistence.
3. Keep metadata only after a valid 201 response. Use authenticated preview/object URLs for editing/canvas. Finish required uploads before saving the owning form.
4. Submit account avatar ID or the existing consumer's published canonical string field. Do not rename every consumer field to IDs.
5. Retry an uncertain upload with the same key and identical file. Deliberate replacement uses a new key. Resolve 409 in-progress using the provided delay; do not blindly replay unrelated account mutations.
6. Cancel may best-effort discard new unattached uploads. Never discard an asset already attached by a confirmed save. Server expiry handles canceled tabs/lost networks.

```js
const origin = '<configured-backend-origin>';
const body = new FormData();
body.append('file', preparedFile);
body.append('purpose', 'avatar');
const response = await fetch(`${origin}/api/v1/admin/media`, {
  method: 'POST', credentials: 'include',
  headers: { 'X-CSRF-Token': csrfToken, 'Idempotency-Key': uploadUuid },
  body,
});
// No Content-Type override: browser supplies the boundary.
const result = await response.json();
if (!response.ok) throw result;
const asset = result.data;
const preview = await fetch(`${origin}${asset.preview_path}`, {credentials:'include'});
if (!preview.ok) throw new Error('Preview unavailable');
const objectUrl = URL.createObjectURL(await preview.blob());
// Send asset.id as avatar_media_id with the account save. Revoke objectUrl later.
```

```sh
curl -i -b '<protected-cookie-jar>' -H 'X-CSRF-Token: <csrf-token>' \
  -H 'Idempotency-Key: <uuid>' -F 'purpose=avatar' -F 'file=@<prepared-avatar.png>' \
  '<backend-origin>/api/v1/admin/media'
curl -i -b '<protected-cookie-jar>' '<backend-origin>/api/v1/admin/media/<id>'
curl -b '<protected-cookie-jar>' '<backend-origin>/api/v1/admin/media/<id>/preview' -o '<local-preview-file>'
curl -I -b '<protected-cookie-jar>' '<backend-origin>/media/<id>/<generated-name>'
curl -i -b '<protected-cookie-jar>' -H 'Range: bytes=0-1023' '<backend-origin>/media/<id>/editor-video.mp4'
curl -i -X DELETE -b '<protected-cookie-jar>' -H 'X-CSRF-Token: <csrf-token>' '<backend-origin>/api/v1/admin/media/<id>'
```

## Errors, cleanup and audit

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `INVALID_MULTIPART` | Invalid upload shape, wrong multipart boundary/header or extra files |
| 401 | `UNAUTHENTICATED` | Missing/invalid/disabled actor session |
| 403 | `CSRF_INVALID` | Mutation CSRF invalid; Origin itself is unrestricted |
| 404 | `MEDIA_NOT_FOUND` | Unknown/inaccessible/expired/deleted/staging asset or discarded idempotent result |
| 409 | `IDEMPOTENCY_CONFLICT`, `UPLOAD_IN_PROGRESS`, `MEDIA_IN_USE` | Conflicting key, busy upload, or retained reference |
| 413 | `FILE_TOO_LARGE`, `STORAGE_QUOTA_EXCEEDED` | Per-file/body or live-byte account limit |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Disguised/unsupported format, SVG or HEIC input |
| 422 | `INVALID_MEDIA` | Corruption, invalid dimensions/animation/codec or validation time/output limit |
| 422 | `VALIDATION_FAILED` | Unknown fields/purpose, missing file/key or incompatible account avatar reference |
| 429 | `RATE_LIMITED` | Honor integer Retry-After seconds |
| 503 | `MEDIA_SERVICE_UNAVAILABLE` | Validator/private storage unavailable; no fabricated URL |
| 503 | `SERVICE_UNAVAILABLE` | Shared identity/database/cache infrastructure unavailable |
| 500 | `INTERNAL_ERROR` | Unexpected safe failure |

```json
{"error":{"code":"INVALID_MEDIA","message":"Send a still 512 by 512 avatar prepared by the frontend.","fields":{"file":["Send a still 512 by 512 avatar prepared by the frontend."]}},"request_id":"req_example_media_error"}
```

Unattached expiry denies access at the 24-hour deadline even before the hourly worker runs. Cleanup serializes reference checks and tombstoning; a retained reference prevents physical deletion. Byte deletion happens after the irreversible tombstone, and failed deletes remain retryable. New references cannot attach a tombstone. Removing the last reference starts a fresh grace period; reuse within that period cancels it. An uploaded asset already attached is not expired by its original upload deadline.

`media_uploaded` and `media_discarded` events record actor, asset ID, purpose, byte count, outcome and request ID through the shared security-event store; 90-day retention/access apply. Infrastructure errors are sanitized. No raw file, cookie/token, storage credential or internal scanner/processor output enters public metadata/logs. Original filename must still be rendered as text by the frontend.

## Verification and remaining integration boundaries

Tests exercise actual PNG upload/child decoding, real animated GIF/WebP validation with identical retained bytes, real H.264 video probing and unsupported-codec rejection, metadata/private/canonical delivery, Range/HEAD/416, idempotency, quota/rate locks, reference rollback, shared image reuse, retained deleted-account avatars, expiry, physical-delete retry and validator/audit failure. Real forked SQLite tests cover reference/discard races on a dedicated temporary database.

These tests do not prove browser third-party-cookie behavior, deployed proxy/PHP body limits, multi-host shared mounts, future content publication, Word ingestion or a frontend crop/metadata-removal implementation. The server intentionally performs no malware scan or metadata stripping; do not mark those original document-16 acceptance items complete.

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
