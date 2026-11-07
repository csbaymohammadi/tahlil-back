<?php

namespace App\Http\Requests\Admin;

use App\Bonuses\BonusFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class BonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $raw = json_decode($this->getContent());
            if (is_object($raw) && property_exists($raw, 'category_id') && ! is_array($raw->category_id)) {
                $validator->errors()->add('category_id', 'Supply a JSON array.');
            }
            try {
                BonusFields::validate($this->all(), $this->isMethod('POST'));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    public function validated($key = null, $default = null): mixed
    {
        $values = BonusFields::validate($this->all(), $this->isMethod('POST'));

        return $key === null ? $values : data_get($values, $key, $default);
    }
}
