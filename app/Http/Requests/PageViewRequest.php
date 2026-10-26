<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PageViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['event_id' => ['required', 'string', 'uuid'], 'path' => ['required', 'string', 'max:2048']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['event_id', 'path']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
