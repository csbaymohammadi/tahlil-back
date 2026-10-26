<?php

namespace App\Http\Requests\Admin;

use App\PageSettings\SettingsDefinition;
use App\PageSettings\SettingsMedia;
use App\PageSettings\SettingsUrls;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PageSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $trim = function (mixed $value) use (&$trim): mixed {
            if (is_array($value)) {
                return array_map($trim, $value);
            }

            return is_string($value) ? trim($value) : $value;
        };
        $this->merge($trim($this->all()));
    }

    public function rules(): array
    {
        $text = ['required', 'string'];
        $ids = ['required', 'integer:strict', 'min:1', 'max:9007199254740991', 'distinct:strict'];
        $rules = [
            'expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'hero' => ['required', 'array:title,accent,description,primary_label,primary_url,secondary_label,secondary_mobile_label,secondary_url'],
            'hero.title' => [...$text, 'max:200'], 'hero.accent' => ['present', 'string', 'max:200'], 'hero.description' => [...$text, 'max:2000'],
            'carousels' => ['present', 'array', 'list', 'size:6'],
            'carousels.*' => ['required', 'array:key,kind,title,description,action_label,action_url,selected_ids'],
            'carousels.*.key' => ['required', Rule::in(array_keys(SettingsDefinition::HOME)), 'distinct:strict'],
            'carousels.*.kind' => ['required', Rule::in(['exchange', 'video', 'news', 'bonus', 'article'])],
            'carousels.*.title' => [...$text, 'max:200'], 'carousels.*.description' => ['present', 'string', 'max:2000'],
            'carousels.*.action_label' => [...$text, 'max:120'], 'carousels.*.action_url' => [...$text, 'max:2048'],
            'carousels.*.selected_ids' => ['present', 'array', 'list'], 'carousels.*.selected_ids.*' => array_slice($ids, 0, 4),
            'menu' => ['present', 'array', 'list', 'size:5'], 'menu.*' => ['required', 'array:key,selected_ids'],
            'menu.*.key' => ['required', Rule::in(array_keys(SettingsDefinition::MENU)), 'distinct:strict'],
            'menu.*.selected_ids' => ['present', 'array', 'list', 'max:9'], 'menu.*.selected_ids.*' => array_slice($ids, 0, 4),
            'links' => ['present', 'array:telegram,instagram,youtube,aparat'],
            'advertisements' => ['present', 'array:home_header,news_and_articles'],
            'infinite_slider' => ['present', 'array', 'list'], 'infinite_slider.*' => ['required', 'array:id,logo_src,name,english_name'],
            'infinite_slider.*.id' => [...$text, 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', 'distinct:strict'],
            'infinite_slider.*.logo_src' => [...$text, 'max:2048'], 'infinite_slider.*.name' => [...$text, 'max:120'], 'infinite_slider.*.english_name' => [...$text, 'max:120'],
        ];
        foreach (['primary_label', 'secondary_label', 'secondary_mobile_label'] as $key) {
            $rules['hero.'.$key] = [...$text, 'max:120'];
        }
        foreach (['primary_url', 'secondary_url'] as $key) {
            $rules['hero.'.$key] = [...$text, 'max:2048'];
        }
        foreach (SettingsDefinition::NETWORKS as $key) {
            $rules['links.'.$key] = ['present', 'string', 'max:2048'];
        }
        foreach (['home_header', 'news_and_articles'] as $key) {
            $rules['advertisements.'.$key] = ['present', 'array', 'list'];
            $rules['advertisements.'.$key.'.*'] = ['required', 'array:image,link'];
            $rules['advertisements.'.$key.'.*.image'] = [...$text, 'max:2048'];
            $rules['advertisements.'.$key.'.*.link'] = [...$text, 'max:2048'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['expected_version', 'hero', 'carousels', 'menu', 'links', 'advertisements', 'infinite_slider']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            $urls = app(SettingsUrls::class);
            $media = app(SettingsMedia::class);
            foreach (Arr::dot($this->all()) as $field => $value) {
                if (! is_string($value)) {
                    continue;
                }
                if (preg_match('/(?:^hero\.(?:title|accent|description|.*label)|\.(?:title|description|action_label|name|english_name))$/D', $field) && strip_tags($value) !== $value) {
                    $validator->errors()->add($field, 'Enter plain text.');
                }
                $social = str_starts_with($field, 'links.');
                if (($social && $value !== '') || preg_match('/(?:_url|\.link)$/D', $field)) {
                    if ($urls->destination($value, $social) === null) {
                        $validator->errors()->add($field, 'Enter a safe HTTP/HTTPS URL, internal path or fragment.');
                    }
                }
                if (preg_match('/(?:\.image|\.logo_src)$/D', $field) && ! $media->approved($value)) {
                    $validator->errors()->add($field, 'Choose an approved image.');
                }
            }
            $title = $this->input('hero.title');
            $accent = $this->input('hero.accent');
            if (is_string($title) && is_string($accent) && $accent !== '' && ! str_contains($title, $accent)) {
                $validator->errors()->add('hero.accent', 'The gradient text must be part of the complete title.');
            }
            foreach (['carousels', 'menu'] as $area) {
                $rows = $this->input($area);
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $index => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if ($area === 'carousels' && isset($row['key']) && is_string($row['key']) && ($row['kind'] ?? null) !== (SettingsDefinition::HOME[$row['key']] ?? null)) {
                        $validator->errors()->add($area.'.'.$index.'.kind', 'Use the fixed section kind.');
                    }
                    $ids = $row['selected_ids'] ?? null;
                    if (is_array($ids) && count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
                        $validator->errors()->add($area.'.'.$index.'.selected_ids', 'Select each record only once within this list.');
                    }
                }
            }
        }];
    }
}
