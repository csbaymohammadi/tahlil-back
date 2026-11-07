# Bonus rebuild API contract

This document is bilingual. The complete English contract comes first, followed by the Persian version.

این مستند دوزبانه است. قرارداد کامل ابتدا به انگلیسی و سپس به فارسی آمده است.

## English

### Panel and public responses

Rebuild the bonus module using the contract below. This contract supersedes the structured campaign contract when the new bonus module goes live.

| Endpoint | Success response |
| --- | --- |
| `GET /api/v1/admin/bonuses/items` | `200` with card rows plus `is_published` and `version`. Use existing `q`, `category_id`, `page`, and `per_page` parameters and pagination `meta`. |
| `POST /api/v1/admin/bonuses/items` | `201` with the complete saved record and its initial `version`. |
| `GET /api/v1/admin/bonuses/items/{id}` | `200` with the complete record and its current `version`. |
| `PATCH /api/v1/admin/bonuses/items/{id}` | `200` with the complete updated record. Send the current `expected_version`. |
| `DELETE /api/v1/admin/bonuses/items/{id}?expected_version=1` | `204` with an empty body. |
| `GET /api/v1/bonuses` | `200` with published card rows, `meta`, `filters`, and `facets`. |
| `GET /api/v1/bonuses/{slug}` | `200` with the published card fields plus the six detail fields below. |
| `GET /api/v1/bonuses/{slug}/latest`, `GET /api/v1/page-settings`, `GET /api/v1/search` | `200` with the same bonus card fields in the existing response structures. Latest returns up to five other published bonuses. Keep shared routing and display keys required by the containing response. |

JSON responses keep the existing `data` and `request_id` envelope. Use numeric IDs for panel items and voting, and slugs for public detail and latest. Panel reads and writes keep the existing session, CSRF and version rules. Drafts have `href: null` in panel responses and are excluded from public results. Retain the existing public not-found behavior for draft, deleted and unknown bonuses.

### Panel write contract

- The form has the fields in the create example. The author enters the provider name, display status, amount, dates and conditions as free text. Use the existing image uploader and send the resulting URL as `cover_image`.
- **Check only the JSON types of the authored fields.** All authored values other than `category_id` and `is_published` are strings and may be empty. Do not require any authored field, enforce length limits or prescribed values, parse the amount or dates, require a URL format, or enforce dependencies between fields. Preserve entered text, including Persian digits, wording and whitespace. Type errors use the existing field-error envelope.
- Use the existing bonus category service at `GET /api/v1/admin/bonuses/categories` for the multi-select. `category_id` is a JSON array of numbers. Keep existing IDs in selection order, collapse duplicates and skip missing IDs. Return `category_id` and `category` in the same order. Each category object contains `id`, `name`, and `key`.
- `is_published` is a JSON boolean. `true` publishes and `false` makes the bonus a draft. On create, omitted strings default to `""`, omitted categories to `[]`, and omitted `is_published` to `false`. On PATCH, omission preserves a field, `""` clears a string and `[]` clears all categories.
- Generate the ID and the slug `bonus-{id}` on the server for new records. Preserve existing IDs, slugs, public URLs, publication state, category assignments and ratings. Migrate available display values into the new fields and use empty strings where data is unavailable. The form has no manual title or slug, linked platform, provider type, campaign type, reward type, or structured campaign settings.

Create request for `POST /api/v1/admin/bonuses/items`

```json
{
  "provider_name": "توبیت",
  "cover_image": "/media/42/cover.png",
  "display_status": "فعال تا پایان ماه",
  "amount": "دویست تتر",
  "start_date": "۱ آذر ۱۴۰۵",
  "end_date": "پایان آذر ۱۴۰۵",
  "category_id": [
    3,
    7
  ],
  "short_description": "بونوس ویژهٔ کاربران جدید",
  "identity_verification": "احراز هویت لازم است",
  "iranian_users": "با شرایط اعلام‌شده توسط ارائه‌دهنده",
  "minimum_deposit": "پنجاه تتر",
  "content": "<p>توضیحات کامل بونوس</p>",
  "website_url": "https://example.com",
  "is_published": true
}
```

