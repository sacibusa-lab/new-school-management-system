<?php

namespace App\Support;

/**
 * The many shapes one registration number can be written in.
 *
 * A parent types "sac 2", a school photographs a candidate and names the file
 * "SAC-1.jpg", and the number printed on the slip is "SAC-00001". All three are
 * the same child, and every screen that has to find one must agree on that — so
 * the rule lives here rather than being reinvented in each caller.
 */
class RegistrationNumber
{
    /**
     * Everything that is not a letter or a digit is noise.
     *
     * "SAC/2026/001" and "sac-2026-001" both become SAC2026001.
     */
    public static function clean(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? '');
    }

    /**
     * Every spelling of a number that should find the same record.
     *
     * @return array<int,string>
     */
    public static function variants(string $value, int $padding = 5): array
    {
        $clean = self::clean($value);

        if ($clean === '') {
            return [];
        }

        $variants = [$clean];

        // Split "SAC00002" into letters and digits so the digits can be padded.
        if (preg_match('/^([A-Z]*)(\d+)$/', $clean, $matches)) {
            [, $letters, $digits] = $matches;
            $padTo = max($padding, 1);

            // Plain: pad the whole digit group. "SAC-1" -> SAC00001
            $variants[] = $letters . str_pad($digits, $padTo, '0', STR_PAD_LEFT);

            // Year-aware: a leading four-digit group is the academic year, so only
            // the sequence after it is padded. "SAC/2026/1" -> SAC2026001
            if (strlen($digits) > 4) {
                $variants[] = $letters
                    . substr($digits, 0, 4)
                    . str_pad(substr($digits, 4), $padTo, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($variants));
    }
}
