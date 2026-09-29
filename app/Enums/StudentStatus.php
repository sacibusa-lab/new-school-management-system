<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StudentStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Suspended = 'suspended';
    case Graduated = 'graduated';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Graduated => 'Graduated',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
            self::Suspended => 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20',
            self::Graduated => 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/20',
            self::Withdrawn => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
        };
    }
}