Edit request for `PATCH /api/v1/admin/bonuses/items/81` that clears the categories and returns the bonus to draft

```json
{
  "expected_version": 1,
  "category_id": [],
  "is_published": false
}
```

Complete panel response after creation. Detail and update use the same record shape with current values. Panel list rows use the card fields shown in the public list example plus `is_published` and `version`.

```json
{
  "data": {
    "id": 81,
    "slug": "bonus-81",
    "href": "/bonuses/bonus-81",
    "provider_name": "توبیت",
    "cover_image": "/media/42/cover.png",
    "display_status": "فعال تا پایان ماه",
    "amount": "دویست تتر",
    "start_date": "۱ آذر ۱۴۰۵",
    "end_date": "پایان آذر ۱۴۰۵",
    "category_id": [
      3,
      7
    ],
    "category": [
      {
        "id": 3,
        "name": "ثبت‌نام",
        "key": "category-3"
      },
      {
        "id": 7,
        "name": "واریز",
        "key": "category-7"
      }
    ],
    "rating": null,
    "rating_count": 0,
    "rating_revision": 0,
    "short_description": "بونوس ویژهٔ کاربران جدید",
    "identity_verification": "احراز هویت لازم است",
    "iranian_users": "با شرایط اعلام‌شده توسط ارائه‌دهنده",
    "minimum_deposit": "پنجاه تتر",
    "content": "<p>توضیحات کامل بونوس</p>",
    "website_url": "https://example.com",
    "is_published": true,
    "version": 1
  },
  "request_id": "req_bonus_create"
}
```

### Public archive, detail and sorting

- Show the cover, provider name, first category and display status on each card. Place status in the upper-left corner. Follow with the amount, stars and labeled start and end dates. Render entered amount and date text as received. For empty values use `$۰` for the amount, `-` for dates and a hollow circle for status. Use empty stars and «۰ رأی» when there are no votes.
- Show the cover, provider name, status, all categories and short description at the top of the detail page. Follow with five cards for identity verification, Iranian users, minimum deposit, start date and end date. Show `-` for empty values. Below these cards, show the body and a website link. In the left column, place a second website box below the rating box with the same cover and provider name. Both links use `website_url` and become clickable only for HTTP or HTTPS URLs. Apply the existing safe HTML policy to the public body.
- Keep the archive parameters `q`, `category` (the public category key), `page`, and `per_page=9`. Add `sort` and echo its effective value in `filters.sort`. Keep the existing category facet counts. Apply search and category filters, sort the matching published records, then paginate. The frontend returns to page 1 when the sort changes.

| Label | `sort` | Order |
| --- | --- | --- |
| Newest | `newest` | Publication date descending. This is also the default for omitted, empty or unknown values. |
| Most popular | `most_popular` | Average rating descending, then vote count descending. Unrated records come after rated records. |
| Most viewed | `most_viewed` | Recorded detail-page views in the last 30 days, descending. |

Use publication date descending and then ID descending to resolve ties. For `most_viewed`, use the existing analytics window and collect through `POST /api/v1/analytics/page-views`. Count one event per successful public detail display, deduplicate retries with the same event ID, and aggregate by the stable bonus ID. API reads, prefetches and component rerenders do not count. An available source with no events means zero views. If the source is unavailable, return `503 ANALYTICS_UNAVAILABLE` for `most_viewed` only. The other sort options remain available.

Example request combining a category and sort

`GET /api/v1/bonuses?category=category-3&sort=most_popular&page=1&per_page=9`

