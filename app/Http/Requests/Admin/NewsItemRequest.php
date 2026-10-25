<?php

namespace App\Http\Requests\Admin;

use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class NewsItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'min:1', 'max:200'],
            'slug' => [$create ? 'required' : 'sometimes', 'string', 'min:1', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['sometimes'],
            'cover_image' => ['sometimes'],
            'content' => ['sometimes'],
            'category_id' => ['sometimes'],
            'reading_minutes' => ['sometimes'],
            'meta_description' => ['sometimes'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'scheduled'])],
            'scheduled_for' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->isMethod('PATCH') && count(array_intersect(array_keys($this->all()), ['title', 'slug', 'description', 'cover_image', 'content', 'category_id', 'reading_minutes', 'meta_description', 'status', 'scheduled_for'])) === 0) {
                $validator->errors()->add('expected_version', 'Supply at least one editable news item field.');
            }
            if (is_string($this->input('content')) && strlen($this->input('content')) <= 1_048_576) {
                try {
                    app(BlogHtmlSanitizer::class)->sanitize(
                        $this->input('content'),
                        config('news.trusted_media_hosts', []),
                        rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'],
                        allowEmpty: true,
                    );
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['The content is not supported.'] as $message) {
                        $validator->errors()->add('content', $message);
                    }
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'عنوان خبر را وارد کنید.',
            'title.string' => 'عنوان خبر باید متن باشد.',
            'title.min' => 'عنوان خبر را وارد کنید.',
            'title.max' => 'عنوان خبر حداکثر می‌تواند ۲۰۰ نویسه باشد.',
            'slug.required' => 'عنوان انگلیسی خبر را وارد کنید.',
            'slug.string' => 'عنوان انگلیسی باید متن باشد.',
            'slug.min' => 'عنوان انگلیسی خبر را وارد کنید.',
            'slug.max' => 'عنوان انگلیسی حداکثر می‌تواند ۲۰۰ نویسه باشد.',
            'slug.regex' => 'عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید.',
            'expected_version.required' => 'نسخهٔ فعلی خبر را بفرستید.',
            'expected_version.integer' => 'نسخهٔ خبر معتبر نیست.',
            'expected_version.min' => 'نسخهٔ خبر معتبر نیست.',
            'status.in' => 'وضعیت انتشار خبر معتبر نیست.',
            'scheduled_for.regex' => 'زمان انتشار را با قالب استاندارد تاریخ ارسال کنید.',
        ];
    }
}
