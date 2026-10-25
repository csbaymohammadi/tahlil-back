<?php

namespace App\Blog;

class BlogApiErrorPresenter
{
    /** @var array<string, string> */
    private const LABELS = [
        'title' => 'عنوان مقاله',
        'slug' => 'عنوان انگلیسی',
        'description' => 'توضیحات',
        'cover_image' => 'تصویر',
        'content' => 'محتوا',
        'category_id' => 'دسته‌بندی',
        'category_name' => 'دسته‌بندی',
        'reading_minutes' => 'زمان مطالعه',
        'meta_description' => 'توضیحات متا',
        'file' => 'فایل انتخاب‌شده',
        'content_type' => 'نوع محتوا',
        'idempotency_key' => 'شناسه درخواست',
        'template' => 'ساختار سند',
        'scheduled_for' => 'زمان انتشار',
        'status' => 'وضعیت انتشار',
        'expected_version' => 'نسخه مقاله',
        'body' => 'بدنه درخواست',
    ];

    /** @var array<string, string> */
    private const MESSAGES = [
        'UNAUTHENTICATED' => 'نشست شما معتبر نیست یا پایان یافته است؛ دوباره وارد پنل شوید.',
        'CSRF_INVALID' => 'اعتبار امنیتی درخواست معتبر نیست؛ توکن تازه دریافت و دوباره تلاش کنید.',
        'INVALID_JSON' => 'ساختار دادهٔ ارسالی معتبر نیست.',
        'ARTICLE_NOT_FOUND' => 'مقاله پیدا نشد یا حذف شده است.',
        'NEWS_NOT_FOUND' => 'خبر پیدا نشد یا حذف شده است.',
        'VERSION_CONFLICT' => 'مقاله هم‌زمان تغییر کرده است؛ نسخهٔ تازه را دریافت کنید.',
        'SLUG_TAKEN' => 'این عنوان انگلیسی قبلاً استفاده یا رزرو شده است.',
        'IDEMPOTENCY_CONFLICT' => 'این شناسه برای فایل یا نوع محتوای دیگری استفاده شده است.',
        'IMPORT_IN_PROGRESS' => 'پردازش این فایل هنوز ادامه دارد؛ کمی بعد با همان شناسه نتیجه را دریافت کنید.',
        'IMPORT_RESULT_NOT_FOUND' => 'پیش‌نویس این درخواست پیدا نشد یا حذف شده است.',
        'IMPORT_TIMEOUT' => 'پردازش سند از مهلت مجاز بیشتر طول کشید؛ فایل را ساده‌تر و دوباره ارسال کنید.',
        'INVALID_WORD_FILE' => 'فایل انتخاب‌شده خراب است یا قالب پشتیبانی‌شده‌ای ندارد.',
        'DOC_CONVERTER_SANDBOX_UNAVAILABLE' => 'تبدیل فایل DOC قدیمی روی سرور آماده نیست؛ پیش‌نیازهای مستندشده را نصب کنید.',
        'OLETOOLS_UNAVAILABLE' => 'بررسی ایمن فایل DOC قدیمی روی سرور آماده نیست.',
        'WORD_IMPORT_UNAVAILABLE' => 'سرویس پردازش سند موقتاً در دسترس نیست.',
        'WORD_PARSER_UNAVAILABLE' => 'پردازش امن سند روی سرور فعال نیست.',
        'MEDIA_SERVICE_UNAVAILABLE' => 'بررسی تصویر موقتاً در دسترس نیست.',
        'SERVICE_UNAVAILABLE' => 'سرویس موقتاً در دسترس نیست؛ کمی بعد دوباره تلاش کنید.',
        'PAYLOAD_TOO_LARGE' => 'حجم دادهٔ ارسالی از حد مجاز بیشتر است.',
        'FILE_TOO_LARGE' => 'حجم فایل از حد مجاز بیشتر است.',
        'UNSUPPORTED_MEDIA_TYPE' => 'نوع فایل یا بدنهٔ درخواست پشتیبانی نمی‌شود.',
        'INVALID_MULTIPART' => 'یک فایل را همراه با نوع محتوا ارسال کنید.',
        'RATE_LIMITED' => 'تعداد درخواست‌ها از حد مجاز بیشتر است؛ کمی بعد دوباره تلاش کنید.',
        'INTERNAL_ERROR' => 'هنگام پردازش درخواست خطای پیش‌بینی‌نشده‌ای رخ داد.',
        'NOT_FOUND' => 'نشانی درخواست پیدا نشد.',
    ];

    /** @param array<string, list<string>> $fields
     * @return array{message: string, fields: array<string, list<string>>}
     */
    public function present(string $code, string $message, array $fields, string $contentType = 'blog'): array
    {
        $message = $this->topLevelMessage($code, $message, $fields, $contentType);
        $presentedFields = [];
        foreach ($fields as $field => $messages) {
            foreach ($messages as $fieldMessage) {
                $presentedFields[$field][] = $this->fieldMessage($field, $code, $fieldMessage, $contentType);
            }
        }

        return ['message' => $message, 'fields' => $presentedFields];
    }

