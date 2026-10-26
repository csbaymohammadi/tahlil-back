<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CommentReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('text'))) {
            $this->merge(['text' => trim($this->input('text'))]);
        }
    }

    public function rules(): array
    {
        return ['text' => ['required', 'string', 'min:1', 'max:5000'], 'expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['text', 'expected_version']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if (is_string($this->input('text')) && preg_match('/^[\s\x{00A0}\x{200B}]*$/u', $this->input('text'))) {
                $validator->errors()->add('text', 'Enter visible reply text.');
            }
        }];
    }
}
