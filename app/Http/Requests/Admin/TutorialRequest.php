<?php

namespace App\Http\Requests\Admin;

use App\Tutorials\TutorialMedia;
use App\Tutorials\TutorialProviders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class TutorialRequest extends FormRequest
{
    public const EDITABLE = ['title', 'description', 'youtube_url', 'aparat_url', 'category_id', 'cover_image'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (self::EDITABLE as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'youtube_url' => [$required, 'string', 'max:2048'],
            'aparat_url' => ['sometimes', 'string', 'max:2048'],
            'category_id' => [$required, 'integer:strict', 'min:1', 'max:9007199254740991'],
            'cover_image' => [$required, 'string', 'max:2048'],
            'expected_version' => $this->isMethod('PATCH') ? ['required', 'integer:strict', 'min:1', 'max:9007199254740991'] : ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...self::EDITABLE, ...($this->isMethod('PATCH') ? ['expected_version'] : [])];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->isMethod('PATCH') && array_intersect(array_keys($this->all()), self::EDITABLE) === []) {
                $validator->errors()->add('expected_version', 'Supply an editable tutorial field.');
            }
            foreach (['title', 'youtube_url', 'cover_image'] as $field) {
                if (array_key_exists($field, $this->all()) && $this->input($field) === '') {
                    $validator->errors()->add($field, 'This field cannot be empty.');
                }
            }
            if (array_key_exists('category_id', $this->all()) && ! is_int($this->input('category_id'))) {
                $validator->errors()->add('category_id', 'Choose a live tutorial category ID.');
            }
            $title = $this->input('title');
            if (is_string($title) && strip_tags($title) !== $title) {
                $validator->errors()->add('title', 'Enter a plain text title.');
            }
            foreach (['youtube', 'aparat'] as $provider) {
                $url = $this->input($provider.'_url');
                if (! is_string($url) || ($provider === 'aparat' && $url === '')) {
                    continue;
                }
                try {
                    app(TutorialProviders::class)->parse($provider, $url);
                } catch (ValidationException $exception) {
                    $validator->errors()->add($provider.'_url', $exception->errors()[$provider.'_url'][0]);
                }
            }
            if (is_string($this->input('cover_image')) && ! app(TutorialMedia::class)->approvedImage($this->input('cover_image'))) {
                $validator->errors()->add('cover_image', 'Use a trusted HTTPS image or approved root-relative asset.');
            }
        }];
    }
}
