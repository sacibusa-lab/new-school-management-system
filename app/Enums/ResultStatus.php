<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ResultStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Computed = 'computed';
    case Approved = 'approved';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Computed => 'Computed',
            self::Approved => 'Approved',
            self::Published => 'Published',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
            self::Computed => 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/20',
            self::Approved => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            self::Published => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
        };
    }

    /** Students may only ever see a published result. */
    public function isVisibleToStudent(): bool
    {
        return $this === self::Published;
    }
}
