<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class NewsCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('panel.news.categories.merge')) {
            return [
                'expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
                'target_category_id' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
                'target_expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            ];
        }

        return ['name' => ['required', 'string', 'min:1', 'max:80']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
