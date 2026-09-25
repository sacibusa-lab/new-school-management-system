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
            self::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Successful => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Reversed => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }

    /** Only a successful payment may reduce an invoice balance. */
    public function countsAsMoney(): bool
    {
        return $this === self::Successful;
    }
}
