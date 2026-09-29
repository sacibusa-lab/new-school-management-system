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
            self::Pending => 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20',
            self::Admitted => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
            self::Rejected => 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20',
            self::Deferred => 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/20',
            self::Withdrawn => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
        };
    }

    /** Only an admitted applicant is transferred into results + fees. */
    public function triggersEnrolment(): bool
    {
        return $this === self::Admitted;
    }
}