```json
{
  "data": [
    {
      "id": 81,
      "slug": "bonus-81",
      "href": "/bonuses/bonus-81",
      "provider_name": "توبیت",
      "cover_image": "/media/42/cover.png",
      "display_status": "فعال تا پایان ماه",
      "amount": "دویست تتر",
      "start_date": "۱ آذر ۱۴۰۵",
      "end_date": "پایان آذر ۱۴۰۵",
      "category_id": [
        3,
        7
      ],
      "category": [
        {
          "id": 3,
          "name": "ثبت‌نام",
          "key": "category-3"
        },
        {
          "id": 7,
          "name": "واریز",
          "key": "category-7"
        }
      ],
      "rating": 3.5,
      "rating_count": 2,
      "rating_revision": 2
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "category": "category-3",
    "sort": "most_popular"
  },
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 3,
        "name": "ثبت‌نام",
        "key": "category-3",
        "count": 1
      },
      {
        "id": 7,
        "name": "واریز",
        "key": "category-7",
        "count": 1
      }
    ]
  },
  "request_id": "req_bonus_list"
}
```

Example detail response for `GET /api/v1/bonuses/bonus-81`. The six additional fields are `short_description`, `identity_verification`, `iranian_users`, `minimum_deposit`, `content` and `website_url`.

```json
{
  "data": {
    "id": 81,
    "slug": "bonus-81",
    "href": "/bonuses/bonus-81",
    "provider_name": "توبیت",
    "cover_image": "/media/42/cover.png",
    "display_status": "فعال تا پایان ماه",
    "amount": "دویست تتر",
    "start_date": "۱ آذر ۱۴۰۵",
    "end_date": "پایان آذر ۱۴۰۵",
    "category_id": [
      3,
      7
    ],
    "category": [
      {
        "id": 3,
        "name": "ثبت‌نام",
        "key": "category-3"
      },
      {
        "id": 7,
        "name": "واریز",
        "key": "category-7"
      }
    ],
    "rating": 3.5,
    "rating_count": 2,
    "rating_revision": 2,
    "short_description": "بونوس ویژهٔ کاربران جدید",
    "identity_verification": "احراز هویت لازم است",
    "iranian_users": "با شرایط اعلام‌شده توسط ارائه‌دهنده",
    "minimum_deposit": "پنجاه تتر",
    "content": "<p>توضیحات کامل بونوس</p>",
    "website_url": "https://example.com"
  },
  "request_id": "req_bonus_detail"
}
```

### Existing star voting

`GET /api/v1/bonuses/81/rating` returns personal voting state and the rating CSRF token. Keep the existing voter cookie and send the token as `X-CSRF-Token` with `PUT /api/v1/bonuses/81/rating`. Send `{"value":5}` as JSON. The existing integer 1–5 rule and session behavior apply to voting.

Personal rating response

```json
{
  "data": {
    "bonus_id": 81,
    "rating": 3.5,
    "rating_count": 2,
    "rating_revision": 2,
    "my_rating": null,
    "csrf_token": "<opaque-token>",
    "csrf_expires_at": "2026-11-24T09:15:00Z"
  },
  "request_id": "req_bonus_rating_read"
}
```

Successful vote response

```json
{
  "data": {
    "bonus_id": 81,
    "rating": 4,
    "rating_count": 3,
    "rating_revision": 3,
    "my_rating": 5
  },
  "request_id": "req_bonus_rating_write"
}
```

Rating aggregates remain server-owned. For an unrated bonus, return `rating: null`, `rating_count: 0` and the current `rating_revision`. Personal votes and rating CSRF tokens appear only in the existing rating responses.

### Acceptance checks

Verify exact round-tripping of free-text fields, empty values, ordered category assignment and clearing, publication and return to draft, and preserved URLs and votes for existing bonuses. Verify each sort together with search and a category across pages, including ties, no votes, zero visits and unavailable analytics.

## فارسی

### پاسخ‌های پنل و سایت اصلی

بخش بونوس بر اساس قرارداد زیر از نو پیاده‌سازی می‌شود. هنگام انتشار نسخهٔ جدید، این مستند جایگزین قرارداد کمپین ساختاریافتهٔ بونوس می‌شود.

