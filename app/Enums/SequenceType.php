<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The two number series in the platform.
 *
 *  AdmissionRegistration -> SAC-00001  (global, ascending forever)
 *  StudentNumber         -> SAC/2026/001 (restarts each academic year)
 */
enum SequenceType: string
{
    use HasOptions;

    case AdmissionRegistration = 'admission_registration';
    case StudentNumber = 'student_number';
    case Invoice = 'invoice';
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::AdmissionRegistration => 'Admission registration number',
            self::StudentNumber => 'Admission number',
            self::Invoice => 'Invoice number',
            self::Receipt => 'Receipt number',
        };
    }

    /** Series that restart each academic year. */
    public function isYearScoped(): bool
    {
        return in_array($this, [self::StudentNumber, self::Invoice, self::Receipt], true);
    }
}
