<?php

namespace App\Http\Requests;

use App\Support\PublicContentIndex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SitemapEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => ['sometimes', Rule::in(['all', ...array_keys(PublicContentIndex::TYPES)])],
            'limit' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'string', 'min:1', 'max:8192']];
    }
}
