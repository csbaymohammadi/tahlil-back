# Homepage carousel advertisements API

This service manages multiple advertisements for the homepage carousel. Each card has an image, title, description and visibility switch, with an optional destination and button label. Administrators control the manual order. The frontend implements the dashboard settings form, carousel and fixed card position.

This resource is independent of the existing banner fields in page settings.

## Authentication and transport

- All `/api/v1/admin/advertisements` endpoints use the existing authenticated panel session cookie. Mutations also require the `X-CSRF-Token` header; see [panel authentication](1-panel-login-api.md).
- Browser requests to the backend must include credentials. The public endpoint requires no login.
- Create and update use `multipart/form-data`. With `FormData`, let the browser set Content-Type and its boundary.
- Reordering uses `application/json`. DELETE has no request body.
- Paths below are relative to the backend origin. Resolve returned `img` paths against that origin, not the frontend origin.
- Responses include `request_id`; the corresponding `X-Request-ID` header supports diagnostics.

## Endpoints

| Operation | Method | Path | Success |
| --- | --- | --- | --- |
| List all non-deleted cards, including hidden cards | GET | `/api/v1/admin/advertisements` | 200 |
| Get one card | GET | `/api/v1/admin/advertisements/{id}` | 200 |
| Create a card | POST | `/api/v1/admin/advertisements` | 201 |
| Partially update a card | POST | `/api/v1/admin/advertisements/{id}` | 200 |
| Set manual order | PUT | `/api/v1/admin/advertisements/order` | 200 |
| Delete a card | DELETE | `/api/v1/admin/advertisements/{id}?expected_version=1` | 204 |
| Get active cards for the homepage | GET | `/api/v1/advertisements` | 200 |

Both lists return all matching cards without pagination, ordered by `position`, then `id`. Unknown query parameters are rejected. The public endpoint also supports OPTIONS.

## Create

Field names are lowercase and case-sensitive.

| Field | Multipart value | Validation |
| --- | --- | --- |
| `img` | File | Exactly one image file; non-empty, at most 5 MiB (5,242,880 bytes) |
| `title` | String | Required plain text, trimmed, at most 200 characters |
| `description` | String | Required plain text, trimmed, at most 2,000 characters |
| `link` | String | Optional destination |
| `link_bt` | String | Optional plain-text button label, trimmed, at most 200 characters |
| `show` | String | Exactly `"1"` for visible or `"0"` for hidden |

JPEG, PNG, GIF, WebP and AVIF are subject to the existing shared-media content and decoding checks. SVG is rejected. The frontend prepares/crops images; the backend does not crop or resize them. See [shared media](16-shared-media-api.md) for format, animation and dimension limits. Files are uploaded internally with purpose `banner`; no separate media upload request or client-provided Idempotency-Key is needed.

```javascript
const form = new FormData();
form.append('img', imageFile);
form.append('title', 'Advertisement title');
form.append('description', 'Advertisement description');
form.append('link', '/offers');
form.append('link_bt', 'View offer');
form.append('show', '1');

const response = await fetch(`${apiOrigin}/api/v1/admin/advertisements`, {
  method: 'POST',
  credentials: 'include',
  headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
  body: form,
});
const result = await response.json();
```

A new card is appended after the current cards. The server controls `id`, `position` and `version`; do not send those fields when creating a card. Successful creation returns status 201 and a Location header pointing to the administrative detail endpoint.

```json
{
  "data": {
    "id": 12,
    "img": "/media/45/banner.webp",
    "title": "Advertisement title",
    "description": "Advertisement description",
    "link": "/offers",
    "link_bt": "View offer",
    "show": true,
    "position": 1,
    "version": 1
  },
  "request_id": "req_example"
}
```

## Read in the dashboard

GET `/api/v1/admin/advertisements/12` returns the single-card envelope shown above. GET `/api/v1/admin/advertisements` returns the same objects inside a `data` array, including hidden cards:

```json
{
  "data": [
    {
      "id": 12,
      "img": "/media/45/banner.webp",
      "title": "Advertisement title",
      "description": "Advertisement description",
      "link": "/offers",
      "link_bt": "View offer",
      "show": true,
      "position": 1,
      "version": 1
    }
  ],
  "request_id": "req_example"
}
```

An empty list is `{"data": [], "request_id": "req_example"}`. Administrative image paths can be null if their underlying media has become unavailable.

## Update and toggle visibility

POST `/api/v1/admin/advertisements/12` uses multipart, even when no file is included. Send the latest `expected_version` as a positive integer string and at least one editable field.