| مسیر | پاسخ موفق |
| --- | --- |
| `GET /api/v1/admin/bonuses/items` | `200` شامل ردیف‌های کارت به‌همراه `is_published` و `version`. پارامترهای فعلی `q`، `category_id`، `page` و `per_page` و اطلاعات صفحه‌بندی `meta` حفظ می‌شوند. |
| `POST /api/v1/admin/bonuses/items` | `201` شامل رکورد کامل ذخیره‌شده و `version` اولیه. |
| `GET /api/v1/admin/bonuses/items/{id}` | `200` شامل رکورد کامل و `version` فعلی. |
| `PATCH /api/v1/admin/bonuses/items/{id}` | `200` شامل رکورد کامل به‌روزشده. `expected_version` فعلی ارسال می‌شود. |
| `DELETE /api/v1/admin/bonuses/items/{id}?expected_version=1` | `204` بدون بدنه. |
| `GET /api/v1/bonuses` | `200` شامل کارت‌های منتشرشده، `meta`، `filters` و `facets`. |
| `GET /api/v1/bonuses/{slug}` | `200` شامل فیلدهای کارت منتشرشده به‌همراه شش فیلد جزئیات که پایین‌تر آمده‌اند. |
| `GET /api/v1/bonuses/{slug}/latest`، `GET /api/v1/page-settings`، `GET /api/v1/search` | `200` با همان فیلدهای کارت بونوس در ساختار فعلی پاسخ‌ها. مسیر بونوس‌های اخیر حداکثر پنج بونوس منتشرشدهٔ دیگر برمی‌گرداند. کلیدهای مشترک مسیر و نمایش در ساختارهای میزبان حفظ می‌شوند. |

پاسخ‌های JSON ساختار فعلی `data` و `request_id` را حفظ می‌کنند. پنل و رأی‌گیری از شناسهٔ عددی و جزئیات عمومی و بونوس‌های اخیر از اسلاگ استفاده می‌کنند. نشست، CSRF و کنترل نسخهٔ پنل طبق قرارداد فعلی هستند. پیش‌نویس در پنل `href: null` دارد و در نتایج عمومی نمی‌آید. رفتار فعلی «پیدا نشد» برای بونوس پیش‌نویس، حذف‌شده و ناشناخته حفظ می‌شود.

### ثبت و ویرایش در پنل

- فرم شامل فیلدهای نمونهٔ ایجاد است. نویسنده نام ارائه‌دهنده، وضعیت نمایشی، مبلغ، تاریخ‌ها و شرایط را به‌صورت متن آزاد می‌نویسد. آدرس حاصل از آپلودر فعلی در `cover_image` ارسال می‌شود.
- **بک‌اند برای فیلدهای واردشده فقط نوع JSON را بررسی کند.** به‌جز `category_id` و `is_published`، مقادیر رشته‌اند و رشتهٔ خالی مجاز است. هیچ فیلدی از فرم اجباری نباشد و محدودیت طول، فهرست مقادیر، قالب مبلغ، تاریخ یا لینک و وابستگی بین فیلدها اعمال نشود. متن، ارقام فارسی و فاصله‌ها حفظ شوند. خطای نوع با ساختار فعلی خطاهای فیلد برگردد.
- برای چندانتخابی دسته‌بندی از `GET /api/v1/admin/bonuses/categories` استفاده شود. `category_id` آرایهٔ اعداد است. شناسه‌های موجود به ترتیب انتخاب حفظ، موارد تکراری یکی و شناسه‌های ناموجود کنار گذاشته می‌شوند. `category_id` و `category` با همان ترتیب برگردند. هر آبجکت دسته‌بندی شامل `id`، `name` و `key` است.
- `is_published` بولی است. `true` منتشر می‌کند و `false` به پیش‌نویس می‌برد. در ایجاد، پیش‌فرض رشته‌ها `""`، دسته‌بندی‌ها `[]` و انتشار `false` است. در ویرایش، فیلد ارسال‌نشده حفظ می‌شود، `""` مقدار رشته‌ای و `[]` دسته‌بندی‌ها را پاک می‌کند.
- بک‌اند برای رکورد جدید شناسه و اسلاگ `bonus-{id}` می‌سازد. شناسه، اسلاگ، مسیر عمومی، انتشار، دسته‌بندی‌ها و رأی‌های رکوردهای قبلی حفظ شوند. اطلاعات نمایشی در دسترس به فیلدهای جدید منتقل و مقادیر ناموجود خالی بمانند. عنوان و اسلاگ دستی، اتصال به پلتفرم، نوع ارائه‌دهنده، نوع کمپین، نوع پاداش و تنظیمات ساختاریافتهٔ کمپین از فرم حذف می‌شوند.

