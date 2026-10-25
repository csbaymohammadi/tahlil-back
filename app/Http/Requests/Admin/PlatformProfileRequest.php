<?php

namespace App\Http\Requests\Admin;

use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Support\PlatformProfileDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class PlatformProfileRequest extends FormRequest
{
    abstract protected function definition(): PlatformProfileDefinition;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $definition = $this->definition();
        $fields = ['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', ...$definition->summaryFields, 'meta_description', 'status'];
        $data = [];
        foreach ($fields as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $data[$field] = trim($value);
            }
        }
        $raw = json_decode($this->getContent());
        if ($raw instanceof \stdClass && ($raw->specifications ?? null) instanceof \stdClass) {
            foreach (get_object_vars($raw->specifications) as $key => $value) {
                if (is_string($value)) {
                    $raw->specifications->{$key} = trim($value);
                }
            }
            $data['specifications'] = get_object_vars($raw->specifications);
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $definition = $this->definition();
        $create = $this->isMethod('POST');
        $required = $create ? ['required'] : ['sometimes'];
        $specifications = $definition->specifications;
        $rules = [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'title' => [...$required, 'string', 'min:1', 'max:'.$definition->nameLimit],
            'english_name' => [...$required, 'string', 'min:1', 'max:'.$definition->nameLimit],
            'slug' => [...$required, 'string', 'min:1', 'max:'.$definition->nameLimit, 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'logo' => [$definition->logoRequired ? ($create ? 'required' : 'sometimes') : 'sometimes', 'string', 'max:2048'],
            'website_url' => [$definition->websiteRequired ? ($create ? 'required' : 'sometimes') : 'sometimes', 'string', 'max:2048'],
            'founded_year' => ['sometimes', 'string', 'regex:/^(?:|[0-9]{4})$/D'],
            'specifications' => ['sometimes'],
            'category_id' => [$create ? 'present' : 'sometimes', 'nullable', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'content' => [...$required, 'string'],
            'meta_description' => ['sometimes', 'string', 'max:500'],
            'status' => [...$required, 'string', Rule::in(['draft', 'published'])],
        ];
        foreach ($definition->summaryFields as $field) {
            $rules[$field] = ['sometimes', 'string', 'max:1500'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $definition = $this->definition();
            $data = $this->all();
            $keys = array_keys($this->rules());
            foreach (array_diff(array_keys($data), $keys) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }

            foreach (['title', 'english_name', 'description', 'founded_year', ...$definition->summaryFields, 'meta_description'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && strip_tags($value) !== $value) {
                    $validator->errors()->add($field, 'Enter plain text without HTML markup.');
                }
            }

            $editable = $definition->editableFields();
            if ($this->isMethod('PATCH') && count(array_intersect(array_keys($data), $editable)) === 0) {
                $validator->errors()->add('expected_version', 'Supply at least one profile field.');
            }

            foreach (['website_url' => $definition->websiteRequired, 'logo' => $definition->logoRequired] as $field => $required) {
                $value = $this->input($field);
                if (! is_string($value) || ($value === '' && ! $required)) {
                    continue;
                }
                $valid = $field === 'website_url' ? $this->isSafeWebsite($value) : $this->isApprovedLogoLocation($value);
                if (! $valid) {
                    $validator->errors()->add($field, $field === 'website_url' ? 'Enter an absolute HTTP or HTTPS URL without credentials.' : 'Use an approved HTTPS media URL or a safe root-relative image asset.');
                }
            }

            $raw = json_decode($this->getContent());
            if ($raw instanceof \stdClass && property_exists($raw, 'specifications')) {
                if (! $raw->specifications instanceof \stdClass) {
                    $validator->errors()->add('specifications', 'Specifications must be a JSON object.');
                } else {
                    $specifications = $definition->specifications;
                    foreach (get_object_vars($raw->specifications) as $key => $value) {
                        if (! in_array($key, $specifications::FIELDS, true)) {
                            $validator->errors()->add("specifications.{$key}", 'This specification is not supported.');
                        } elseif (! is_string($value)) {
                            $validator->errors()->add("specifications.{$key}", 'Enter a string value.');
                        } elseif (mb_strlen($value, 'UTF-8') > 1500) {
                            $validator->errors()->add("specifications.{$key}", 'This value may not exceed 1500 characters.');
                        } elseif (strip_tags($value) !== $value) {
                            $validator->errors()->add("specifications.{$key}", 'Enter plain text without HTML markup.');
                        } elseif (in_array($key, $specifications::STATUS_FIELDS, true) && ! in_array($value, ['', 'yes', 'no', 'not-applicable'], true)) {
                            $validator->errors()->add("specifications.{$key}", 'Select a supported status value.');
                        }
                    }
                }
            }

            $content = $this->input('content');
            if (is_string($content) && strlen($content) > 1_048_576) {
                $validator->errors()->add('content', 'The content may not exceed 1 MiB in UTF-8 bytes.');
            } elseif (is_string($content)) {
                try {
                    app(BlogHtmlSanitizer::class)->sanitize($content, config('platform_profiles.trusted_media_hosts', []), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/']);
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['The content is not supported.'] as $message) {
                        $validator->errors()->add('content', $message);
                    }
                }
            }
        }];
    }

    private function isSafeWebsite(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ! empty($parts['host']) &&
            ! isset($parts['user']) && ! isset($parts['pass']) && ! preg_match('/[\x00-\x20\x7f]/', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
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
            (! isset($parts['port']) || (int) $parts['port'] === 443) && in_array(strtolower($parts['host']), config('platform_profiles.trusted_media_hosts', []), true);
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
