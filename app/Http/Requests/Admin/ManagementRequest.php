<?php

namespace App\Http\Requests\Admin;

use App\Support\RoleNames;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'username', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $value = Str::trim($this->input($field));
                $this->merge([$field => $field === 'email' ? mb_strtolower($value) : $value]);
            }
        }
    }

    public function rules(): array
    {
        $id = ['required', 'integer:strict', 'min:1', 'max:9007199254740991'];
        $queryInteger = ['required', 'regex:/^[1-9][0-9]*$/', 'integer', 'max:9007199254740991'];
        $route = $this->route()->getName();
        if ($this->isMethod('DELETE')) {
            return ['expected_version' => $queryInteger];
        }
        if ($route === 'panel.users.index') {
            return ['page' => ['sometimes', ...$queryInteger], 'per_page' => ['sometimes', ...$queryInteger, Rule::in([10, 20, 50])]];
        }
        if ($this->isMethod('GET')) {
            return [];
        }
        if (str_starts_with($route, 'panel.roles.')) {
            return ['name' => ['required', 'string', 'min:1', 'max:80'], ...($this->isMethod('PATCH') ? ['expected_version' => $id] : [])];
        }
        $create = $this->isMethod('POST');
        $required = $create ? ['required'] : ['sometimes', 'required'];

        return [
            'username' => [...$required, 'string', 'min:3', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => [...$required, 'string', 'min:1', 'max:100'],
            'email' => [...$required, 'string', 'email', 'max:254'],
            'password' => [$create ? 'present' : 'sometimes', 'string', ...($create || $this->input('password') !== '' ? ['min:15'] : []), 'max:128'],
            'role_id' => [...$required, 'integer:strict', 'min:1', 'max:9007199254740991'],
            'is_active' => [...$required, 'boolean:strict'],
            'avatar_media_id' => ['sometimes', ...($create ? ['nullable'] : ['required']), 'integer:strict', 'min:1', 'max:9007199254740991'],
            ...($create ? [] : ['expected_version' => $id]),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->routeIs('panel.roles.store', 'panel.roles.update') && is_string($this->input('name')) && RoleNames::normalize($this->input('name')) === '') {
                $validator->errors()->add('name', 'Use a visible role name.');
            }
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->routeIs('panel.users.update') && count(array_intersect(array_keys($this->all()), ['username', 'name', 'email', 'password', 'role_id', 'is_active', 'avatar_media_id'])) === 0) {
                $validator->errors()->add('expected_version', 'Supply at least one editable field.');
            }
        }];
    }
}
