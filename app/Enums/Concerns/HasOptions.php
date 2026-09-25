<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    /** @return array<string,string> value => human label */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromLabel(string $label): ?static
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->label(), $label) === 0 || strcasecmp($case->value, $label) === 0) {
                return $case;
            }
        }

        return null;
    }
}
