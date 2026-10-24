<?php

namespace App\Http\Requests\Admin;

use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Support\ExchangeSpecifications;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', 'platform_type', 'markets', 'access', 'support', 'meta_description'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $data[$field] = trim($value);
            }
        }
        $decoded = json_decode($this->getContent());
        if ($decoded instanceof \stdClass && ($decoded->specifications ?? null) instanceof \stdClass) {
            foreach (get_object_vars($decoded->specifications) as $key => $value) {
                if (is_string($value)) {
                    $decoded->specifications->{$key} = trim($value);
                }
            }
            $data['specifications'] = get_object_vars($decoded->specifications);
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $required = $create ? ['required'] : ['sometimes'];

        return [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'title' => [...$required, 'string', 'min:1', 'max:200'],
            'english_name' => [...$required, 'string', 'min:1', 'max:200'],
            'slug' => [...$required, 'string', 'min:1', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'logo' => [...$required, 'string', 'min:1', 'max:2048'],
            'website_url' => [...$required, 'string', 'url:http,https', 'max:2048'],
            'founded_year' => ['sometimes', 'string', 'regex:/^(?:|[0-9]{4})$/D'],
            'platform_type' => ['sometimes', 'string', 'max:1500'],
            'markets' => ['sometimes', 'string', 'max:1500'],
            'access' => ['sometimes', 'string', 'max:1500'],
            'support' => ['sometimes', 'string', 'max:1500'],
            'specifications' => ['sometimes'],
            'category_id' => [$create ? 'present' : 'sometimes', 'nullable', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'content' => [...$required, 'string', 'max:1048576'],
            'meta_description' => ['sometimes', 'string', 'max:500'],
            'status' => [...$required, 'string', Rule::in(['draft', 'published'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }

            $mutations = ['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', 'platform_type', 'markets', 'access', 'support', 'specifications', 'category_id', 'content', 'meta_description', 'status'];
            if ($this->isMethod('PATCH') && count(array_intersect(array_keys($this->all()), $mutations)) === 0) {
                $validator->errors()->add('expected_version', 'Supply at least one exchange field.');
            }

            if (is_string($this->input('website_url'))) {
                $parts = parse_url($this->input('website_url'));
                if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $this->input('website_url'))) {
                    $validator->errors()->add('website_url', 'Enter an absolute HTTP or HTTPS URL without credentials.');
                }
            }

            if (is_string($this->input('logo')) && ! $this->isApprovedLogoLocation($this->input('logo'))) {
                $validator->errors()->add('logo', 'Use an approved HTTPS media URL or a safe root-relative image asset.');
            }

            $raw = json_decode($this->getContent());
            if ($raw instanceof \stdClass && property_exists($raw, 'specifications')) {
                $specifications = $raw->specifications;
                if (! $specifications instanceof \stdClass) {
                    $validator->errors()->add('specifications', 'Specifications must be a JSON object.');
                } else {
                    foreach (get_object_vars($specifications) as $key => $value) {
                        if (! in_array($key, ExchangeSpecifications::FIELDS, true)) {
                            $validator->errors()->add("specifications.{$key}", 'This specification is not supported.');
                        } elseif (! is_string($value)) {
                            $validator->errors()->add("specifications.{$key}", 'Enter a string value.');
                        } elseif (mb_strlen($value) > 1500) {
                            $validator->errors()->add("specifications.{$key}", 'This value may not exceed 1500 characters.');
                        } elseif (in_array($key, ExchangeSpecifications::STATUS_FIELDS, true) && ! in_array($value, ['', 'yes', 'no', 'not-applicable'], true)) {
                            $validator->errors()->add("specifications.{$key}", 'Select a supported status value.');
                        }
                    }
                }
            }

            if (is_string($this->input('content')) && strlen($this->input('content')) <= 1_048_576) {
                try {
                    app(BlogHtmlSanitizer::class)->sanitize(
                        $this->input('content'),
                        config('exchanges.trusted_media_hosts', []),
                        strictLinks: true,
                        rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'],
                    );
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['The content is not supported.'] as $message) {
                        $validator->errors()->add('content', $message);
                    }
                }
            }
        }];
    }

    private function isApprovedLogoLocation(string $logo): bool
    {
        if (str_starts_with($logo, '/')) {
            if (preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $logo)) {
                return true;
            }

            return $this->isSafeRootAsset($logo);
        }

        $parts = parse_url($logo);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) &&
            (! isset($parts['port']) || (int) $parts['port'] === 443) && in_array(strtolower($parts['host']), config('exchanges.trusted_media_hosts', []), true);
    }

    private function isSafeRootAsset(string $path): bool
    {
        if (str_contains($path, '\\') || preg_match('/[%?#\x00-\x20\x7f]/', $path) || str_starts_with($path, '//') ||
            (! str_starts_with($path, '/images/') && ! str_starts_with($path, '/tahlilpanel/images/'))) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                return false;
            }
        }

        return true;
    }
}
