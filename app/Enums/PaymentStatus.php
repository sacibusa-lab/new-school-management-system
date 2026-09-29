<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PaymentStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20',
            self::Successful => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
            self::Failed => 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20',
            self::Reversed => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
        };
    }

    /** Only a successful payment may reduce an invoice balance. */
    public function countsAsMoney(): bool
    {
        return $this === self::Successful;
    }
}
