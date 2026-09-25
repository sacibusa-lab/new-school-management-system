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
            self::Unpaid => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Partial => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Paid => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Overdue => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            self::Cancelled => 'bg-slate-100 text-slate-500 ring-slate-500/20',
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
