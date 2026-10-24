<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('panel.auth.login') && is_string($this->input('username'))) {
            $this->merge(['username' => Str::lower(trim($this->input('username')))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'panel.auth.login' => ['username' => ['required', 'string', 'min:3', 'max:120'], 'password' => ['present', 'string', 'min:8', 'max:256']],
            'panel.auth.logout-all' => ['current_password' => ['present', 'string', 'min:1', 'max:256']],
            'panel.auth.password' => ['current_password' => ['present', 'string', 'min:1', 'max:256'], 'new_password' => ['present', 'string', 'min:15', 'max:128']],
            default => [],
        };
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
