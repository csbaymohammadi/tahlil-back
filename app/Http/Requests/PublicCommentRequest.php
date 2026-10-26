<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublicCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['name', 'email', 'text'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:80'], 'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'text' => ['required', 'string', 'min:10', 'max:2000']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['name', 'email', 'text']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            foreach (['name', 'text'] as $field) {
                if (is_string($this->input($field)) && preg_match('/^[\s\x{00A0}\x{200B}]*$/u', $this->input($field))) {
                    $validator->errors()->add($field, 'Enter visible plain text.');
                }
            }
        }];
    }
}
