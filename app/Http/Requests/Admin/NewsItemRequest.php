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
        $required = $create ? ['required'] : ['sometimes'];
        $categoryRequired = $create ? ['present'] : ['sometimes'];

        return [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'title' => [...$required, 'string', 'min:1', 'max:200'],
            'slug' => [...$required, 'string', 'min:1', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'cover_image' => ['sometimes', 'string', 'max:2048'],
            'content' => [...$required, 'string', 'max:1048576'],
            'category_id' => [...$categoryRequired, 'nullable', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'reading_minutes' => [...$required, 'integer:strict', 'min:1', 'max:1440'],
            'meta_description' => ['sometimes', 'string', 'max:500'],
            'status' => [...$required, 'string', Rule::in(['draft', 'published', 'scheduled'])],
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
                    app(BlogHtmlSanitizer::class)->sanitize($this->input('content'), config('news.trusted_media_hosts', []));
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['The content is not supported.'] as $message) {
                        $validator->errors()->add('content', $message);
                    }
                }
            }
        }];
    }
}
