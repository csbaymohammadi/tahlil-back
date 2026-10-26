<?php

namespace App\Support;

use App\Exceptions\PanelAuthException;

class StrictJson
{
    public static function settingsShape(\stdClass $body): void
    {
        $fields = [];
        foreach (['hero', 'links', 'advertisements'] as $key) {
            if (property_exists($body, $key) && ! $body->$key instanceof \stdClass) {
                $fields[$key] = ['Send a JSON object.'];
            }
        }
        foreach (['carousels', 'menu', 'infinite_slider'] as $key) {
            if (! property_exists($body, $key)) {
                continue;
            }
            if (! is_array($body->$key)) {
                $fields[$key] = ['Send a JSON array.'];

                continue;
            }
            foreach ($body->$key as $index => $row) {
                if (! $row instanceof \stdClass) {
                    $fields[$key.'.'.$index] = ['Send a JSON object.'];
                } elseif (property_exists($row, 'selected_ids') && ! is_array($row->selected_ids)) {
                    $fields[$key.'.'.$index.'.selected_ids'] = ['Send a JSON array.'];
                }
            }
        }
        if (($body->advertisements ?? null) instanceof \stdClass) {
            foreach (get_object_vars($body->advertisements) as $key => $rows) {
                if (! is_array($rows)) {
                    $fields['advertisements.'.$key] = ['Send a JSON array.'];

                    continue;
                }
                foreach ($rows as $index => $row) {
                    if (! $row instanceof \stdClass) {
                        $fields['advertisements.'.$key.'.'.$index] = ['Send a JSON object.'];
                    }
                }
            }
        }
        if ($fields !== []) {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Check the JSON field shapes.', $fields);
        }
    }

    public static function rejectDuplicateKeys(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\],:]|[^\\s{}\\[\\],:]+/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => true, 'seen' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack !== []) {
                $index = count($stack) - 1;
                if ($token === ',') {
                    $stack[$index]['key'] = true;
                } elseif ($token[0] === '"' && $stack[$index]['object'] && $stack[$index]['key']) {
                    $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
                    if (isset($stack[$index]['seen'][$key])) {
                        throw new PanelAuthException(400, 'INVALID_JSON', 'Duplicate JSON object keys are not accepted.');
                    }
                    $stack[$index]['seen'][$key] = true;
                    $stack[$index]['key'] = false;
                }
            }
        }
    }
}
