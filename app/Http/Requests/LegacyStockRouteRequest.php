<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LegacyStockRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_path' => ['required', 'string', 'max:2048', 'regex:~^/brokers/[a-z0-9]+(?:-[a-z0-9]+)*$~D']];
    }
}
