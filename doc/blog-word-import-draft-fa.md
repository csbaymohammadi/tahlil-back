# ورود Word و ساخت پیش‌نویس مقاله

این راهنما فقط جریان ارسال فایل Word برای ساخت پیش‌نویس مقاله را پوشش می‌دهد. این API برای ورود مقاله، انتشار انجام نمی‌دهد.

## آماده‌کردن فایل

قالب فارسی مقاله از مسیر زیر دریافت می‌شود:

`https://service.tahlilex.com/tahlilpanel/templates/blog-import-v2.docx`

بخش‌های قالب به‌ترتیب عبارت‌اند از: **عنوان**، **عنوان انگلیسی**، **توضیحات**، **تصویر**، **محتوا** و **توضیحات متا**. عنوان و عنوان انگلیسی لازم‌اند؛ عنوان انگلیسی به‌عنوان `slug` مقاله ذخیره می‌شود. بقیهٔ بخش‌ها اختیاری‌اند. اگر دسته‌بندی یا زمان مطالعه درج شود، این دو بخش باید بعد از توضیحات متا باشند.

## ارسال فایل

درخواست به این مسیر ارسال می‌شود:

`POST https://service.tahlilex.com/api/v1/admin/content-imports`

درخواست باید با نشست معتبر پنل و توکن CSRF ارسال شود. بدنه `multipart/form-data` است و شامل این بخش‌هاست:

- `content_type`: مقدار ثابت `blog`
- `file`: فایل `.docx` یا `.doc`، حداکثر ۲۰ مگابایت
- هدر `Idempotency-Key`: یک UUID جدید برای این فایل
- هدر `X-CSRF-Token`: توکن نشست جاری

نمونهٔ مرورگر:

```js
const form = new FormData();
form.append("content_type", "blog");
form.append("file", fileInput.files[0]);

const idempotencyKey = crypto.randomUUID();
const response = await fetch(
  "https://service.tahlilex.com/api/v1/admin/content-imports",
  {
    method: "POST",
    credentials: "include",
    headers: {
      "X-CSRF-Token": csrfToken,
      "Idempotency-Key": idempotencyKey,
    },
    body: form,
    signal: AbortSignal.timeout(120_000),
  },
);

const result = await response.json();
if (!response.ok) {
  throw { status: response.status, ...result };
}

const draft = result.data.record;
// draft.id برای بازکردن صفحهٔ ویرایش پیش‌نویس استفاده می‌شود.
```

برای `FormData`، هدر `Content-Type` را دستی تنظیم نکنید تا مرورگر مرز multipart را اضافه کند. درخواست اولیه ممکن است تا ۱۲۰ ثانیه طول بکشد.

## پاسخ موفق

پاسخ موفق با HTTP `201 Created` برمی‌گردد. مقاله همیشه با وضعیت `draft` ساخته می‌شود:

```json
{
  "data": {
    "content_type": "blog",
    "record": {
      "id": 42,
      "title": "عنوان مقاله",
      "slug": "english-article-title",
      "status": "draft"
    }
  },
  "warnings": [],
  "request_id": "شناسهٔ درخواست"
}
```

`record` منبع کامل مقاله است و اطلاعات نویسنده، نسخه و سایر فیلدهای مقاله را هم دارد. اگر یک مقدار اختیاری قابل استفاده نباشد، ذخیره متوقف نمی‌شود؛ مقدار خالی می‌ماند و مورد فارسی در `warnings` برمی‌گردد. هشدار به معنی شکست درخواست نیست.

## پاسخ خطا

خطاهای عنوان و ساختار/فایل با HTTP `422` برمی‌گردند. کدها و نام فیلدها برای منطق فرانت هستند؛ متن‌های قابل‌نمایش فارسی‌اند:

```json
{
  "error": {
    "code": "IMPORT_VALIDATION_FAILED",
    "message": "فایل یا ساختار سند قابل‌پردازش نیست؛ جزئیات فارسی هر بخش را بررسی کنید.",
    "fields": {},
    "issues": [
      {
        "field": "file",
        "code": "INVALID_WORD_FILE",
        "label": "فایل انتخاب‌شده",
        "location": "فایل انتخاب‌شده",
        "message": "فایل انتخاب‌شده خراب، خالی یا پشتیبانی‌نشده است؛ یک فایل DOC یا DOCX سالم بفرستید."
      }
    ]
  },
  "request_id": "شناسهٔ درخواست"
}
```

برای اتصال خطا به فرم از `issues[].field` استفاده کنید و `issues[].message` یا `error.message` را نمایش دهید. خطا ممکن است در `fields` هم پیام‌های همان فیلد را داشته باشد. پاسخ خطای ورود مقاله فایل گزارش یا زمان نمایشی ندارد.

خطاهای نشست و درخواست نیز از `error.code`, `error.message`, `error.fields` و `request_id` استفاده می‌کنند؛ برای نمونه `UNAUTHENTICATED` یعنی ورود دوباره لازم است. `IMPORT_IN_PROGRESS` با HTTP `409` یعنی همان فایل هنوز در حال پردازش است و ممکن است هدر `Retry-After` داشته باشد.

## بازیابی پس از timeout

قطع انتظار فرانت به معنی لغو پردازش سرور نیست. اگر timeout رخ داد، فایل انتخاب‌شده و همان `Idempotency-Key` را نگه دارید. برای بازیابی نتیجه، دقیقاً همان فایل را با همان کلید دوباره بفرستید؛ نتیجهٔ موفق یا خطای قبلی بدون پردازش دوباره بازپخش می‌شود. برای فایل اصلاح‌شده، UUID تازه بسازید. از retry خودکار و کلید متفاوت برای همان تلاش خودداری کنید.
