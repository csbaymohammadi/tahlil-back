<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePlatformRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['value' => ['required', 'integer:strict', 'min:1', 'max:5']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['value']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
