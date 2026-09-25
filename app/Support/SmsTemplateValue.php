<?php

namespace App\Support;

/**
 * Keeps placeholder values tidy: no nulls leaking into a text message, and
 * no accidental "Array to string conversion" when a relation slips through.
 */
class SmsTemplateValue
{
    /**
     * @param  array<string,mixed>  $values
     * @return array<string,string>
     */
    public static function clean(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $out[$key] = trim((string) $value);
        }

        return $out;
    }
}
