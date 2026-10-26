<?php

namespace App\Http\Requests\Admin;

use App\PageSettings\SettingsUrls;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'linkedin_url', 'bio'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return ['expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'name' => ['sometimes', 'required', 'string', 'max:100'], 'linkedin_url' => ['sometimes', 'string', 'max:2048'], 'bio' => ['sometimes', 'string', 'max:5000'],
            'avatar_media_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:9007199254740991']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if (array_intersect(array_keys($this->all()), ['name', 'linkedin_url', 'bio', 'avatar_media_id']) === []) {
                $validator->errors()->add('expected_version', 'Supply at least one profile field.');
            }
            foreach (['name', 'bio'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && strip_tags($value) !== $value) {
                    $validator->errors()->add($field, 'Enter plain text.');
                }
            }
            $url = $this->input('linkedin_url');
            if (is_string($url) && app(SettingsUrls::class)->linkedin($url) === null) {
                $validator->errors()->add('linkedin_url', 'Enter an HTTP/HTTPS URL on linkedin.com or its subdomains.');
            }
        }];
    }
}
