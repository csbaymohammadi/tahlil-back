<?php

namespace App\Bonuses;

use App\Models\Bonus;
use App\Models\BonusCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BonusFields
{
    public const TEXT = ['provider_name', 'display_status', 'amount', 'start_date', 'end_date', 'short_description',
        'identity_verification', 'iranian_users', 'minimum_deposit', 'website_url'];

    public const CARD = ['provider_name', 'display_status', 'amount', 'start_date', 'end_date'];

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate(array $input, bool $creating): array
    {
        $version = Validator::make($input, [
            'expected_version' => $creating ? ['prohibited'] : ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
        ])->validate();
        $values = array_intersect_key($input, array_flip([...self::TEXT, 'cover_image', 'content', 'category_id', 'is_published']));
        foreach ($values as $field => $value) {
            $valid = match ($field) {
                'category_id' => is_array($value) && array_is_list($value),
                'is_published' => is_bool($value),
                default => is_string($value),
            };
            if (! $valid) {
                throw ValidationException::withMessages([$field => ['Supply the documented JSON type.']]);
            }
        }
        $values += $version;
        foreach ($values['category_id'] ?? [] as $index => $id) {
            if (! is_int($id) && ! is_float($id)) {
                throw ValidationException::withMessages(['category_id.'.$index => ['Supply a numeric category ID.']]);
            }
        }

        return $values;
    }

    /** @return array<string, string> */
    public static function values(Bonus $bonus): array
    {
        return array_replace(array_fill_keys(self::TEXT, ''), $bonus->display_fields ?? []);
    }

    /** @param list<int|float> $ids
     * @return list<int>
     */
    public static function categories(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids,
            fn (int|float $id): bool => is_finite((float) $id) && $id >= 1 && $id <= 9007199254740991 && floor($id) == $id))));
        $available = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (BonusCategory::whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id') as $id) {
                $available[(int) $id] = true;
            }
        }

        return array_values(array_filter($ids, fn (int $id): bool => isset($available[$id])));
    }
}
