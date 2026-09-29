<?php

namespace App\Support\Concerns;

use App\Support\RegistrationNumber;

/**
 * Finding a record by a printed number, however the parent actually typed it.
 *
 * Shared by the public lookup screens and by resit self-registration, so
 * "sac 00002", "SAC-00002" and "SAC00002" all behave identically everywhere.
 *
 * The spellings themselves come from RegistrationNumber, so the screens that
 * READ a number and the screens that MATCH one from a filename agree.
 */
trait FindsRecordsByNumber
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return \Illuminate\Database\Eloquent\Builder<*>
     */
    protected function findByNumber($query, string $column, string $value, int $padding)
    {
        $variants = RegistrationNumber::variants($value, $padding);

        if ($variants === []) {
            return $query->whereRaw('1 = 0');
        }

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
