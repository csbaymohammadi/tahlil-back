<?php

namespace App\Http\Requests\Admin;

use App\Blog\BlogHtmlSanitizer;
use App\Bonuses\BonusMedia;
use App\Exceptions\PanelAuthException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BonusRequest extends FormRequest
{
    public const EDITABLE = ['title', 'slug', 'description', 'cover_image', 'content', 'category_id', 'reading_minutes', 'meta_description', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['title', 'slug', 'description', 'cover_image', 'meta_description', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $required = $create ? 'required' : 'sometimes';

        return [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'title' => [$required, 'string', 'min:1', 'max:200'],
            'slug' => [$required, 'string', 'min:1', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'cover_image' => ['sometimes', 'string', 'max:2048'],
            'content' => [$required, 'string'],
            'category_id' => [$create ? 'present' : 'sometimes', 'nullable', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'reading_minutes' => [$required, 'integer:strict', 'min:1', 'max:1440'],
            'meta_description' => ['sometimes', 'string', 'max:500'],
            'status' => [$required, 'string', Rule::in(['draft', 'published'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...self::EDITABLE, ...($this->isMethod('PATCH') ? ['expected_version'] : [])]) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->isMethod('PATCH') && array_intersect(array_keys($this->all()), self::EDITABLE) === []) {
                $validator->errors()->add('expected_version', 'Supply at least one editable bonus field.');
            }
            foreach (['title', 'slug', 'status'] as $field) {
                if (array_key_exists($field, $this->all()) && $this->input($field) === '') {
                    $validator->errors()->add($field, 'This field cannot be empty.');
                }
            }
            foreach (['title', 'description', 'meta_description'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && strip_tags($value) !== $value) {
                    $validator->errors()->add($field, 'Enter plain text without HTML markup.');
                }
            }
            foreach (['reading_minutes', 'category_id'] as $field) {
                if (array_key_exists($field, $this->all()) && ! ($field === 'category_id' && $this->input($field) === null) && ! is_int($this->input($field))) {
                    $validator->errors()->add($field, 'Enter an integer value.');
                }
            }
            if (is_string($this->input('cover_image')) && ! app(BonusMedia::class)->approvedImage($this->input('cover_image'))) {
                $validator->errors()->add('cover_image', 'Use an approved HTTPS image or safe root-relative asset.');
            }
            $content = $this->input('content');
            if (is_string($content)) {
                try {
                    app(BlogHtmlSanitizer::class)->sanitize($content, config('bonuses.trusted_media_hosts'), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/']);
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['The content is not supported.'] as $message) {
                        $validator->errors()->add('content', $message);
                    }
                }
            }
        }];
    }
}
