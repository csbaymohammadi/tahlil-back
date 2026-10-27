<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicBonusRequest extends FormRequest
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
        return ['q' => ['sometimes', 'string', 'max:200', 'regex:/\A[\s\S]*\z/u'], 'category' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'], 'per_page' => ['sometimes', 'in:9']];
    }
}
