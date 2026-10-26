<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublicSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge(['q' => trim($this->query('q'))]);
        }
    }

    public function rules(): array
    {
        return ['q' => ['sometimes', 'string', 'max:200'], 'surface' => ['sometimes', 'in:header,page'],
            'type' => ['sometimes', 'in:all,articles,news,tutorials'],
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'min:1', 'max:50']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('surface', 'page') === 'page' && $this->input('type', 'all') !== 'all') {
                $validator->errors()->add('type', 'Page search accepts only all.');
            }
        }];
    }
}
