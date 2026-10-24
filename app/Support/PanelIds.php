<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PanelIds
{
    public static function parse(string $value): int
    {
        if (! preg_match('/^[1-9][0-9]{0,15}$/D', $value) || (float) $value > 9007199254740991) {
            throw ValidationException::withMessages(['id' => ['Use a positive safe integer identifier.']]);
        }

        return (int) $value;
    }
}
