# راهنمای ورود Word و ساخت پیش‌نویس خبر

این راهنما فقط مسیر ارسال فایل Word و ساخت پیش‌نویس خبر را توضیح می‌دهد. برای ورود خبر، فایل Word باید با قالب فارسی خبر آماده شود؛ پاسخ خطاها و هشدارهای قابل‌نمایش به کاربر فارسی است.

## دریافت قالب و فایل نمونه

- قالب خالی: [news-import-v2.docx](../public/tahlilpanel/templates/news-import-v2.docx)
- نمونهٔ آماده برای بارگذاری در پنل: [news-word-import-sample-fa.docx](samples/news-word-import-sample-fa.docx)
- نشانی قالب در محیط توسعه: `http://localhost:8000/tahlilpanel/templates/news-import-v2.docx`
- نشانی قالب در سرور: `https://service.tahlilex.com/tahlilpanel/templates/news-import-v2.docx`

ترتیب بخش‌ها در قالب:

1. **عنوان** — اجباری؛ عنوان نمایشی خبر، حداکثر ۲۰۰ نویسه.
2. **عنوان انگلیسی** — اجباری؛ به `slug` خبر تبدیل می‌شود. فقط حروف کوچک لاتین، عدد و خط تیرهٔ تکی پذیرفته می‌شود؛ حداکثر ۲۰۰ نویسه و باید یکتا باشد.
3. **توضیحات** — اختیاری.
4. **تصویر** — اختیاری؛ اولین تصویر این بخش جلد خبر می‌شود.
5. **محتوا** — اختیاری؛ متن خالی نیز Draft می‌سازد. تصویرهای داخل محتوا در متن خبر باقی می‌مانند.
6. **توضیحات متا** — اختیاری.
7. **دسته‌بندی** — اختیاری؛ نام باید با یک دستهٔ زندهٔ اخبار منطبق باشد. در غیر این صورت خبر بدون دسته ذخیره و هشدار برمی‌گردد.
8. **زمان مطالعه** — اختیاری؛ عدد ۱ تا ۱۴۴۰ پذیرفته می‌شود. مقدار خالی یا نامعتبر به‌صورت `null` ذخیره و با هشدار گزارش می‌شود.

قالب قبلی خبر (نسخهٔ v1 با نشانگرها و جدول metadata) برای فایل‌های قدیمی همچنان قابل‌خواندن است. عنوان، نویسنده، وضعیت انتشار، شناسهٔ دسته و زمان‌بندی از خود فایل دریافت نمی‌شوند؛ نویسنده از نشست واردشده تعیین و وضعیت همیشه `draft` است.

## ارسال از فرانت‌اند

API root محیط توسعه `http://localhost:8000/api/v1/admin` و API root سرور `https://service.tahlilex.com/api/v1/admin` است. در staging، میزبان واقعی را جایگزین کنید.

ابتدا کوکی نشست و توکن CSRF را با `GET /auth/csrf` دریافت کنید. برای درخواست multipart، مرورگر خودش `Content-Type` و مرز multipart را می‌سازد؛ این هدر را دستی تنظیم نکنید. کوکی باید با `credentials: 'include'` ارسال شود.

```js
const api = 'https://service.tahlilex.com/api/v1/admin';
const csrfResponse = await fetch(`${api}/auth/csrf`, {
  credentials: 'include',
  headers: { Accept: 'application/json' },
});
const csrf = (await csrfResponse.json()).data.csrf_token;

const body = new FormData();
body.append('content_type', 'news');
body.append('file', selectedFile);

const response = await fetch(`${api}/content-imports`, {
  method: 'POST',
  credentials: 'include',
  headers: {
    Accept: 'application/json',
    'X-CSRF-Token': csrf,
    'Idempotency-Key': crypto.randomUUID(),
  },
  body,
  signal: AbortSignal.timeout(120_000),
});

const result = response.status === 204 ? null : await response.json();
```

در زمان timeout یا قطع شبکه، همان فایل و همان `Idempotency-Key` را دوباره ارسال کنید تا نتیجهٔ ذخیره‌شده بازیابی شود. کلید جدید فقط برای فایل اصلاح‌شده یا تلاش مستقل بسازید. timeout مرورگر پردازش سرور را لغو نمی‌کند.

