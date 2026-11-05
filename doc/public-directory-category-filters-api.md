# Public directory category filters

Stock brokerages, gold platforms, and prop firms use the same public archive category contract as exchanges and forex brokers. This handoff covers the website URL and the corresponding read-only API call.

## Website URLs and API calls

| Directory | Website URL example | API request example |
| --- | --- | --- |
| Stock brokerages | `/stock-brokers?category=category-4` | `GET /api/v1/stock-brokers?category=category-4` |
| Gold platforms | `/gold-platforms?category=category-7` | `GET /api/v1/gold-platforms?category=category-7` |
| Prop firms | `/prop-firms?category=category-12` | `GET /api/v1/prop-firms?category=category-12` |

Examples use illustrative keys. Build links from the current directory's `facets.categories[].key`. Category identity is scoped to the directory, so the same key can appear in more than one directory. A record's `href` still points directly to its review.

| Query parameter | Accepted value |
| --- | --- |
| `category` | A single key from the current directory. Omit it for All. |
| `q` | Optional search text, trimmed, up to 200 Unicode characters. Matches title, English name, description or derived excerpt, and any assigned live category name. |
| `sort` | `default` (newest creation first) or `alphabetical`. |
| `page` | Positive integer up to `9007199254740991`, default `1`. |
| `per_page` | Omit it or pass `9`. |

The API returns All with `filters.category: null` when the category is omitted, empty, `all`, unknown, deleted or merged, repeated, structured, invalid UTF-8, or longer than 100 bytes. An old category key does not select the merge destination. Unknown, repeated, or malformed non-category parameters and GET request bodies return `422 VALIDATION_FAILED`. The list uses the public read rate limit and the usual `request_id` and `X-Request-ID`. No credentials are required.

## Example response

Example for `GET /api/v1/gold-platforms?q=gold&category=category-7`.

```json
{
  "data": [
    {
      "id": 61,
      "slug": "example-gold-platform",
      "title": "پلتفرم نمونه",
      "english_name": "Example Gold",
      "logo": "",
      "href": "/gold-platforms/example-gold-platform",
      "description": "Platform overview.",
      "excerpt": "Platform overview.",
      "category_id": [7, 9],
      "category": [
        {"id": 7, "name": "Gold Services", "key": "category-7"},
        {"id": 9, "name": "Physical Delivery", "key": "category-9"}
      ],
      "rating_average": null,
      "rating_count": 0,
      "rating_revision": 0,
      "comment_count": 2,
      "kind": "gold-platforms",
      "founding_year": "1400",
      "platform_label": "Gold Platform"
    }
  ],
  "meta": {"page": 1, "per_page": 9, "total": 1, "total_pages": 1},
  "filters": {"q": "gold", "category": "category-7", "sort": "default"},
  "facets": {
    "all_count": 2,
    "uncategorized_count": 1,
    "categories": [
      {"id": 7, "name": "Gold Services", "key": "category-7", "count": 1},
      {"id": 9, "name": "Physical Delivery", "key": "category-9", "count": 1},
      {"id": 11, "name": "Savings", "key": "category-11", "count": 0}
    ]
  },
  "request_id": "req_example"
}
```

- `meta.total` applies the selected category after search. `facets` uses search alone before the category filter and includes every live category, including categories with zero matches. Categories are ordered by normalized name, then ID.
- Only published, non-deleted records count. A record assigned to several live categories is counted once in All and once per matching category. Summing the category counts can exceed `all_count`. A record without any live category counts as uncategorized.
- A card, review, or related card exposes all live assignments in `category_id` and `category`, in saved order. Both arrays are empty for uncategorized records.
- A page beyond the last page returns an empty `data` array and keeps the requested page, effective filters, and counts.

## Frontend integration

1. Restore `category`, `q`, `sort`, and `page` from the website URL for the first render, reload, and back/forward navigation. Fetch the corresponding public API with those values.
2. Build the selector from a local All option using `facets.all_count` and the returned `facets.categories`. Use `filters.category` for the active option. `uncategorized_count` is informational.
3. Reset to page 1 when changing category, search, or sort. Preserve filters when paging. Omit default values from the website URL. When the API falls back to All, remove the stale `category` query parameter.
4. Keep existing market navigation separate from category filters. Scope the request cache by directory and effective query. Category selection belongs to the archive URL. The detail and `/others` endpoints accept no query parameters.

The public APIs use the categories and assignments already managed in the panel. Deployment of this change requires no additional migration. Apply the URL handling in the website frontend when connecting these APIs.
