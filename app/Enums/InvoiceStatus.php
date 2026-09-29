<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvoiceStatus: string
{
    use HasOptions;

    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Unpaid => 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20',
            self::Partial => 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20',
            self::Paid => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
            self::Overdue => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            self::Cancelled => 'bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20',
        };
    }

    /** Derive status from the money, never trust a hand-set value. */
    public static function derive(float $total, float $paid, bool $pastDue = false): self
    {
        if ($total > 0 && $paid >= $total) {
            return self::Paid;
        }

        if ($paid > 0) {
            return $pastDue ? self::Overdue : self::Partial;
        }

        return $pastDue ? self::Overdue : self::Unpaid;
    }
}
