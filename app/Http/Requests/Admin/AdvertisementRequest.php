<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdvertisementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $integer = ['required', 'integer:strict', 'min:1', 'max:9007199254740991'];
        if ($this->routeIs('panel.advertisements.order')) {
            return ['items' => ['present', 'array', 'list'], 'items.*' => ['required', 'array:id,expected_version'],
                'items.*.id' => [...$integer, 'distinct:strict'], 'items.*.expected_version' => $integer];
        }
        $version = ['required', 'string', 'regex:/^[1-9][0-9]{0,15}$/D', 'integer', 'max:9007199254740991'];
        if ($this->isMethod('DELETE')) {
            return ['expected_version' => $version];
        }
        $create = $this->routeIs('panel.advertisements.store');
        $required = $create ? ['required'] : ['sometimes', 'required'];

        return ['img' => [...$required, 'file'], 'title' => [...$required, 'string', 'max:200'],
            'description' => [...$required, 'string', 'max:2000'], 'show' => [...$required, 'string', Rule::in(['0', '1'])],
            ...($create ? [] : ['expected_version' => $version])];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            foreach (['title', 'description'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && strip_tags($value) !== $value) {
                    $validator->errors()->add($field, 'Enter plain text.');
                }
            }
            if ($this->routeIs('panel.advertisements.update') && array_intersect(array_keys($this->all()), ['img', 'title', 'description', 'show']) === []) {
                $validator->errors()->add('body', 'Supply at least one editable field.');
            }
        }];
    }
}
