<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExchangeCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST') && ! $this->routeIs('panel.exchanges.categories.merge');

        return [
            'name' => $creating ? ['required', 'string', 'min:1', 'max:80'] : ['prohibited'],
            'expected_version' => $creating ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'target_category_id' => $this->routeIs('panel.exchanges.categories.merge') ? ['required', 'integer:strict', 'min:1', 'max:9007199254740991'] : ['prohibited'],
            'target_expected_version' => $this->routeIs('panel.exchanges.categories.merge') ? ['required', 'integer:strict', 'min:1', 'max:9007199254740991'] : ['prohibited'],
        ];
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
