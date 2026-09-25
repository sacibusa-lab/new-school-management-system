<?php

namespace App\Support\Concerns;

/**
 * Finding a record by a printed number, however the parent actually typed it.
 *
 * Shared by the public lookup screens and by resit self-registration, so
 * "sac 00002", "SAC-00002" and "SAC00002" all behave identically everywhere.
 */
trait FindsRecordsByNumber
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return \Illuminate\Database\Eloquent\Builder<*>
     */
    protected function findByNumber($query, string $column, string $value, int $padding)
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? '');

        if ($clean === '') {
            return $query->whereRaw('1 = 0');
        }

        $variants = [$clean];

        // Split "SAC00002" into letters + digits so we can also try padding.
        if (preg_match('/^([A-Z]*)(\d+)$/', $clean, $matches)) {
            [, $letters, $digits] = $matches;
            $padTo = max($padding, 1);

            // Plain: pad the whole digit group. "SAC-1" -> SAC00001
            $variants[] = $letters . str_pad($digits, $padTo, '0', STR_PAD_LEFT);

            // Year-aware: treat the leading four digits as the academic year and
            // pad only the sequence. "SAC/2026/1" -> SAC2026001
            if (strlen($digits) > 4) {
                $variants[] = $letters
                    . substr($digits, 0, 4)
                    . str_pad(substr($digits, 4), $padTo, '0', STR_PAD_LEFT);
            }
        }

        $variants = array_values(array_unique($variants));

        // $column is always a hard-coded internal column name, never user input.
        return $query->where(function ($inner) use ($column, $variants) {
            foreach ($variants as $variant) {
                $inner->orWhereRaw(
                    "UPPER(REPLACE(REPLACE(REPLACE({$column}, '-', ''), '/', ''), ' ', '')) = ?",
                    [$variant],
                );
            }
        });
    }
}
