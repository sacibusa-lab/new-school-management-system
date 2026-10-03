<?php

namespace App\Services;

use App\Enums\SequenceType;
use App\Models\NumberSequence;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Issues the two gaps-free number series used across the platform.
 *
 *  - SAC-00001      admission registration number, global and ascending forever
 *  - SAC/2026/001   admission number, restarting each academic year
 *
 * Allocation is done inside a transaction with a row lock so two simultaneous
 * registrations can never be handed the same number.
 */
class NumberSequenceService
{
    public function nextAdmissionRegistrationNumber(): string
    {
        $prefix = (string) (Setting::get('admission_number_prefix') ?: config('saci.admission_prefix', 'SAC'));
        $padding = (int) (Setting::get('admission_number_padding') ?: 5);

        return $this->allocate(
            SequenceType::AdmissionRegistration,
            'global',
            $prefix,
            fn (int $n) => sprintf('%s-%s', $prefix, str_pad((string) $n, $padding, '0', STR_PAD_LEFT)),
        );
    }

    public function nextStudentNumber(int|string|null $year = null): string
    {
        $year = (string) ($year ?: now()->year);
        $prefix = (string) (Setting::get('student_number_prefix') ?: config('saci.student_prefix', 'SAC'));
        $padding = (int) (Setting::get('student_number_padding') ?: 3);

        return $this->allocate(
            SequenceType::StudentNumber,
            $year,
            $prefix,
            fn (int $n) => sprintf('%s/%s/%s', $prefix, $year, str_pad((string) $n, $padding, '0', STR_PAD_LEFT)),
        );
    }

    public function nextInvoiceNumber(int|string|null $year = null): string
    {
        $year = (string) ($year ?: now()->year);
        $prefix = (string) (Setting::get('invoice_prefix') ?: 'INV');

        return $this->allocate(
            SequenceType::Invoice,
            $year,
            $prefix,
            fn (int $n) => sprintf('%s/%s/%s', $prefix, $year, str_pad((string) $n, 5, '0', STR_PAD_LEFT)),
        );
    }

    public function nextReceiptNumber(int|string|null $year = null): string
    {
        $year = (string) ($year ?: now()->year);
        $prefix = (string) (Setting::get('receipt_prefix') ?: 'RCP');

        return $this->allocate(
            SequenceType::Receipt,
            $year,
            $prefix,
            fn (int $n) => sprintf('%s/%s/%s', $prefix, $year, str_pad((string) $n, 5, '0', STR_PAD_LEFT)),
        );
    }

    /** What the next number *would* be, without consuming it. */
    public function preview(SequenceType $type, int|string|null $year = null): string
    {
        $scope = $type->isYearScoped() ? (string) ($year ?: now()->year) : 'global';

        $current = (int) NumberSequence::query()
            ->where('type', $type->value)
            ->where('scope', $scope)
            ->value('last_number');

        $next = $current + 1;

        return match ($type) {
            SequenceType::AdmissionRegistration => sprintf(
                '%s-%s',
                (string) (Setting::get('admission_number_prefix') ?: 'SAC'),
                str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            ),
            SequenceType::StudentNumber => sprintf(
                '%s/%s/%s',
                (string) (Setting::get('student_number_prefix') ?: 'SAC'),
                $scope,
                str_pad((string) $next, 3, '0', STR_PAD_LEFT),
            ),
            SequenceType::Invoice => sprintf(
                '%s/%s/%s',
                (string) (Setting::get('invoice_prefix') ?: 'INV'),
                $scope,
                str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            ),
            SequenceType::Receipt => sprintf(
                '%s/%s/%s',
                (string) (Setting::get('receipt_prefix') ?: 'RCP'),
                $scope,
                str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            ),
        };
    }

    /** Manually reposition a series (e.g. migrating from an old system). */
    public function setLastNumber(SequenceType $type, int|string $scope, int $lastNumber): void
    {
        NumberSequence::query()->updateOrCreate(
            ['type' => $type->value, 'scope' => (string) $scope],
            ['last_number' => $lastNumber],
        );
    }

    /**
     * Raise the admission number series above a number the office issued by hand.
     *
     * The series is normally handed out from here, but the office can type an admission
     * number on the Add Student screen: they have a paper register, and the number on it
     * is the school's rather than ours. That number is then in use, and the counter behind
     * the series has no idea — left alone it would eventually reach the same number and
     * offer it to an admission, which the unique index on `students.student_number` would
     * refuse at the counter, with a parent waiting.
     *
     * So the counter is raised to sit above whatever was typed, and only ever raised: a
     * number already in use must never be issued twice, so this can move the series
     * forwards and never back. A number not shaped like this series is left alone — a
     * school that numbers its children its own way keeps its own way, and there is
     * nothing here to keep in step with it.
     *
     * @see setLastNumber() For the unconditional version, which the settings screen uses.
     */
    public function raiseStudentNumberTo(string $studentNumber): void
    {
        $prefix = (string) (Setting::get('student_number_prefix') ?: config('saci.student_prefix', 'SAC'));

        if (preg_match('#^'.preg_quote($prefix, '#').'/(\d{4})/(\d+)$#', $studentNumber, $matches) !== 1) {
            return;
        }

        $year = $matches[1];
        $number = (int) $matches[2];

        DB::transaction(function () use ($prefix, $year, $number): void {
            $row = $this->lockRow(SequenceType::StudentNumber, $year, $prefix);

            if ((int) $row->last_number < $number) {
                $row->forceFill(['last_number' => $number])->save();
            }
        }, 3);
    }

    /**
     * Atomically reserve the next value for a series.
     */
    protected function allocate(SequenceType $type, string $scope, string $prefix, Closure $formatter): string
    {
        return DB::transaction(function () use ($type, $scope, $prefix, $formatter) {
            $row = $this->lockRow($type, $scope, $prefix);

            $row->increment('last_number');
            $row->refresh();

            return $formatter((int) $row->last_number);
        }, 3);
    }

    protected function lockRow(SequenceType $type, string $scope, string $prefix): NumberSequence
    {
        $row = NumberSequence::query()
            ->where('type', $type->value)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        // First use of this series. insertOrIgnore survives a concurrent creator,
        // then the re-select (with a lock) gives us the shared row.
        NumberSequence::query()->insertOrIgnore([
            'type' => $type->value,
            'scope' => $scope,
            'prefix' => $prefix,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return NumberSequence::query()
            ->where('type', $type->value)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