نمونهٔ درخواست ایجاد با `POST /api/v1/admin/bonuses/items`

```json
{
  "provider_name": "توبیت",
  "cover_image": "/media/42/cover.png",
  "display_status": "فعال تا پایان ماه",
  "amount": "دویست تتر",
  "start_date": "۱ آذر ۱۴۰۵",
  "end_date": "پایان آذر ۱۴۰۵",
  "category_id": [
    3,
    7
  ],
  "short_description": "بونوس ویژهٔ کاربران جدید",
  "identity_verification": "احراز هویت لازم است",
  "iranian_users": "با شرایط اعلام‌شده توسط ارائه‌دهنده",
  "minimum_deposit": "پنجاه تتر",
  "content": "<p>توضیحات کامل بونوس</p>",
  "website_url": "https://example.com",
  "is_published": true
}
```

نمونهٔ درخواست ویرایش با `PATCH /api/v1/admin/bonuses/items/81` برای پاک کردن دسته‌بندی‌ها و بازگرداندن بونوس به پیش‌نویس

```json
{
  "expected_version": 1,
  "category_id": [],
  "is_published": false
}
```

پاسخ کامل پنل بعد از ایجاد. دریافت جزئیات و ویرایش همین ساختار را با مقادیر فعلی برمی‌گردانند. ردیف فهرست پنل شامل فیلدهای کارت در نمونهٔ فهرست عمومی به‌همراه `is_published` و `version` است.

```json
{
  "data": {
    "id": 81,
    "slug": "bonus-81",
    "href": "/bonuses/bonus-81",
    "provider_name": "توبیت",
    "cover_image": "/media/42/cover.png",
    "display_status": "فعال تا پایان ماه",
    "amount": "دویست تتر",
    "start_date": "۱ آذر ۱۴۰۵",
    "end_date": "پایان آذر ۱۴۰۵",
    "category_id": [
      3,
      7
    ],
    "category": [
      {
        "id": 3,
        "name": "ثبت‌نام",
        "key": "category-3"
      },
      {
        "id": 7,
        "name": "واریز",
        "key": "category-7"
      }
    ],
    "rating": null,
    "rating_count": 0,
    "rating_revision": 0,
    "short_description": "بونوس ویژهٔ کاربران جدید",
    "identity_verification": "احراز هویت لازم است",
    "iranian_users": "با شرایط اعلام‌شده توسط ارائه‌دهنده",
    "minimum_deposit": "پنجاه تتر",
    "content": "<p>توضیحات کامل بونوس</p>",
    "website_url": "https://example.com",
    "is_published": true,
    "version": 1
  },
  "request_id": "req_bonus_create"
}
```

### فهرست، جزئیات و مرتب‌سازی سایت اصلی