```text
expected_version: 1
show: 0
```

Other editable fields are `title`, `description`, `link`, `link_bt`, and `img`. Omit `img` to retain the previous image or send a new file to replace it. Omitted text fields retain their saved values. Send an empty string to clear `link` or `link_bt`.

A successful update returns 200 and the full administrative object. `show` in responses is a JSON boolean. A real change increments `version`; a no-op preserves it. Always use the returned version in the next mutation.

## Manual order

PUT `/api/v1/admin/advertisements/order` accepts JSON. Include every non-deleted card exactly once, including hidden cards. The array order defines the slide order; IDs and versions here must be JSON integers.

```json
{
  "items": [
    { "id": 8, "expected_version": 1 },
    { "id": 12, "expected_version": 2 }
  ]
}
```

The response is 200 with the complete sorted administrative list in `data`. Positions become consecutive starting at 1. Cards whose positions change receive new versions. An unchanged order does not increment versions or emit a change notification.

Duplicate IDs or malformed input return 422. Missing/unknown IDs or stale versions return 409. Refetch the administrative list before retrying; never automatically replay a stale mutation. For an empty collection, `{"items": []}` is valid.

## Delete

DELETE `/api/v1/admin/advertisements/12?expected_version=2` requires the latest version in the query string, with no body. Success is 204 with no response body; do not call `response.json()` on that response.

Deletion is soft. The card disappears from administrative and public lists. Its media reference is detached, and the normal shared-media cleanup lifecycle applies. A file still referenced elsewhere is retained. Reading, editing or deleting the deleted card again returns 404.

## Public homepage response

GET `/api/v1/advertisements` returns visible, non-deleted cards with ready, non-deleted media, in manual order. No request body or query parameters are accepted.

```json
{
  "data": [
    {
      "id": 8,
      "img": "/media/46/banner.png",
      "title": "Advertisement title",
      "description": "Advertisement description",
      "link": "/offers",
      "link_bt": "View offer",
      "show": true
    }
  ],
  "request_id": "req_example"
}
```

The public object excludes `position` and `version`; preserve the returned array order. Both public and administrative responses omit `link_bt` when the label is empty. Render the label as plain text. One active card still returns a one-element array. No active cards returns `data: []`; the frontend should hide the carousel container.

Hidden cards do not make their image publicly readable through this resource. A file shared with another public resource can remain public through that other resource. Existing authenticated administrators can preview hidden-card images.

Public list responses use `no-cache, must-revalidate`. Create, update, delete and reorder operations record public change notifications for `homepage` and `advertisements` through the existing delivery mechanism.

## Errors

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Check the submitted information.",
    "fields": {
      "title": ["The title field is required."]
    }
  },
  "request_id": "req_example"
}
```

| HTTP status | Typical code | Frontend handling |
| --- | --- | --- |
| 400 | `INVALID_MULTIPART`, `INVALID_JSON` | Correct request encoding; creation must contain exactly one `img` file |
| 401 | `UNAUTHENTICATED` | Sign in through the existing panel flow |
| 403 | `CSRF_INVALID` | Retrieve a valid CSRF token before a new submission |
| 404 | `ADVERTISEMENT_NOT_FOUND` | Refresh the list; the card is missing or deleted |
| 409 | `VERSION_CONFLICT` | Refetch the card/list and let the user reconcile changes |
| 413 | `FILE_TOO_LARGE`, `PAYLOAD_TOO_LARGE`, `STORAGE_QUOTA_EXCEEDED` | Reduce the image/request size or resolve storage capacity |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Send a supported image format or the documented content type |
| 422 | `VALIDATION_FAILED`, `INVALID_MEDIA` | Display `error.fields` and correct the input |
| 429 | `RATE_LIMITED` | Respect Retry-After before retrying |
| 503 | Service-specific unavailable code | Show a temporary service error; public reads use `ADVERTISEMENTS_UNAVAILABLE` |

All writes retain server-side session checks, CSRF protection, version checks and transactional record/reference/audit updates. A successfully uploaded file whose card save fails remains unreferenced and private until the existing cleanup lifecycle removes it; no failed save publishes a card.

## Deployment

Run pending migrations using the project's normal deployment process:

```sh
php artisan migrate --no-interaction
```

The additive migration creates `advertisements` and requires the existing users, panel and shared-media infrastructure. Preserve existing data; do not use `migrate:fresh`. Continue running the existing media cleanup scheduler and public-change delivery process.
