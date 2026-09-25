<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AdmissionDecisionStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Deferred = 'deferred';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Admitted => 'Admitted',
            self::Rejected => 'Not Admitted',
            self::Deferred => 'Deferred',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Admitted => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Rejected => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Deferred => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::Withdrawn => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }

    /** Only an admitted applicant is transferred into results + fees. */
    public function triggersEnrolment(): bool
    {
        return $this === self::Admitted;
    }
}
