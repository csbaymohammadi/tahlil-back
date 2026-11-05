<?php

namespace App\Http\Requests\Admin;

use App\Blog\BlogHtmlSanitizer;
use App\Bonuses\BonusMedia;
use App\Exceptions\PanelAuthException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BonusRequest extends FormRequest
{
    public const EDITORIAL = ['title', 'slug', 'description', 'cover_image', 'content', 'category_id', 'reading_minutes', 'meta_description', 'status'];

    public const EDITABLE = [...self::EDITORIAL, 'campaign'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['title', 'slug', 'description', 'cover_image', 'meta_description', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $required = $create ? 'required' : 'sometimes';

        return [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'title' => [$required, 'string', 'min:1', 'max:200'],
            'slug' => [$required, 'string', 'min:1', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'cover_image' => ['sometimes', 'string', 'max:2048'],
            'content' => ['sometimes', 'string'],
            'category_id' => ['sometimes', 'array', 'list'],
            'category_id.*' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991', 'distinct:strict'],
            'reading_minutes' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:1440'],
            'meta_description' => ['sometimes', 'string', 'max:500'],
            'campaign' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $raw = json_decode($this->getContent());
            if (is_object($raw) && property_exists($raw, 'category_id') && ! is_array($raw->category_id)) {
                $validator->errors()->add('category_id', 'Supply a JSON array of category IDs.');
            }
            if (is_object($raw) && property_exists($raw, 'campaign')) {
                $this->validateCampaignShape($raw->campaign, $validator);
            }
            foreach (array_diff(array_keys($this->all()), [...self::EDITABLE, ...($this->isMethod('PATCH') ? ['expected_version'] : [])]) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->isMethod('PATCH') && array_intersect(array_keys($this->all()), self::EDITABLE) === []) {
                $validator->errors()->add('expected_version', 'Supply at least one editable bonus field.');
            }
            foreach (['title', 'slug', 'status'] as $field) {
                if (array_key_exists($field, $this->all()) && $this->input($field) === '') {
                    $validator->errors()->add($field, 'This field cannot be empty.');
                }
            }
            foreach (['title', 'description', 'meta_description'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && strip_tags($value) !== $value) {
                    $validator->errors()->add($field, 'Enter plain text without HTML markup.');
                }
            }
            $minutes = $this->input('reading_minutes');
            if ($minutes !== null && ! is_int($minutes)) {
                $validator->errors()->add('reading_minutes', 'Enter an integer value or null.');
            }
            if (is_string($this->input('cover_image')) && ! app(BonusMedia::class)->approvedImage($this->input('cover_image'))) {
                $validator->errors()->add('cover_image', 'Use an approved HTTPS image or safe root-relative asset.');
            }
            $content = $this->input('content');
            if (is_string($content)) {
                try {
                    app(BlogHtmlSanitizer::class)->sanitize($content, config('bonuses.trusted_media_hosts'), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);
                } catch (PanelAuthException $exception) {
                    foreach ($exception->fields['content'] ?? ['The content is not supported.'] as $message) {
                        $validator->errors()->add('content', $message);
                    }
                }
            }
        }];
    }

    private function validateCampaignShape(mixed $campaign, Validator $validator): void
    {
        if (! is_object($campaign)) {
            $validator->errors()->add('campaign', 'Supply a JSON object.');

            return;
        }
        foreach (['provider', 'reward', 'eligibility', 'conditions', 'redemption'] as $field) {
            if (property_exists($campaign, $field) && ! is_object($campaign->{$field}) &&
                ! ($field === 'provider' && $campaign->provider === null)) {
                $validator->errors()->add('campaign.'.$field, 'Supply a JSON object.');
            }
        }
        $redemption = $campaign->redemption ?? null;
        if (is_object($redemption) && property_exists($redemption, 'steps') && ! is_array($redemption->steps)) {
            $validator->errors()->add('campaign.redemption.steps', 'Supply a JSON array.');
        }
    }
}
