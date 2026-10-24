<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['purpose' => ['required', 'string', Rule::in(config('media.purposes'))], 'file' => ['required', 'file']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['purpose', 'file']) as $key) {
                $validator->errors()->add($key, 'This field is not accepted.');
            }
            if (! is_string($this->header('Idempotency-Key')) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $this->header('Idempotency-Key'))) {
                $validator->errors()->add('idempotency_key', 'Supply a UUID Idempotency-Key header.');
            }
        }];
    }
}
