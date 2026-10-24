<?php

namespace App\Http\Requests\Admin;

use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BlogArticleRequest extends FormRequest
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
                $validator->errors()->add('expected_version', 'برای ویرایش، دست‌کم یک فیلد مقاله را بفرستید.');
            }
            if (is_string($this->input('content')) && strlen($this->input('content')) <= 1_048_576) {
                try {
                    app(BlogHtmlSanitizer::class)->sanitize($this->input('content'), allowEmpty: true);
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['ساختار محتوا پشتیبانی نمی‌شود.'] as $message) {
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
            'title.required' => 'عنوان مقاله را وارد کنید.',
            'title.string' => 'عنوان مقاله باید متن باشد.',
            'title.min' => 'عنوان مقاله را وارد کنید.',
            'title.max' => 'عنوان مقاله حداکثر می‌تواند ۲۰۰ نویسه باشد.',
            'slug.required' => 'عنوان انگلیسی مقاله را وارد کنید.',
            'slug.string' => 'عنوان انگلیسی باید متن باشد.',
            'slug.min' => 'عنوان انگلیسی مقاله را وارد کنید.',
            'slug.max' => 'عنوان انگلیسی حداکثر می‌تواند ۲۰۰ نویسه باشد.',
            'slug.regex' => 'عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید.',
            'expected_version.required' => 'نسخه فعلی مقاله را بفرستید.',
            'expected_version.integer' => 'نسخه مقاله معتبر نیست.',
            'expected_version.min' => 'نسخه مقاله معتبر نیست.',
            'status.required' => 'وضعیت انتشار را مشخص کنید.',
            'status.in' => 'وضعیت انتشار معتبر نیست.',
            'scheduled_for.regex' => 'زمان انتشار را با قالب استاندارد تاریخ ارسال کنید.',
        ];
    }
}