## پاسخ موفق

وضعیت پاسخ `201 Created` است. رکورد کامل News در `data.record` و فهرست هشدارهای اختیاری در `warnings` برمی‌گردد. برای این پاسخ، سرور `Location` را روی مسیر جزئیات خبر تنظیم می‌کند.

```json
{
  "data": {
    "content_type": "news",
    "record": {
      "id": 42,
      "title": "راهنمای خواندن خبرهای بازار",
      "slug": "reading-market-news",
      "status": "draft",
      "description": "",
      "cover_image": "",
      "content": "<h2>منبع خبر را بررسی کنید</h2>",
      "category_id": null,
      "reading_minutes": null
    }
  },
  "warnings": [],
  "request_id": "req_..."
}
```

نمونهٔ هشدار:

```json
{
  "field": "reading_minutes",
  "code": "READING_TIME_IGNORED",
  "message": "زمان مطالعه معتبر نبود و خالی ذخیره شد."
}
```

هشدار مانع ساخت Draft نیست. اگر چند مقدار اختیاری قابل‌استفاده نباشند، برای هر مورد یک هشدار فارسی می‌آید.

## خطاها

خطای قالب یا عنوان با `422 Unprocessable Entity` برمی‌گردد. ساختار ماشینی API انگلیسی باقی می‌ماند تا فرانت بتواند بر اساس `code` و `field` تصمیم بگیرد؛ متن‌هایی که در رابط نمایش داده می‌شوند (`message`، پیام‌های `fields`، و `issues.label/location/message`) فارسی هستند. پاسخ خطای جدید فایل گزارش Word، نام فایل ارسالی یا زمان نمایشی ندارد.

```json
{
  "error": {
    "code": "IMPORT_VALIDATION_FAILED",
    "message": "عنوان و عنوان انگلیسی سند را بررسی و اصلاح کنید.",
    "fields": {
      "slug": ["عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید؛ طول آن حداکثر ۲۰۰ نویسه باشد."]
    },
    "issues": [
      {
        "code": "FIELD_INVALID",
        "field": "slug",
        "label": "عنوان انگلیسی",
        "location": "بخش عنوان انگلیسی",
        "message": "عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید؛ طول آن حداکثر ۲۰۰ نویسه باشد."
      }
    ]
  },
  "request_id": "req_..."
}
```

کدهای پرتکرار:

| وضعیت | کد | رفتار فرانت |
|---|---|---|
| 401 | `UNAUTHENTICATED` | نشست منقضی شده؛ ورود مجدد و دریافت CSRF تازه |
| 403 | `CSRF_INVALID` | دریافت توکن تازه و ارسال مجدد همان فایل و همان کلید |
| 409 | `IDEMPOTENCY_CONFLICT` | کلید قبلی با فایل یا نوع محتوای دیگری استفاده شده؛ کلید تازه بسازید |
| 409 | `IMPORT_IN_PROGRESS` | همان فایل و کلید را پس از زمان `Retry-After` تکرار کنید |
| 413 | `PAYLOAD_TOO_LARGE` | حجم فایل از ۲۰ MiB بیشتر است |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | درخواست با `multipart/form-data` ارسال شود؛ مرورگر مرز multipart را تنظیم می‌کند |
| 422 | `IMPORT_VALIDATION_FAILED` | متن فارسی `issues` و `fields` را در فرم نشان دهید |
| 429 | `RATE_LIMITED` | پس از `Retry-After` تلاش کنید؛ کلید را برای همان فایل عوض نکنید |
| 503 | `WORD_IMPORT_UNAVAILABLE` یا `WORD_PARSER_UNAVAILABLE` | پیام فارسی را نشان دهید و برای پشتیبانی `request_id` را نگه دارید |

فایل خراب، رمزدار، دارای ماکرو/شیء اجرایی، رابطهٔ خارجی، ساختار Word پشتیبانی‌نشده یا محدودیت منابع همچنان رد می‌شود. اختیاری‌بودن فیلدهای محتوا، کنترل امنیتی فایل را حذف نمی‌کند. خطای 503 متن عمومی دارد تا جزئیات داخلی سرور افشا نشود. endpoint گزارش فقط برای گزارش‌هایی است که در نسخه‌های قدیمی‌تر ذخیره شده‌اند؛ خطاهای جدید گزارش Word نمی‌سازند.