- **کارت بونوس** تصویر شاخص، نام ارائه‌دهنده، دسته‌بندی اول و وضعیت در گوشهٔ بالا سمت چپ را نشان می‌دهد. بعد مبلغ، ستاره‌ها و تاریخ شروع و پایان با برچسب می‌آیند. مبلغ و تاریخ‌ها عین متن دریافتی نمایش داده شوند. مبلغ خالی `$۰`، تاریخ خالی `-` و وضعیت خالی دایرهٔ توخالی دارد. بونوس بدون رأی با ستاره‌های خالی و «۰ رأی» نمایش داده می‌شود.
- **صفحهٔ جزئیات** با تصویر شاخص، نام ارائه‌دهنده، وضعیت، همهٔ دسته‌بندی‌ها و توضیح کوتاه شروع می‌شود. سپس پنج کارت «احراز هویت»، «برای کاربران ایرانی»، «حداقل واریز»، «تاریخ شروع» و «تاریخ پایان» می‌آیند. مقدار خالی این کارت‌ها `-` است. بعد از آن محتوا و لینک سایت ارائه‌دهنده قرار می‌گیرند. در ستون سمت چپ، زیر باکس امتیاز، باکس سایت ارائه‌دهنده با همان تصویر و نام قرار می‌گیرد. هر دو لینک از `website_url` می‌آیند و فقط برای آدرس‌های HTTP یا HTTPS قابل کلیک‌اند. محتوای عمومی با سیاست فعلی نمایش امن HTML رندر می‌شود.
- پارامترهای فعلی فهرست `q`، `category` با کلید عمومی دسته، `page` و `per_page=9` حفظ شوند. پارامتر `sort` اضافه و مقدار اعمال‌شده در `filters.sort` برگردانده شود. شمارش فعلی دسته‌بندی‌ها حفظ شود. بک‌اند ابتدا جست‌وجو و دسته‌بندی را روی موارد منتشرشده اعمال کند، سپس مرتب‌سازی و بعد صفحه‌بندی را انجام دهد. با تغییر ترتیب، فرانت فهرست را به صفحهٔ اول برمی‌گرداند.

| گزینه | مقدار `sort` | ترتیب |
| --- | --- | --- |
| جدیدترین | `newest` | تاریخ انتشار نزولی. پیش‌فرض برای مقدار ارسال‌نشده، خالی یا ناشناخته. |
| محبوب‌ترین | `most_popular` | میانگین ستاره نزولی، سپس تعداد رأی نزولی. بونوس‌های بدون رأی بعد از موارد امتیازدار. |
| پربازدیدترین | `most_viewed` | تعداد بازدید ثبت‌شدهٔ صفحهٔ جزئیات در ۳۰ روز اخیر، نزولی. |

در تساوی، تاریخ انتشار و سپس شناسه به‌ترتیب نزولی اعمال شوند. برای `most_viewed` از بازهٔ فعلی سرویس آمار و `POST /api/v1/analytics/page-views` استفاده شود. هر نمایش موفق صفحهٔ جزئیات یک رویداد است. تلاش مجدد با همان شناسهٔ رویداد فقط یک بار شمرده شود. آمار بر اساس شناسهٔ پایدار بونوس تجمیع شود. واکشی API، پیش‌واکشی و رندر مجدد بازدید محسوب نمی‌شوند. منبع سالم بدون رویداد یعنی صفر بازدید. اختلال منبع فقط برای `most_viewed` پاسخ `503 ANALYTICS_UNAVAILABLE` دارد و ترتیب‌های دیگر در دسترس می‌مانند.

نمونهٔ درخواست ترکیب دسته‌بندی و مرتب‌سازی

`GET /api/v1/bonuses?category=category-3&sort=most_popular&page=1&per_page=9`

```json
{
  "data": [
    {
      "id": 81,
      "slug": "bonus-81",
      "href": "/bonuses/bonus-81",
      "provider_name": "توبیت",
      "cover_image": "/media/42/cover.png",
      "display_status": "فعال تا پایان ماه",
      "amount": "دویست تتر",
      "start_date": "۱ آذر ۱۴۰۵",
      "end_date": "پایان آذر ۱۴۰۵",
      "category_id": [
        3,
        7
      ],
      "category": [
        {
          "id": 3,
          "name": "ثبت‌نام",
          "key": "category-3"
        },
        {
          "id": 7,
          "name": "واریز",
          "key": "category-7"
        }
      ],
      "rating": 3.5,
      "rating_count": 2,
      "rating_revision": 2
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 9,
    "total": 1,
    "total_pages": 1
  },
  "filters": {
    "q": "",
    "category": "category-3",
    "sort": "most_popular"
  },
  "facets": {
    "all_count": 1,
    "uncategorized_count": 0,
    "categories": [
      {
        "id": 3,
        "name": "ثبت‌نام",
        "key": "category-3",
        "count": 1
      },
      {
        "id": 7,
        "name": "واریز",
        "key": "category-7",
        "count": 1
      }
    ]
  },
  "request_id": "req_bonus_list"
}
```

