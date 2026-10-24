<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ContentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_type' => ['required', 'string', Rule::in(['blog', 'news'])],
            'file' => ['required', 'file', 'max:20480', 'extensions:doc,docx'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['content_type', 'file']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            $key = $this->header('Idempotency-Key');
            if (! is_string($key) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key)) {
                $validator->errors()->add('idempotency_key', 'Supply a UUID Idempotency-Key header.');
            }
        }];
    }
}