## پیش‌نیازهای سرور Ubuntu 24.04

فایل DOCX مستقیماً با PHP خوانده می‌شود. برای PHP 8.3 بسته‌های زیر را نصب کنید؛ همین افزونه‌ها باید برای PHP-FPM مورد استفادهٔ سایت نیز فعال باشند:

```sh
sudo apt update
sudo apt install php8.3-zip php8.3-xml php8.3-mbstring php8.3-gd ffmpeg
php8.3 -m | grep -E '^(dom|fileinfo|gd|mbstring|xml|zip)$'
ffmpeg -version
```

`fileinfo` معمولاً همراه بستهٔ PHP common فعال است. برای DOCX بدون تصویر، FFmpeg استفاده نمی‌شود؛ وجود تصویر تعبیه‌شده برای نرمال‌سازی نیازمند FFmpeg است. پردازش‌گر باید در `storage/framework/cache` و دیسک رسانه مجوز نوشتن داشته باشد. بعد از نصب/تغییر افزونه‌ها، PHP-FPM و workerهای طولانی‌عمر را reload کنید.

برای فایل قدیمی DOC، این پیش‌نیازهای اضافه لازم‌اند:

```sh
sudo apt install libreoffice-writer python3 python3-venv bubblewrap
sudo python3 -m venv /opt/tahlil-word-import-venv
sudo /opt/tahlil-word-import-venv/bin/pip install -r /path/to/release/requirements-word-import.txt
```

در محیط برنامه مسیرها را تنظیم کنید:

```dotenv
WORD_IMPORT_PYTHON=/opt/tahlil-word-import-venv/bin/python
LIBREOFFICE_BINARY=/usr/bin/soffice
BWRAP_BINARY=/usr/bin/bwrap
```

تبدیل DOC در sandbox بدون شبکه اجرا می‌شود و در صورت نبود Bubblewrap یا سیاست مجاز Ubuntu 24.04 تبدیل ناامن انجام نمی‌شود. AppArmor ممکن است user namespace را محدود کند؛ سیاست میزبان را برای کاربر PHP-FPM بررسی کنید و محدودیت امنیتی را به‌طور سراسری خاموش نکنید.

### تشخیص پیام «سرویس پردازش سند موقتاً در دسترس نیست»

این پیام fallback امن برای خطای غیرمنتظره هنگام پردازش است و به‌تنهایی نمی‌گوید کدام مورد مشکل دارد. برنامه به سرور production دسترسی ندارد و علت قطعی را بدون لاگ همان سرور نمی‌توان مشخص کرد. کد فعلی در لاگ درخواست، `request_id` و نوع استثنا را ثبت می‌کند اما متن استثنا و stack trace را برای جلوگیری از افشای اطلاعات ثبت نمی‌کند. اگر زمان مطالعه خالی است، ابتدا مطمئن شوید migration افزایشی `2026_10_24_171338_make_news_reading_minutes_nullable` اجرا شده باشد؛ در غیر این صورت دیتابیس ممکن است مقدار `null` جدید را رد و خطای عمومی ۵۰۳ تولید کند. سپس افزونه‌های PHP-FPM، مجوز نوشتن در `storage/framework/cache` و دیسک رسانه، و در صورت داشتن تصویر FFmpeg را بررسی کنید. LibreOffice/Python/oletools/Bubblewrap برای DOCX لازم نیستند؛ آن‌ها فقط برای پسوند قدیمی DOC لازم‌اند.

پس از استقرار نسخه، migrationهای افزایشی را در روند معمول release اجرا کنید:

```sh
php artisan migrate --force
```

روی production مقدار `APP_DEBUG=true` نگذارید و محتوای Word، کوکی یا توکن CSRF را در گزارش پشتیبانی نفرستید. اگر خطا ادامه داشت، `request_id` و بخش مرتبط لاگ را بدون اطلاعات محرمانه ارائه کنید تا علت دقیق از stack trace مشخص شود.