نمونهٔ پاسخ `GET /api/v1/bonuses/bonus-81`. شش فیلد افزوده بر کارت عبارت‌اند از `short_description`، `identity_verification`، `iranian_users`، `minimum_deposit`، `content` و `website_url`.

```json
{
  "data": {
    "id": 81,
    "slug": "bonus-81",
    "href": "/bonuses/bonus-81",
    "provider_name": "توبیت",
    "cover_image": "/media/42/cover.png",
    "display_status": "فعال تا پایان ماه",
    "amount": "دویست تتر",
    "start_date": "۱ آذر ۱۴۰۵",
    "end_date": "پایان آذر ۱۴۰۵",
    "category_id": [
      3,
      7
    ],
    "category": [
      {
        "id": 3,
        "name": "ثبت‌نام",
        "key": "category-3"
      },
      {
        "id": 7,
        "name": "واریز",
        "key": "category-7"
      }
    ],
    "rating": 3.5,
    "rating_count": 2,
    "rating_revision": 2,
    "short_description": "بونوس ویژهٔ کاربران جدید",
    "identity_verification": "احراز هویت لازم است",
    "iranian_users": "با شرایط اعلام‌شده توسط ارائه‌دهنده",
    "minimum_deposit": "پنجاه تتر",
    "content": "<p>توضیحات کامل بونوس</p>",
    "website_url": "https://example.com"
  },
  "request_id": "req_bonus_detail"
}
```

### رأی‌گیری ستاره‌ای فعلی

`GET /api/v1/bonuses/81/rating` وضعیت رأی شخصی و توکن CSRF رأی‌گیری را برمی‌گرداند. کوکی فعلی رأی‌دهنده حفظ شود. در `PUT /api/v1/bonuses/81/rating`، توکن در `X-CSRF-Token` و بدنهٔ JSON به شکل `{"value":5}` ارسال شود. قاعدهٔ فعلی عدد صحیح ۱ تا ۵ و نشست رأی‌گیری حفظ می‌شوند.

پاسخ دریافت رأی شخصی

```json
{
  "data": {
    "bonus_id": 81,
    "rating": 3.5,
    "rating_count": 2,
    "rating_revision": 2,
    "my_rating": null,
    "csrf_token": "<opaque-token>",
    "csrf_expires_at": "2026-11-24T09:15:00Z"
  },
  "request_id": "req_bonus_rating_read"
}
```

پاسخ ثبت موفق رأی

```json
{
  "data": {
    "bonus_id": 81,
    "rating": 4,
    "rating_count": 3,
    "rating_revision": 3,
    "my_rating": 5
  },
  "request_id": "req_bonus_rating_write"
}
```

تجمیع امتیاز در بک‌اند انجام می‌شود. برای بونوس بدون رأی، `rating: null`، `rating_count: 0` و `rating_revision` فعلی برگردند. رأی شخصی و توکن CSRF فقط در پاسخ‌های سرویس رأی‌گیری می‌آیند.

### بررسی پذیرش

بررسی شود که متن آزاد و مقادیر خالی بدون تغییر رفت‌وبرگشت دارند، ترتیب و پاک‌کردن دسته‌بندی‌ها درست است، انتشار و بازگشت به پیش‌نویس کار می‌کند و آدرس و رأی بونوس‌های قبلی حفظ می‌شوند. هر سه ترتیب همراه جست‌وجو و دسته‌بندی در چند صفحه آزمایش شوند و حالت تساوی، بدون رأی، بدون بازدید و اختلال آمار پوشش داده شود.