    /** @param array<string, mixed> $error
     * @return array<string, mixed>
     */
    public function presentImportError(array $error, string $contentType = 'blog'): array
    {
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'IMPORT_VALIDATION_FAILED';
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $fields = $error['fields'] ?? [];
        $fields = is_object($fields) ? get_object_vars($fields) : $fields;
        $fields = is_array($fields) ? $fields : [];
        $presented = $this->present($code, $message, $fields, $contentType);
        $error['message'] = $presented['message'];
        $error['fields'] = (object) $presented['fields'];

        $issues = is_array($error['issues'] ?? null) ? $error['issues'] : [];
        foreach ($issues as $index => $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $field = is_string($issue['field'] ?? null) ? $issue['field'] : 'file';
            $issueCode = is_string($issue['code'] ?? null) ? $issue['code'] : $code;
            $issue['label'] = $field === 'title' ? 'عنوان '.$this->contentLabel($contentType) : (self::LABELS[$field] ?? 'بخش سند');
            $issue['location'] = $this->persianText((string) ($issue['location'] ?? ''), 'بخش سند: '.$issue['label']);
            $issue['message'] = $this->fieldMessage($field, $issueCode, (string) ($issue['message'] ?? ''), $contentType);
            $error['issues'][$index] = $issue;
        }

        unset($error['report']);

        return $error;
    }

    private function fieldMessage(string $field, string $code, string $message, string $contentType = 'blog'): string
    {
        $label = self::LABELS[$field] ?? 'این بخش';
        if ($field === 'title') {
            return 'عنوان '.$this->contentLabel($contentType).' الزامی است و حداکثر می‌تواند ۲۰۰ نویسه باشد.';
        }
        if ($field === 'slug') {
            return $code === 'SLUG_TAKEN'
                ? self::MESSAGES['SLUG_TAKEN']
                : 'عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید؛ طول آن حداکثر ۲۰۰ نویسه باشد.';
        }
        if ($field === 'file') {
            return 'فایل انتخاب‌شده خراب، خالی یا پشتیبانی‌نشده است؛ یک فایل DOC یا DOCX سالم بفرستید.';
        }
        if ($field === 'reading_minutes') {
            return 'زمان مطالعه قابل‌استفاده نیست و خالی ذخیره شد.';
        }
        if ($field === 'category_id' || $field === 'category_name') {
            return 'دسته‌بندی قابل‌استفاده نیست و بدون دسته‌بندی ذخیره شد.';
        }
        if ($field === 'cover_image') {
            return 'تصویر جلد قابل‌استفاده نیست و بدون تصویر ذخیره شد.';
        }

        return $this->persianText($message, 'مقدار «'.$label.'» قابل‌استفاده نیست.');
    }

    /** @param array<string, list<string>> $fields */
    private function topLevelMessage(string $code, string $message, array $fields, string $contentType = 'blog'): string
    {
        if (in_array($code, ['ARTICLE_NOT_FOUND', 'NEWS_NOT_FOUND'], true)) {
            return $contentType === 'news' ? self::MESSAGES['NEWS_NOT_FOUND'] : self::MESSAGES['ARTICLE_NOT_FOUND'];
        }
        if ($code === 'VERSION_CONFLICT') {
            return $contentType === 'news'
                ? 'خبر هم‌زمان تغییر کرده است؛ نسخهٔ تازه را دریافت کنید.'
                : 'مقاله هم‌زمان تغییر کرده است؛ نسخهٔ تازه را دریافت کنید.';
        }
        if (in_array($code, ['VALIDATION_FAILED', 'IMPORT_VALIDATION_FAILED'], true)) {
            $titleFieldsOnly = $fields !== [] && array_diff(array_keys($fields), ['title', 'slug']) === [];
            if ($titleFieldsOnly) {
                return $code === 'IMPORT_VALIDATION_FAILED'
                    ? 'عنوان و عنوان انگلیسی سند را بررسی و اصلاح کنید.'
                    : 'عنوان و عنوان انگلیسی را بررسی کنید؛ سایر بخش‌ها اختیاری هستند.';
            }

            return $code === 'IMPORT_VALIDATION_FAILED'
                ? 'فایل یا ساختار سند قابل‌پردازش نیست؛ جزئیات فارسی هر بخش را بررسی کنید.'
                : 'ساختار درخواست یا محتوای '.$this->contentLabel($contentType).' با الزامات امنیتی سازگار نیست.';
        }

        return self::MESSAGES[$code] ?? $this->persianText($message, 'در پردازش درخواست خطایی رخ داد.');
    }

    private function contentLabel(string $contentType): string
    {
        return $contentType === 'news' ? 'خبر' : 'مقاله';
    }

    private function persianText(string $message, string $fallback): string
    {
        return preg_match('/[A-Za-z]/', $message) === 1 ? $fallback : $message;
    }
}
